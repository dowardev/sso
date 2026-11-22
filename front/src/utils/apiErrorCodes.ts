// SPDX-License-Identifier: MIT

// Single source of truth for machine-readable API error codes and their
// localized copy. The backend owns the `code` names (see
// odd/tasks/api-error-contract-codes.md); this module owns the ES/EN copy.
// A code that is not listed here is treated as an unmapped code: the UI falls
// back to generic copy and logs the raw code so the gap is detectable.

export interface ErrorCodeEntry {
  es: string
  en: string
}

/**
 * Machine-readable detail of a single API error, as sent by the backend in
 * `error_details` (see odd/tasks/api-error-contract-codes.md).
 */
export interface ApiErrorDetail {
  code?: string
  field?: string
  message?: string
  params?: Record<string, string | number>
}

export const errorCodeCatalog = {
  // Auth
  'auth.unauthenticated': {
    es: 'Necesitás iniciar sesión para continuar.',
    en: 'You need to sign in to continue.',
  },
  'auth.forbidden': {
    es: 'No tenés permisos para realizar esta acción.',
    en: 'You do not have permission to perform this action.',
  },
  'auth.token_missing': {
    es: 'Falta el token de autenticación.',
    en: 'The authentication token is missing.',
  },
  'auth.user_not_found': {
    es: 'No encontramos una cuenta con esos datos.',
    en: 'We could not find an account with that information.',
  },
  'auth.invalid_credentials': {
    es: 'Credenciales inválidas.',
    en: 'Invalid credentials.',
  },
  'auth.credentials_not_found': {
    es: 'No encontramos esa credencial.',
    en: 'We could not find that credential.',
  },
  'auth.current_password_invalid': {
    es: 'La contraseña actual es incorrecta.',
    en: 'The current password is incorrect.',
  },
  'auth.passkeys_not_registered': {
    es: 'Tu usuario no tiene passkeys registrados.',
    en: 'Your account has no registered passkeys.',
  },
  'auth.registration_session_expired': {
    es: 'La sesión de registro expiró. Volvé a empezar.',
    en: 'The registration session expired. Please start again.',
  },
  'auth.authentication_session_expired': {
    es: 'La sesión de autenticación expiró. Volvé a empezar.',
    en: 'The authentication session expired. Please start again.',
  },
  'auth.session_not_found': {
    es: 'No encontramos esa sesión.',
    en: 'We could not find that session.',
  },
  'auth.too_many_attempts': {
    es: 'Demasiados intentos. Inténtalo de nuevo más tarde.',
    en: 'Too many attempts. Please try again later.',
  },
  'auth.email_not_verified': {
    es: 'Verifica tu email antes de iniciar sesión.',
    en: 'Please verify your email before signing in.',
  },
  'auth.seed_phrase_invalid': {
    es: 'La frase semilla es incorrecta.',
    en: 'The seed phrase is incorrect.',
  },
  'auth.unknown': {
    es: 'No pudimos completar la operación de autenticación. Inténtalo de nuevo.',
    en: 'We could not complete the authentication operation. Please try again.',
  },

  // SSO client
  'client.not_registered': {
    es: 'La aplicación no está registrada en el SSO.',
    en: 'The application is not registered in the SSO.',
  },
  'client.disabled': {
    es: 'El cliente SSO está desactivado.',
    en: 'The SSO client is disabled.',
  },
  'client.redirect_uri_not_allowed': {
    es: 'La URL de redirección no está permitida.',
    en: 'The redirect URL is not allowed.',
  },
  'client.misconfigured': {
    es: 'La aplicación no está bien configurada para iniciar sesión. Avisá al administrador del SSO.',
    en: 'The application is misconfigured for sign-in. Please contact the SSO administrator.',
  },
  'client.invalid_credentials': {
    es: 'Las credenciales del cliente no son válidas.',
    en: 'The client credentials are not valid.',
  },

  // DPoP
  'dpop.incomplete_jwk': {
    es: 'La clave pública DPoP está incompleta.',
    en: 'The DPoP public key is incomplete.',
  },
  'dpop.unsupported_jwk': {
    es: 'El tipo de clave DPoP no es soportado.',
    en: 'The DPoP key type is not supported.',
  },

  // Validation (field-scoped)
  'validation.required': { es: 'Este campo es requerido.', en: 'This field is required.' },
  'validation.string': { es: 'Este campo debe ser texto.', en: 'This field must be text.' },
  'validation.min': {
    es: 'Este campo debe tener al menos {min} caracteres.',
    en: 'This field must be at least {min} characters long.',
  },
  'validation.max': {
    es: 'Este campo no puede superar los {max} caracteres.',
    en: 'This field cannot be longer than {max} characters.',
  },
  'validation.email': { es: 'Este campo debe ser un email válido.', en: 'This field must be a valid email.' },
  'validation.unique': { es: 'Este campo ya está en uso.', en: 'This field is already in use.' },
  'validation.exists': {
    es: 'El valor de este campo no existe.',
    en: 'The value of this field does not exist.',
  },
  'validation.confirmed': {
    es: 'La confirmación de este campo no coincide.',
    en: 'The confirmation of this field does not match.',
  },
  'validation.regex': {
    es: 'Este campo no tiene un formato válido.',
    en: 'This field has an invalid format.',
  },
  'validation.json': { es: 'Este campo debe ser JSON válido.', en: 'This field must be valid JSON.' },
  'validation.boolean': {
    es: 'Este campo debe ser verdadero o falso.',
    en: 'This field must be true or false.',
  },
  'validation.in': { es: 'El valor de este campo no es válido.', en: 'The value of this field is not valid.' },
  'validation.array': { es: 'Este campo debe ser una lista.', en: 'This field must be a list.' },
  'validation.integer': {
    es: 'Este campo debe ser un número entero.',
    en: 'This field must be an integer.',
  },
  'validation.numeric': { es: 'Este campo debe ser un número.', en: 'This field must be a number.' },
  'validation.url': { es: 'Este campo debe ser una URL válida.', en: 'This field must be a valid URL.' },
  'validation.date': { es: 'Este campo debe ser una fecha válida.', en: 'This field must be a valid date.' },
  'validation.after_or_equal': {
    es: 'Este campo debe ser una fecha posterior o igual a {date}.',
    en: 'This field must be a date after or equal to {date}.',
  },
  'validation.image': { es: 'Este campo debe ser una imagen válida.', en: 'This field must be a valid image.' },
  'validation.password_complex': {
    es: 'La contraseña debe tener al menos 8 caracteres, una mayúscula, un número y un carácter especial.',
    en: 'The password must be at least 8 characters long and include an uppercase letter, a number and a special character.',
  },
  'validation.unknown': {
    es: 'Error de validación. Revisa los datos e inténtalo de nuevo.',
    en: 'Validation error. Please check your input and try again.',
  },

  // --- #12: single-language API (English) with frontend-owned copy ---
  // Error codes
  'auth.totp_required': {
    es: 'Necesitás tu código TOTP (o un código de respaldo) para continuar.',
    en: 'You need your TOTP code (or a backup code) to continue.',
  },
  'auth.passkey_required': {
    es: 'Usá tu passkey para completar el inicio de sesión.',
    en: 'Use your passkey to complete sign-in.',
  },
  'auth.passkey_session_expired': {
    es: 'La sesión passkey expiró o ya fue usada. Iniciá sesión de nuevo.',
    en: 'The passkey session expired or was already used. Please sign in again.',
  },
  'auth.passkey_session_used': {
    es: 'La sesión passkey ya fue usada. Iniciá sesión de nuevo.',
    en: 'The passkey session was already used. Please sign in again.',
  },
  'auth.totp_not_enabled': {
    es: 'No hay TOTP habilitado. Habilitá TOTP antes de generar códigos de respaldo.',
    en: 'TOTP is not enabled. Enable TOTP before generating backup codes.',
  },
  'auth.advanced_encryption_already_set': {
    es: 'El usuario ya tiene cifrado avanzado habilitado.',
    en: 'The user already has advanced encryption enabled.',
  },
  'csp.hash_already_allowed': {
    es: 'El hash ya está en la lista de permitidos.',
    en: 'The hash is already in the allowed list.',
  },
  'email_verification.invalid_link': {
    es: 'El enlace de verificación es inválido o expiró.',
    en: 'The verification link is invalid or expired.',
  },
  'role.protected_update': {
    es: 'No podés modificar un rol protegido.',
    en: 'You cannot modify a protected role.',
  },
  'role.protected_delete': {
    es: 'No podés eliminar un rol protegido.',
    en: 'You cannot delete a protected role.',
  },
  'role.in_use': {
    es: 'No podés eliminar un rol que tiene usuarios asignados.',
    en: 'You cannot delete a role that has users assigned.',
  },
  'setting.image_required': {
    es: 'Se requiere una imagen.',
    en: 'An image is required.',
  },
  'setup.already_completed': {
    es: 'Ya hay usuarios registrados en el sistema.',
    en: 'There are already registered users in the system.',
  },
  'user.self_delete_forbidden': {
    es: 'No podés eliminar tu propio usuario.',
    en: 'You cannot delete your own user.',
  },
  'session.self_revoke_forbidden': {
    es: 'No podés revocar tus propias sesiones desde el admin.',
    en: 'You cannot revoke your own sessions from the admin panel.',
  },
  'profile.encrypted_profile_required': {
    es: 'Se requiere encrypted_profile para usuarios avanzados.',
    en: 'encrypted_profile is required for advanced users.',
  },

  // Success codes
  'success.csp_hash_added': {
    es: 'Se agregó el hash al CSP. Requiere deploy a producción.',
    en: 'Hash added to the CSP. A production deploy is required.',
  },
  'success.email_verified': {
    es: 'Email verificado correctamente.',
    en: 'Email verified successfully.',
  },
  'success.verification_email_sent': {
    es: 'Revisa tu email para verificar tu cuenta.',
    en: 'Check your email to verify your account.',
  },
  'success.verification_email_resent': {
    es: 'Si el email existe, vas a recibir un enlace de verificación.',
    en: 'If the email exists, you will receive a verification link.',
  },
  'success.role_created': {
    es: 'Rol creado correctamente.',
    en: 'Role created successfully.',
  },
  'success.role_updated': {
    es: 'Rol actualizado correctamente.',
    en: 'Role updated successfully.',
  },
  'success.role_deleted': {
    es: 'Rol eliminado correctamente.',
    en: 'Role deleted successfully.',
  },
  'success.setting_updated': {
    es: 'Configuración actualizada correctamente.',
    en: 'Settings updated successfully.',
  },
  'success.logo_updated': {
    es: 'Logo actualizado correctamente.',
    en: 'Logo updated successfully.',
  },
  'success.logo_deleted': {
    es: 'Logo eliminado correctamente.',
    en: 'Logo deleted successfully.',
  },
  'success.email_logo_updated': {
    es: 'Logo del email actualizado correctamente.',
    en: 'Email logo updated successfully.',
  },
  'success.email_logo_deleted': {
    es: 'Logo del email eliminado correctamente.',
    en: 'Email logo deleted successfully.',
  },
  'success.admin_created': {
    es: 'Administrador creado correctamente.',
    en: 'Administrator created successfully.',
  },
  'success.client_created': {
    es: 'Cliente SSO creado correctamente.',
    en: 'SSO client created successfully.',
  },
  'success.client_updated': {
    es: 'Cliente SSO actualizado correctamente.',
    en: 'SSO client updated successfully.',
  },
  'success.client_deleted': {
    es: 'Cliente SSO eliminado correctamente.',
    en: 'SSO client deleted successfully.',
  },
  'success.user_updated': {
    es: 'Usuario actualizado correctamente.',
    en: 'User updated successfully.',
  },
  'success.user_deleted': {
    es: 'Usuario eliminado correctamente.',
    en: 'User deleted successfully.',
  },
  'success.user_roles_updated': {
    es: 'Roles actualizados correctamente.',
    en: 'Roles updated successfully.',
  },
  'success.session_revoked': {
    es: 'Sesión revocada correctamente.',
    en: 'Session revoked successfully.',
  },
  'success.user_sessions_revoked': {
    es: 'Todas las sesiones del usuario han sido revocadas.',
    en: 'All the user\'s sessions have been revoked.',
  },
  'success.sessions_revoked': {
    es: 'Todas las sesiones han sido revocadas correctamente.',
    en: 'All sessions have been revoked successfully.',
  },
  'success.account_recovered': {
    es: 'Cuenta recuperada correctamente. Iniciá sesión con tu nueva contraseña.',
    en: 'Account recovered successfully. Sign in with your new password.',
  },
  'success.password_updated': {
    es: 'Contraseña actualizada correctamente.',
    en: 'Password updated successfully.',
  },
  'success.password_updated_sessions_revoked': {
    es: 'Contraseña actualizada correctamente. Todas las sesiones han sido revocadas.',
    en: 'Password updated successfully. All sessions have been revoked.',
  },
  'success.upgrade_to_advanced': {
    es: 'Cuenta actualizada a cifrado avanzado. Iniciá sesión con tu nueva contraseña.',
    en: 'Account upgraded to advanced encryption. Sign in with your new password.',
  },
  'success.logout': {
    es: 'Sesión cerrada correctamente.',
    en: 'Signed out successfully.',
  },
  'success.profile_updated': {
    es: 'Perfil actualizado correctamente.',
    en: 'Profile updated successfully.',
  },
  'success.passkey_registered': {
    es: 'Passkey registrado correctamente.',
    en: 'Passkey registered successfully.',
  },
  'success.passkey_deleted': {
    es: 'Passkey eliminado correctamente.',
    en: 'Passkey deleted successfully.',
  },
  'success.totp_enabled': {
    es: 'TOTP habilitado correctamente.',
    en: 'TOTP enabled successfully.',
  },
  'success.totp_disabled': {
    es: 'TOTP deshabilitado correctamente.',
    en: 'TOTP disabled successfully.',
  },
  'success.backup_codes_regenerated': {
    es: 'Códigos de respaldo regenerados.',
    en: 'Backup codes regenerated.',
  },
  'success.account_deleted': {
    es: 'Cuenta eliminada correctamente.',
    en: 'Account deleted successfully.',
  },
  'success.authorization_denied': {
    es: 'Autorización denegada.',
    en: 'Authorization denied.',
  },
  'success.access_revoked': {
    es: 'Acceso revocado correctamente.',
    en: 'Access revoked successfully.',
  },
} as const satisfies Record<string, ErrorCodeEntry>

