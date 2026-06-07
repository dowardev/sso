# Glassmorphism Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rediseñar visualmente el frontend SSO con estética glassmorphism púrpura→cian sobre fondo oscuro profundo

**Architecture:** Reemplazar la paleta azul genérica y los estilos card-shadow existentes con un sistema glassmorphism completo: fondo oscuro `#0A0F1D`, tarjetas translúcidas con `backdrop-filter: blur(24px)`, degradado púrpura→cian como acento principal. Los cambios son puramente CSS — no se modifica lógica de negocio.

**Tech Stack:** Ionic Vue 8, CSS custom properties, backdrop-filter

---

### Task 1: Nueva paleta dark.css (tema base)

**Files:**
- Modify: `front/src/theme/files/dark.css`

Cambiar la paleta del tema dark para que sea el glassmorphism base:

- [ ] **Step 1: Reemplazar colores primarios y de fondo en dark.css**

Reemplazar el contenido completo de `body.dark { ... }` con los nuevos valores:

```css
body.dark {
  --ion-color-primary: #8B5CF6;
  --ion-color-primary-rgb: 139, 92, 246;
  --ion-color-primary-contrast: #ffffff;
  --ion-color-primary-contrast-rgb: 255, 255, 255;
  --ion-color-primary-shade: #7A52D9;
  --ion-color-primary-tint: #976DF7;

  --ion-color-secondary: #06B6D4;
  --ion-color-secondary-rgb: 6, 182, 212;
  --ion-color-secondary-contrast: #ffffff;
  --ion-color-secondary-contrast-rgb: 255, 255, 255;
  --ion-color-secondary-shade: #05A0BB;
  --ion-color-secondary-tint: #1FBCD8;

  --ion-color-tertiary: #A78BFA;
  --ion-color-tertiary-rgb: 167, 139, 250;
  --ion-color-tertiary-contrast: #ffffff;
  --ion-color-tertiary-contrast-rgb: 255, 255, 255;
  --ion-color-tertiary-shade: #927ADC;
  --ion-color-tertiary-tint: #B097FB;

  --ion-color-success: #4ADE80;
  --ion-color-success-rgb: 74, 222, 128;
  --ion-color-success-contrast: #000000;
  --ion-color-success-contrast-rgb: 0, 0, 0;
  --ion-color-success-shade: #41C371;
  --ion-color-success-tint: #5DE18D;

  --ion-color-warning: #F59E0B;
  --ion-color-warning-rgb: 245, 158, 11;
  --ion-color-warning-contrast: #000000;
  --ion-color-warning-contrast-rgb: 0, 0, 0;
  --ion-color-warning-shade: #D88B0A;
  --ion-color-warning-tint: #F6A823;

  --ion-color-danger: #EF4444;
  --ion-color-danger-rgb: 239, 68, 68;
  --ion-color-danger-contrast: #ffffff;
  --ion-color-danger-contrast-rgb: 255, 255, 255;
  --ion-color-danger-shade: #D23C3C;
  --ion-color-danger-tint: #F15757;

  --ion-color-light: #1E293B;
  --ion-color-light-rgb: 30, 41, 59;
  --ion-color-light-contrast: #ffffff;
  --ion-color-light-contrast-rgb: 255, 255, 255;
  --ion-color-light-shade: #1A2433;
  --ion-color-light-tint: #343F54;

  --ion-color-medium: #64748B;
  --ion-color-medium-rgb: 100, 116, 139;
  --ion-color-medium-contrast: #ffffff;
  --ion-color-medium-contrast-rgb: 255, 255, 255;
  --ion-color-medium-shade: #58657A;
  --ion-color-medium-tint: #748397;

  --ion-color-dark: #E2E8F0;
  --ion-color-dark-rgb: 226, 232, 240;
  --ion-color-dark-contrast: #000000;
  --ion-color-dark-contrast-rgb: 0, 0, 0;
  --ion-color-dark-shade: #C5CBD6;
  --ion-color-dark-tint: #E5EAF2;

  --ion-background-color: #0A0F1D;
  --ion-background-color-rgb: 10, 15, 29;
  --background-contrast: rgba(255, 255, 255, 0.04);

  --ion-text-color: rgba(255, 255, 255, 0.92);
  --ion-text-color-rgb: 255, 255, 255;

  --ion-text-color-step-50: rgba(255, 255, 255, 0.88);
  --ion-text-color-step-100: rgba(255, 255, 255, 0.84);
  --ion-text-color-step-150: rgba(255, 255, 255, 0.80);
  --ion-text-color-step-200: rgba(255, 255, 255, 0.76);
  --ion-text-color-step-250: rgba(255, 255, 255, 0.72);
  --ion-text-color-step-300: rgba(255, 255, 255, 0.68);
  --ion-text-color-step-350: rgba(255, 255, 255, 0.64);
  --ion-text-color-step-400: rgba(255, 255, 255, 0.60);
  --ion-text-color-step-450: rgba(255, 255, 255, 0.56);
  --ion-text-color-step-500: rgba(255, 255, 255, 0.52);
  --ion-text-color-step-550: rgba(255, 255, 255, 0.48);
  --ion-text-color-step-600: rgba(255, 255, 255, 0.44);
  --ion-text-color-step-650: rgba(255, 255, 255, 0.40);
  --ion-text-color-step-700: rgba(255, 255, 255, 0.36);
  --ion-text-color-step-750: rgba(255, 255, 255, 0.32);
  --ion-text-color-step-800: rgba(255, 255, 255, 0.28);
  --ion-text-color-step-850: rgba(255, 255, 255, 0.24);
  --ion-text-color-step-900: rgba(255, 255, 255, 0.20);
  --ion-text-color-step-950: rgba(255, 255, 255, 0.16);

  --ion-background-color-step-50: #141A2E;
  --ion-background-color-step-100: #1E2439;
  --ion-background-color-step-150: #282E44;
  --ion-background-color-step-200: #32384F;
  --ion-background-color-step-250: #3C425A;
  --ion-background-color-step-300: #464C65;
  --ion-background-color-step-350: #505670;
  --ion-background-color-step-400: #5A607B;
  --ion-background-color-step-450: #646A86;
  --ion-background-color-step-500: #6E7491;
  --ion-background-color-step-550: #787E9C;
  --ion-background-color-step-600: #8288A7;
  --ion-background-color-step-650: #8C92B2;
  --ion-background-color-step-700: #969CBD;
  --ion-background-color-step-750: #A0A6C8;
  --ion-background-color-step-800: #AAB0D3;
  --ion-background-color-step-850: #B4BADE;
  --ion-background-color-step-900: #BEC4E9;
  --ion-background-color-step-950: #C8CEF4;

  --card-shadow-light: rgba(139, 92, 246, 0.06);
  --card-shadow-heavy: rgba(0, 0, 0, 0.3);
  --card-shadow-subtle: rgba(139, 92, 246, 0.08);

  --text-primary: rgba(255, 255, 255, 0.92);
  --text-secondary: rgba(255, 255, 255, 0.55);
  --text-tertiary: rgba(255, 255, 255, 0.35);
  --card-border-radius: 16px;
  --stat-card-min-width: 180px;
  --dashboard-padding: 16px;
  --dashboard-padding-lg: 32px;
  --welcome-section-bg: linear-gradient(135deg, #8B5CF6, #06B6D4);
  --welcome-section-text: #ffffff;
  --system-status-bg: var(--ion-background-color);

  --glass-bg: rgba(255, 255, 255, 0.04);
  --glass-bg-hover: rgba(255, 255, 255, 0.07);
  --glass-border: rgba(255, 255, 255, 0.08);
  --glass-border-hover: rgba(255, 255, 255, 0.14);
  --glass-blur: blur(24px);
  --gradient-primary: linear-gradient(135deg, #8B5CF6, #06B6D4);
  --gradient-primary-rgb: 139, 92, 246, 6, 182, 212;
}
```

