<?php

// SPDX-License-Identifier: AGPL-3.0-only

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\OAuth2Controller;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SsoClientController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserSessionController;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

Route::post('/csp-report', [CspReportController::class, 'store']);
Route::get('/setup/status', [SetupController::class, 'status']);
Route::post('/setup/admin', [SetupController::class, 'storeAdmin'])->middleware(['setup.not_completed', 'throttle:1,5']);
Route::get('/settings/public', [SettingController::class, 'publicIndex']);

Route::post('/login', [AuthController::class, 'login'])->middleware(['throttle.login', 'throttle:5,1']);
Route::post('/crypto/blobs', [AuthController::class, 'cryptoBlobs'])->middleware(['throttle:5,1']);
Route::post('/auth/methods', [AuthController::class, 'authMethods'])->middleware(['throttle:5,1']);
Route::post('/login/advanced/verify', [AuthController::class, 'loginAdvancedVerify'])->middleware(['throttle.login', 'throttle:5,1']);
Route::post('/refresh-token', [AuthController::class, 'refreshToken'])->middleware('refresh.token');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:3,1');
Route::post('/register/advanced', [AuthController::class, 'registerAdvanced'])->middleware('throttle:3,1');
Route::post('/recovery/verify', [AuthController::class, 'recoveryVerify'])->middleware('throttle:10,1');
Route::post('/recovery/complete', [AuthController::class, 'recoveryComplete'])->middleware('throttle:10,1');
Route::post('/password/change-advanced', [AuthController::class, 'changePasswordAdvanced'])->middleware('throttle:3,1');
Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
Route::post('/user/upgrade', [AuthController::class, 'upgradeToAdvanced'])->middleware(['jwt.auth', 'throttle:3,1']);
Route::post('/passkey/register/start', [AuthController::class, 'passkeyRegisterStart'])->middleware(['throttle:3,1', StartSession::class]);
Route::post('/passkey/register/finish', [AuthController::class, 'passkeyRegisterFinish'])->middleware(['throttle:3,1', StartSession::class]);
Route::post('/passkey/authenticate/start', [AuthController::class, 'passkeyAuthenticateStart'])->middleware(['throttle:5,1', StartSession::class]);
Route::post('/passkey/authenticate/finish', [AuthController::class, 'passkeyAuthenticateFinish'])->middleware(['throttle:5,1', StartSession::class]);
Route::get('/passkey', [AuthController::class, 'passkeyIndex'])->middleware('jwt.auth');
Route::delete('/passkey', [AuthController::class, 'passkeyDelete'])->middleware('jwt.auth');

// TOTP (RFC 6238) enrollment — opt-in 2FA fallback for users without a
// usable passkey (LBDS-8). Re-auth happens inside totpDisable via the
// password/auth_proof fields the client sends; the endpoints themselves
// are JWT-gated like every other settings surface.
// TOTP (RFC 6238) enrollment — opt-in 2FA fallback for users without a
// usable passkey (LBDS-8). Re-auth happens inside totpDisable via the
// password/auth_proof fields the client sends; the endpoints themselves
// are JWT-gated like every other settings surface. The throttle guards
// against an authenticated user spamming /enroll/start to rotate the
// secret and wipe backup codes (LBDS-8 review B-03).
Route::post('/totp/enroll/start', [AuthController::class, 'totpEnrollStart'])->middleware(['jwt.auth', 'throttle:5,1']);
Route::post('/totp/enroll/confirm', [AuthController::class, 'totpEnrollConfirm'])->middleware(['jwt.auth', 'throttle:5,1']);
Route::delete('/totp', [AuthController::class, 'totpDisable'])->middleware(['jwt.auth', 'throttle:5,1']);
Route::get('/totp/status', [AuthController::class, 'totpStatus'])->middleware('jwt.auth');
Route::post('/totp/backup-codes/regenerate', [AuthController::class, 'totpBackupCodesRegenerate'])->middleware(['jwt.auth', 'throttle:3,1']);

