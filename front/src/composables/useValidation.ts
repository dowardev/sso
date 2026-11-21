// SPDX-License-Identifier: MIT
import { helpers, required, email, minLength, maxLength, sameAs, url as urlValidator } from '@vuelidate/validators'
import type { ValidationRule } from '@vuelidate/core'
import type { ComputedRef } from 'vue'

import { fieldLabelsEn, fieldLabelsEs } from '@/utils/fieldLabels'
import { getFieldFriendlyMessage, getFriendlyMessage } from '@/utils/userFriendlyErrors'
import type { ApiErrorDetail } from '@/utils/apiErrorCodes'

// Single source of truth for field labels (front/src/utils/fieldLabels.ts).
const fieldLabels: Record<string, string> = fieldLabelsEs

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

/**
 * Notification contract (LBDS-267):
 * - `handleApiError` is a PURE translator: it returns the friendly copy and
 *   logs the raw error to the console. It does NOT show a toast.
 * - Form errors are rendered inline by the caller (Pattern A):
 *     `error.value = handleApiError(err)`
 *   This avoids showing the same message twice (toast + inline text).
 * - Actions without an inline error block MUST notify through an explicit
 *   `showError({ message: handleApiError(err) })` (Pattern B), so the user
 *   still gets feedback.
 * - Callers that already pass the result to `showError` (Pattern C) MUST
 *   keep that wrapping — do NOT remove the `showError` wrapper just because
 *   the helper used to toast on its own. The wrapper is now the only channel
 *   firing; dropping it would silence the screen entirely.
 */
/**
 * Extracts the machine-readable error details (`error_details`) carried by an
 * API error. The backend sends one entry per field with its stable `code`
 * (see odd/tasks/api-error-contract-codes.md).
 */
function getApiErrorDetails(params: { err: unknown }): ApiErrorDetail[] {
  const { err } = params
  if (typeof err !== 'object' || err === null) return []
  const details = (err as { error_details?: unknown }).error_details
  if (typeof details !== 'object' || details === null || Array.isArray(details)) return []

  const result: ApiErrorDetail[] = []
  for (const [field, value] of Object.entries(details as Record<string, unknown>)) {
    const entries = Array.isArray(value) ? value : [value]
    for (const entry of entries) {
      if (typeof entry === 'string') {
        // Legacy response without codes.
        result.push({ field, message: entry })
        continue
      }
      if (typeof entry !== 'object' || entry === null) continue
      const record = entry as Record<string, unknown>
      const paramsValue = record.params
      result.push({
        code: typeof record.code === 'string' ? record.code : undefined,
        field: typeof record.field === 'string' ? record.field : field,
        message: typeof record.message === 'string' ? record.message : '',
        params:
          typeof paramsValue === 'object' && paramsValue !== null
            ? (paramsValue as Record<string, string | number>)
            : undefined,
      })
    }
  }
  return result
}

function getErrorCode(params: { err: unknown }): string | undefined {
  const { err } = params
  if (typeof err !== 'object' || err === null) return undefined
  const code = (err as { code?: unknown }).code
  return typeof code === 'string' && code ? code : undefined
}

export function useValidation() {
  function handleApiError(err: unknown): string {
    // Raw backend detail always goes to the console; the returned string is
    // friendly catalog copy so raw text never leaks into the UI.
    console.error('[API Error]', err)
    const messages: string[] = []

    // 1. Machine-readable channel: resolve by stable code.
    const details = getApiErrorDetails({ err })
    if (details.length > 0) {
      console.error('[API Validation Errors]', details)

      for (const detail of details) {
        messages.push(
          getFieldFriendlyMessage({
            field: detail.field ?? '',
            message: detail.message ?? '',
            code: detail.code,
            params: detail.params,
            fieldLabels,
            fieldLabelsEn,
          }),
        )
      }
    } else if (getErrorCode({ err })) {
      // Top-level error with a stable code.
      messages.push(
        getFriendlyMessage({ message: err instanceof Error ? err.message : '', code: getErrorCode({ err }) }),
      )
    } else {
      // 2. Legacy fallback: response without a code.
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
    }

    const cleaned = messages.map((item) => item.trim().replace(/[.\s]+$/, '')).filter((item) => item.length > 0)
    const joined = cleaned.join('. ') + (cleaned.length > 0 ? '.' : '')
    return joined
  }

  function translateServerError(field: string, message: string, code?: string): string {
    return getFieldFriendlyMessage({ field, message, code, fieldLabels, fieldLabelsEn })
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