- [ ] **Step 2: Verificar que dark.css se carga correctamente**

Run: `cd front && head -5 src/theme/variables.css`

Expected: see `@import url('./files/dark.css');`

---

### Task 2: Nueva paleta light.css

**Files:**
- Modify: `front/src/theme/files/light.css`

Actualizar la paleta light para mantener coherencia con el glassmorphism cuando el usuario elija modo claro:

- [ ] **Step 1: Reemplazar colores primarios en light.css**

Reemplazar las variables de color en `:root { ... }` con:

```css
:root {
  --ion-color-primary: #7C3AED;
  --ion-color-primary-rgb: 124, 58, 237;
  --ion-color-primary-contrast: #ffffff;
  --ion-color-primary-contrast-rgb: 255, 255, 255;
  --ion-color-primary-shade: #6D33D1;
  --ion-color-primary-tint: #894DEF;

  --ion-color-secondary: #0891B2;
  --ion-color-secondary-rgb: 8, 145, 178;
  --ion-color-secondary-contrast: #ffffff;
  --ion-color-secondary-contrast-rgb: 255, 255, 255;
  --ion-color-secondary-shade: #0780A0;
  --ion-color-secondary-tint: #219CBA;

  --ion-color-tertiary: #8B5CF6;
  --ion-color-tertiary-rgb: 139, 92, 246;
  --ion-color-tertiary-contrast: #ffffff;
  --ion-color-tertiary-contrast-rgb: 255, 255, 255;
  --ion-color-tertiary-shade: #7A52D9;
  --ion-color-tertiary-tint: #976DF7;

  --ion-color-success: #16A34A;
  --ion-color-success-rgb: 22, 163, 74;
  --ion-color-success-contrast: #ffffff;
  --ion-color-success-contrast-rgb: 255, 255, 255;
  --ion-color-success-shade: #139042;
  --ion-color-success-tint: #2DAB5C;

  --ion-color-warning: #D97706;
  --ion-color-warning-rgb: 217, 119, 6;
  --ion-color-warning-contrast: #ffffff;
  --ion-color-warning-contrast-rgb: 255, 255, 255;
  --ion-color-warning-shade: #C06B05;
  --ion-color-warning-tint: #DD151F;

  --ion-color-danger: #DC2626;
  --ion-color-danger-rgb: 220, 38, 38;
  --ion-color-danger-contrast: #ffffff;
  --ion-color-danger-contrast-rgb: 255, 255, 255;
  --ion-color-danger-shade: #C22121;
  --ion-color-danger-tint: #E03C3C;

  --ion-color-light: #F8FAFC;
  --ion-color-light-rgb: 248, 250, 252;
  --ion-color-light-contrast: #000000;
  --ion-color-light-contrast-rgb: 0, 0, 0;
  --ion-color-light-shade: #DAE0E6;
  --ion-color-light-tint: #F9FBFD;

  --ion-color-medium: #64748B;
  --ion-color-medium-rgb: 100, 116, 139;
  --ion-color-medium-contrast: #ffffff;
  --ion-color-medium-contrast-rgb: 255, 255, 255;
  --ion-color-medium-shade: #58657A;
  --ion-color-medium-tint: #748397;

  --ion-color-dark: #1E293B;
  --ion-color-dark-rgb: 30, 41, 59;
  --ion-color-dark-contrast: #ffffff;
  --ion-color-dark-contrast-rgb: 255, 255, 255;
  --ion-color-dark-shade: #1A2433;
  --ion-color-dark-tint: #343F54;

  --ion-background-color: #F1F5F9;
  --ion-background-color-rgb: 241, 245, 249;
  --background-contrast: #ffffff;

  --ion-text-color: #1E293B;
  --ion-text-color-rgb: 30, 41, 59;

  /* steps generadas para consistencia */
  --ion-text-color-step-50: #2A3449;
  --ion-text-color-step-100: #363F57;
  --ion-text-color-step-150: #424A65;
  --ion-text-color-step-200: #4E5573;
  --ion-text-color-step-250: #5A6081;
  --ion-text-color-step-300: #666B8F;
  --ion-text-color-step-350: #72769D;
  --ion-text-color-step-400: #7E81AB;
  --ion-text-color-step-450: #8A8CB9;
  --ion-text-color-step-500: #9697C7;
  --ion-text-color-step-550: #A2A2D5;
  --ion-text-color-step-600: #AEADE3;
  --ion-text-color-step-650: #BAB8F1;
  --ion-text-color-step-700: #C6C3FF;
  --ion-text-color-step-750: #D0CBFF;
  --ion-text-color-step-800: #DAD3FF;
  --ion-text-color-step-850: #E4DBFF;
  --ion-text-color-step-900: #EEE3FF;
  --ion-text-color-step-950: #F8EBFF;

  --ion-background-color-step-50: #E8ECF1;
  --ion-background-color-step-100: #DFE3E9;
  --ion-background-color-step-150: #D6DAE1;
  --ion-background-color-step-200: #CDD1D9;
  --ion-background-color-step-250: #C4C8D1;
  --ion-background-color-step-300: #BBBFC9;
  --ion-background-color-step-350: #B2B6C1;
  --ion-background-color-step-400: #A9ADB9;
  --ion-background-color-step-450: #A0A4B1;
  --ion-background-color-step-500: #979BA9;
  --ion-background-color-step-550: #8E92A1;
  --ion-background-color-step-600: #858999;
  --ion-background-color-step-650: #7C8091;
  --ion-background-color-step-700: #737789;
  --ion-background-color-step-750: #6A6E81;
  --ion-background-color-step-800: #616579;
  --ion-background-color-step-850: #585C71;
  --ion-background-color-step-900: #4F5369;
  --ion-background-color-step-950: #464A61;

  --card-shadow-light: rgba(124, 58, 237, 0.06);
  --card-shadow-heavy: rgba(0, 0, 0, 0.08);
  --card-shadow-subtle: rgba(124, 58, 237, 0.04);

  --text-primary: #1E293B;
  --text-secondary: #475569;
  --text-tertiary: #94A3B8;
  --card-border-radius: 16px;
  --stat-card-min-width: 180px;
  --dashboard-padding: 16px;
  --dashboard-padding-lg: 32px;
  --welcome-section-bg: linear-gradient(135deg, #7C3AED, #0891B2);
  --welcome-section-text: #ffffff;
  --system-status-bg: var(--background-contrast);

  --glass-bg: rgba(255, 255, 255, 0.7);
  --glass-bg-hover: rgba(255, 255, 255, 0.85);
  --glass-border: rgba(0, 0, 0, 0.08);
  --glass-border-hover: rgba(0, 0, 0, 0.14);
  --glass-blur: blur(16px);
  --gradient-primary: linear-gradient(135deg, #7C3AED, #0891B2);
  --gradient-primary-rgb: 124, 58, 237, 8, 145, 178;
}
```

