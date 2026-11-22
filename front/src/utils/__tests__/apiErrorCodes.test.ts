// SPDX-License-Identifier: MIT
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { errorCodeCatalog, type ErrorCodeCatalog } from '@/utils/apiErrorCodes'
import { getFriendlyMessage, getFieldFriendlyMessage, getSuccessMessage } from '@/utils/userFriendlyErrors'

const consoleWarnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})

beforeEach(() => {
  consoleWarnSpy.mockClear()
})

describe('apiErrorCodes catalogue', () => {
  // Table-driven: every code from the catalogue must have exact ES/EN copy
  const expectedCodes: Array<{ code: keyof ErrorCodeCatalog; es: string; en: string }> = [
    // Auth
    { code: 'auth.unauthenticated', es: 'Necesitás iniciar sesión para continuar.', en: 'You need to sign in to continue.' },
    { code: 'auth.forbidden', es: 'No tenés permisos para realizar esta acción.', en: 'You do not have permission to perform this action.' },
    { code: 'auth.token_missing', es: 'Falta el token de autenticación.', en: 'The authentication token is missing.' },
    { code: 'auth.user_not_found', es: 'No encontramos una cuenta con esos datos.', en: 'We could not find an account with that information.' },
    { code: 'auth.invalid_credentials', es: 'Credenciales inválidas.', en: 'Invalid credentials.' },
    { code: 'auth.credentials_not_found', es: 'No encontramos esa credencial.', en: 'We could not find that credential.' },
    { code: 'auth.current_password_invalid', es: 'La contraseña actual es incorrecta.', en: 'The current password is incorrect.' },
    { code: 'auth.passkeys_not_registered', es: 'Tu usuario no tiene passkeys registrados.', en: 'Your account has no registered passkeys.' },
    { code: 'auth.registration_session_expired', es: 'La sesión de registro expiró. Volvé a empezar.', en: 'The registration session expired. Please start again.' },
    { code: 'auth.authentication_session_expired', es: 'La sesión de autenticación expiró. Volvé a empezar.', en: 'The authentication session expired. Please start again.' },
    { code: 'auth.session_not_found', es: 'No encontramos esa sesión.', en: 'We could not find that session.' },
    { code: 'auth.too_many_attempts', es: 'Demasiados intentos. Inténtalo de nuevo más tarde.', en: 'Too many attempts. Please try again later.' },
    { code: 'auth.email_not_verified', es: 'Verifica tu email antes de iniciar sesión.', en: 'Please verify your email before signing in.' },
    { code: 'auth.seed_phrase_invalid', es: 'La frase semilla es incorrecta.', en: 'The seed phrase is incorrect.' },
    { code: 'auth.unknown', es: 'No pudimos completar la operación de autenticación. Inténtalo de nuevo.', en: 'We could not complete the authentication operation. Please try again.' },
    // SSO client
    { code: 'client.not_registered', es: 'La aplicación no está registrada en el SSO.', en: 'The application is not registered in the SSO.' },
    { code: 'client.disabled', es: 'El cliente SSO está desactivado.', en: 'The SSO client is disabled.' },
    { code: 'client.redirect_uri_not_allowed', es: 'La URL de redirección no está permitida.', en: 'The redirect URL is not allowed.' },
    { code: 'client.misconfigured', es: 'La aplicación no está bien configurada para iniciar sesión. Avisá al administrador del SSO.', en: 'The application is misconfigured for sign-in. Please contact the SSO administrator.' },
    { code: 'client.invalid_credentials', es: 'Las credenciales del cliente no son válidas.', en: 'The client credentials are not valid.' },
    // DPoP
    { code: 'dpop.incomplete_jwk', es: 'La clave pública DPoP está incompleta.', en: 'The DPoP public key is incomplete.' },
    { code: 'dpop.unsupported_jwk', es: 'El tipo de clave DPoP no es soportado.', en: 'The DPoP key type is not supported.' },
    // Validation
    { code: 'validation.required', es: 'Este campo es requerido.', en: 'This field is required.' },
    { code: 'validation.string', es: 'Este campo debe ser texto.', en: 'This field must be text.' },
    { code: 'validation.min', es: 'Este campo debe tener al menos {min} caracteres.', en: 'This field must be at least {min} characters long.' },
    { code: 'validation.max', es: 'Este campo no puede superar los {max} caracteres.', en: 'This field cannot be longer than {max} characters.' },
    { code: 'validation.email', es: 'Este campo debe ser un email válido.', en: 'This field must be a valid email.' },
    { code: 'validation.unique', es: 'Este campo ya está en uso.', en: 'This field is already in use.' },
    { code: 'validation.exists', es: 'El valor de este campo no existe.', en: 'The value of this field does not exist.' },
    { code: 'validation.confirmed', es: 'La confirmación de este campo no coincide.', en: 'The confirmation of this field does not match.' },
    { code: 'validation.regex', es: 'Este campo no tiene un formato válido.', en: 'This field has an invalid format.' },
    { code: 'validation.json', es: 'Este campo debe ser JSON válido.', en: 'This field must be valid JSON.' },
    { code: 'validation.boolean', es: 'Este campo debe ser verdadero o falso.', en: 'This field must be true or false.' },
    { code: 'validation.in', es: 'El valor de este campo no es válido.', en: 'The value of this field is not valid.' },
    { code: 'validation.array', es: 'Este campo debe ser una lista.', en: 'This field must be a list.' },
    { code: 'validation.integer', es: 'Este campo debe ser un número entero.', en: 'This field must be an integer.' },
    { code: 'validation.numeric', es: 'Este campo debe ser un número.', en: 'This field must be a number.' },
    { code: 'validation.url', es: 'Este campo debe ser una URL válida.', en: 'This field must be a valid URL.' },
    { code: 'validation.date', es: 'Este campo debe ser una fecha válida.', en: 'This field must be a valid date.' },
    { code: 'validation.after_or_equal', es: 'Este campo debe ser una fecha posterior o igual a {date}.', en: 'This field must be a date after or equal to {date}.' },
    { code: 'validation.image', es: 'Este campo debe ser una imagen válida.', en: 'This field must be a valid image.' },
    { code: 'validation.password_complex', es: 'La contraseña debe tener al menos 8 caracteres, una mayúscula, un número y un carácter especial.', en: 'The password must be at least 8 characters long and include an uppercase letter, a number and a special character.' },
    { code: 'validation.unknown', es: 'Error de validación. Revisa los datos e inténtalo de nuevo.', en: 'Validation error. Please check your input and try again.' },
    { code: 'csp.hash_already_allowed', es: 'El hash ya está en la lista de permitidos.', en: 'The hash is already in the allowed list.' },
    { code: 'email_verification.invalid_link', es: 'El enlace de verificación es inválido o expiró.', en: 'The verification link is invalid or expired.' },
    { code: 'role.protected_update', es: 'No podés modificar un rol protegido.', en: 'You cannot modify a protected role.' },
    { code: 'role.protected_delete', es: 'No podés eliminar un rol protegido.', en: 'You cannot delete a protected role.' },
    { code: 'role.in_use', es: 'No podés eliminar un rol que tiene usuarios asignados.', en: 'You cannot delete a role that has users assigned.' },
    { code: 'setting.image_required', es: 'Se requiere una imagen.', en: 'An image is required.' },
    { code: 'setup.already_completed', es: 'Ya hay usuarios registrados en el sistema.', en: 'There are already registered users in the system.' },
    { code: 'user.self_delete_forbidden', es: 'No podés eliminar tu propio usuario.', en: 'You cannot delete your own user.' },
    { code: 'session.self_revoke_forbidden', es: 'No podés revocar tus propias sesiones desde el admin.', en: 'You cannot revoke your own sessions from the admin panel.' },
    { code: 'profile.encrypted_profile_required', es: 'Se requiere encrypted_profile para usuarios avanzados.', en: 'encrypted_profile is required for advanced users.' },
    { code: 'auth.totp_required', es: 'Necesitás tu código TOTP (o un código de respaldo) para continuar.', en: 'You need your TOTP code (or a backup code) to continue.' },
    { code: 'auth.passkey_required', es: 'Usá tu passkey para completar el inicio de sesión.', en: 'Use your passkey to complete sign-in.' },
    { code: 'auth.passkey_session_expired', es: 'La sesión passkey expiró o ya fue usada. Iniciá sesión de nuevo.', en: 'The passkey session expired or was already used. Please sign in again.' },
    { code: 'auth.passkey_session_used', es: 'La sesión passkey ya fue usada. Iniciá sesión de nuevo.', en: 'The passkey session was already used. Please sign in again.' },
    { code: 'auth.totp_not_enabled', es: 'No hay TOTP habilitado. Habilitá TOTP antes de generar códigos de respaldo.', en: 'TOTP is not enabled. Enable TOTP before generating backup codes.' },
    { code: 'auth.advanced_encryption_already_set', es: 'El usuario ya tiene cifrado avanzado habilitado.', en: 'The user already has advanced encryption enabled.' },
    { code: 'success.csp_hash_added', es: 'Se agregó el hash al CSP. Requiere deploy a producción.', en: 'Hash added to the CSP. A production deploy is required.' },
    { code: 'success.email_verified', es: 'Email verificado correctamente.', en: 'Email verified successfully.' },
    { code: 'success.verification_email_sent', es: 'Revisa tu email para verificar tu cuenta.', en: 'Check your email to verify your account.' },
    { code: 'success.verification_email_resent', es: 'Si el email existe, vas a recibir un enlace de verificación.', en: 'If the email exists, you will receive a verification link.' },
    { code: 'success.role_created', es: 'Rol creado correctamente.', en: 'Role created successfully.' },
    { code: 'success.role_updated', es: 'Rol actualizado correctamente.', en: 'Role updated successfully.' },
    { code: 'success.role_deleted', es: 'Rol eliminado correctamente.', en: 'Role deleted successfully.' },
    { code: 'success.setting_updated', es: 'Configuración actualizada correctamente.', en: 'Settings updated successfully.' },
    { code: 'success.logo_updated', es: 'Logo actualizado correctamente.', en: 'Logo updated successfully.' },
    { code: 'success.logo_deleted', es: 'Logo eliminado correctamente.', en: 'Logo deleted successfully.' },
    { code: 'success.email_logo_updated', es: 'Logo del email actualizado correctamente.', en: 'Email logo updated successfully.' },
    { code: 'success.email_logo_deleted', es: 'Logo del email eliminado correctamente.', en: 'Email logo deleted successfully.' },
    { code: 'success.admin_created', es: 'Administrador creado correctamente.', en: 'Administrator created successfully.' },
    { code: 'success.client_created', es: 'Cliente SSO creado correctamente.', en: 'SSO client created successfully.' },
    { code: 'success.client_updated', es: 'Cliente SSO actualizado correctamente.', en: 'SSO client updated successfully.' },
    { code: 'success.client_deleted', es: 'Cliente SSO eliminado correctamente.', en: 'SSO client deleted successfully.' },
    { code: 'success.user_updated', es: 'Usuario actualizado correctamente.', en: 'User updated successfully.' },
    { code: 'success.user_deleted', es: 'Usuario eliminado correctamente.', en: 'User deleted successfully.' },
    { code: 'success.user_roles_updated', es: 'Roles actualizados correctamente.', en: 'Roles updated successfully.' },
    { code: 'success.session_revoked', es: 'Sesión revocada correctamente.', en: 'Session revoked successfully.' },
    { code: 'success.user_sessions_revoked', es: 'Todas las sesiones del usuario han sido revocadas.', en: 'All the user\'s sessions have been revoked.' },
    { code: 'success.sessions_revoked', es: 'Todas las sesiones han sido revocadas correctamente.', en: 'All sessions have been revoked successfully.' },
    { code: 'success.account_recovered', es: 'Cuenta recuperada correctamente. Iniciá sesión con tu nueva contraseña.', en: 'Account recovered successfully. Sign in with your new password.' },
    { code: 'success.password_updated', es: 'Contraseña actualizada correctamente.', en: 'Password updated successfully.' },
    { code: 'success.password_updated_sessions_revoked', es: 'Contraseña actualizada correctamente. Todas las sesiones han sido revocadas.', en: 'Password updated successfully. All sessions have been revoked.' },
    { code: 'success.upgrade_to_advanced', es: 'Cuenta actualizada a cifrado avanzado. Iniciá sesión con tu nueva contraseña.', en: 'Account upgraded to advanced encryption. Sign in with your new password.' },
    { code: 'success.logout', es: 'Sesión cerrada correctamente.', en: 'Signed out successfully.' },
    { code: 'success.profile_updated', es: 'Perfil actualizado correctamente.', en: 'Profile updated successfully.' },
    { code: 'success.passkey_registered', es: 'Passkey registrado correctamente.', en: 'Passkey registered successfully.' },
    { code: 'success.passkey_deleted', es: 'Passkey eliminado correctamente.', en: 'Passkey deleted successfully.' },
    { code: 'success.totp_enabled', es: 'TOTP habilitado correctamente.', en: 'TOTP enabled successfully.' },
    { code: 'success.totp_disabled', es: 'TOTP deshabilitado correctamente.', en: 'TOTP disabled successfully.' },
    { code: 'success.backup_codes_regenerated', es: 'Códigos de respaldo regenerados.', en: 'Backup codes regenerated.' },
    { code: 'success.account_deleted', es: 'Cuenta eliminada correctamente.', en: 'Account deleted successfully.' },
    { code: 'success.authorization_denied', es: 'Autorización denegada.', en: 'Authorization denied.' },
    { code: 'success.access_revoked', es: 'Acceso revocado correctamente.', en: 'Access revoked successfully.' },
  ]

  for (const { code, es, en } of expectedCodes) {
    it(`pins code ${code} to exact ES/EN copy`, () => {
      const entry = errorCodeCatalog[code]
      expect(entry).toBeDefined()
      expect(entry.es).toBe(es)
      expect(entry.en).toBe(en)
    })
  }

  it('has no extra codes beyond the specification', () => {
    const catalogCodes = Object.keys(errorCodeCatalog).sort()
    const expectedCodesSorted = expectedCodes.map((c) => c.code).sort()
    expect(catalogCodes).toEqual(expectedCodesSorted)
  })
})

