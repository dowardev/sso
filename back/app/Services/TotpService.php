<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Services;

use App\Models\TotpBackupCode;
use App\Models\TotpCredential;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * RFC 6238 TOTP service (Google Authenticator style).
 *
 * Single source of truth for every TOTP computation in the app: enrollment,
 * verification at login, anti-reuse bookkeeping, backup-code generation
 * and consumption. The HTTP layer (AuthController) MUST go through this
 * service and never read `encrypted_secret` directly — see
 * {@see encryptSecret()} / {@see decryptSecret()} for the storage contract.
 *
 * Defaults match what Google Authenticator, Authy, 1Password, Bitwarden, and
 * the rest of the consumer authenticator ecosystem expect: 6 digits,
 * 30-second step, SHA-1. Changing any of these would break compatibility
 * with those apps — the schema stores them anyway so a future migration can
 * surface them without a schema change.
 */
class TotpService
{
    /** Length of the raw secret in bytes (160 bits — RFC 4226 §6 R1). */
    private const SECRET_BYTES = 20;

    /**
     * Symmetric leeway accepted on either side of the current step. RFC 6238
     * §5.2: "We RECOMMEND a default time-step of 30 seconds… the OTP SHOULD
     * be accepted if it matches any of the (current, previous, and next)
     * values." That is 3 values total = ±1 step, which is what
     * DEFAULT_WINDOW=1 produces here. The wider the window, the larger the
     * replay-attack surface, so we keep it tight.
     */
    public const DEFAULT_WINDOW = 1;

    public const DEFAULT_DIGITS = 6;

    public const DEFAULT_PERIOD = 30;

    public const DEFAULT_ALGORITHM = 'SHA1';

    public const BACKUP_CODE_COUNT = 10;

    public const BACKUP_CODE_LENGTH = 10;

    /**
     * Generate a fresh random base32 secret suitable for an otpauth:// URI.
     * Caller stores the encrypted form via {@see encryptSecret()}; the
     * plaintext returned here never leaves the request — see the enrollment
     * controller which serializes it inside the JSON response and never
     * persists it unencrypted.
     */
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /**
     * Build the otpauth:// URI that authenticator apps consume. Issuer and
     * account label are URL-encoded so an account name like "name with
     * spaces" still produces a parseable URI.
     */
    public function buildOtpauthUri(User $user, string $plainSecret): string
    {
        $issuer = (string) config('app.name', 'SSO Server');
        // Account label: prefer the stable identifier the user types at
        // login. For ZKE users name/email are null by design, so fall back
        // to a neutral local-only label that does not leak the real id.
        $label = $user->user ?? $user->email ?? ('user-'.$user->id);

        $query = http_build_query([
            'secret' => $plainSecret,
            'issuer' => $issuer,
            'algorithm' => self::DEFAULT_ALGORITHM,
            'digits' => self::DEFAULT_DIGITS,
            'period' => self::DEFAULT_PERIOD,
        ]);

        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($label).'?'.$query;
    }

    /**
     * RFC 6238 time-step for a given Unix timestamp. Time T at default
     * 30s/period yields floor(T/30).
     */
    public function currentStep(?int $timestamp = null, int $period = self::DEFAULT_PERIOD): int
    {
        $timestamp ??= time();

        return intdiv($timestamp, $period);
    }