---

### Task 3: Variables CSS globales — glassmorphism utilities

**Files:**
- Modify: `front/src/theme/variables.css`

Agregar las clases utilitarias glassmorphism y actualizar las existentes:

- [ ] **Step 1: Reemplazar `.card-list` y agregar `.glass-card`, `.gradient-header`**

En `variables.css`, reemplazar el bloque `.card-list` (líneas 251-258) con:

```css
.card-list {
  background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  border: 1px solid var(--glass-border);
  border-radius: var(--card-border-radius);
  overflow: hidden;
}
```

Agregar después del bloque `.card-list`:

```css
.glass-card {
  background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  border: 1px solid var(--glass-border);
  border-radius: var(--card-border-radius);
  transition: background 0.2s ease, border-color 0.2s ease;
}

.glass-card:hover {
  background: var(--glass-bg-hover);
  border-color: var(--glass-border-hover);
}

.glass-card-static {
  background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  border: 1px solid var(--glass-border);
  border-radius: var(--card-border-radius);
}

.gradient-header {
  background: linear-gradient(135deg, rgba(139, 92, 246, 0.2), rgba(6, 182, 212, 0.08));
  border-bottom: 1px solid var(--glass-border);
}
```

- [ ] **Step 2: Actualizar `ion-button.gradient-btn`**

