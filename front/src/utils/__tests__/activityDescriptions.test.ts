// SPDX-License-Identifier: MIT
import { describe, it, expect } from 'vitest'

import { activityDescriptionCatalog, getActivityDescription } from '@/utils/activityDescriptions'

describe('activityDescriptionCatalog', () => {
  it('pins every description to its ES/EN copy', () => {
    const expected: Array<{ en: string; es: string }> = [
      { en: 'Sign in', es: 'Inicio de sesión' },
      { en: 'Sign in (advanced encryption)', es: 'Inicio de sesión (cifrado avanzado)' },
      { en: 'Sign in (password + passkey)', es: 'Inicio de sesión (contraseña + passkey)' },
      { en: 'Sign in (passkey)', es: 'Inicio de sesión (passkey)' },
      { en: 'Sign in (TOTP)', es: 'Inicio de sesión (TOTP)' },
      { en: 'Sign in (TOTP - backup code)', es: 'Inicio de sesión (TOTP — código de respaldo)' },
      { en: 'Account registration', es: 'Registro de cuenta' },
      { en: 'Account registration (advanced encryption)', es: 'Registro de cuenta (cifrado avanzado)' },
      { en: 'Account recovery (advanced encryption)', es: 'Recuperación de cuenta (cifrado avanzado)' },
      { en: 'Password change', es: 'Cambio de contraseña' },
      { en: 'Password change (advanced encryption)', es: 'Cambio de contraseña (cifrado avanzado)' },
      { en: 'Password reset (email)', es: 'Restablecimiento de contraseña (email)' },
      { en: 'Upgrade to advanced encryption', es: 'Actualización a cifrado avanzado' },
      { en: 'Sign out', es: 'Cierre de sesión' },
      { en: 'Profile update', es: 'Actualización de perfil' },
      { en: 'Encrypted profile update', es: 'Actualización de perfil cifrado' },
      { en: 'TOTP enabled', es: 'TOTP habilitado' },
      { en: 'TOTP disabled', es: 'TOTP deshabilitado' },
      { en: 'Passkey registered', es: 'Passkey registrado' },
      { en: 'Passkey deleted', es: 'Passkey eliminado' },
      { en: 'TOTP backup codes regenerated', es: 'Códigos de respaldo TOTP regenerados' },
      { en: 'Session revoked', es: 'Sesión revocada' },
      { en: 'All sessions revoked', es: 'Todas las sesiones revocadas' },
      { en: 'Account deleted', es: 'Cuenta eliminada' },
      { en: 'Email verified', es: 'Email verificado' },
      { en: 'Access granted to ', es: 'Acceso concedido a ' },
      { en: 'Access revoked to ', es: 'Acceso revocado a ' },
      { en: 'Authorization granted to ', es: 'Autorización concedida a ' },
      { en: 'Authorization denied to ', es: 'Autorización denegada a ' },
    ]

    for (const { en, es } of expected) {
      const entry = activityDescriptionCatalog.find((item) => item.en === en)
      expect(entry, `missing catalogue entry for "${en}"`).toBeDefined()
      expect(entry?.es).toBe(es)
    }
  })

  it('has no extra entries beyond the specification', () => {
    expect(activityDescriptionCatalog.map((entry) => entry.en)).toHaveLength(29)
  })
})

describe('getActivityDescription', () => {
  it('resolves a legacy Spanish description to the Spanish copy', () => {
    expect(getActivityDescription({ description: 'Inicio de sesión', locale: 'es' })).toBe('Inicio de sesión')
    expect(getActivityDescription({ description: 'Cierre de sesión', locale: 'es' })).toBe('Cierre de sesión')
    expect(getActivityDescription({ description: 'Inicio de sesión (cifrado avanzado)', locale: 'es' })).toBe(
      'Inicio de sesión (cifrado avanzado)',
    )
  })

  it('resolves a legacy Spanish description to the English copy', () => {
    expect(getActivityDescription({ description: 'Inicio de sesión', locale: 'en' })).toBe('Sign in')
    expect(getActivityDescription({ description: 'Cierre de sesión', locale: 'en' })).toBe('Sign out')
  })

  it('resolves a new English description to the localized copy', () => {
    expect(getActivityDescription({ description: 'Sign in', locale: 'es' })).toBe('Inicio de sesión')
    expect(getActivityDescription({ description: 'Sign in', locale: 'en' })).toBe('Sign in')
    expect(getActivityDescription({ description: 'TOTP enabled', locale: 'es' })).toBe('TOTP habilitado')
  })

  it('keeps the dynamic suffix of authorization descriptions', () => {
    expect(getActivityDescription({ description: 'Authorization granted to Acme', locale: 'es' })).toBe(
      'Autorización concedida a Acme',
    )
    expect(getActivityDescription({ description: 'Autorización denegada a Acme', locale: 'en' })).toBe(
      'Authorization denied to Acme',
    )
    expect(getActivityDescription({ description: 'Acceso concedido a Acme', locale: 'es' })).toBe('Acceso concedido a Acme')
  })

  it('returns an unknown description untouched', () => {
    expect(getActivityDescription({ description: 'Something custom', locale: 'es' })).toBe('Something custom')
    expect(getActivityDescription({ description: 'Something custom', locale: 'en' })).toBe('Something custom')
  })

  it('defaults to Spanish, the default language of the app', () => {
    expect(getActivityDescription({ description: 'Sign in' })).toBe('Inicio de sesión')
  })
})
