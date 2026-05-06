import { useTheme, type ThemeName, type ResolvedThemeName, type ThemeInfo } from '@/services/theme';

export function useThemeController() {
  const theme = useTheme();

  return {
    currentTheme: theme.currentTheme,
    currentThemeName: theme.currentThemeName,
    resolvedTheme: theme.resolvedTheme,
    setTheme: (params: { themeName: ThemeName }): void => {
      theme.setTheme(params.themeName);
    },
    isDark: theme.isDark,
    isAuto: theme.isAuto,
    themes: theme.themes as ThemeInfo[],
    autoLightTheme: theme.autoLightTheme,
    autoDarkTheme: theme.autoDarkTheme,
    autoLightThemeInfo: theme.autoLightThemeInfo,
    autoDarkThemeInfo: theme.autoDarkThemeInfo,
    setAutoLightTheme: (params: { themeName: ResolvedThemeName }): void => {
      theme.setAutoLightTheme(params.themeName);
    },
    setAutoDarkTheme: (params: { themeName: ResolvedThemeName }): void => {
      theme.setAutoDarkTheme(params.themeName);
    },
  };
}