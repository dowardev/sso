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

    // New auth codes (single-language API)
    public const AUTH_TOTP_REQUIRED = 'auth.totp_required';

    public const AUTH_PASSKEY_REQUIRED = 'auth.passkey_required';

    public const AUTH_PASSKEY_SESSION_EXPIRED = 'auth.passkey_session_expired';

    public const AUTH_PASSKEY_SESSION_USED = 'auth.passkey_session_used';

    public const AUTH_TOTP_NOT_ENABLED = 'auth.totp_not_enabled';

    public const AUTH_ADVANCED_ENCRYPTION_ALREADY_SET = 'auth.advanced_encryption_already_set';

    // SSO client codes
    public const CLIENT_NOT_REGISTERED = 'client.not_registered';

    public const CLIENT_DISABLED = 'client.disabled';

    public const CLIENT_REDIRECT_URI_NOT_ALLOWED = 'client.redirect_uri_not_allowed';

    public const CLIENT_MISCONFIGURED = 'client.misconfigured';

    public const CLIENT_INVALID_CREDENTIALS = 'client.invalid_credentials';

    // DPoP codes
    public const DPOP_INCOMPLETE_JWK = 'dpop.incomplete_jwk';

    public const DPOP_UNSUPPORTED_JWK = 'dpop.unsupported_jwk';

    // Role codes
    public const ROLE_PROTECTED_UPDATE = 'role.protected_update';

    public const ROLE_PROTECTED_DELETE = 'role.protected_delete';

    public const ROLE_IN_USE = 'role.in_use';

    // Setting codes
    public const SETTING_IMAGE_REQUIRED = 'setting.image_required';

    // Setup codes
    public const SETUP_ALREADY_COMPLETED = 'setup.already_completed';

    // User codes
    public const USER_SELF_DELETE_FORBIDDEN = 'user.self_delete_forbidden';

    // Session codes
    public const SESSION_SELF_REVOKE_FORBIDDEN = 'session.self_revoke_forbidden';

    // Profile codes
    public const PROFILE_ENCRYPTED_PROFILE_REQUIRED = 'profile.encrypted_profile_required';

    // CSP codes
    public const CSP_HASH_ALREADY_ALLOWED = 'csp.hash_already_allowed';

    // Email verification codes
    public const EMAIL_VERIFICATION_INVALID_LINK = 'email_verification.invalid_link';

    // Success codes
    public const SUCCESS_CSP_HASH_ADDED = 'success.csp_hash_added';

    public const SUCCESS_EMAIL_VERIFIED = 'success.email_verified';

    public const SUCCESS_VERIFICATION_EMAIL_SENT = 'success.verification_email_sent';

    public const SUCCESS_VERIFICATION_EMAIL_RESENT = 'success.verification_email_resent';

    public const SUCCESS_ROLE_CREATED = 'success.role_created';

    public const SUCCESS_ROLE_UPDATED = 'success.role_updated';

    public const SUCCESS_ROLE_DELETED = 'success.role_deleted';

    public const SUCCESS_SETTING_UPDATED = 'success.setting_updated';

    public const SUCCESS_LOGO_UPDATED = 'success.logo_updated';

    public const SUCCESS_LOGO_DELETED = 'success.logo_deleted';

    public const SUCCESS_EMAIL_LOGO_UPDATED = 'success.email_logo_updated';

    public const SUCCESS_EMAIL_LOGO_DELETED = 'success.email_logo_deleted';

    public const SUCCESS_ADMIN_CREATED = 'success.admin_created';

    public const SUCCESS_CLIENT_CREATED = 'success.client_created';

    public const SUCCESS_CLIENT_UPDATED = 'success.client_updated';

    public const SUCCESS_CLIENT_DELETED = 'success.client_deleted';

    public const SUCCESS_USER_UPDATED = 'success.user_updated';

    public const SUCCESS_USER_DELETED = 'success.user_deleted';

    public const SUCCESS_USER_ROLES_UPDATED = 'success.user_roles_updated';

    public const SUCCESS_SESSION_REVOKED = 'success.session_revoked';

    public const SUCCESS_USER_SESSIONS_REVOKED = 'success.user_sessions_revoked';

    public const SUCCESS_SESSIONS_REVOKED = 'success.sessions_revoked';

    public const SUCCESS_ACCOUNT_RECOVERED = 'success.account_recovered';

    public const SUCCESS_PASSWORD_UPDATED = 'success.password_updated';

    public const SUCCESS_PASSWORD_UPDATED_SESSIONS_REVOKED = 'success.password_updated_sessions_revoked';

    public const SUCCESS_UPGRADE_TO_ADVANCED = 'success.upgrade_to_advanced';

    public const SUCCESS_LOGOUT = 'success.logout';

    public const SUCCESS_PROFILE_UPDATED = 'success.profile_updated';

    public const SUCCESS_PASSKEY_REGISTERED = 'success.passkey_registered';

    public const SUCCESS_PASSKEY_DELETED = 'success.passkey_deleted';

    public const SUCCESS_TOTP_ENABLED = 'success.totp_enabled';

    public const SUCCESS_TOTP_DISABLED = 'success.totp_disabled';

    public const SUCCESS_BACKUP_CODES_REGENERATED = 'success.backup_codes_regenerated';

    public const SUCCESS_ACCOUNT_DELETED = 'success.account_deleted';

    public const SUCCESS_AUTHORIZATION_DENIED = 'success.authorization_denied';

    public const SUCCESS_ACCESS_REVOKED = 'success.access_revoked';

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
        // A missing file fails as a required field; the attribute exception below
        // refines it for the logo upload.
        'File' => self::VALIDATION_REQUIRED,
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
        'image' => [
            'File' => self::SETTING_IMAGE_REQUIRED,
            'Required' => self::SETTING_IMAGE_REQUIRED,
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
            self::VALIDATION_REQUIRED => 'This field is required.',
            self::VALIDATION_STRING => 'This field must be text.',
            self::VALIDATION_MIN => 'This field must have at least {min} characters.',
            self::VALIDATION_MAX => 'This field cannot exceed {max} characters.',
            self::VALIDATION_EMAIL => 'This field must be a valid email.',
            self::VALIDATION_UNIQUE => 'This field is already in use.',
            self::VALIDATION_EXISTS => 'The value of this field does not exist.',
            self::VALIDATION_CONFIRMED => 'The confirmation of this field does not match.',
            self::VALIDATION_REGEX => 'This field does not have a valid format.',
            self::VALIDATION_JSON => 'This field must be valid JSON.',
            self::VALIDATION_BOOLEAN => 'This field must be true or false.',
            self::VALIDATION_IN => 'The value of this field is not valid.',
            self::VALIDATION_ARRAY => 'This field must be a list.',
            self::VALIDATION_INTEGER => 'This field must be an integer.',
            self::VALIDATION_NUMERIC => 'This field must be a number.',
            self::VALIDATION_URL => 'This field must be a valid URL.',
            self::VALIDATION_DATE => 'This field must be a valid date.',
            self::VALIDATION_AFTER_OR_EQUAL => 'This field must be a date after or equal to {date}.',
            self::VALIDATION_IMAGE => 'This field must be a valid image.',
            self::VALIDATION_PASSWORD_COMPLEX => 'The password must have at least 8 characters, an uppercase letter, a number, and a special character.',
            self::VALIDATION_UNKNOWN => 'Validation error. Check the data and try again.',
            self::CLIENT_NOT_REGISTERED => 'The application is not registered in the SSO.',
            self::CSP_HASH_ALREADY_ALLOWED => 'The hash is already in the allowed list.',
            self::EMAIL_VERIFICATION_INVALID_LINK => 'The verification link is invalid or expired.',
            self::ROLE_PROTECTED_UPDATE => 'You cannot modify a protected role.',
            self::ROLE_PROTECTED_DELETE => 'You cannot delete a protected role.',
            self::ROLE_IN_USE => 'You cannot delete a role that has users assigned.',
            self::SETTING_IMAGE_REQUIRED => 'An image is required.',
            self::SETUP_ALREADY_COMPLETED => 'There are already registered users in the system.',
            self::USER_SELF_DELETE_FORBIDDEN => 'You cannot delete your own user.',
            self::SESSION_SELF_REVOKE_FORBIDDEN => 'You cannot revoke your own sessions from the admin panel.',
            self::PROFILE_ENCRYPTED_PROFILE_REQUIRED => 'encrypted_profile is required for advanced users.',
            self::AUTH_TOTP_REQUIRED => 'You need your TOTP code (or a backup code) to continue.',
            self::AUTH_PASSKEY_REQUIRED => 'Use your passkey to complete sign-in.',
            self::AUTH_PASSKEY_SESSION_EXPIRED => 'The passkey session expired or was already used. Please sign in again.',
            self::AUTH_PASSKEY_SESSION_USED => 'The passkey session was already used. Please sign in again.',
            self::AUTH_TOTP_NOT_ENABLED => 'TOTP is not enabled. Enable TOTP before generating backup codes.',
            self::AUTH_ADVANCED_ENCRYPTION_ALREADY_SET => 'The user already has advanced encryption enabled.',
            default => 'Validation error.',
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
     * Create a top-level success response array.
     *
     * @param  string  $code  One of the SUCCESS_* constants
     * @param  string  $message  English prose for the success
     * @return array{code: string, message: string}
     */
    public static function success(string $code, string $message): array
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
            str_contains($prose, 'Unauthenticated') || str_contains($prose, 'Unauthorized') => self::AUTH_UNAUTHENTICATED,
            str_contains($prose, 'Forbidden - admin role required') => self::AUTH_FORBIDDEN,
            str_contains($prose, 'token was not provided') => self::AUTH_TOKEN_MISSING,
            str_contains($prose, 'User not found') => self::AUTH_USER_NOT_FOUND,
            str_contains($prose, 'Invalid credentials') => self::AUTH_INVALID_CREDENTIALS,
            str_contains($prose, 'Credential not found') => self::AUTH_CREDENTIALS_NOT_FOUND,
            str_contains($prose, 'The current password is incorrect') => self::AUTH_CURRENT_PASSWORD_INVALID,
            str_contains($prose, 'no registered passkeys') => self::AUTH_PASSKEYS_NOT_REGISTERED,
            str_contains($prose, 'registration session') && str_contains($prose, 'expired') => self::AUTH_REGISTRATION_SESSION_EXPIRED,
            str_contains($prose, 'authentication session') && str_contains($prose, 'expired') => self::AUTH_AUTHENTICATION_SESSION_EXPIRED,
            str_contains($prose, 'Session not found') => self::AUTH_SESSION_NOT_FOUND,
            str_contains($prose, 'Too many attempts') || str_contains($prose, 'Too many requests') => self::AUTH_TOO_MANY_ATTEMPTS,
            str_contains($prose, 'Verify your email') => self::AUTH_EMAIL_NOT_VERIFIED,
            str_contains($prose, 'The seed phrase is incorrect') => self::AUTH_SEED_PHRASE_INVALID,
            str_contains($prose, 'TOTP code') => self::AUTH_TOTP_REQUIRED,
            str_contains($prose, 'passkey to complete') => self::AUTH_PASSKEY_REQUIRED,
            str_contains($prose, 'passkey session expired') => self::AUTH_PASSKEY_SESSION_EXPIRED,
            str_contains($prose, 'passkey session was already used') => self::AUTH_PASSKEY_SESSION_USED,
            str_contains($prose, 'TOTP is not enabled') => self::AUTH_TOTP_NOT_ENABLED,
            str_contains($prose, 'already has advanced encryption enabled') => self::AUTH_ADVANCED_ENCRYPTION_ALREADY_SET,

            // Client
            str_contains($prose, 'not registered in the SSO') => self::CLIENT_NOT_REGISTERED,
            str_contains($prose, 'client is disabled') => self::CLIENT_DISABLED,
            str_contains($prose, 'redirect_uri is not allowed') => self::CLIENT_REDIRECT_URI_NOT_ALLOWED,
            str_contains($prose, 'allowed_origins') && str_contains($prose, 'redirect_uri') => self::CLIENT_MISCONFIGURED,
            str_contains($prose, 'client credentials are not valid') => self::CLIENT_INVALID_CREDENTIALS,

            // DPoP
            str_contains($prose, 'incomplete jwk') => self::DPOP_INCOMPLETE_JWK,
            str_contains($prose, 'unsupported jwk') => self::DPOP_UNSUPPORTED_JWK,

            default => self::AUTH_UNKNOWN,
        };
    }
}
