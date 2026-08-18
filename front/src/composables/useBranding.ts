// SPDX-License-Identifier: MIT
import { ref, type Ref } from 'vue'
import { api } from '@/services/api'

const API_URL = import.meta.env.VITE_API_URL

export interface PublicBranding {
  auth_logo_url: string | null
}

const cachedLogo = ref<string | null>(null)
let loaded = false

function resolveLogoUrl(url: string | null): string | null {
  if (!url) return null
  if (/^https?:\/\//i.test(url) || url.startsWith('//')) return url
  const origin = (API_URL ?? '').replace(/\/api\/?$/, '').replace(/\/+$/, '')
  if (!origin) return url
  return `${origin}${url.startsWith('/') ? url : `/${url}`}`
}

export function useBranding(): {
  logoUrl: Ref<string | null>
  loadBranding: () => Promise<void>
  uploadLogo: (file: File) => Promise<{ auth_logo_url: string | null }>
  removeLogo: () => Promise<{ auth_logo_url: string | null }>
} {
  async function loadBranding(): Promise<void> {
    if (loaded) return
    try {
      const data = await api.get<PublicBranding>({ endpoint: '/settings/public' })
      cachedLogo.value = resolveLogoUrl(data.auth_logo_url)
    } catch {
      cachedLogo.value = null
    } finally {
      loaded = true
    }
  }

  async function uploadLogo(file: File): Promise<{ auth_logo_url: string | null }> {
    const formData = new FormData()
    formData.append('image', file)
    const baseUrl = (API_URL ?? '').replace(/\/+$/, '')
    const response = await fetch(`${baseUrl}/admin/settings/auth-logo`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${localStorage.getItem('token') ?? ''}`,
      },
      body: formData,
    })
    if (!response.ok) {
      const err = await response.json().catch(() => ({ message: 'Error al subir el logo' }))
      throw new Error(err.message || 'Error al subir el logo')
    }
    const data = await response.json()
    cachedLogo.value = resolveLogoUrl(data.auth_logo_url)
    return data
  }

  async function removeLogo(): Promise<{ auth_logo_url: string | null }> {
    const baseUrl = (API_URL ?? '').replace(/\/+$/, '')
    const response = await fetch(`${baseUrl}/admin/settings/auth-logo`, {
      method: 'DELETE',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${localStorage.getItem('token') ?? ''}`,
      },
    })
    if (!response.ok) {
      const err = await response.json().catch(() => ({ message: 'Error al quitar el logo' }))
      throw new Error(err.message || 'Error al quitar el logo')
    }
    const data = await response.json()
    cachedLogo.value = resolveLogoUrl(data.auth_logo_url)
    return data
  }

  return { logoUrl: cachedLogo, loadBranding, uploadLogo, removeLogo }
}