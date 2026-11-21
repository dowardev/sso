<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Support;

use Illuminate\Validation\Validator;

/**
 * Centralised error codes and response helpers for the auth/OAuth API surface.
 *
 * Wire format (see odd/tasks/api-error-contract-codes.md):
 * - Top-level error: { "code": "<code>", "message": "<existing prose>" }
 * - Validation error (422): adds "error_details" map alongside existing "errors"
 *   error_details[field] = [{ "code", "field", "message", "params"? }]
 * - OAuth error: the existing "message" key stays as is, "code" is added
 *
 * All codes are defined as constants here. The catalogue is the single source
 * of truth — do not invent new codes in controllers or middleware.
 */
class ApiError
{
    // Auth codes
    public const AUTH_UNAUTHENTICATED = 'auth.unauthenticated';

    public const AUTH_FORBIDDEN = 'auth.forbidden';

    public const AUTH_TOKEN_MISSING = 'auth.token_missing';

    public const AUTH_USER_NOT_FOUND = 'auth.user_not_found';

    public const AUTH_INVALID_CREDENTIALS = 'auth.invalid_credentials';

    public const AUTH_CREDENTIALS_NOT_FOUND = 'auth.credentials_not_found';

    public const AUTH_CURRENT_PASSWORD_INVALID = 'auth.current_password_invalid';

    public const AUTH_PASSKEYS_NOT_REGISTERED = 'auth.passkeys_not_registered';

    public const AUTH_REGISTRATION_SESSION_EXPIRED = 'auth.registration_session_expired';

    public const AUTH_AUTHENTICATION_SESSION_EXPIRED = 'auth.authentication_session_expired';

    public const AUTH_SESSION_NOT_FOUND = 'auth.session_not_found';

    public const AUTH_TOO_MANY_ATTEMPTS = 'auth.too_many_attempts';

    public const AUTH_EMAIL_NOT_VERIFIED = 'auth.email_not_verified';

    public const AUTH_SEED_PHRASE_INVALID = 'auth.seed_phrase_invalid';

    public const AUTH_UNKNOWN = 'auth.unknown';

    // SSO client codes
    public const CLIENT_NOT_REGISTERED = 'client.not_registered';

    public const CLIENT_DISABLED = 'client.disabled';

    public const CLIENT_REDIRECT_URI_NOT_ALLOWED = 'client.redirect_uri_not_allowed';

    public const CLIENT_MISCONFIGURED = 'client.misconfigured';

    public const CLIENT_INVALID_CREDENTIALS = 'client.invalid_credentials';

    // DPoP codes
    public const DPOP_INCOMPLETE_JWK = 'dpop.incomplete_jwk';

    public const DPOP_UNSUPPORTED_JWK = 'dpop.unsupported_jwk';

    // Validation codes (field-scoped)
    public const VALIDATION_REQUIRED = 'validation.required';

    public const VALIDATION_STRING = 'validation.string';

    public const VALIDATION_MIN = 'validation.min';

    public const VALIDATION_MAX = 'validation.max';

    public const VALIDATION_EMAIL = 'validation.email';

    public const VALIDATION_UNIQUE = 'validation.unique';

    public const VALIDATION_EXISTS = 'validation.exists';

    public const VALIDATION_CONFIRMED = 'validation.confirmed';

    public const VALIDATION_REGEX = 'validation.regex';

    public const VALIDATION_JSON = 'validation.json';

    public const VALIDATION_BOOLEAN = 'validation.boolean';

    public const VALIDATION_IN = 'validation.in';

    public const VALIDATION_ARRAY = 'validation.array';

    public const VALIDATION_INTEGER = 'validation.integer';

    public const VALIDATION_NUMERIC = 'validation.numeric';

    public const VALIDATION_URL = 'validation.url';

    public const VALIDATION_DATE = 'validation.date';

    public const VALIDATION_AFTER_OR_EQUAL = 'validation.after_or_equal';

    public const VALIDATION_IMAGE = 'validation.image';

    public const VALIDATION_PASSWORD_COMPLEX = 'validation.password_complex';

    public const VALIDATION_UNKNOWN = 'validation.unknown';

