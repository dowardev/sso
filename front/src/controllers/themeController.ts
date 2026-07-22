// SPDX-License-Identifier: MIT
import { useThemeStore } from '@/stores/theme'
import type { ThemeName, ResolvedThemeName } from '@/stores/theme'

export type { ThemeName, ResolvedThemeName }

export function useThemeController() {
  const themeStore = useThemeStore()

  return {
    currentTheme: themeStore.currentTheme,
    currentThemeName: themeStore.currentThemeName,
    resolvedTheme: themeStore.resolvedTheme,
    setTheme: (params: { themeName: ThemeName }): void => {
      themeStore.setTheme(params.themeName)
    },
    isDark: themeStore.isDark,
    isAuto: themeStore.isAuto,
    themes: themeStore.themes,
    autoLightTheme: themeStore.autoLightTheme,
    autoDarkTheme: themeStore.autoDarkTheme,
    autoLightThemeInfo: themeStore.autoLightThemeInfo,
    autoDarkThemeInfo: themeStore.autoDarkThemeInfo,
    setAutoLightTheme: (params: { themeName: ResolvedThemeName }): void => {
      themeStore.setAutoLightTheme(params.themeName)
    },
    setAutoDarkTheme: (params: { themeName: ResolvedThemeName }): void => {
      themeStore.setAutoDarkTheme(params.themeName)
    },
  }
}
