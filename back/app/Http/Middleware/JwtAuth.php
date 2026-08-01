<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Symfony\Component\HttpFoundation\Response;

class JwtAuth
{
    private Configuration $jwtConfig;

    public function __construct()
    {
        $this->jwtConfig = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::file(base_path(env('JWT_PRIVATE_KEY_PATH', 'keys/private.pem'))),
            InMemory::file(base_path(env('JWT_PUBLIC_KEY_PATH', 'keys/public.pem')))
        );
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'message' => 'Token no proporcionado',
            ], 401);
        }

        try {
            $parsedToken = $this->jwtConfig->parser()->parse($token);

            $constraints = [
                new SignedWith($this->jwtConfig->signer(), $this->jwtConfig->verificationKey()),
                new IssuedBy(config('jwt.issuer')),
                new LooseValidAt(
                    new SystemClock(new \DateTimeZone('UTC'))
                ),
            ];

            $validation = $this->jwtConfig->validator();
            if (! $validation->validate($parsedToken, ...$constraints)) {
                return response()->json([
                    'message' => 'Token inválido',
                ], 401);
            }

            $cnf = $parsedToken->claims()->get('cnf');
            if ($cnf !== null && isset($cnf['jkt'])) {
                $dpopHeader = $request->header('DPoP');
                if (! $dpopHeader) {
                    return response()->json([
                        'message' => 'DPoP proof requerido para este token',
                    ], 401);
                }

                $verifyDpop = new VerifyDpopProof;
                $dpopResponse = $verifyDpop->verify($token, $dpopHeader, $request);
                if ($dpopResponse !== null) {
                    return $dpopResponse;
                }

                $request->merge(['dpop_verified' => true]);
            }

            $grantType = $parsedToken->claims()->get('grant_type');

            if ($grantType === 'client_credentials') {
                $clientId = $parsedToken->claims()->get('client_id');
                $request->merge([
                    'sso_client_id' => $clientId,
                    'sso_grant_type' => 'client_credentials',
                ]);
            } else {
                $userId = $parsedToken->claims()->get('sub');
                $request->merge(['sso_user_id' => $userId]);

                $user = User::find($userId);
                if ($user) {
                    $request->setUserResolver(function () use ($user) {
                        return $user;
                    });
                }
            }

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Token inválido: '.$e->getMessage(),
            ], 401);
        }

        return $next($request);
    }
}