Reemplazar el bloque `.gradient-btn` (líneas 138-159) con:

```css
ion-button.gradient-btn {
  --background: var(--gradient-primary);
  --box-shadow: 0 4px 14px rgba(139, 92, 246, 0.35);
  --border-radius: 12px;
  font-weight: 600;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

ion-button.gradient-btn:hover {
  transform: translateY(-1px);
  --box-shadow: 0 6px 20px rgba(139, 92, 246, 0.45);
}
```

- [ ] **Step 3: Actualizar `ion-item.input-item`**

Reemplazar el bloque `.input-item` (líneas 191-214) con:

```css
ion-item.input-item {
  --background: var(--glass-bg);
  --border-radius: 12px;
  --padding-start: 16px;
  --padding-end: 16px;
  --inner-padding-end: 16px;
  --min-height: 56px;
  --border-color: transparent;
  --highlight-height: 0;
  margin-bottom: 10px;
  border: 1.5px solid var(--glass-border);
  border-radius: 12px;
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

ion-item.input-item.item-has-focus {
  border-color: var(--ion-color-primary);
  box-shadow: 0 0 0 3px rgba(var(--ion-color-primary-rgb), 0.15);
}

ion-item.input-item ion-label {
  font-weight: 500;
  color: var(--text-secondary) !important;
}
```