// Login-challenge step: basic /login and /login/advanced/verify return
// { code: 'TOTP_REQUIRED', totp_token } when the user has TOTP enabled.
// The client posts totp_token + (code | backup_code) here to complete
// the login. Unauthenticated (no jwt.auth) — the totp_token itself is
// the proof that the password step already succeeded. The IP-bucket
// throttle is tight (3/min) because the totp_token is a bearer
// credential and the endpoint is unauthenticated (LBDS-8 review B-10).
Route::post('/totp/login', [AuthController::class, 'totpLogin'])->middleware(['throttle.login', 'throttle:3,1']);
Route::get('/email/verify/{token}', [EmailVerificationController::class, 'verify'])->middleware('throttle:10,1');
Route::post('/resend-confirmation-email', [EmailVerificationController::class, 'resend'])->middleware('throttle:3,1');
Route::post('/oauth/token', [OAuth2Controller::class, 'token'])->middleware(['throttle:10,1', 'throttle.token']);
Route::get('/.well-known/jwks', [AuthController::class, 'jwks']);
Route::get('/session/check', [AuthController::class, 'checkSession']);
Route::post('/session/check', [AuthController::class, 'checkSession']);
Route::post('/logout', [AuthController::class, 'logout']);
Route::get('/roles', [RoleController::class, 'index']);

Route::middleware(['jwt.auth', 'client.credentials'])->group(function () {
    Route::get('/users', [UserController::class, 'apiIndex']);
});

Route::middleware('jwt.auth')->group(function () {
    Route::post('/oauth/authorize', [OAuth2Controller::class, 'authorize']);
    Route::post('/oauth/authorize/approve', [OAuth2Controller::class, 'approveAuthorization']);
    Route::post('/oauth/authorize/deny', [OAuth2Controller::class, 'denyAuthorization']);
    Route::post('/oauth/authorize/revoke', [OAuth2Controller::class, 'revokeAuthorization']);

    Route::get('/oauth/authorizations', [OAuth2Controller::class, 'userAuthorizations']);

    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/user/profile', [AuthController::class, 'getUserProfile']);
    Route::put('/user/profile', [AuthController::class, 'updateUserProfile']);
    Route::put('/user/password', [AuthController::class, 'updateUserPassword']);
    Route::get('/user/sessions', [AuthController::class, 'getUserSessions']);
    Route::delete('/user/sessions/{sessionId}', [AuthController::class, 'revokeUserSession']);
    Route::delete('/user/sessions', [AuthController::class, 'revokeAllUserSessions']);
    Route::get('/user/activity-log', [AuthController::class, 'getUserActivityLog']);
    Route::delete('/user/account', [AuthController::class, 'deleteAccount']);
    Route::get('/user-auth', [AuthController::class, 'me']);
    Route::get('/user/profile-token', [AuthController::class, 'profileToken']);

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
        Route::post('/users/{user}/toggle', [UserController::class, 'toggleAdmin']);

        Route::get('/users/{user}/roles', [UserController::class, 'indexRoles']);
        Route::put('/users/{user}/roles', [UserController::class, 'updateRoles']);

        Route::get('/sessions', [UserSessionController::class, 'index']);
        Route::get('/users/{user}/sessions', [UserSessionController::class, 'userSessions']);
        Route::delete('/sessions/{session}', [UserSessionController::class, 'destroy']);
        Route::delete('/users/{user}/sessions', [UserSessionController::class, 'destroyUserSessions']);

        Route::get('/clients', [SsoClientController::class, 'index']);
        Route::get('/clients/{client}', [SsoClientController::class, 'show']);
        Route::get('/clients/{client}/authorizations', [OAuth2Controller::class, 'clientAuthorizations']);
        Route::post('/clients', [SsoClientController::class, 'store']);
        Route::put('/clients/{client}', [SsoClientController::class, 'update']);
        Route::delete('/clients/{client}', [SsoClientController::class, 'destroy']);

        Route::get('/roles', [RoleController::class, 'adminIndex']);
        Route::get('/roles/{role}', [RoleController::class, 'show']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::put('/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/roles/{role}', [RoleController::class, 'destroy']);

        Route::get('/settings', [SettingController::class, 'index']);
        Route::put('/settings/{key}', [SettingController::class, 'update']);
        Route::post('/settings/auth-logo', [SettingController::class, 'uploadLogo']);
        Route::delete('/settings/auth-logo', [SettingController::class, 'destroyLogo']);
        Route::get('/settings/email-logo', [SettingController::class, 'getEmailLogo']);
        Route::post('/settings/email-logo', [SettingController::class, 'uploadEmailLogo']);
        Route::delete('/settings/email-logo', [SettingController::class, 'destroyEmailLogo']);

        Route::get('/csp-reports', [CspReportController::class, 'index']);
        Route::post('/csp-allow', [CspReportController::class, 'allowScript']);
    });
});
