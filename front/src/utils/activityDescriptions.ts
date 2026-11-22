// SPDX-License-Identifier: MIT

// Activity-log descriptions are stored as free text in `user_activity_logs`.
// The backend writes them in English (issue #12), but rows created before that
// change keep their original Spanish description. This catalogue is the
// single source of the user-facing copy for those descriptions: the frontend
// resolves them here instead of showing whatever the row happens to contain.

export type ActivityLocale = 'es' | 'en'

export interface ActivityDescriptionEntry {
  // Canonical description (English, as written by the backend today).
  en: string
  // Localized copy.
  es: string
  // Descriptions written before #12 that map to this entry.
  legacy?: string[]
  // When true, `en`/`es` are prefixes and the rest of the description (for
  // example a client name) is preserved.
  prefix?: boolean
}

export const activityDescriptionCatalog: ActivityDescriptionEntry[] = [
  { en: 'Sign in', es: 'Inicio de sesión', legacy: ['Inicio de sesión'] },
  { en: 'Sign in (advanced encryption)', es: 'Inicio de sesión (cifrado avanzado)', legacy: ['Inicio de sesión (cifrado avanzado)'] },
  {
    en: 'Sign in (password + passkey)',
    es: 'Inicio de sesión (contraseña + passkey)',
    legacy: ['Inicio de sesión (contraseña + passkey)'],
  },
  { en: 'Sign in (passkey)', es: 'Inicio de sesión (passkey)', legacy: ['Inicio de sesión (passkey)'] },
  { en: 'Sign in (TOTP)', es: 'Inicio de sesión (TOTP)', legacy: ['Inicio de sesión (TOTP)'] },
  {
    en: 'Sign in (TOTP - backup code)',
    es: 'Inicio de sesión (TOTP — código de respaldo)',
    legacy: ['Inicio de sesión (TOTP — código de respaldo)'],
  },
  { en: 'Account registration', es: 'Registro de cuenta', legacy: ['Registro de cuenta'] },
  {
    en: 'Account registration (advanced encryption)',
    es: 'Registro de cuenta (cifrado avanzado)',
    legacy: ['Registro de cuenta (cifrado avanzado)'],
  },
  {
    en: 'Account recovery (advanced encryption)',
    es: 'Recuperación de cuenta (cifrado avanzado)',
    legacy: ['Recuperación de cuenta (cifrado avanzado)'],
  },
  { en: 'Password change', es: 'Cambio de contraseña', legacy: ['Cambio de contraseña'] },
  {
    en: 'Password change (advanced encryption)',
    es: 'Cambio de contraseña (cifrado avanzado)',
    legacy: ['Cambio de contraseña (cifrado avanzado)'],
  },
  { en: 'Password reset (email)', es: 'Restablecimiento de contraseña (email)', legacy: ['Restablecimiento de contraseña (email)'] },
  { en: 'Upgrade to advanced encryption', es: 'Actualización a cifrado avanzado', legacy: ['Actualización a cifrado avanzado'] },
  { en: 'Sign out', es: 'Cierre de sesión', legacy: ['Cierre de sesión'] },
  { en: 'Profile update', es: 'Actualización de perfil', legacy: ['Actualización de perfil'] },
  { en: 'Encrypted profile update', es: 'Actualización de perfil cifrado', legacy: ['Actualización de perfil cifrado'] },
  { en: 'TOTP enabled', es: 'TOTP habilitado', legacy: ['TOTP habilitado'] },
  { en: 'TOTP disabled', es: 'TOTP deshabilitado', legacy: ['TOTP deshabilitado'] },
  { en: 'Passkey registered', es: 'Passkey registrado', legacy: ['Passkey registrado'] },
  { en: 'Passkey deleted', es: 'Passkey eliminado', legacy: ['Passkey eliminado'] },
  {
    en: 'TOTP backup codes regenerated',
    es: 'Códigos de respaldo TOTP regenerados',
    legacy: ['Códigos de respaldo TOTP regenerados'],
  },
  { en: 'Session revoked', es: 'Sesión revocada', legacy: ['Sesión revocada'] },
  { en: 'All sessions revoked', es: 'Todas las sesiones revocadas', legacy: ['Todas las sesiones revocadas'] },
  { en: 'Account deleted', es: 'Cuenta eliminada', legacy: ['Cuenta eliminada'] },
  { en: 'Email verified', es: 'Email verificado', legacy: ['Email verificado'] },
  {
    en: 'Access granted to ',
    es: 'Acceso concedido a ',
    legacy: ['Acceso concedido a '],
    prefix: true,
  },
  {
    en: 'Access revoked to ',
    es: 'Acceso revocado a ',
    legacy: ['Acceso revocado a '],
    prefix: true,
  },
  {
    en: 'Authorization granted to ',
    es: 'Autorización concedida a ',
    legacy: ['Autorización concedida a '],
    prefix: true,
  },
  {
    en: 'Authorization denied to ',
    es: 'Autorización denegada a ',
    legacy: ['Autorización denegada a '],
    prefix: true,
  },
]

/**
 * Resolves a stored activity-log description to the localized copy.
 *
 * Rows written before #12 carry the Spanish description, rows written after
 * it carry the English one; both resolve to the same entry. An unknown
 * description is returned as-is (nothing to translate).
 */
export function getActivityDescription(params: {
  description: string
  locale?: ActivityLocale
}): string {
  const { description, locale = 'es' } = params

  const exact = activityDescriptionCatalog.find(
    (entry) => entry.en === description || (entry.legacy ?? []).includes(description),
  )
  if (exact) {
    return locale === 'en' ? exact.en : exact.es
  }

  const prefixed = activityDescriptionCatalog.find(
    (entry) =>
      entry.prefix === true &&
      (description.startsWith(entry.en) || (entry.legacy ?? []).some((legacy) => description.startsWith(legacy))),
  )
  if (prefixed) {
    const source = description.startsWith(prefixed.en)
      ? prefixed.en
      : (prefixed.legacy ?? []).find((legacy) => description.startsWith(legacy)) ?? ''
    const rest = description.slice(source.length)
    return (locale === 'en' ? prefixed.en : prefixed.es) + rest
  }

  return description
}