- [ ] **Step 4: Actualizar `ion-toolbar`**

Reemplazar el bloque `ion-toolbar` (líneas 162-166) con:

```css
ion-toolbar {
  --background: var(--glass-bg);
  --border-width: 0 0 1px 0;
  --border-color: var(--glass-border);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
}
```

- [ ] **Step 5: Actualizar `ion-searchbar`**

Reemplazar el bloque `ion-searchbar` (líneas 117-122) con:

```css
ion-searchbar {
  --border-radius: 999px !important;
  --box-shadow: none !important;
  --background: var(--glass-bg) !important;
  padding: 0;
  border: 1px solid var(--glass-border);
}
```

---

### Task 4: Catppuccin themes — agregar glassmorphism vars

**Files:**
- Modify: `front/src/theme/files/catppuccin-macchiato-maroon.css`
- Modify: `front/src/theme/files/catppuccin-latte-maroon.css`

Agregar las variables glassmorphism a ambos temas catppuccin:

- [ ] **Step 1: Agregar vars glass a catppuccin-macchiato-maroon.css**

Antes del `}` de cierre, agregar:

```css
  --glass-bg: rgba(255, 255, 255, 0.04);
  --glass-bg-hover: rgba(255, 255, 255, 0.07);
  --glass-border: rgba(255, 255, 255, 0.08);
  --glass-border-hover: rgba(255, 255, 255, 0.14);
  --glass-blur: blur(24px);
  --gradient-primary: linear-gradient(135deg, #8B5CF6, #06B6D4);
  --gradient-primary-rgb: 139, 92, 246, 6, 182, 212;
```

- [ ] **Step 2: Agregar vars glass a catppuccin-latte-maroon.css**

Antes del `}` de cierre, agregar:

```css
  --glass-bg: rgba(255, 255, 255, 0.7);
  --glass-bg-hover: rgba(255, 255, 255, 0.85);
  --glass-border: rgba(0, 0, 0, 0.08);
  --glass-border-hover: rgba(0, 0, 0, 0.14);
  --glass-blur: blur(16px);
  --gradient-primary: linear-gradient(135deg, #7C3AED, #0891B2);
  --gradient-primary-rgb: 124, 58, 237, 8, 145, 178;
```

---

### Task 5: Sidebar glassmorphism — App.vue

**Files:**
- Modify: `front/src/App.vue`

Aplicar glassmorphism al sidebar completo:

