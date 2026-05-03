<?php

namespace App\Http\Controllers;

use App\Models\SsoClient;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\Validation\Constraint;
use Lcobucci\JWT\Validation\Constraint\SignedWith;

class AuthController extends Controller
{
    private Configuration $jwtConfig;
    private int $tokenExpiration;
    private string $issuer;

    public function __construct()
    {
        $this->jwtConfig = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::file(config('jwt.private_key_path')),
            InMemory::file(config('jwt.public_key_path'))
        );

        $this->tokenExpiration = config('jwt.token_expiration', 3600);
        $this->issuer = config('jwt.issuer', 'http://localhost:8001');
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'user' => 'required|string|max:255|unique:users',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'client_id' => 'required|string|exists:sso_clients,client_id',
            'dpop_public_key' => 'nullable|json',
        ], [
            'client_id.exists' => 'La aplicación ":value" no está registrada en el SSO. '
                . 'Por favor, contacta al administrador del sistema.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'user' => $request->user,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $dpopPublicKey = $request->input('dpop_public_key');
        $tokenData = $this->generateToken($user, $dpopPublicKey);

        $this->registerSession($user, $tokenData['token'], $request);

        return response()->json([
            'user' => $user,
            'token' => $tokenData['token'],
            'token_type' => $tokenData['token_type'],
            'expires_in' => $tokenData['expires_in'],
        ], 201);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'nullable|string',
            'user' => 'nullable|string',
            'password' => 'required|string',
            'client_id' => 'nullable|string|exists:sso_clients,client_id',
            'dpop_public_key' => 'nullable|json',
        ], [
            'client_id.exists' => 'La aplicación ":value" no está registrada en el SSO. '
                . 'Por favor, contacta al administrador del sistema.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        $loginField = $request->email ?? $request->user;
        
        if (!$loginField) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => ['email' => ['El email o usuario es requerido']],
            ], 422);
        }

        $user = User::where('email', $loginField)
            ->orWhere('user', $loginField)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Credenciales inválidas',
            ], 401);
        }

        $dpopPublicKey = $request->input('dpop_public_key');
        $tokenData = $this->generateToken($user, $dpopPublicKey);

        $this->registerSession($user, $tokenData['token'], $request, $dpopPublicKey);

        $response = response()->json([
            'user' => $user,
            'token' => $tokenData['token'],
            'token_type' => $tokenData['token_type'],
            'expires_in' => $tokenData['expires_in'],
        ]);

        $response->withCookie(cookie('sso_token', $tokenData['token'], $this->tokenExpiration / 60, '/', null, false, false));

        return $response;
    }

    public function logout(Request $request)
    {
        $tokenString = $request->bearerToken();

        if (!$tokenString) {
            return response()->json(['message' => 'Token no proporcionado'], 401);
        }

        UserSession::where('token', $tokenString)->update([
            'last_used_at' => now(),
        ]);

        return response()->json([
            'message' => 'Sesión cerrada correctamente',
        ]);
    }

    public function me(Request $request)
    {
        $tokenString = $request->bearerToken();

        if (!$tokenString) {
            return response()->json(['message' => 'Token no proporcionado'], 401);
        }

        try {
            $token = $this->jwtConfig->parser()->parse($tokenString);

            if (!$token->isValid()) {
                return response()->json(['message' => 'Token inválido'], 401);
            }

            $userId = $token->claims()->get('sub');
            $user = User::with('roles')->find($userId);

            if (!$user) {
                return response()->json(['message' => 'Usuario no encontrado'], 401);
            }

            $userArray = $user->toArray();
            $userArray['roles'] = $user->roles->toArray();

            return response()->json($userArray);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Token inválido: ' . $e->getMessage()], 401);
        }
    }

    public function jwks(Request $request)
    {
        $privateKeyContent = file_get_contents(config('jwt.private_key_path'));
        $keyDetails = openssl_pkey_get_details(openssl_pkey_get_private($privateKeyContent));

        if (!$keyDetails) {
            return response()->json(['error' => 'Unable to get key details'], 500);
        }

        $n = $this->base64UrlEncode($keyDetails['rsa']['n']);
        $e = $this->base64UrlEncode($keyDetails['rsa']['e']);

        return response()->json([
            'keys' => [
                [
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'kid' => 'sso-key-1',
                    'n' => $n,
                    'e' => $e,
                ]
            ]
        ]);
    }

    public function checkSession(Request $request)
    {
        $tokenString = $request->bearerToken();

        if (!$tokenString) {
            $tokenString = $request->cookie('sso_token');
        }

        if (!$tokenString) {
            return response()->json(['authenticated' => false], 200);
        }

        try {
            $token = $this->jwtConfig->parser()->parse($tokenString);

            if (!$token->isValid()) {
                return response()->json(['authenticated' => false], 200);
            }

            $userId = $token->claims()->get('sub');
            $user = User::with('roles')->find($userId);

            if (!$user) {
                return response()->json(['authenticated' => false], 200);
            }

            $userArray = $user->toArray();
            $userArray['roles'] = $user->roles->toArray();

            return response()->json([
                'authenticated' => true,
                'user' => $userArray,
            ]);

        } catch (\Exception $e) {
            return response()->json(['authenticated' => false], 200);
        }
    }

    private function generateToken(User $user, ?string $dpopPublicKey = null): array
    {
        $now = time();
        $expiresAt = $now + $this->tokenExpiration;

        $builder = $this->jwtConfig->builder()
            ->issuedBy($this->issuer)
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt(new \DateTimeImmutable('@' . $now))
            ->expiresAt(new \DateTimeImmutable('@' . $expiresAt))
            ->relatedTo((string) $user->id)
            ->withClaim('user', [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'user' => $user->user,
            ]);

        if ($dpopPublicKey) {
            $publicKeyData = json_decode($dpopPublicKey, true);
            if ($publicKeyData) {
                if (!isset($publicKeyData['kty']) || !isset($publicKeyData['n']) || !isset($publicKeyData['e'])) {
                    return response()->json([
                        'message' => 'Error de validación',
                        'errors' => ['dpop_public_key' => ['JWK incompleto: se requieren los campos kty, n y e']],
                    ], 422);
                }
                if (($publicKeyData['kty'] ?? '') !== 'RSA') {
                    return response()->json([
                        'message' => 'Error de validación',
                        'errors' => ['dpop_public_key' => ['Tipo de clave JWK no soportado: se requiere RSA']],
                    ], 422);
                }
                $jkt = $this->generateJWKThumbprint($publicKeyData);
                $builder->withClaim('cnf', [
                    'jkt' => $jkt
                ]);
            }
        }

        $token = $builder->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey());

        return [
            'token' => $token->toString(),
            'token_type' => $dpopPublicKey ? 'DPoP' : 'Bearer',
            'expires_in' => $this->tokenExpiration,
        ];
    }

    private function generateJWKThumbprint(array $jwk): string
    {
        $jwkString = json_encode([
            'kty' => $jwk['kty'] ?? 'RSA',
            'n' => $jwk['n'] ?? null,
            'e' => $jwk['e'] ?? null,
        ]);

        return rtrim(strtr(base64_encode(hash('sha256', $jwkString, true)), '+/', '-_'), '=');
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function registerSession(User $user, string $token, Request $request, ?string $dpopPublicKey = null)
    {
        $client = null;
        
        if ($request->client_id) {
            $client = SsoClient::where('client_id', $request->client_id)->first();
        }

        UserSession::create([
            'user_id' => $user->id,
            'sso_client_id' => $client?->id,
            'token' => $token,
            'dpop_public_key' => $dpopPublicKey,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_used_at' => now(),
        ]);
    }
}