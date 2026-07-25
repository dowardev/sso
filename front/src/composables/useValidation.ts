// SPDX-License-Identifier: MIT
import { helpers, required, email, minLength, maxLength, sameAs, url as urlValidator } from '@vuelidate/validators'
import type { ValidationRule } from '@vuelidate/core'
import type { ComputedRef } from 'vue'

import { useToast } from '@/composables/useToast'

const fieldLabels: Record<string, string> = {
  name: 'Nombre',
  user: 'Usuario',
  email: 'Email',
  password: 'Contraseña',
  password_confirmation: 'Confirmación de contraseña',
  client_id: 'ID del cliente',
  client_secret: 'Secreto del cliente',
  redirect_url: 'URL de redirección',
  allowed_origins: 'Orígenes permitidos',
  grant_types: 'Tipos de concesión',
  description: 'Descripción',
  slug: 'Slug',
  role_id: 'ID del rol',
  action: 'Acción',
  token: 'Token',
  code: 'Código',
  remember_me: 'Recordar sesión',
}

export function withMsg(message: string, rule: ValidationRule): ValidationRule {
  return helpers.withMessage(message, rule)
}

export function useValidation() {
  const { showError } = useToast()

  function handleApiError(err: unknown): string {
    const messages: string[] = []

    if (typeof err === 'object' && err && 'errors' in err) {
      const errors = (err as Record<string, unknown>).errors as Record<string, string[]>

      console.error('[API Validation Errors]', errors)

      for (const [field, fieldErrors] of Object.entries(errors)) {
        for (const msg of fieldErrors) {
          messages.push(translateServerError(field, msg))
        }
      }
    } else if (err instanceof Error) {
      messages.push(err.message)
    } else {
      messages.push('Ocurrió un error inesperado')
    }

    const joined = messages.join('. ')
    showError({ message: joined })
    return joined
  }

  function translateServerError(field: string, message: string): string {
    const label = fieldLabels[field] || field

    if (message.includes('field is required')) return `El campo ${label} es requerido.`
    if (message.includes('must be at least 8 characters') && message.match(/uppercase|special|number/))
      return 'La contraseña debe tener al menos 8 caracteres, una mayúscula, un número y un carácter especial.'
    if (message.includes('must be at least')) {
      const match = message.match(/must be at least (\d+)/)
      return `El campo ${label} debe tener al menos ${match?.[1] ?? ''} caracteres.`
    }
    if (message.includes('must be a valid email')) return `El campo ${label} debe ser un email válido.`
    if (message.includes('confirmation does not match')) return `La confirmación de ${label} no coincide.`
    if (message.includes('has already been taken')) return `El campo ${label} ya está en uso.`
    if (message.includes('may only contain')) return `El campo ${label} contiene caracteres no válidos.`
    if (message.includes('not registered in SSO')) return 'La aplicación no está registrada en el SSO.'
    if (message.includes('is not a valid URL')) return `El campo ${label} debe ser una URL válida.`
    if (message.includes('Incomplete JWK')) return 'La clave pública DPoP está incompleta.'
    if (message.includes('Unsupported JWK')) return 'El tipo de clave DPoP no es soportado.'
    if (message.includes('Invalid credentials')) return 'Credenciales inválidas.'
    if (message.includes('Client is disabled')) return 'El cliente SSO está desactivado.'

    return `Error en ${label}: ${message}`
  }

  return { handleApiError, translateServerError, withMsg, fieldLabels }
}

export const validators = {
  required: (label: string) => withMsg(`El campo ${label} es requerido`, required),
  email: (label: string) => withMsg(`El campo ${label} debe ser un email v\u00e1lido`, email),
  minLength: (label: string, min: number) =>
    withMsg(`El campo ${label} debe tener al menos ${min} caracteres`, minLength(min)),
  maxLength: (label: string, max: number) =>
    withMsg(`El campo ${label} no puede exceder ${max} caracteres`, maxLength(max)),
  url: (label: string) =>
    withMsg(`El campo ${label} debe ser una URL v\u00e1lida`, urlValidator),
  sameAs: (label: string, target: ComputedRef<string>) =>
    withMsg(`El campo ${label} no coincide`, sameAs(target)),
  alphaNum: (label: string) =>
    withMsg(`El campo ${label} solo permite letras, n\u00fameros, guiones y guiones bajos`, helpers.regex(/^[a-zA-Z0-9_-]+$/)),
  passwordComplex: (label: string) =>
    withMsg(`El campo ${label} debe tener 8+ caracteres, una may\u00fascula, un n\u00famero y un car\u00e1cter especial`, helpers.regex(/^(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z0-9]).+$/)),
  hostOrIp: (label: string) => {
    const isValidHostOrIp = (value: string): boolean => {
      if (!value) return true

      const ipv4Octet = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/
      const ipMatch = value.match(ipv4Octet)
      if (ipMatch) {
        return ipMatch.slice(1).every((octet) => {
          const n = Number.parseInt(octet, 10)
          return n >= 0 && n <= 255
        })
      }

      return /^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/.test(value)
    }

    return withMsg(`El campo ${label} debe ser un host o IP v\u00e1lido`, isValidHostOrIp)
  },
}
