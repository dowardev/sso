// SPDX-License-Identifier: MIT
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

let useBranding: typeof import('@/composables/useBranding').useBranding

describe('useBranding', () => {
  beforeEach(async () => {
    vi.stubGlobal('fetch', vi.fn())
    vi.resetModules()
    const mod = await import('@/composables/useBranding')
    useBranding = mod.useBranding
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('carga logo configurado', async () => {
    vi.mocked(fetch).mockResolvedValue({
      ok: true,
      json: async () => ({ auth_logo_url: '/storage/branding/auth-logo.png' }),
    } as Response)

    const { logoUrl, loadBranding } = useBranding()
    await loadBranding()

    expect(logoUrl.value).toBe('http://localhost:8001/storage/branding/auth-logo.png')
  })

  it('mantiene URLs absolutas sin resolver', async () => {
    vi.mocked(fetch).mockResolvedValue({
      ok: true,
      json: async () => ({ auth_logo_url: 'https://cdn.example.com/logo.png' }),
    } as Response)

    const { logoUrl, loadBranding } = useBranding()
    await loadBranding()

    expect(logoUrl.value).toBe('https://cdn.example.com/logo.png')
  })

  it('devuelve null si no hay logo', async () => {
    vi.mocked(fetch).mockResolvedValue({
      ok: true,
      json: async () => ({ auth_logo_url: null }),
    } as Response)

    const { logoUrl, loadBranding } = useBranding()
    await loadBranding()

    expect(logoUrl.value).toBeNull()
  })

  it('cae a null si la llamada falla', async () => {
    vi.mocked(fetch).mockRejectedValue(new Error('network'))

    const { logoUrl, loadBranding } = useBranding()
    await loadBranding()

    expect(logoUrl.value).toBeNull()
  })
})