    /**
     * RFC 4226 HOTP for a given counter (used as the building block of
     * TOTP). Returns the truncated code modulo 10^digits.
     */
    public function hotp(string $base32Secret, int $counter, int $digits = self::DEFAULT_DIGITS, string $algorithm = self::DEFAULT_ALGORITHM): string
    {
        $key = $this->base32Decode($base32Secret);
        $binCounter = pack('N*', 0, $counter);

        $hash = match ($algorithm) {
            'SHA1' => hash_hmac('sha1', $binCounter, $key, true),
            'SHA256' => hash_hmac('sha256', $binCounter, $key, true),
            'SHA512' => hash_hmac('sha512', $binCounter, $key, true),
            default => throw new \InvalidArgumentException("Unsupported TOTP algorithm: {$algorithm}"),
        };

        // RFC 4226 §5.3 dynamic truncation. Mask the high byte to discard
        // its sign bit, then take the low 4 bits as an offset into the hash.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part = substr($hash, $offset, 4);
        $unpack = unpack('N', $part);
        $value = ($unpack[1] & 0x7FFFFFFF) % (10 ** $digits);

        return str_pad((string) $value, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Convenience: TOTP for "now". Mostly used in tests; production code
     * goes through {@see verifyCode()} which handles window + anti-reuse.
     */
    public function totpNow(string $base32Secret, int $period = self::DEFAULT_PERIOD, int $digits = self::DEFAULT_DIGITS, string $algorithm = self::DEFAULT_ALGORITHM): string
    {
        return $this->hotp($base32Secret, $this->currentStep(null, $period), $digits, $algorithm);
    }

    /**
     * Verify a presented TOTP code against the stored credential.
     *
     * Accepts a code that matches the current step OR any of the steps in
     * [-window, +window]. Among matches, picks the highest step (i.e. the
     * freshest one a future-leaning clock would accept), then enforces the
     * anti-reuse rule against `last_used_step`: any step <= last_used_step
     * is rejected even if its HMAC matches.
     *
     * On success, updates last_used_step to the accepted step so the same
     * code cannot be replayed. Persistence is wrapped in a transaction so a
     * mid-write crash does not leave a hole in the counter.
     *
     * Returns the accepted step on success, null on failure. Callers MUST
     * treat null as "do not consume a backup code, do not issue a token".
     *
     * The caller is responsible for any "must be active" gate — the login
     * flow needs it (a pending enrollment MUST NOT log the user in), but
     * the enrollment-confirm flow does not (it verifies a code BEFORE
     * setting confirmed_at). The totpLogin endpoint re-checks
     * `activeTotpCredential`; totpEnrollConfirm skips the gate because it
     * is the moment that flips confirmed_at to set.
     */
    public function verifyAndConsume(TotpCredential $credential, string $code, int $window = self::DEFAULT_WINDOW): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        // Validate against the credential's own `digits` column, not the
        // DEFAULT_DIGITS constant — a future schema could store 7/8-digit
        // codes and the constant would silently reject every match.
        if (! preg_match('/^\d{'.$credential->digits.'}$/', $code)) {
            return null;
        }

        $plainSecret = $this->decryptSecret($credential->encrypted_secret);

        // LBDS-8 review (B-01): the original implementation read
        // `last_used_step` outside the transaction, leaving a TOCTOU
        // window: two parallel requests with a valid code for the same
        // step could both pass the replay check. We now do the read,
        // decision, and write under a single lockForUpdate so concurrent
        // verifications serialize on the row.
        return DB::transaction(function () use ($credential, $code, $window, $plainSecret) {
            // Re-load under the row lock; the $credential passed in may
            // carry stale data. The caller is responsible for any
            // "must be active" gate — the login flow uses
            // activeTotpCredential() before calling us, the enrollment-
            // confirm flow does not (it sets confirmed_at after we
            // succeed).
            $locked = TotpCredential::where('id', $credential->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return null;
            }

            $nowStep = $this->currentStep(null, $locked->period);

            $bestStep = null;
            for ($offset = -$window; $offset <= $window; $offset++) {
                $step = $nowStep + $offset;
                $candidate = $this->hotp(
                    $plainSecret,
                    $step,
                    $locked->digits,
                    $locked->algorithm,
                );

                if (hash_equals($candidate, $code)) {
                    if ($bestStep === null || $step > $bestStep) {
                        $bestStep = $step;
                    }
                }
            }

            if ($bestStep === null) {
                return null;
            }

            if ($locked->last_used_step !== null && $bestStep <= $locked->last_used_step) {
                // Replay attempt — the same code (or one for an earlier
                // step in the window) was already accepted. Refuse.
                return null;
            }

            $locked->last_used_step = $bestStep;
            $locked->save();

            return $bestStep;
        });
    }

    /**
     * Verify a backup code and mark the matching row as used.
     *
     * Backup codes are single-use: once a row's `used_at` is set the row
     * stops matching this query. Returns true on the first match found and
     * consumed. The transaction scopes the lookup + update so a race
     * between two parallel requests cannot double-spend the same code.
     */
    public function verifyBackupCode(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if ($code === '') {
            return false;
        }

        // LBDS-8 review (B-07): an early-return loop leaks the position of
        // the matching row through the response time. Argon2id is
        // constant-time per hash, but a "no match" request that runs
        // through all 10 hashes takes measurably longer than a "first-row
        // match" request. We now lock all unused rows in one query so the
        // full set is held, then iterate hashing every row before
        // returning. The wall-clock cost is now independent of match
        // position. Combined with FOR UPDATE the parallel-consume race
        // is also closed.
        $rows = TotpBackupCode::where('user_id', $user->id)
            ->whereNull('used_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $matchId = null;
        foreach ($rows as $row) {
            if (Hash::check($code, $row->code_hash)) {
                $matchId = $row->id;
                // Continue the loop so every row gets hashed regardless
                // of where the match is — that's what closes the timing
                // leak. The break is intentionally absent.
            }
        }

        if ($matchId === null) {
            return false;
        }

        $row = $rows->firstWhere('id', $matchId);
        $row->used_at = now();
        $row->save();

        return true;
    }

    /**
     * Generate `count` backup codes (plaintext), hash + insert them all in
     * one transaction, and return the plaintext list to the caller. The
     * plaintext MUST be shown to the user exactly once (download/copy) and
     * is never persisted.
     *
     * Existing codes for the user are NOT deleted here — the caller
     * (regenerate flow) does that explicitly so this method is safe to call
     * at enrollment without nuking a user's first set on a retry.
     */
    public function generateBackupCodes(User $user, int $count = self::BACKUP_CODE_COUNT): array
    {
        // LBDS-8 review (B-02): the previous version used `Str::random`
        // then `strtoupper` and trusted the result to be human-readable.
        // [A-Za-z0-9] contains I, O, 0, 1, l — chars users regularly
        // transpose when transcribing by hand. We now sample from an
        // unambiguous alphabet (Crockford-style, no I/O/0/1) and re-roll
        // until each code is unique within the set.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $alphabetLen = strlen($alphabet);
        $codes = [];
        $seen = [];
        while (count($codes) < $count) {
            $code = '';
            for ($i = 0; $i < self::BACKUP_CODE_LENGTH; $i++) {
                $code .= $alphabet[random_int(0, $alphabetLen - 1)];
            }
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $codes[] = $code;
        }

        DB::transaction(function () use ($user, $codes) {
            foreach ($codes as $code) {
                TotpBackupCode::create([
                    'user_id' => $user->id,
                    'code_hash' => Hash::make($code),
                ]);
            }
        });

        return $codes;
    }

    /**
     * Delete every backup code row for the user. Used by regenerate after
     * issuing the new set, and by disable when TOTP is turned off.
     */
    public function deleteAllBackupCodes(User $user): int
    {
        return TotpBackupCode::where('user_id', $user->id)->delete();
    }

    public function encryptSecret(string $plainSecret): string
    {
        return Crypt::encryptString($plainSecret);
    }

    public function decryptSecret(string $encryptedSecret): string
    {
        return Crypt::decryptString($encryptedSecret);
    }

    /**
     * RFC 4648 base32 encode. Padding to 8 chars is required by RFC 4648 §6
     * and lets the matching decoder (every consumer authenticator
     * implementation) round-trip the secret.
     */
    private function base32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $buffer = ($buffer << 8) | ord($bytes[$i]);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $output .= $alphabet[($buffer >> $bitsLeft) & 0x1F];
            }
        }

