// SPDX-License-Identifier: MIT
import { describe, it, expect, vi, beforeEach } from 'vitest'

const { showErrorMock } = vi.hoisted(() => ({ showErrorMock: vi.fn() }))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({
    showError: showErrorMock,
    showSuccess: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
  }),
}))

import { useValidation } from '@/composables/useValidation'
import {
  detectLocale,
  friendlyErrorCatalog,
  getFieldFriendlyMessage,
  getFriendlyMessage,
} from '@/utils/userFriendlyErrors'

const { handleApiError, translateServerError } = useValidation()

function setNavigatorLanguage(params: { value: string }): void {
  const { value } = params
  Object.defineProperty(window.navigator, 'language', { value, configurable: true })
}

beforeEach(() => {
  showErrorMock.mockClear()
  setNavigatorLanguage({ value: 'es-ES' })
})

describe('userFriendlyErrors catalog', () => {
  it('covers every pattern required by LBDS-232', () => {
    const matches = friendlyErrorCatalog.map((entry) => entry.match)
    for (const required of [
      'field is required',
      'must be at least',
      'must be a valid email',
      'confirmation does not match',
      'has already been taken',
      'may only contain',
      'not registered in sso',
      'is not a valid url',
      'incomplete jwk',
      'unsupported jwk',
      'invalid credentials',
      'client is disabled',
      'credenciales inv',
      'verifica tu email',
      'usuario no encontrado',
      'frase semilla incorrecta',
      'contrasena actual incorrecta',
      'demasiados intentos',
      'cliente desactivado',
      'redirect_uri no permitida',
      'debe contener',
      'error de validacion',
    ]) {
      expect(matches).toContain(required)
    }
  })

  it('Spanish fallback never leaks raw backend text', () => {
    const friendly = getFriendlyMessage({ message: 'Weird backend text XYZ-123', locale: 'es' })
    expect(friendly).toBe('Ocurrió un error inesperado. Inténtalo de nuevo.')
    expect(friendly).not.toContain('XYZ-123')
  })

  it('English fallback never leaks raw backend text', () => {
    const friendly = getFriendlyMessage({ message: 'Weird backend text XYZ-123', locale: 'en' })
    expect(friendly).toBe('Something went wrong. Please try again.')
    expect(friendly).not.toContain('XYZ-123')
  })

  it('maps known messages in Spanish', () => {
    const labels = { email: 'Email', password: 'Contraseña' }
    expect(
      getFieldFriendlyMessage({ field: 'email', message: 'The email field is required.', locale: 'es', fieldLabels: labels }),
    ).toBe('El campo Email es requerido.')
    expect(
      getFriendlyMessage({ message: 'Credenciales inválidas', locale: 'es' }),
    ).toBe('Credenciales inválidas.')
    expect(
      getFriendlyMessage({ message: 'Demasiados intentos. Cuenta bloqueada temporalmente.', locale: 'es' }),
    ).toBe('Demasiados intentos. Inténtalo de nuevo más tarde.')
    expect(
      getFriendlyMessage({ message: 'Debe contener al menos una letra mayúscula.', locale: 'es' }),
    ).toBe('La contraseña debe tener al menos 8 caracteres, una mayúscula, un número y un carácter especial.')
    expect(
      getFieldFriendlyMessage({ field: 'password', message: 'The password must be at least 8 characters.', locale: 'es', fieldLabels: labels }),
    ).toBe('El campo Contraseña debe tener al menos 8 caracteres.')
  })

  it('maps known messages in English', () => {
    expect(getFriendlyMessage({ message: 'Invalid credentials', locale: 'en' })).toBe('Invalid credentials.')
    expect(
      getFieldFriendlyMessage({ field: 'email', message: 'The email field is required.', locale: 'en', fieldLabels: { email: 'Email' } }),
    ).toBe('The Email field is required.')
    expect(
      getFriendlyMessage({ message: 'Demasiados intentos. Cuenta bloqueada temporalmente.', locale: 'en' }),
    ).toBe('Too many attempts. Please try again later.')
    expect(
      getFriendlyMessage({ message: 'Usuario no encontrado', locale: 'en' }),
    ).toBe('We could not find an account with that information.')
  })

  it('detectLocale honors navigator.language with es fallback', () => {
    setNavigatorLanguage({ value: 'es-MX' })
    expect(detectLocale({})).toBe('es')
    setNavigatorLanguage({ value: 'en-US' })
    expect(detectLocale({})).toBe('en')
    vi.stubGlobal('navigator', undefined)
    expect(detectLocale({})).toBe('es')
    expect(detectLocale({ fallback: 'en' })).toBe('en')
    vi.unstubAllGlobals()
  })
})

  it('uses English labels when locale is en', () => {
    const labels = { email: 'Correo' }
    const labelsEn = { email: 'Email' }
    expect(
      getFieldFriendlyMessage({ field: 'email', message: 'The email field is required.', locale: 'en', fieldLabels: labels, fieldLabelsEn: labelsEn }),
    ).toBe('The Email field is required.')
  })

  it('does not map a bare uppercase mention to the password copy', () => {
    expect(getFriendlyMessage({ message: 'Username must be uppercase.', locale: 'en' })).toBe('Something went wrong. Please try again.')
  })

  it('maps password complexity only with password context', () => {
    expect(getFriendlyMessage({ message: 'The password must be at least 8 characters and include an uppercase letter.', locale: 'en' })).toBe('The password must be at least 8 characters long and include an uppercase letter, a number and a special character.')
  })

  it('does not map non-password debe contener to the password copy', () => {
    expect(getFriendlyMessage({ message: 'Debe contener solo numeros.', locale: 'es' })).toBe('Ocurrió un error inesperado. Inténtalo de nuevo.')
  })

  it('uses a min-less copy when no number is present', () => {
    expect(getFriendlyMessage({ message: 'The value must be at least acceptable.', locale: 'en' })).toBe('This field does not meet the minimum length.')
  })

  it('falls back to generic for empty errors objects', () => {
    const err = Object.assign(new Error('Error de validacion'), { errors: { email: [] as string[] } })
    const result = handleApiError(err)
    expect(result).toBe('Error de validación. Revisa los datos e inténtalo de nuevo.')
    // LBDS-267: helper is pure; the caller decides if/how to notify.
    expect(showErrorMock).not.toHaveBeenCalled()
  })

  it('falls back to generic for malformed errors shapes', () => {
    const result = handleApiError({ errors: 'oops' })
    expect(result).toBe('Ocurrió un error inesperado. Inténtalo de nuevo.')
    // LBDS-267: helper is pure; the caller decides if/how to notify.
    expect(showErrorMock).not.toHaveBeenCalled()
  })

  it('maps the allowed_origins mismatch to its own copy', () => {
    // El mensaje real lo fija el backend: su test verifica que contenga
    // 'redirect_uri' y 'allowed_origins'. Acá se prueba el matching del catálogo
    // sin volver a copiar el literal completo (evita drift silencioso).
    const message = 'redirect_uri fuera de allowed_origins'

    expect(getFriendlyMessage({ message, locale: 'es' })).toBe(
      'La aplicación no está bien configurada para iniciar sesión. Avisá al administrador del SSO.',
    )
    expect(getFriendlyMessage({ message, locale: 'en' })).toBe(
      'The application is misconfigured for sign-in. Please contact the SSO administrator.',
    )
  })

  it('does not map an allowed_origins field validation error to the app-config copy', () => {
    expect(getFriendlyMessage({ message: 'The allowed_origins.0 field must be a valid URL.', locale: 'es' })).toBe(
      'Ocurrió un error inesperado. Inténtalo de nuevo.',
    )
  })

