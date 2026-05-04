<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateJwtKeys extends Command
{
    protected $signature = 'jwt:generate-keys
                            {--bits=2048 : RSA key size in bits}
                            {--force : Overwrite existing keys}';

    protected $description = 'Generate RSA key pair for JWT signing (RS256)';

    public function handle(): int
    {
        $privateKeyPath = config('jwt.private_key_path', base_path('keys/private.pem'));
        $publicKeyPath = config('jwt.public_key_path', base_path('keys/public.pem'));
        $bits = (int) $this->option('bits');

        $keysDir = dirname($privateKeyPath);

        if (! File::isDirectory($keysDir)) {
            File::makeDirectory($keysDir, 0755, true);
            $this->info("Created directory: {$keysDir}");
        }

        if (File::exists($privateKeyPath) || File::exists($publicKeyPath)) {
            if (! $this->option('force')) {
                $this->error('Keys already exist. Use --force to overwrite.');
                return self::FAILURE;
            }
            $this->warn('Overwriting existing keys...');
        }

        $this->info("Generating {$bits}-bit RSA key pair...");

        $privateKey = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (! $privateKey) {
            $this->error('Failed to generate private key: ' . openssl_error_string());
            return self::FAILURE;
        }

        $privateKeyExport = '';
        if (! openssl_pkey_export($privateKey, $privateKeyExport)) {
            $this->error('Failed to export private key: ' . openssl_error_string());
            return self::FAILURE;
        }

        $publicKeyDetails = openssl_pkey_get_details($privateKey);
        if (! $publicKeyDetails || empty($publicKeyDetails['key'])) {
            $this->error('Failed to extract public key: ' . openssl_error_string());
            return self::FAILURE;
        }
        $publicKeyExport = $publicKeyDetails['key'];

        File::put($privateKeyPath, $privateKeyExport);
        File::chmod($privateKeyPath, 0600);

        File::put($publicKeyPath, $publicKeyExport);
        File::chmod($publicKeyPath, 0644);

        $this->info("Private key saved: {$privateKeyPath}");
        $this->info("Public  key saved: {$publicKeyPath}");

        return self::SUCCESS;
    }
}