describe('getFriendlyMessage resolution by code', () => {
  it('returns localized copy when code is present and mapped', () => {
    const resultEs = getFriendlyMessage({ message: '', code: 'auth.unauthenticated', locale: 'es' })
    expect(resultEs).toBe('Necesitás iniciar sesión para continuar.')

    const resultEn = getFriendlyMessage({ message: '', code: 'auth.unauthenticated', locale: 'en' })
    expect(resultEn).toBe('You need to sign in to continue.')
  })

  it('interpolates {min} from params', () => {
    const resultEs = getFriendlyMessage({ message: '', code: 'validation.min', params: { min: '12' }, locale: 'es' })
    expect(resultEs).toBe('Este campo debe tener al menos 12 caracteres.')

    const resultEn = getFriendlyMessage({ message: '', code: 'validation.min', params: { min: '8' }, locale: 'en' })
    expect(resultEn).toBe('This field must be at least 8 characters long.')
  })

  it('interpolates {max} from params', () => {
    const resultEs = getFriendlyMessage({ message: '', code: 'validation.max', params: { max: '50' }, locale: 'es' })
    expect(resultEs).toBe('Este campo no puede superar los 50 caracteres.')

    const resultEn = getFriendlyMessage({ message: '', code: 'validation.max', params: { max: '100' }, locale: 'en' })
    expect(resultEn).toBe('This field cannot be longer than 100 characters.')
  })

  it('interpolates {date} from params', () => {
    const resultEs = getFriendlyMessage({ message: '', code: 'validation.after_or_equal', params: { date: '2025-01-01' }, locale: 'es' })
    expect(resultEs).toBe('Este campo debe ser una fecha posterior o igual a 2025-01-01.')

    const resultEn = getFriendlyMessage({ message: '', code: 'validation.after_or_equal', params: { date: '2025-01-01' }, locale: 'en' })
    expect(resultEn).toBe('This field must be a date after or equal to 2025-01-01.')
  })

  it('falls back to copy without placeholder when param is missing', () => {
    const resultEs = getFriendlyMessage({ message: '', code: 'validation.min', params: {}, locale: 'es' })
    expect(resultEs).not.toContain('{min}')
    expect(resultEs).not.toContain('{min}')

    const resultEn = getFriendlyMessage({ message: '', code: 'validation.max', params: {}, locale: 'en' })
    expect(resultEn).not.toContain('{max}')
  })

  it('unmapped code returns generic fallback AND logs raw code', () => {
    const resultEs = getFriendlyMessage({ message: '', code: 'some.unknown.code', locale: 'es' })
    expect(resultEs).toBe('Ocurrió un error inesperado. Inténtalo de nuevo.')
    expect(consoleWarnSpy).toHaveBeenCalledWith('[API Error] unmapped code', 'some.unknown.code')

    const resultEn = getFriendlyMessage({ message: '', code: 'another.unknown', locale: 'en' })
    expect(resultEn).toBe('Something went wrong. Please try again.')
    expect(consoleWarnSpy).toHaveBeenCalledWith('[API Error] unmapped code', 'another.unknown')
  })

  it('legacy fallback: no code -> prose substring matching still works', () => {
    const resultEs = getFriendlyMessage({ message: 'The email field is required.', locale: 'es' })
    expect(resultEs).toBe('Este campo es requerido.')

    const resultEn = getFriendlyMessage({ message: 'The password must be at least 8 characters.', locale: 'en' })
    expect(resultEn).toBe('This field must be at least 8 characters long.')
  })

  it('legacy fallback: no code and no match -> generic fallback', () => {
    const resultEs = getFriendlyMessage({ message: 'Weird backend text XYZ-123', locale: 'es' })
    expect(resultEs).toBe('Ocurrió un error inesperado. Inténtalo de nuevo.')

    const resultEn = getFriendlyMessage({ message: 'Weird backend text XYZ-123', locale: 'en' })
    expect(resultEn).toBe('Something went wrong. Please try again.')
  })

  it('raw backend prose never reaches UI when code is present', () => {
    const result = getFriendlyMessage({ message: 'Raw backend prose that should not appear', code: 'auth.invalid_credentials', locale: 'es' })
    expect(result).toBe('Credenciales inválidas.')
    expect(result).not.toContain('Raw backend prose')
  })
})