describe('useValidation handleApiError', () => {
  it('turns errors{} into friendly messages and notifies friendly text only', () => {
    const err = Object.assign(new Error('Error de validación'), {
      status: 422,
      errors: {
        email: ['The email must be a valid email.'],
        password: ['The password field is required.'],
      },
    })
    const result = handleApiError(err)
    expect(result).toBe('El campo Email debe ser un email válido. El campo Contraseña es requerido.')
    expect(result).not.toContain('must be a valid email')
    expect(result).not.toContain('field is required')
    // LBDS-267: helper is pure; the caller decides if/how to notify.
    expect(showErrorMock).not.toHaveBeenCalled()
  })

  it('accepts a plain object with errors{}', () => {
    const result = handleApiError({ errors: { slug: ['The slug has already been taken.'] } })
    expect(result).toBe('El campo Slug ya está en uso.')
    // LBDS-267: helper is pure; the caller decides if/how to notify.
    expect(showErrorMock).not.toHaveBeenCalled()
  })

  it('maps a plain Error without leaking raw text', () => {
    const result = handleApiError(new Error('Invalid credentials'))
    expect(result).toBe('Credenciales inválidas.')
    // LBDS-267: helper is pure; the caller decides if/how to notify.
    expect(showErrorMock).not.toHaveBeenCalled()
  })

  it('uses the friendly fallback for unknown errors', () => {
    const result = handleApiError(new Error('Weird backend text XYZ-999'))
    expect(result).toBe('Ocurrió un error inesperado. Inténtalo de nuevo.')
    expect(result).not.toContain('XYZ-999')
  })

  it('translateServerError delegates to the catalog', () => {
    expect(translateServerError('email', 'The email must be a valid email.')).toBe(
      'El campo Email debe ser un email válido.',
    )
  })
})
