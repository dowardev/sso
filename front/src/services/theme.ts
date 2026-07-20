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

export function getSystemTheme(): 'light' | 'dark' {
  if (typeof window !== 'undefined' && window.matchMedia) {
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
  }
  return 'light'
}

export const themeClasses = [
  'dark',
  'catppuccin-latte-maroon',
  'catppuccin-macchiato-maroon',
]
