// SPDX-License-Identifier: MIT
// Frontend-only catalog that maps raw backend/validator messages to friendly
// user-facing copy. Raw backend text must never reach the UI.
//
// Resolution order (see odd/tasks/api-error-contract-codes.md):
//   1. `code` present and mapped in `errorCodeCatalog` -> localized copy.
//   2. `code` present but unmapped -> generic copy + the raw code is logged.
//   3. No `code` -> LEGACY FALLBACK: prose substring matching below. This path
//      only exists for responses that predate the error-code contract and is
//      deprecated; do not add new prose patterns to it.

import { getErrorCodeEntry, interpolateErrorCopy } from '@/utils/apiErrorCodes'

export { fieldLabelsEn } from '@/utils/fieldLabels'

export type SupportedLocale = 'es' | 'en'

export interface FriendlyErrorEntry {
  match: string
  requireAll?: string[]
  es: string
  en: string
}

// Ordered catalog: the first normalized-includes match wins, so specific
// entries must stay before their generic prefixes (e.g. password complexity
// before the generic "must be at least").
export const friendlyErrorCatalog: FriendlyErrorEntry[] = [
  { match: 'field is required', es: 'Este campo es requerido.', en: 'This field is required.' },
  {
    match: 'uppercase',
    requireAll: ['must be at least'],
    es: 'La contraseña debe tener al menos 8 caracteres, una mayúscula, un número y un carácter especial.',
    en: 'The password must be at least 8 characters long and include an uppercase letter, a number and a special character.',
  },
  {
    match: 'must be at least',
    es: 'Este campo debe tener al menos {min} caracteres.',
    en: 'This field must be at least {min} characters long.',
  },
  { match: 'must be a valid email', es: 'Este campo debe ser un email válido.', en: 'This field must be a valid email.' },
  {
    match: 'confirmation does not match',
    es: 'La confirmación de este campo no coincide.',
    en: 'The confirmation of this field does not match.',
  },
  { match: 'has already been taken', es: 'Este campo ya está en uso.', en: 'This field is already in use.' },
  { match: 'may only contain', es: 'Este campo contiene caracteres no válidos.', en: 'This field contains invalid characters.' },
  {
    match: 'not registered in sso',
    es: 'La aplicación no está registrada en el SSO.',
    en: 'The application is not registered in the SSO.',
  },
  {
    // Spanish variant emitted by the backend for the client_id.exists rule.
    // Without it the raw text misses the catalog and the UI falls back to
    // the generic "unexpected error", hiding the actionable reason.
    match: 'no esta registrada en el sso',
    es: 'La aplicación no está registrada en el SSO.',
    en: 'The application is not registered in the SSO.',
  },
  {
    match: 'the selected client id is invalid',
    es: 'La aplicación no está registrada en el SSO.',
    en: 'The application is not registered in the SSO.',
  },
  { match: 'is not a valid url', es: 'Este campo debe ser una URL válida.', en: 'This field must be a valid URL.' },
  { match: 'incomplete jwk', es: 'La clave pública DPoP está incompleta.', en: 'The DPoP public key is incomplete.' },
  { match: 'unsupported jwk', es: 'El tipo de clave DPoP no es soportado.', en: 'The DPoP key type is not supported.' },
  { match: 'invalid credentials', es: 'Credenciales inválidas.', en: 'Invalid credentials.' },
  { match: 'client is disabled', es: 'El cliente SSO está desactivado.', en: 'The SSO client is disabled.' },
  { match: 'credenciales inv', es: 'Credenciales inválidas.', en: 'Invalid credentials.' },
  {
    match: 'verifica tu email',
    es: 'Verifica tu email antes de iniciar sesión.',
    en: 'Please verify your email before signing in.',
  },
  {
    match: 'usuario no encontrado',
    es: 'No encontramos una cuenta con esos datos.',
    en: 'We could not find an account with that information.',
  },
  {
    match: 'frase semilla incorrecta',
    es: 'La frase semilla es incorrecta.',
    en: 'The seed phrase is incorrect.',
  },
  {
    match: 'contrasena actual incorrecta',
    es: 'La contraseña actual es incorrecta.',
    en: 'The current password is incorrect.',
  },
  {
    match: 'demasiados intentos',
    es: 'Demasiados intentos. Inténtalo de nuevo más tarde.',
    en: 'Too many attempts. Please try again later.',
  },
  { match: 'cliente desactivado', es: 'El cliente SSO está desactivado.', en: 'The SSO client is disabled.' },
  {
    match: 'redirect_uri no permitida',
    es: 'La URL de redirección no está permitida.',
    en: 'The redirect URL is not allowed.',
  },
  {
    // Client-side config mismatch: redirect_uri outside allowed_origins. The
    // browser would block the token exchange, so the flow is stopped up front.
    // requireAll keeps this from swallowing admin field-validation errors
    // about allowed_origins alone.
    match: 'allowed_origins',
    requireAll: ['redirect_uri'],
    es: 'La aplicación no está bien configurada para iniciar sesión. Avisá al administrador del SSO.',
    en: 'The application is misconfigured for sign-in. Please contact the SSO administrator.',
  },
  {
    match: 'debe contener',
    requireAll: ['mayus'],
    es: 'La contraseña debe tener al menos 8 caracteres, una mayúscula, un número y un carácter especial.',
    en: 'The password must be at least 8 characters long and include an uppercase letter, a number and a special character.',
  },
  {
    match: 'error de validacion',
    es: 'Error de validación. Revisa los datos e inténtalo de nuevo.',
    en: 'Validation error. Please check your input and try again.',
  },
]