- [ ] **Step 1: Actualizar CSS del sidebar-header-toolbar**

Reemplazar `.sidebar-header-toolbar` (líneas 211-214):

```css
.sidebar-header-toolbar {
  --background: var(--gradient-primary);
  --border-width: 0;
}
```

- [ ] **Step 2: Actualizar `.user-info`**

Reemplazar `.user-info` (líneas 236-243):

```css
.user-info {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 20px 16px;
  background: var(--glass-bg);
  border-bottom: 1px solid var(--glass-border);
}
```

- [ ] **Step 3: Actualizar `.nav-list ion-item.active-item`**

Reemplazar `.nav-list ion-item.active-item` y `.nav-list ion-item.active-item ion-label` (líneas 316-327):

```css
.nav-list ion-item.active-item {
  --background: rgba(139, 92, 246, 0.12);
}

.nav-list ion-item.active-item::part(native) {
  background: rgba(139, 92, 246, 0.12);
}

.nav-list ion-item.active-item ion-label {
  font-weight: 600;
  color: #A78BFA;
}
```

- [ ] **Step 4: Actualizar `.nav-icon-wrapper.active-icon` y su ion-icon**

Reemplazar (líneas 341-353):

```css
.nav-icon-wrapper.active-icon {
  background: var(--gradient-primary);
}

.nav-icon-wrapper ion-icon {
  font-size: 18px;
  color: var(--text-secondary);
  transition: color 0.2s ease;
}

.nav-icon-wrapper.active-icon ion-icon {
  color: #ffffff;
}
```

- [ ] **Step 5: Actualizar `.logout-section`**

Reemplazar (líneas 362-366):

```css
.logout-section {
  padding: 12px 16px;
  border-top: 1px solid var(--glass-border);
  margin-top: 8px;
}
```

- [ ] **Step 6: Agregar glassmorphism al ion-menu via :root global**

En `variables.css`, agregar dentro del bloque `:root { }`:

```css
  --ion-menu-background: var(--glass-bg);
```

Y agregar después de `:root { }`:

```css
ion-menu {
  --background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
}
```

---

### Task 6: AuthLoginForm — glassmorphism login

**Files:**
- Modify: `front/src/components/AuthLoginForm.vue`

- [ ] **Step 1: Actualizar hero-panel gradient**

Reemplazar `.hero-panel` (líneas 266-273):

```css
.hero-panel {
  background: linear-gradient(135deg, #8B5CF6 0%, #06B6D4 100%);
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
}
```

- [ ] **Step 2: Actualizar form-col background**

Reemplazar `.form-col` (líneas 327-332):

```css
.form-col {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #0A0F1D;
}
```

- [ ] **Step 3: Actualizar form-logo-circle**

Reemplazar `.form-logo-circle` (líneas 345-354):

```css
.form-logo-circle {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: var(--gradient-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 16px;
}
```

---

### Task 7: RegisterPage + PublicRegisterPage — glassmorphism

**Files:**
- Modify: `front/src/views/RegisterPage.vue`
- Modify: `front/src/views/PublicRegisterPage.vue`

- [ ] **Step 1: RegisterPage.vue — actualizar `.hero-panel`**

Reemplazar:

```css
.hero-panel {
  background: linear-gradient(135deg, #8B5CF6 0%, #06B6D4 100%);
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
}
```

- [ ] **Step 2: RegisterPage.vue — actualizar `.form-col`**

Reemplazar:

```css
.form-col {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #0A0F1D;
}
```

- [ ] **Step 3: RegisterPage.vue — actualizar `.form-logo-circle`**

Reemplazar:

```css
.form-logo-circle {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: var(--gradient-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 16px;
}
```

- [ ] **Step 4: PublicRegisterPage.vue — mismos 3 cambios**

Aplicar los mismos 3 cambios del Steps 1-3 a `PublicRegisterPage.vue`.

---

### Task 8: Dashboard — glassmorphism completo

**Files:**
- Modify: `front/src/views/admin/DashboardPage.vue`