    /**
     * Map of Laravel validation rule names (studly case from Validator::failed())
     * to our validation code constants.
     *
     * @var array<string, string>
     */
    private static array $ruleToCode = [
        'Required' => self::VALIDATION_REQUIRED,
        'String' => self::VALIDATION_STRING,
        'Min' => self::VALIDATION_MIN,
        'Max' => self::VALIDATION_MAX,
        'Email' => self::VALIDATION_EMAIL,
        'Unique' => self::VALIDATION_UNIQUE,
        'Exists' => self::VALIDATION_EXISTS,
        'Confirmed' => self::VALIDATION_CONFIRMED,
        'Regex' => self::VALIDATION_REGEX,
        'Json' => self::VALIDATION_JSON,
        'Boolean' => self::VALIDATION_BOOLEAN,
        'In' => self::VALIDATION_IN,
        'Array' => self::VALIDATION_ARRAY,
        'Integer' => self::VALIDATION_INTEGER,
        'Numeric' => self::VALIDATION_NUMERIC,
        'Url' => self::VALIDATION_URL,
        'Date' => self::VALIDATION_DATE,
        'AfterOrEqual' => self::VALIDATION_AFTER_OR_EQUAL,
        'Image' => self::VALIDATION_IMAGE,
    ];

    /**
     * Documented exceptions: attribute + rule -> specific code.
     * These override the generic rule mapping.
     *
     * @var array<string, array<string, string>>
     */
    private static array $attributeRuleExceptions = [
        'client_id' => [
            'Exists' => self::CLIENT_NOT_REGISTERED,
        ],
        'password' => [
            'Regex' => self::VALIDATION_PASSWORD_COMPLEX,
        ],
    ];

    /**
     * Map of validation rule names to the parameter keys they carry
     * (from Validator::failed() ruleParameters). Used to extract params
     * for error_details[].params.
     *
     * @var array<string, array<int, string>>
     */
    private static array $ruleParameters = [
        'Min' => ['min'],
        'Max' => ['max'],
        'Date' => ['date'],
        'AfterOrEqual' => ['date'],
        'Between' => ['min', 'max'],
        'Size' => ['size'],
    ];

    /**
     * Build the error_details array for a failed validation.
     *
     * @return array<string, array<array{code: string, field: string, message: string, params?: array}>>
     */
    public static function buildErrorDetails(Validator $validator): array
    {
        $failed = $validator->failed();
        $rules = $validator->getRules();
        $messages = $validator->errors()->messages();

        $errorDetails = [];

        foreach ($failed as $attribute => $ruleFailures) {
            $attributeMessages = $messages[$attribute] ?? [];

            foreach ($ruleFailures as $ruleName => $parameters) {
                $code = self::resolveCode($attribute, $ruleName);
                $message = $attributeMessages[0] ?? self::defaultMessageForCode($code);

                $detail = [
                    'code' => $code,
                    'field' => $attribute,
                    'message' => $message,
                ];

                $params = self::extractParams($ruleName, $parameters);
                if ($params !== []) {
                    $detail['params'] = $params;
                }

                $errorDetails[$attribute][] = $detail;
            }
        }

        return $errorDetails;
    }

    /**
     * Resolve the error code for a given attribute and rule.
     */
    private static function resolveCode(string $attribute, string $ruleName): string
    {
        // Check documented exceptions first
        if (isset(self::$attributeRuleExceptions[$attribute][$ruleName])) {
            return self::$attributeRuleExceptions[$attribute][$ruleName];
        }

        // Generic rule mapping
        return self::$ruleToCode[$ruleName] ?? self::VALIDATION_UNKNOWN;
    }

    /**
     * Extract rule parameters for the error_details params field.
     *
     * @param  array<mixed>  $parameters
     * @return array<string, mixed>
     */
    private static function extractParams(string $ruleName, array $parameters): array
    {
        $paramKeys = self::$ruleParameters[$ruleName] ?? [];
        $params = [];

        foreach ($paramKeys as $index => $key) {
            if (isset($parameters[$index])) {
                $params[$key] = $parameters[$index];
            }
        }

        return $params;
    }