const genericFallback: Record<SupportedLocale, string> = {
  es: 'Ocurrió un error inesperado. Inténtalo de nuevo.',
  en: 'Something went wrong. Please try again.',
}

const genericSubject: Record<SupportedLocale, string> = {
  es: 'este campo',
  en: 'this field',
}

export function detectLocale(params?: { fallback?: SupportedLocale }): SupportedLocale {
  const { fallback = 'es' } = params ?? {}
  const language =
    typeof navigator !== 'undefined' && typeof navigator.language === 'string'
      ? navigator.language.toLowerCase()
      : ''
  if (language.startsWith('en')) return 'en'
  if (language.startsWith('es')) return 'es'
  return fallback
}

function normalizeMessage(params: { text: string }): string {
  const { text } = params
  return text
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
}

function resolveLocale(params: { locale?: SupportedLocale }): SupportedLocale {
  const { locale } = params
  return locale ?? detectLocale({})
}

function findEntry(params: { message: string }): FriendlyErrorEntry | null {
  const { message } = params
  const normalized = normalizeMessage({ text: message })
  if (!normalized.trim()) return null
  return friendlyErrorCatalog.find((entry) => {
    if (!normalized.includes(normalizeMessage({ text: entry.match }))) return false
    const required = entry.requireAll ?? []
    return required.every((need) => normalized.includes(normalizeMessage({ text: need })))
  }) ?? null
}

function applyMinCount(params: { text: string; raw: string; locale: SupportedLocale }): string {
  const { text, raw, locale } = params
  if (!text.includes('{min}')) return text
  const min = raw.match(/at least (\d+)/i)?.[1] ?? ''
  if (!min) return locale === 'en' ? 'This field does not meet the minimum length.' : 'Este campo no alcanza la longitud mínima.'
  return text.replace('{min}', min).replace(/\s{2,}/g, ' ')
}

export function getFriendlyMessage(params: {
  message: string
  code?: string
  params?: Record<string, string | number>
  locale?: SupportedLocale
}): string {
  const { message, code, params: values, locale: requestedLocale } = params
  const locale = resolveLocale({ locale: requestedLocale })

  if (code) {
    const entry = getErrorCodeEntry({ code })
    if (!entry) {
      // Missing mapping: detectable instead of silent.
      console.warn('[API Error] unmapped code', code)
      return genericFallback[locale]
    }
    return interpolateErrorCopy({ copy: entry[locale], code, params: values })
  }

  // Legacy fallback: response without a code.
  const entry = findEntry({ message })
  if (!entry) return genericFallback[locale]
  const withSubject = entry[locale].replace('{field}', genericSubject[locale])
  return applyMinCount({ text: withSubject, raw: message, locale })
}

export function getFieldFriendlyMessage(params: {
  field: string
  message: string
  code?: string
  params?: Record<string, string | number>
  locale?: SupportedLocale
  fieldLabels?: Record<string, string>,
  fieldLabelsEn?: Record<string, string>
}): string {
  const { field, message, code, params: values, locale: requestedLocale, fieldLabels = {}, fieldLabelsEn = {} } = params
  const locale = resolveLocale({ locale: requestedLocale })
  const label = locale === 'en' ? (fieldLabelsEn[field] ?? fieldLabels[field] ?? field) : (fieldLabels[field] ?? field)

  if (code) {
    const entry = getErrorCodeEntry({ code })
    if (!entry) {
      console.warn('[API Error] unmapped code', code)
      const unmapped = genericFallback[locale]
      return locale === 'en' ? `The ${label} field: ${unmapped}` : `El campo ${label}: ${unmapped}`
    }
    const copy = interpolateErrorCopy({ copy: entry[locale], code, params: values })
    if (locale === 'en') {
      if (copy.includes('This field')) return copy.replace('This field', `The ${label} field`)
      return `The ${label} field: ${copy}`
    }
    if (copy.includes('Este campo')) return copy.replace('Este campo', `El campo ${label}`)
    return `El campo ${label}: ${copy}`
  }

  // Legacy fallback: response without a code.
  const entry = findEntry({ message })
  if (!entry) {
    const fallback = genericFallback[locale]
    return locale === 'en' ? `The ${label} field: ${fallback}` : `El campo ${label}: ${fallback}`
  }
  const friendly = getFriendlyMessage({ message, locale })
  if (locale === 'en') {
    return friendly.replace('This field', `The ${label} field`).replace('this field', label)
  }
  return friendly.replace('Este campo', `El campo ${label}`).replace('este campo', label)
}