- [ ] **Step 1: Actualizar `.welcome-section`**

Reemplazar `.welcome-section` (líneas 223-234):

```css
.welcome-section {
  background: linear-gradient(135deg, #8B5CF6, #06B6D4);
  border-radius: var(--card-border-radius);
  padding: 24px;
  margin-bottom: 24px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  position: relative;
  overflow: hidden;
  animation: fadeInUp 0.5s ease both;
}
```

- [ ] **Step 2: Actualizar `.stat-card`**

Reemplazar `.stat-card` (líneas 329-345):

```css
.stat-card {
  background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  border: 1px solid var(--glass-border);
  border-radius: var(--card-border-radius);
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px;
  cursor: pointer;
  transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease;
  position: relative;
  overflow: hidden;
  height: 100%;
  animation: fadeInUp 0.5s ease both;
}
```

- [ ] **Step 3: Actualizar `.stat-card:hover`**

Reemplazar (líneas 355-358):

```css
.stat-card:hover {
  transform: translateY(-2px);
  background: var(--glass-bg-hover);
  border-color: var(--glass-border-hover);
}
```

- [ ] **Step 4: Actualizar `.stat-icon` backgrounds**

Reemplazar (líneas 411-413):

```css
.users-icon { background: var(--gradient-primary); }
.sessions-icon { background: rgba(74, 222, 128, 0.2); }
.clients-icon { background: rgba(6, 182, 212, 0.2); }
```

- [ ] **Step 5: Actualizar `.status-card`**

Reemplazar `.status-card` (líneas 466-478):

```css
.status-card {
  background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  border: 1px solid var(--glass-border);
  border-radius: var(--card-border-radius);
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 12px;
  height: 100%;
  animation: fadeInUp 0.5s ease both;
}
```

---

### Task 9: Páginas de lista — glassmorphism (Users, Sessions, Clients, Roles)

**Files:**
- Modify: `front/src/views/admin/UsersPage.vue`
- Modify: `front/src/views/admin/SessionsPage.vue`
- Modify: `front/src/views/admin/ClientsPage.vue`
- Modify: `front/src/views/admin/RolesPage.vue`

- [ ] **Step 1: UsersPage.vue — actualizar `.search-card` y lista items**

Reemplazar `.search-card` — eliminar (ya no se necesita wrapper separado).

Reemplazar `.users-list ion-item` border-bottom:

```css
.users-list ion-item {
  --padding-start: 16px;
  --padding-end: 16px;
  --background: transparent;
  border-bottom: 1px solid var(--glass-border);
  transition: background 0.2s ease;
}

.users-list ion-item:hover {
  --background: var(--glass-bg-hover);
}
```

Reemplazar `.avatar-placeholder`:

```css
.avatar-placeholder {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: var(--gradient-primary);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 14px;
}
```

- [ ] **Step 2: SessionsPage.vue — actualizar filter y lista**

Reemplazar `.filter-card`:

```css
.filter-card {
  background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  border: 1px solid var(--glass-border);
  border-radius: var(--card-border-radius);
  margin-bottom: 16px;
  padding: 8px 16px;
}
```

Reemplazar `.sessions-list ion-item`:

```css
.sessions-list ion-item {
  --padding-start: 16px;
  --padding-end: 16px;
  --background: transparent;
  border-bottom: 1px solid var(--glass-border);
  transition: background 0.2s ease;
}

.sessions-list ion-item:hover {
  --background: var(--glass-bg-hover);
}
```

- [ ] **Step 3: ClientsPage.vue — actualizar lista**

Reemplazar `.clients-list ion-item`:

```css
.clients-list ion-item {
  --padding-start: 16px;
  --padding-end: 16px;
  --background: transparent;
  border-bottom: 1px solid var(--glass-border);
  transition: background 0.2s ease;
}

.clients-list ion-item:hover {
  --background: var(--glass-bg-hover);
}
```

- [ ] **Step 4: RolesPage.vue — actualizar lista**

