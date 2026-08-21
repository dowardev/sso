// SPDX-License-Identifier: MIT
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

let useEmailBranding: typeof import('@/composables/useEmailBranding').useEmailBranding

describe('useEmailBranding', () => {
  beforeEach(async () => {
    vi.stubGlobal('fetch', vi.fn())
    vi.resetModules()
    const mod = await import('@/composables/useEmailBranding')
    useEmailBranding = mod.useEmailBranding
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('carga logo del email configurado', async () => {
    vi.mocked(fetch).mockResolvedValue({
      ok: true,
      json: async () => ({ email_logo_url: '/storage/branding/email-logo.png' }),
    } as Response)

    const { logoUrl, loadEmailLogo } = useEmailBranding()
    await loadEmailLogo()

    expect(logoUrl.value).toBe('http://localhost:8001/storage/branding/email-logo.png')
  })

  it('mantiene URLs absolutas sin resolver', async () => {
    vi.mocked(fetch).mockResolvedValue({
      ok: true,
      json: async () => ({ email_logo_url: 'https://cdn.example.com/email-logo.png' }),
    } as Response)

    const { logoUrl, loadEmailLogo } = useEmailBranding()
    await loadEmailLogo()

    expect(logoUrl.value).toBe('https://cdn.example.com/email-logo.png')
  })

  it('devuelve null si no hay logo del email', async () => {
    vi.mocked(fetch).mockResolvedValue({
      ok: true,
      json: async () => ({ email_logo_url: null }),
    } as Response)

    const { logoUrl, loadEmailLogo } = useEmailBranding()
    await loadEmailLogo()

    expect(logoUrl.value).toBeNull()
  })

  it('cae a null si la llamada falla', async () => {
    vi.mocked(fetch).mockRejectedValue(new Error('network'))

    const { logoUrl, loadEmailLogo } = useEmailBranding()
    await loadEmailLogo()

    expect(logoUrl.value).toBeNull()
  })

  it('no relanza la carga si ya cargó', async () => {
    const fetchMock = vi.mocked(fetch).mockResolvedValue({
      ok: true,
      json: async () => ({ email_logo_url: '/storage/branding/email-logo.png' }),
    } as Response)

    const { loadEmailLogo } = useEmailBranding()
    await loadEmailLogo()
    await loadEmailLogo()

    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('sube el logo del email y actualiza el valor', async () => {
    vi.mocked(fetch).mockResolvedValue({
      ok: true,
      json: async () => ({ email_logo_url: '/storage/branding/email-logo.png' }),
    } as Response)

    const { logoUrl, uploadEmailLogo } = useEmailBranding()
    const file = new File(['content'], 'logo.png', { type: 'image/png' })
    await uploadEmailLogo(file)

    expect(logoUrl.value).toBe('http://localhost:8001/storage/branding/email-logo.png')
  })

  it('lanza error si la subida falla', async () => {
    vi.mocked(fetch).mockResolvedValue({
      ok: false,
      json: async () => ({ message: 'Imagen inválida' }),
    } as Response)

    const { uploadEmailLogo } = useEmailBranding()
    const file = new File(['content'], 'logo.png', { type: 'image/png' })

    await expect(uploadEmailLogo(file)).rejects.toThrow('Imagen inválida')
  })

  it('quita el logo del email y devuelve null', async () => {
    vi.mocked(fetch).mockResolvedValue({
      ok: true,
      json: async () => ({ email_logo_url: null }),
    } as Response)

    const { logoUrl, removeEmailLogo } = useEmailBranding()
    await removeEmailLogo()

    expect(logoUrl.value).toBeNull()
  })
})