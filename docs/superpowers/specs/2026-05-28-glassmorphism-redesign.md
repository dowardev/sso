# Glassmorphism Redesign — SSO Server Frontend

## Resumen

Rediseño visual completo del frontend usando estética **glassmorphism** con paleta púrpura→cian sobre fondo oscuro profundo, inspirado en Auth0 y Clerk. Modo oscuro como experiencia principal.

## Paleta de Color

| Rol | Color | Uso |
|-----|-------|-----|
| Gradient primary | `linear-gradient(135deg, #8B5CF6, #06B6D4)` | Botones, headers, iconos activos, badges, acentos |
| Purple | `#8B5CF6` | Elementos hover, backgrounds activos |
| Cyan | `#06B6D4` | Acentos secundarios, links |
| Purple medium | `#A78BFA` | Texto secundario sobre glass |
| Fondo base | `#0A0F1D` | Background principal (modo oscuro) |
| Glass card | `rgba(255,255,255,0.04)` | Tarjetas con `backdrop-filter: blur(24px)` |
| Glass border | `rgba(255,255,255,0.08)` | Borde sutil de tarjetas |
| Glass hover | `rgba(255,255,255,0.08)` | Hover de tarjetas/elementos |
| Text primary | `rgba(255,255,255,0.92)` | Títulos |
| Text secondary | `rgba(255,255,255,0.55)` | Subtítulos, labels |
| Text muted | `rgba(255,255,255,0.35)` | Metadata, timestamps |
| Green success | `#4ADE80` | Trends positivos, activo |
| Amber warning | `#F59E0B` | Trends neutros, pending |
| Red danger | `#EF4444` | Trends negativos, errores |

### Light mode

Se mantiene pero simplificado: fondo `#F8FAFC`, tarjetas con glass sutil sobre blanco. El modo oscuro es el principal.

## Arquitectura de Cambios

### 1. CSS Global — `variables.css`

Agregar clases utilitarias glassmorphism:

```css
/* Glass card base */
.glass-card {
  background: rgba(255, 255, 255, 0.04);
  backdrop-filter: blur(24px);
  -webkit-backdrop-filter: blur(24px);
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 16px;
}

.glass-card:hover {
  background: rgba(255, 255, 255, 0.07);
  border-color: rgba(255, 255, 255, 0.12);
}

/* Gradient button */
.gradient-btn {
  background: linear-gradient(135deg, #8B5CF6, #06B6D4);
  border: none;
  color: #fff;
}

.gradient-btn:hover {
  opacity: 0.9;
  transform: translateY(-1px);
}

/* Gradient header */
.gradient-header {
  background: linear-gradient(135deg, rgba(139, 92, 246, 0.3), rgba(6, 182, 212, 0.1));
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}
```

### 2. Themes — `dark.css`

Servir como tema base. `light.css` mantiene compatibilidad pero simplificado. Catppuccin se actualiza con las mismas variables glass.

### 3. Sidebar — `App.vue`

- Fondo glass con blur (`rgba(255,255,255,0.04)` + `backdrop-filter`)
- Logo en header con gradient bg + texto blanco
- Item activo: `rgba(139,92,246,0.15)` + texto `#A78BFA`
- Items inactivos: texto `rgba(255,255,255,0.55)`
- Hover items: `rgba(255,255,255,0.06)`
- Botón logout: outline con hover danger

### 4. Login/Registro — `AuthLoginForm.vue`

- Mantener split layout existente
- Panel hero: gradient `#8B5CF6 → #06B6D4` con círculos decorativos
- Panel formulario: fondo `#0A0F1D` con glass card para el formulario
- Inputs: glass effect con borde sutil
- Botón submit: gradient

### 5. Dashboard — `DashboardPage.vue`

```html
<!-- Estructura propuesta -->
<ion-content>
  <div class="page-container">
    <!-- Welcome header: gradient sutil + texto -->
    <div class="gradient-header glass-card welcome-section">
      <h1>Bienvenido, Admin</h1>
      <p>Resumen del sistema</p>
    </div>

    <!-- Stat cards: glass grid -->
    <div class="stat-grid">
      <div class="glass-card stat-card">
        <div class="stat-icon">👤</div>
        <div class="stat-content">
          <span class="stat-label">Usuarios</span>
          <span class="stat-value">1,234</span>
          <span class="stat-trend positive">↑ 12%</span>
        </div>
      </div>
      <!-- ... -->
    </div>

    <!-- Status cards: glass list -->
    <div class="glass-card">
      <div class="list-item">Sistema activo</div>
    </div>
  </div>
</ion-content>
```

- NO más `ion-card` para stat cards — usar `glass-card` divs
- Stat grid: 4 columnas en desktop, 2 en tablet, 1 en mobile
- Icono cuadrado con gradient bg
- Número grande (32px, bold), label pequeño (12px, uppercase, muted)
- Trend indicator: positivo (verde), neutro (ambar), negativo (rojo)

### 6. Páginas de Lista — Users, Sessions, Clients, Roles, Config

- Wrapper `.page-container` con fondo base
- Header de página con título + botón de acción (gradient)
- Lista de items como `.glass-card` con items dentro
- Empty state centrado con texto muted
- Refresher: estilo acorde

### 7. UserDetailPage

- Header: gradient con información del usuario + avatar con gradient bg
- Info items: con iconos (mail, calendar, key) sobre glass
- Roles: ion-chip adaptados
- Acciones: botones gradient

### 8. Modales

- Mantener `.gradient-btn` en save/confirm
- Modal: fondo glass, border-radius consistente

## Archivos a Modificar

| Archivo | Cambio |
|---------|--------|
| `front/src/theme/variables.css` | + glass-card, gradient-btn, gradient-header, stat-grid utils |
| `front/src/theme/files/dark.css` | Nueva paleta como tema base |
| `front/src/theme/files/light.css` | Simplificar, mantener compat |
| `front/src/theme/files/catppuccin-latte-maroon.css` | Actualizar vars |
| `front/src/theme/files/catppuccin-macchiato-maroon.css` | Actualizar vars |
| `front/src/App.vue` | Sidebar: glass, logo, hover states |
| `front/src/components/AuthLoginForm.vue` | Hero gradient + glass form + dark bg |
| `front/src/views/RegisterPage.vue` | Glass form |
| `front/src/views/PublicRegisterPage.vue` | Glass form |
| `front/src/views/admin/DashboardPage.vue` | Glass stats, gradient welcome, status cards |
| `front/src/views/admin/UsersPage.vue` | Glass items, unified list |
| `front/src/views/admin/SessionsPage.vue` | Glass items, unified list |
| `front/src/views/admin/ClientsPage.vue` | Glass items, unified list |
| `front/src/views/admin/RolesPage.vue` | Glass items, unified list |
| `front/src/views/admin/UserDetailPage.vue` | Glass elements |
| `front/src/views/admin/ConfigPage.vue` | Glass elements |

## No Incluye (fuera de scope)

- No cambiar lógica de negocio, stores, controllers, servicios
- No arreglar errores TS preexistentes (7 errores)
- No modificar backend
- No agregar nuevas funcionalidades

## Criterios de Éxito

1. Dashboard se ve moderno y premium (comparación visual con Auth0/Clerk)
2. Glassmorphism consistente en todas las páginas
3. Modo oscuro se ve bien como experiencia principal
4. Modo claro funcional y limpio
5. Sin regresiones visuales (nada roto)
6. Build exitoso (si se resuelven errores TS)