describe('getFieldFriendlyMessage resolution by code', () => {
  const labels = { email: 'Email', password: 'Contraseña' }
  const labelsEn = { email: 'Email', password: 'Password' }

  it('returns localized copy with field label when code is present and mapped', () => {
    const resultEs = getFieldFriendlyMessage({ field: 'email', message: '', code: 'validation.required', fieldLabels: labels, locale: 'es' })
    expect(resultEs).toBe('El campo Email es requerido.')

    const resultEn = getFieldFriendlyMessage({ field: 'email', message: '', code: 'validation.required', fieldLabels: labels, fieldLabelsEn: labelsEn, locale: 'en' })
    expect(resultEn).toBe('The Email field is required.')
  })

  it('interpolates params with field label', () => {
    const resultEs = getFieldFriendlyMessage({ field: 'password', message: '', code: 'validation.min', params: { min: '10' }, fieldLabels: labels, locale: 'es' })
    expect(resultEs).toBe('El campo Contraseña debe tener al menos 10 caracteres.')

    const resultEn = getFieldFriendlyMessage({ field: 'password', message: '', code: 'validation.max', params: { max: '20' }, fieldLabels: labels, fieldLabelsEn: labelsEn, locale: 'en' })
    expect(resultEn).toBe('The Password field cannot be longer than 20 characters.')
  })

  it('unmapped code returns generic fallback with field label AND logs raw code', () => {
    const resultEs = getFieldFriendlyMessage({ field: 'email', message: '', code: 'unknown.code', fieldLabels: labels, locale: 'es' })
    expect(resultEs).toBe('El campo Email: Ocurrió un error inesperado. Inténtalo de nuevo.')
    expect(consoleWarnSpy).toHaveBeenCalledWith('[API Error] unmapped code', 'unknown.code')

    const resultEn = getFieldFriendlyMessage({ field: 'email', message: '', code: 'unknown.code', fieldLabels: labels, fieldLabelsEn: labelsEn, locale: 'en' })
    expect(resultEn).toBe('The Email field: Something went wrong. Please try again.')
    expect(consoleWarnSpy).toHaveBeenCalledWith('[API Error] unmapped code', 'unknown.code')
  })

  it('legacy fallback: no code -> prose substring with field label', () => {
    const resultEs = getFieldFriendlyMessage({ field: 'email', message: 'The email field is required.', fieldLabels: labels, locale: 'es' })
    expect(resultEs).toBe('El campo Email es requerido.')

    const resultEn = getFieldFriendlyMessage({ field: 'email', message: 'The email field is required.', fieldLabels: labels, fieldLabelsEn: labelsEn, locale: 'en' })
    expect(resultEn).toBe('The Email field is required.')
  })

  it('legacy fallback: no code and no match -> generic fallback with field label', () => {
    const resultEs = getFieldFriendlyMessage({ field: 'email', message: 'Weird backend text', fieldLabels: labels, locale: 'es' })
    expect(resultEs).toBe('El campo Email: Ocurrió un error inesperado. Inténtalo de nuevo.')

    const resultEn = getFieldFriendlyMessage({ field: 'email', message: 'Weird backend text', fieldLabels: labels, fieldLabelsEn: labelsEn, locale: 'en' })
    expect(resultEn).toBe('The Email field: Something went wrong. Please try again.')
  })

  it('raw backend prose never reaches UI when code is present', () => {
    const result = getFieldFriendlyMessage({ field: 'password', message: 'Raw backend prose', code: 'auth.current_password_invalid', fieldLabels: labels, locale: 'es' })
    expect(result).toBe('El campo Contraseña: La contraseña actual es incorrecta.')
    expect(result).not.toContain('Raw backend prose')
  })
})

