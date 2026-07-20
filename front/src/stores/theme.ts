import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

export type ThemeName =
  | 'auto'
  | 'light'
  | 'dark'
  | 'catppuccin-latte'
  | 'catppuccin-macchiato'

export type ResolvedThemeName =
  | 'light'
  | 'dark'
  | 'catppuccin-latte'
  | 'catppuccin-macchiato'

export interface ThemeInfo {
  name: ThemeName
  label: string
  className: string
  color: string
}

export const themes: ThemeInfo[] = [
  {
    name: 'auto',
    label: 'Automático',
    className: '',
    color: 'linear-gradient(135deg, #0054e9 50%, #1a1a2e 50%)',
  },
  {
    name: 'light',
    label: 'Claro',
    className: '',
    color: '#0054e9',
  },
  {
    name: 'dark',
    label: 'Oscuro',
    className: 'dark',
    color: '#4d91ff',
  },
  {
    name: 'catppuccin-latte',
    label: 'Catppuccin Latte',
    className: 'catppuccin-latte-maroon',
    color: '#d20f39',
  },
  {
    name: 'catppuccin-macchiato',
    label: 'Catppuccin Macchiato',
    className: 'catppuccin-macchiato-maroon',
    color: '#ee99a0',
  },
]

const STORAGE_KEY = 'sso-theme'
const STORAGE_KEY_AUTO_LIGHT = 'sso-theme-auto-light'
const STORAGE_KEY_AUTO_DARK = 'sso-theme-auto-dark'

function getSystemTheme(): 'light' | 'dark' {
  if (typeof window !== 'undefined' && window.matchMedia) {
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
  }
  return 'light'
}

function getStoredValue<T>(key: string, fallback: T): T {
  if (typeof localStorage !== 'undefined') {
    const stored = localStorage.getItem(key)
    if (stored) return stored as unknown as T
  }
  return fallback
}

const themeClasses = [
  'dark',
  'catppuccin-latte-maroon',
  'catppuccin-macchiato-maroon',
]

export const useThemeStore = defineStore('theme', () => {
  const currentThemeName = ref<ThemeName>(
    getStoredValue<ThemeName>(STORAGE_KEY, 'auto')
  )
  const autoLightTheme = ref<ResolvedThemeName>(
    getStoredValue<ResolvedThemeName>(STORAGE_KEY_AUTO_LIGHT, 'light')
  )
  const autoDarkTheme = ref<ResolvedThemeName>(
    getStoredValue<ResolvedThemeName>(STORAGE_KEY_AUTO_DARK, 'dark')
  )

  function resolveTheme(themeName: ThemeName): ResolvedThemeName {
    if (themeName === 'auto') {
      const systemPref = getSystemTheme()
      return systemPref === 'dark' ? autoDarkTheme.value : autoLightTheme.value
    }
    return themeName as ResolvedThemeName
  }

  function applyTheme(themeName: ThemeName) {
    if (typeof document === 'undefined') return

    const body = document.body
    themeClasses.forEach((cls) => body.classList.remove(cls))

    const resolved = resolveTheme(themeName)
    const theme = themes.find((t) => t.name === resolved)
    if (theme && theme.className) {
      body.classList.add(theme.className)
    }

    localStorage.setItem(STORAGE_KEY, themeName)
  }

  const currentTheme = computed(() => {
    return themes.find((t) => t.name === currentThemeName.value) || themes[0]
  })

  const isAuto = computed(() => currentThemeName.value === 'auto')

  const resolvedTheme = computed(() => {
    const resolved = resolveTheme(currentThemeName.value)
    return themes.find((t) => t.name === resolved) || themes[1]
  })

  const isDark = computed(() => {
    const resolved = resolveTheme(currentThemeName.value)
    return resolved === 'dark' || resolved === 'catppuccin-macchiato'
  })

  const autoLightThemeInfo = computed(() => {
    return themes.find((t) => t.name === autoLightTheme.value) || themes[1]
  })

  const autoDarkThemeInfo = computed(() => {
    return themes.find((t) => t.name === autoDarkTheme.value) || themes[2]
  })

  let mediaQuery: MediaQueryList | null = null

  function init() {
    applyTheme(currentThemeName.value)
    if (typeof window !== 'undefined') {
      mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
      mediaQuery.addEventListener('change', () => {
        if (currentThemeName.value === 'auto') {
          applyTheme('auto')
        }
      })
    }
  }

  function setTheme(themeName: ThemeName) {
    currentThemeName.value = themeName
    applyTheme(themeName)
  }

  function setAutoLightTheme(themeName: ResolvedThemeName) {
    autoLightTheme.value = themeName
    localStorage.setItem(STORAGE_KEY_AUTO_LIGHT, themeName)
    if (currentThemeName.value === 'auto') {
      applyTheme('auto')
    }
  }

  function setAutoDarkTheme(themeName: ResolvedThemeName) {
    autoDarkTheme.value = themeName
    localStorage.setItem(STORAGE_KEY_AUTO_DARK, themeName)
    if (currentThemeName.value === 'auto') {
      applyTheme('auto')
    }
  }

  return {
    currentThemeName,
    currentTheme,
    resolvedTheme,
    isAuto,
    isDark,
    autoLightTheme,
    autoDarkTheme,
    autoLightThemeInfo,
    autoDarkThemeInfo,
    themes,
    init,
    setTheme,
    setAutoLightTheme,
    setAutoDarkTheme,
  }
})
