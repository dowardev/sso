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