describe('getSuccessMessage resolution by code', () => {
  it('returns the localized copy for a mapped success code', () => {
    const resultEs = getSuccessMessage({ code: 'success.role_created', message: 'Role created successfully.', locale: 'es' })
    expect(resultEs).toBe('Rol creado correctamente.')

    const resultEn = getSuccessMessage({ code: 'success.role_created', message: 'Role created successfully.', locale: 'en' })
    expect(resultEn).toBe('Role created successfully.')
  })

  it('falls back to the english message and logs the raw code when the code is unknown', () => {
    const result = getSuccessMessage({ code: 'success.not_mapped', message: 'Something happened.', locale: 'es' })
    expect(result).toBe('Something happened.')
    expect(consoleWarnSpy).toHaveBeenCalledWith('[API Error] unmapped code', 'success.not_mapped')
  })

  it('falls back to the english message when the response carries no code', () => {
    const result = getSuccessMessage({ message: 'Something happened.', locale: 'es' })
    expect(result).toBe('Something happened.')
  })

  it('never renders the backend prose when the code is mapped', () => {
    const result = getSuccessMessage({ code: 'success.client_created', message: 'Raw backend prose', locale: 'es' })
    expect(result).toBe('Cliente SSO creado correctamente.')
    expect(result).not.toContain('Raw backend prose')
  })
})