        if ($bitsLeft > 0) {
            $output .= $alphabet[($buffer << (5 - $bitsLeft)) & 0x1F];
        }

        // Pad to a multiple of 8 characters.
        return str_pad($output, (int) ceil(strlen($output) / 8) * 8, '=');
    }

    /**
     * RFC 4648 base32 decode. Strips whitespace + padding before decoding
     * so callers may pass human-typed secrets with line breaks.
     */
    private function base32Decode(string $input): string
    {
        $input = strtoupper(trim($input));
        $input = preg_replace('/\s+|=+$/', '', $input) ?? '';

        // LBDS-8 review (B-09): RFC 4648 §6 mandates a length that is a
        // multiple of 8 chars (after padding is restored). A 20-byte
        // secret rounds up to 32 chars; anything else is malformed. We
        // surface the bad input rather than silently producing a short
        // key that fails the HMAC in a confusing way downstream.
        if (strlen($input) === 0 || strlen($input) % 8 !== 0) {
            throw new \InvalidArgumentException(
                'Base32 secret length must be a non-zero multiple of 8 characters (got '
                . strlen($input) . ').'
            );
        }

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $map = array_flip(str_split($alphabet));

        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0, $n = strlen($input); $i < $n; $i++) {
            $ch = $input[$i];
            if (! isset($map[$ch])) {
                throw new \InvalidArgumentException("Invalid base32 character: {$ch}");
            }
            $buffer = ($buffer << 5) | $map[$ch];
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}