    /**
     * Default fallback message for a code (used when validator message is missing).
     */
    private static function defaultMessageForCode(string $code): string
    {
        return match ($code) {
            self::VALIDATION_REQUIRED => 'Este campo es requerido.',
            self::VALIDATION_STRING => 'Este campo debe ser texto.',
            self::VALIDATION_MIN => 'Este campo debe tener al menos {min} caracteres.',
            self::VALIDATION_MAX => 'Este campo no puede superar los {max} caracteres.',
            self::VALIDATION_EMAIL => 'Este campo debe ser un email válido.',
            self::VALIDATION_UNIQUE => 'Este campo ya está en uso.',
            self::VALIDATION_EXISTS => 'El valor de este campo no existe.',
            self::VALIDATION_CONFIRMED => 'La confirmación de este campo no coincide.',
            self::VALIDATION_REGEX => 'Este campo no tiene un formato válido.',
            self::VALIDATION_JSON => 'Este campo debe ser JSON válido.',
            self::VALIDATION_BOOLEAN => 'Este campo debe ser verdadero o falso.',
            self::VALIDATION_IN => 'El valor de este campo no es válido.',
            self::VALIDATION_ARRAY => 'Este campo debe ser una lista.',
            self::VALIDATION_INTEGER => 'Este campo debe ser un número entero.',
            self::VALIDATION_NUMERIC => 'Este campo debe ser un número.',
            self::VALIDATION_URL => 'Este campo debe ser una URL válida.',
            self::VALIDATION_DATE => 'Este campo debe ser una fecha válida.',
            self::VALIDATION_AFTER_OR_EQUAL => 'Este campo debe ser una fecha posterior o igual a {date}.',
            self::VALIDATION_IMAGE => 'Este campo debe ser una imagen válida.',
            self::VALIDATION_PASSWORD_COMPLEX => 'La contraseña debe tener al menos 8 caracteres, una mayúscula, un número y un carácter especial.',
            self::VALIDATION_UNKNOWN => 'Error de validación. Revisa los datos e inténtalo de nuevo.',
            self::CLIENT_NOT_REGISTERED => 'La aplicación no está registrada en el SSO.',
            default => 'Error de validación.',
        };
    }

    /**
     * Create a top-level error response array.
     *
     * @param  string  $code  One of the ApiError constants
     * @param  string  $message  Existing human-readable prose (kept for backwards compatibility)
     * @return array{code: string, message: string}
     */
    public static function topLevel(string $code, string $message): array
    {
        return [
            'code' => $code,
            'message' => $message,
        ];
    }

    /**
     * Map a prose message (from the existing backend) to its code constant.
     * Used for retrofitting existing error responses without changing the prose.
     */
    public static function codeFromProse(string $prose): string
    {
        return match (true) {
            // Auth
            str_contains($prose, 'No autenticado') => self::AUTH_UNAUTHENTICATED,
            str_contains($prose, 'Unauthorized') => self::AUTH_UNAUTHENTICATED,
            str_contains($prose, 'Forbidden - admin role required') => self::AUTH_FORBIDDEN,
            str_contains($prose, 'Token no proporcionado') => self::AUTH_TOKEN_MISSING,
            str_contains($prose, 'Usuario no encontrado') || str_contains($prose, 'User not found') => self::AUTH_USER_NOT_FOUND,
            str_contains($prose, 'Credenciales inválidas') => self::AUTH_INVALID_CREDENTIALS,
            str_contains($prose, 'Credencial no encontrada') => self::AUTH_CREDENTIALS_NOT_FOUND,
            str_contains($prose, 'Contraseña actual incorrecta') => self::AUTH_CURRENT_PASSWORD_INVALID,
            str_contains($prose, 'passkeys registrados') => self::AUTH_PASSKEYS_NOT_REGISTERED,
            str_contains($prose, 'Sesión de registro expirada') => self::AUTH_REGISTRATION_SESSION_EXPIRED,
            str_contains($prose, 'Sesión de autenticación expirada') => self::AUTH_AUTHENTICATION_SESSION_EXPIRED,
            str_contains($prose, 'Sesión no encontrada') => self::AUTH_SESSION_NOT_FOUND,
            str_contains($prose, 'Demasiados intentos') => self::AUTH_TOO_MANY_ATTEMPTS,
            str_contains($prose, 'Verifica tu email') => self::AUTH_EMAIL_NOT_VERIFIED,
            str_contains($prose, 'Frase semilla incorrecta') => self::AUTH_SEED_PHRASE_INVALID,

            // Client
            str_contains($prose, 'no está registrada en el SSO') => self::CLIENT_NOT_REGISTERED,
            str_contains($prose, 'cliente desactivado') || str_contains($prose, 'El cliente SSO está desactivado') => self::CLIENT_DISABLED,
            str_contains($prose, 'redirect_uri no permitida') => self::CLIENT_REDIRECT_URI_NOT_ALLOWED,
            str_contains($prose, 'allowed_origins') && str_contains($prose, 'redirect_uri') => self::CLIENT_MISCONFIGURED,
            str_contains($prose, 'credenciales del cliente no son válidas') => self::CLIENT_INVALID_CREDENTIALS,

            // DPoP
            str_contains($prose, 'incomplete jwk') => self::DPOP_INCOMPLETE_JWK,
            str_contains($prose, 'unsupported jwk') => self::DPOP_UNSUPPORTED_JWK,

            default => self::AUTH_UNKNOWN,
        };
    }
}
