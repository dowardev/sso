// SPDX-License-Identifier: MIT
import { ref, type Ref } from 'vue'

const API_URL = import.meta.env.VITE_API_URL

const cachedEmailLogo = ref<string | null>(null)
let loaded = false

function resolveLogoUrl(url: string | null): string | null {
  if (!url) return null
  if (/^https?:\/\//i.test(url) || url.startsWith('//')) return url
  const origin = (API_URL ?? '').replace(/\/api\/?$/, '').replace(/\/+$/, '')
  if (!origin) return url
  return `${origin}${url.startsWith('/') ? url : `/${url}`}`
}

export function useEmailBranding(): {
  logoUrl: Ref<string | null>
  loadEmailLogo: (force?: boolean) => Promise<void>
  uploadEmailLogo: (file: File) => Promise<{ email_logo_url: string | null }>
  removeEmailLogo: () => Promise<{ email_logo_url: string | null }>
} {
  async function loadEmailLogo(force: boolean = false): Promise<void> {
    if (loaded && !force) return
    try {
      const token = localStorage.getItem('token') ?? ''
      const baseUrl = (API_URL ?? '').replace(/\/+$/, '')
      const response = await fetch(`${baseUrl}/admin/settings/email-logo`, {
        credentials: 'include',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
      })
      if (!response.ok) {
        cachedEmailLogo.value = null
        return
      }
      const data = await response.json()
      cachedEmailLogo.value = resolveLogoUrl(data.email_logo_url)
    } catch {
      cachedEmailLogo.value = null
    } finally {
      loaded = true
    }
  }

  async function uploadEmailLogo(
    file: File
  ): Promise<{ email_logo_url: string | null }> {
    const formData = new FormData()
    formData.append('image', file)
    const baseUrl = (API_URL ?? '').replace(/\/+$/, '')
    const response = await fetch(`${baseUrl}/admin/settings/email-logo`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${localStorage.getItem('token') ?? ''}`,
      },
      body: formData,
    })
    if (!response.ok) {
      const err = await response
        .json()
        .catch(() => ({ message: 'Error al subir el logo del email' }))
      throw new Error(err.message || 'Error al subir el logo del email')
    }
    const data = await response.json()
    cachedEmailLogo.value = resolveLogoUrl(data.email_logo_url)
    return data
  }

  async function removeEmailLogo(): Promise<{ email_logo_url: string | null }> {
    const baseUrl = (API_URL ?? '').replace(/\/+$/, '')
    const response = await fetch(`${baseUrl}/admin/settings/email-logo`, {
      method: 'DELETE',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${localStorage.getItem('token') ?? ''}`,
      },
    })
    if (!response.ok) {
      const err = await response
        .json()
        .catch(() => ({ message: 'Error al quitar el logo del email' }))
      throw new Error(
        err.message || 'Error al quitar el logo del email'
      )
    }
    const data = await response.json()
    cachedEmailLogo.value = resolveLogoUrl(data.email_logo_url)
    return data
  }

  return { logoUrl: cachedEmailLogo, loadEmailLogo, uploadEmailLogo, removeEmailLogo }
}
