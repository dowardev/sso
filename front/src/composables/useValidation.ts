// SPDX-License-Identifier: MIT
import { helpers, required, email, minLength, maxLength, sameAs, url as urlValidator } from '@vuelidate/validators'
import type { ValidationRule } from '@vuelidate/core'
import type { ComputedRef } from 'vue'

import { useToast } from '@/composables/useToast'
import { fieldLabelsEn, getFieldFriendlyMessage, getFriendlyMessage } from '@/utils/userFriendlyErrors'

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

function getValidationErrors(params: { err: unknown }): Record<string, string[]> | null {
  const { err } = params
  if (typeof err !== 'object' || err === null) return null
  const errors = (err as { errors?: unknown }).errors
  if (!errors || typeof errors !== 'object') return null
  const record: Record<string, string[]> = {}
  for (const [field, value] of Object.entries(errors as Record<string, unknown>)) {
    if (Array.isArray(value)) {
      const items = value.map((item) => String(item)).filter((item) => item.trim().length > 0)
      if (items.length > 0) record[field] = items
    } else if (typeof value === 'string') {
      record[field] = [value]
    }
  }
  return Object.keys(record).length > 0 ? record : null
}

export function useValidation() {
  const { showError } = useToast()

  function handleApiError(err: unknown): string {
    // Raw backend detail always goes to the console; the toast only shows
    // friendly catalog copy so raw text never leaks into the UI.
    console.error('[API Error]', err)
    const messages: string[] = []

    const validationErrors = getValidationErrors({ err })
    if (validationErrors) {
      console.error('[API Validation Errors]', validationErrors)

      for (const [field, fieldErrors] of Object.entries(validationErrors)) {
        for (const msg of fieldErrors) {
          messages.push(getFieldFriendlyMessage({ field, message: msg, fieldLabels, fieldLabelsEn }))
        }
      }
    } else if (err instanceof Error) {
      messages.push(getFriendlyMessage({ message: err.message }))
    } else if (typeof err === 'string' && err) {
      messages.push(getFriendlyMessage({ message: err }))
    } else {
      messages.push(getFriendlyMessage({ message: '' }))
    }

    const cleaned = messages.map((item) => item.trim().replace(/[.\s]+$/, '')).filter((item) => item.length > 0)
    const joined = cleaned.join('. ') + (cleaned.length > 0 ? '.' : '')
    showError({ message: joined })
    return joined
  }

  function translateServerError(field: string, message: string): string {
    return getFieldFriendlyMessage({ field, message, fieldLabels, fieldLabelsEn })
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