export type ErrorCodeCatalog = typeof errorCodeCatalog
export type ErrorCode = keyof ErrorCodeCatalog

// Copy used when the backend sends a code whose rule parameters are missing
// (e.g. `validation.min` without `params.min`). It must never contain a
// placeholder and must never fall back to raw backend prose.
const missingParamCopy: Record<string, ErrorCodeEntry> = {
  'validation.min': {
    es: 'Este campo no alcanza la longitud mínima.',
    en: 'This field does not meet the minimum length.',
  },
  'validation.max': {
    es: 'Este campo supera la longitud máxima.',
    en: 'This field exceeds the maximum length.',
  },
  'validation.after_or_equal': {
    es: 'Este campo debe ser una fecha válida.',
    en: 'This field must be a valid date.',
  },
}

export function isKnownErrorCode(params: { code: string }): boolean {
  const { code } = params
  return Object.prototype.hasOwnProperty.call(errorCodeCatalog, code)
}

export function getErrorCodeEntry(params: { code: string }): ErrorCodeEntry | null {
  const { code } = params
  if (!isKnownErrorCode({ code })) return null
  return errorCodeCatalog[code as ErrorCode]
}

/**
 * Interpolates `{min}` / `{max}` / `{date}` placeholders from the rule
 * parameters sent by the backend. When a placeholder has no value, the
 * placeholder-free copy is used instead of leaving the raw placeholder in the
 * UI.
 */
export function interpolateErrorCopy(params: {
  copy: string
  code: string
  params?: Record<string, string | number | undefined>
}): string {
  const { copy, code, params: values } = params
  const placeholders = copy.match(/\{(\w+)\}/g)
  if (!placeholders || placeholders.length === 0) return copy

  const missing = placeholders.some((placeholder) => {
    const key = placeholder.slice(1, -1)
    const value = values?.[key]
    return value === undefined || value === null || String(value).trim() === ''
  })

  if (missing) {
    const fallback = missingParamCopy[code]
    return fallback ? fallback.es : copy.replace(/\s*\{\w+\}/g, '')
  }

  return copy.replace(/\{(\w+)\}/g, (_match, key: string) => {
    const value = values?.[key]
    return value === undefined || value === null ? '' : String(value)
  })
}