Reemplazar `.roles-list ion-item`:

```css
.roles-list ion-item {
  --padding-start: 16px;
  --padding-end: 16px;
  --background: transparent;
  border-bottom: 1px solid var(--glass-border);
  transition: background 0.2s ease;
}

.roles-list ion-item:hover {
  --background: var(--glass-bg-hover);
}
```

---

### Task 10: UserDetailPage — glassmorphism

**Files:**
- Modify: `front/src/views/admin/UserDetailPage.vue`

- [ ] **Step 1: Actualizar `.header-card .user-header` gradient**

Reemplazar `.user-header` (líneas 593-602):

```css
.user-header {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 24px;
  background: linear-gradient(135deg, #8B5CF6, #06B6D4);
  color: white;
  position: relative;
  z-index: 1;
}
```

- [ ] **Step 2: Actualizar `.info-card`, `.roles-card`, `.sessions-card`**

Reemplazar (líneas 645-655):

```css
.info-card, .roles-card, .sessions-card {
  background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  border: 1px solid var(--glass-border);
  border-radius: var(--card-border-radius);
  padding: 16px;
  height: 100%;
}
```

- [ ] **Step 3: Actualizar `.info-item` border**

Reemplazar (líneas 677-687):

```css
.info-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 0;
  border-bottom: 1px solid var(--glass-border);
}

.info-item:last-child {
  border-bottom: none;
}
```

- [ ] **Step 4: Actualizar `.detail-list ion-item` borders**

Reemplazar (líneas 737-751):

```css
.detail-list ion-item {
  --padding-start: 16px;
  --padding-end: 16px;
  --background: transparent;
  border-bottom: 1px solid var(--glass-border);
  transition: background 0.2s ease;
}

.detail-list ion-item:hover {
  --background: var(--glass-bg-hover);
}

.detail-list ion-item:last-child {
  border-bottom: none;
}
```

---

### Task 11: ConfigPage — glassmorphism

**Files:**
- Modify: `front/src/views/admin/ConfigPage.vue`

- [ ] **Step 1: Actualizar `.config-list ion-item`**

Reemplazar (líneas 120-128):

```css
.config-list ion-item {
  --border-radius: 12px;
  --background: var(--glass-bg);
  margin-bottom: 8px;
  --padding-start: 12px;
  --padding-end: 12px;
  --min-height: 52px;
  transition: background 0.2s ease;
  border: 1px solid var(--glass-border);
}
```

- [ ] **Step 2: Actualizar `.config-list ion-item:hover` y `.active-item`**

Reemplazar (líneas 130-141):

```css
.config-list ion-item:hover:not([disabled]) {
  --background: var(--glass-bg-hover);
  border-color: var(--glass-border-hover);
}

.config-list ion-item.active-item {
  --background: rgba(139, 92, 246, 0.1);
  border-color: rgba(139, 92, 246, 0.2);
}

.config-list ion-item.active-item ion-label {
  font-weight: 600;
  color: #A78BFA;
}
```

- [ ] **Step 3: Actualizar `.config-icon-wrapper`**

Reemplazar (líneas 147-156):

```css
.config-icon-wrapper {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--gradient-primary);
  flex-shrink: 0;
}

.config-icon-wrapper ion-icon {
  font-size: 18px;
  color: #ffffff;
}
```

---

### Task 12: Verificación visual

- [ ] **Step 1: Levantar dev server y verificar visualmente**

Run: `pnpm run dev:front`

Verificar:
- Modo oscuro: fondo `#0A0F1D`, tarjetas translúcidas, gradientes púrpura→cian
- Sidebar: glass effect, item activo púrpura
- Dashboard: stat cards glass, welcome gradient
- Login: hero panel púrpura→cian, form sobre fondo oscuro
- Páginas de lista: card-list glass, items con glass borders
- Modo claro: glass sobre blanco, mismos gradientes

- [ ] **Step 2: Ejecutar lint**

Run: `cd front && pnpm run lint`

Expected: sin errores nuevos
