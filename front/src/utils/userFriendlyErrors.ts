// SPDX-License-Identifier: MIT
// Frontend-only catalog that maps raw backend/validator messages to friendly
// user-facing copy. Raw backend text must never reach the UI.

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

export const fieldLabelsEn: Record<string, string> = {
  name: 'Name',
  user: 'Username',
  email: 'Email',
  password: 'Password',
  password_confirmation: 'Password confirmation',
  client_id: 'Client ID',
  client_secret: 'Client secret',
  redirect_url: 'Redirect URL',
  allowed_origins: 'Allowed origins',
  grant_types: 'Grant types',
  description: 'Description',
  slug: 'Slug',
  role_id: 'Role ID',
  action: 'Action',
  token: 'Token',
  code: 'Code',
  remember_me: 'Remember me',
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

export function getFriendlyMessage(params: { message: string; locale?: SupportedLocale }): string {
  const { message, locale: requestedLocale } = params
  const locale = resolveLocale({ locale: requestedLocale })
  const entry = findEntry({ message })
  if (!entry) return genericFallback[locale]
  const withSubject = entry[locale].replace('{field}', genericSubject[locale])
  return applyMinCount({ text: withSubject, raw: message, locale })
}

export function getFieldFriendlyMessage(params: {
  field: string
  message: string
  locale?: SupportedLocale
  fieldLabels?: Record<string, string>,
  fieldLabelsEn?: Record<string, string>
}): string {
  const { field, message, locale: requestedLocale, fieldLabels = {}, fieldLabelsEn = {} } = params
  const locale = resolveLocale({ locale: requestedLocale })
  const label = locale === 'en' ? (fieldLabelsEn[field] ?? fieldLabels[field] ?? field) : (fieldLabels[field] ?? field)
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
