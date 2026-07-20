# Login + Dashboard Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the rejected split-layout login with a centered card + animated blobs background, and replace the generic dashboard with a Vercel/Railway-style resource grid.

**Architecture:** Two independent CSS redesigns. Login: full-screen dark background with 3 animated gradient blobs and a centered glassmorphism card. Dashboard: header with stat chips + 6 clickable resource cards in a responsive grid (Vercel/Railway style). Both are pure CSS/template changes — no business logic modifications.

**Tech Stack:** Ionic Vue 8, CSS (scoped), ionicons, existing glass CSS variables from `variables.css` / `dark.css`.

---

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `front/src/components/AuthLoginForm.vue` | Modify | Replace split layout with centered card + animated blobs |
| `front/src/views/RegisterPage.vue` | Modify | Same blob background + centered card pattern |
| `front/src/views/PublicRegisterPage.vue` | Modify | Same blob background + centered card pattern |
| `front/src/views/admin/DashboardPage.vue` | Modify | Replace welcome+stats+status with Vercel/Railway grid |
| `front/src/components/SkeletonStatCard.vue` | Modify | Match new dashboard card skeleton |

---

### Task 1: AuthLoginForm — Centered Card with Animated Blobs

**Files:**
- Modify: `front/src/components/AuthLoginForm.vue` (full template + style rewrite)

- [ ] **Step 1: Replace the template with centered card layout**

Replace the entire `<template>` block with:

```html
<template>
  <div class="auth-screen">
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>

    <div class="auth-card">
      <div class="auth-card-header">
        <div class="auth-logo-circle">
          <img alt="Logo SSO Server" src="@/assets/images/lambda_v1.svg" class="auth-logo" />
        </div>
        <h2>Iniciar Sesión</h2>
        <p>Accede a tu cuenta de SSO Server</p>
      </div>

      <form @submit.prevent="handleLogin">
        <ion-item class="input-item" lines="none">
          <ion-input
            v-model="credentials.user"
            label-placement="floating"
            label="Usuario / Email"
            type="text"
            required
            placeholder="Ingresa tu usuario"
          />
        </ion-item>

        <ion-item class="input-item" lines="none">
          <ion-input
            v-model="credentials.password"
            label="Contraseña"
            label-placement="floating"
            type="password"
            required
            placeholder="Ingresa tu contraseña"
          >
            <ion-input-password-toggle slot="end" />
          </ion-input>
        </ion-item>

        <div v-if="error" class="error-message" role="alert" aria-live="assertive">
          <ion-icon :icon="alertCircle" size="small"></ion-icon>
          {{ error }}
        </div>

        <div class="ion-padding-top">
          <ion-button
            type="submit"
            expand="block"
            class="gradient-btn"
            :disabled="loading"
          >
            <ion-spinner v-if="loading" name="lines"></ion-spinner>
            <span v-else>Ingresar</span>
          </ion-button>
        </div>
      </form>

      <div class="auth-card-footer">
        <ion-button
          v-if="oauthParams.redirect_uri"
          fill="clear"
          expand="block"
          :disabled="loading"
          class="subtle"
          @click="goToRegister"
        >
          Crear cuenta
        </ion-button>

        <ion-button
          fill="clear"
          expand="block"
          :disabled="loading"
          class="subtle"
          @click="goBack"
        >
          <ion-icon slot="start" :icon="arrowBack"></ion-icon>
          Volver
        </ion-button>
      </div>
    </div>
  </div>
</template>
```

- [ ] **Step 2: Remove unused Ionic grid imports from script**

In the `<script setup>` block, replace the Ionic imports:

```ts
import {
  IonItem,
  IonInput,
  IonButton,
  IonSpinner,
  IonIcon,
  IonInputPasswordToggle,
} from '@ionic/vue';
```

(Remove `IonGrid`, `IonRow`, `IonCol` — they are no longer used in the template.)

- [ ] **Step 3: Replace the entire `<style scoped>` block**

```css
<style scoped>
.auth-screen {
  min-height: 100dvh;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
  background: var(--ion-background-color, #0A0F1D);
  padding: 24px;
}

.blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
  opacity: 0.5;
  pointer-events: none;
  will-change: transform;
}

.blob-1 {
  width: 420px;
  height: 420px;
  background: #8B5CF6;
  top: -10%;
  left: -5%;
  animation: blob-float-1 18s ease-in-out infinite;
}

.blob-2 {
  width: 350px;
  height: 350px;
  background: #06B6D4;
  bottom: -10%;
  right: -5%;
  animation: blob-float-2 22s ease-in-out infinite;
}

.blob-3 {
  width: 280px;
  height: 280px;
  background: #A78BFA;
  top: 50%;
  left: 60%;
  animation: blob-float-3 20s ease-in-out infinite;
}

@keyframes blob-float-1 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33% { transform: translate(60px, 40px) scale(1.08); }
  66% { transform: translate(-30px, 70px) scale(0.95); }
}

@keyframes blob-float-2 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33% { transform: translate(-50px, -60px) scale(1.1); }
  66% { transform: translate(40px, -30px) scale(0.92); }
}

@keyframes blob-float-3 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33% { transform: translate(30px, -50px) scale(1.05); }
  66% { transform: translate(-60px, 20px) scale(0.97); }
}

.auth-card {
  position: relative;
  z-index: 1;
  width: 100%;
  max-width: 400px;
  padding: 40px 32px;
  background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  border: 1px solid var(--glass-border);
  border-radius: 20px;
  animation: card-appear 0.6s ease both;
}

@keyframes card-appear {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.auth-card-header {
  text-align: center;
  margin-bottom: 32px;
}

.auth-logo-circle {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: var(--gradient-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 16px;
  box-shadow: 0 4px 20px rgba(var(--gradient-primary-rgb), 0.3);
}

.auth-logo {
  max-width: 32px;
  height: auto;
  filter: brightness(0) invert(1);
}

.auth-card-header h2 {
  font-family: var(--font-heading);
  font-size: 24px;
  font-weight: 700;
  color: var(--text-primary);
  margin: 0 0 6px 0;
}

.auth-card-header p {
  font-size: 14px;
  color: var(--text-tertiary);
  margin: 0;
}

.error-message {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--ion-color-danger);
  font-size: 13px;
  padding: 8px 0;
}

.auth-card-footer {
  margin-top: 8px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}

@media (max-width: 374px) {
  .auth-card {
    padding: 24px 16px;
  }

  .auth-card-header h2 {
    font-size: 20px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .blob {
    animation: none;
  }

  .auth-card {
    animation: none;
  }
}
</style>
```

- [ ] **Step 4: Verify the dev server renders login correctly**

Run: `cd front && pnpm run build 2>&1 | tail -20`

Expected: Build succeeds (or only pre-existing TS errors, no new errors from this change).

---

### Task 2: RegisterPage — Centered Card with Animated Blobs

**Files:**
- Modify: `front/src/views/RegisterPage.vue` (full template + style rewrite)

- [ ] **Step 1: Replace the template with centered card layout**

Replace the entire `<template>` block with:

```html
<template>
  <ion-page>
    <ion-content :fullscreen="true">
      <div class="auth-screen">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>

        <div class="auth-card">
          <div class="auth-card-header">
            <div class="auth-logo-circle">
              <img alt="Logo SSO Server" src="@/assets/images/lambda_v1.svg" class="auth-logo" />
            </div>
            <h2>Crear Cuenta</h2>
            <p>Regístrate para acceder</p>
          </div>

          <form @submit.prevent="handleRegister">
            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.name"
                label="Nombre"
                label-placement="floating"
                type="text"
                required
                placeholder="Ingresa tu nombre"
              />
            </ion-item>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.user"
                label="Usuario"
                label-placement="floating"
                type="text"
                required
                placeholder="Ingresa tu usuario"
              />
            </ion-item>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.email"
                label="Email"
                label-placement="floating"
                type="email"
                required
                placeholder="Ingresa tu email"
                @ion-blur="validateEmail"
              />
            </ion-item>
            <div v-if="emailError" class="field-error">{{ emailError }}</div>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.password"
                label="Contraseña"
                label-placement="floating"
                type="password"
                required
                placeholder="Ingresa tu contraseña"
              />
            </ion-item>

            <div v-if="form.password.length > 0" class="strength-bar-container">
              <div
                class="strength-bar"
                :style="{ width: passwordStrength.percent + '%' }"
                :class="'strength-' + passwordStrength.level"
              ></div>
              <span class="strength-label">{{ passwordStrength.label }}</span>
            </div>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.password_confirmation"
                label="Confirmar contraseña"
                label-placement="floating"
                type="password"
                required
                placeholder="Confirma tu contraseña"
                @ion-blur="validatePasswordMatch"
              />
            </ion-item>
            <div v-if="passwordConfirmError" class="field-error">{{ passwordConfirmError }}</div>

            <div v-if="error" class="error-message" role="alert" aria-live="assertive">
              <ion-icon :icon="alertCircle" size="small"></ion-icon>
              {{ error }}
            </div>

            <div class="ion-padding-top">
              <ion-button
                type="submit"
                expand="block"
                class="gradient-btn"
                :disabled="loading"
              >
                <ion-spinner v-if="loading" name="lines"></ion-spinner>
                <span v-else>Registrar</span>
              </ion-button>
            </div>
          </form>

          <div class="auth-card-footer">
            <ion-button fill="clear" expand="block" class="subtle" @click="goToLogin">
              Ya tengo cuenta
            </ion-button>
            <ion-button fill="clear" expand="block" class="subtle" @click="goBack">
              <ion-icon slot="start" :icon="arrowBack"></ion-icon>
              Volver
            </ion-button>
          </div>
        </div>
      </div>
    </ion-content>
  </ion-page>
</template>
```

- [ ] **Step 2: Remove unused Ionic grid imports from script**

Replace the Ionic imports in `<script setup>`:

```ts
import {
  IonPage,
  IonContent,
  IonItem,
  IonInput,
  IonButton,
  IonSpinner,
  IonIcon,
  loadingController,
} from '@ionic/vue';
```

(Remove `IonGrid`, `IonRow`, `IonCol`.)

- [ ] **Step 3: Replace the entire `<style scoped>` block**

```css
<style scoped>
.auth-screen {
  min-height: 100dvh;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
  background: var(--ion-background-color, #0A0F1D);
  padding: 24px;
}

.blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
  opacity: 0.5;
  pointer-events: none;
  will-change: transform;
}

.blob-1 {
  width: 420px;
  height: 420px;
  background: #8B5CF6;
  top: -10%;
  left: -5%;
  animation: blob-float-1 18s ease-in-out infinite;
}

.blob-2 {
  width: 350px;
  height: 350px;
  background: #06B6D4;
  bottom: -10%;
  right: -5%;
  animation: blob-float-2 22s ease-in-out infinite;
}

.blob-3 {
  width: 280px;
  height: 280px;
  background: #A78BFA;
  top: 50%;
  left: 60%;
  animation: blob-float-3 20s ease-in-out infinite;
}

@keyframes blob-float-1 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33% { transform: translate(60px, 40px) scale(1.08); }
  66% { transform: translate(-30px, 70px) scale(0.95); }
}

@keyframes blob-float-2 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33% { transform: translate(-50px, -60px) scale(1.1); }
  66% { transform: translate(40px, -30px) scale(0.92); }
}

@keyframes blob-float-3 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33% { transform: translate(30px, -50px) scale(1.05); }
  66% { transform: translate(-60px, 20px) scale(0.97); }
}

.auth-card {
  position: relative;
  z-index: 1;
  width: 100%;
  max-width: 400px;
  padding: 40px 32px;
  background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  border: 1px solid var(--glass-border);
  border-radius: 20px;
  animation: card-appear 0.6s ease both;
}

@keyframes card-appear {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.auth-card-header {
  text-align: center;
  margin-bottom: 32px;
}

.auth-logo-circle {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: var(--gradient-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 16px;
  box-shadow: 0 4px 20px rgba(var(--gradient-primary-rgb), 0.3);
}

.auth-logo {
  max-width: 32px;
  height: auto;
  filter: brightness(0) invert(1);
}

.auth-card-header h2 {
  font-family: var(--font-heading);
  font-size: 24px;
  font-weight: 700;
  color: var(--text-primary);
  margin: 0 0 6px 0;
}

.auth-card-header p {
  font-size: 14px;
  color: var(--text-tertiary);
  margin: 0;
}

.error-message {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--ion-color-danger);
  font-size: 13px;
  padding: 8px 0;
}

.field-error {
  color: var(--ion-color-danger);
  font-size: 12px;
  padding: 0 0 8px 0;
}

.strength-bar-container {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 0 8px;
}

.strength-bar {
  height: 4px;
  border-radius: 999px;
  transition: all 0.3s ease;
  flex: 1;
  max-width: 100px;
}

.strength-bar.strength-weak { background: var(--ion-color-danger); }
.strength-bar.strength-medium { background: var(--ion-color-warning); }
.strength-bar.strength-strong { background: var(--ion-color-success); }
.strength-bar.strength-very-strong { background: var(--ion-color-primary); }

.strength-label {
  font-size: 11px;
  color: var(--text-tertiary);
  white-space: nowrap;
}

.auth-card-footer {
  margin-top: 8px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}

@media (max-width: 374px) {
  .auth-card {
    padding: 24px 16px;
  }

  .auth-card-header h2 {
    font-size: 20px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .blob {
    animation: none;
  }

  .auth-card {
    animation: none;
  }
}
</style>
```

- [ ] **Step 4: Verify build**

Run: `cd front && pnpm run build 2>&1 | tail -20`

Expected: No new errors from this change.

---

### Task 3: PublicRegisterPage — Centered Card with Animated Blobs

**Files:**
- Modify: `front/src/views/PublicRegisterPage.vue` (full template + style rewrite)

- [ ] **Step 1: Replace the template with centered card layout**

Same template as Task 2 Step 1 — identical structure with `PublicRegisterPage.vue`'s existing form fields and functions.

```html
<template>
  <ion-page>
    <ion-content :fullscreen="true">
      <div class="auth-screen">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>

        <div class="auth-card">
          <div class="auth-card-header">
            <div class="auth-logo-circle">
              <img alt="Logo SSO Server" src="@/assets/images/lambda_v1.svg" class="auth-logo" />
            </div>
            <h2>Crear Cuenta</h2>
            <p>Regístrate para acceder</p>
          </div>

          <form @submit.prevent="handleRegister">
            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.name"
                label="Nombre"
                label-placement="floating"
                type="text"
                required
                placeholder="Ingresa tu nombre"
              />
            </ion-item>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.user"
                label="Usuario"
                label-placement="floating"
                type="text"
                required
                placeholder="Ingresa tu usuario"
              />
            </ion-item>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.email"
                label="Email"
                label-placement="floating"
                type="email"
                required
                placeholder="Ingresa tu email"
                @ion-blur="validateEmail"
              />
            </ion-item>
            <div v-if="emailError" class="field-error">{{ emailError }}</div>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.password"
                label="Contraseña"
                label-placement="floating"
                type="password"
                required
                placeholder="Ingresa tu contraseña"
              />
            </ion-item>

            <div v-if="form.password.length > 0" class="strength-bar-container">
              <div
                class="strength-bar"
                :style="{ width: passwordStrength.percent + '%' }"
                :class="'strength-' + passwordStrength.level"
              ></div>
              <span class="strength-label">{{ passwordStrength.label }}</span>
            </div>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.password_confirmation"
                label="Confirmar contraseña"
                label-placement="floating"
                type="password"
                required
                placeholder="Confirma tu contraseña"
                @ion-blur="validatePasswordMatch"
              />
            </ion-item>
            <div v-if="passwordConfirmError" class="field-error">{{ passwordConfirmError }}</div>

            <div v-if="error" class="error-message" role="alert" aria-live="assertive">
              <ion-icon :icon="alertCircle" size="small"></ion-icon>
              {{ error }}
            </div>

            <div class="ion-padding-top">
              <ion-button
                type="submit"
                expand="block"
                class="gradient-btn"
                :disabled="loading"
              >
                <ion-spinner v-if="loading" name="lines"></ion-spinner>
                <span v-else>Registrar</span>
              </ion-button>
            </div>
          </form>

          <div class="auth-card-footer">
            <ion-button fill="clear" expand="block" class="subtle" @click="goToLogin">
              Ya tengo cuenta
            </ion-button>
            <ion-button fill="clear" expand="block" class="subtle" @click="goBack">
              <ion-icon slot="start" :icon="arrowBack"></ion-icon>
              Volver
            </ion-button>
          </div>
        </div>
      </div>
    </ion-content>
  </ion-page>
</template>
```

- [ ] **Step 2: Remove unused Ionic grid imports from script**

Replace the Ionic imports:

```ts
import {
  IonPage,
  IonContent,
  IonItem,
  IonInput,
  IonButton,
  IonSpinner,
  IonIcon,
  loadingController,
} from '@ionic/vue';
```

(Remove `IonGrid`, `IonRow`, `IonCol`.)

- [ ] **Step 3: Replace the entire `<style scoped>` block**

Identical CSS to Task 2 Step 3 — same `.auth-screen`, `.blob`, `.auth-card`, etc. classes.

```css
<style scoped>
.auth-screen {
  min-height: 100dvh;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
  background: var(--ion-background-color, #0A0F1D);
  padding: 24px;
}

.blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
  opacity: 0.5;
  pointer-events: none;
  will-change: transform;
}

.blob-1 {
  width: 420px;
  height: 420px;
  background: #8B5CF6;
  top: -10%;
  left: -5%;
  animation: blob-float-1 18s ease-in-out infinite;
}

.blob-2 {
  width: 350px;
  height: 350px;
  background: #06B6D4;
  bottom: -10%;
  right: -5%;
  animation: blob-float-2 22s ease-in-out infinite;
}

.blob-3 {
  width: 280px;
  height: 280px;
  background: #A78BFA;
  top: 50%;
  left: 60%;
  animation: blob-float-3 20s ease-in-out infinite;
}

@keyframes blob-float-1 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33% { transform: translate(60px, 40px) scale(1.08); }
  66% { transform: translate(-30px, 70px) scale(0.95); }
}

@keyframes blob-float-2 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33% { transform: translate(-50px, -60px) scale(1.1); }
  66% { transform: translate(40px, -30px) scale(0.92); }
}

@keyframes blob-float-3 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33% { transform: translate(30px, -50px) scale(1.05); }
  66% { transform: translate(-60px, 20px) scale(0.97); }
}

.auth-card {
  position: relative;
  z-index: 1;
  width: 100%;
  max-width: 400px;
  padding: 40px 32px;
  background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  border: 1px solid var(--glass-border);
  border-radius: 20px;
  animation: card-appear 0.6s ease both;
}

@keyframes card-appear {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.auth-card-header {
  text-align: center;
  margin-bottom: 32px;
}

.auth-logo-circle {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: var(--gradient-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 16px;
  box-shadow: 0 4px 20px rgba(var(--gradient-primary-rgb), 0.3);
}

.auth-logo {
  max-width: 32px;
  height: auto;
  filter: brightness(0) invert(1);
}

.auth-card-header h2 {
  font-family: var(--font-heading);
  font-size: 24px;
  font-weight: 700;
  color: var(--text-primary);
  margin: 0 0 6px 0;
}

.auth-card-header p {
  font-size: 14px;
  color: var(--text-tertiary);
  margin: 0;
}

.error-message {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--ion-color-danger);
  font-size: 13px;
  padding: 8px 0;
}

.field-error {
  color: var(--ion-color-danger);
  font-size: 12px;
  padding: 0 0 8px 0;
}

.strength-bar-container {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 0 8px;
}

.strength-bar {
  height: 4px;
  border-radius: 999px;
  transition: all 0.3s ease;
  flex: 1;
  max-width: 100px;
}

.strength-bar.strength-weak { background: var(--ion-color-danger); }
.strength-bar.strength-medium { background: var(--ion-color-warning); }
.strength-bar.strength-strong { background: var(--ion-color-success); }
.strength-bar.strength-very-strong { background: var(--ion-color-primary); }

.strength-label {
  font-size: 11px;
  color: var(--text-tertiary);
  white-space: nowrap;
}

.auth-card-footer {
  margin-top: 8px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}

@media (max-width: 374px) {
  .auth-card {
    padding: 24px 16px;
  }

  .auth-card-header h2 {
    font-size: 20px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .blob {
    animation: none;
  }

  .auth-card {
    animation: none;
  }
}
</style>
```

- [ ] **Step 4: Verify build**

Run: `cd front && pnpm run build 2>&1 | tail -20`

Expected: No new errors from this change.

---

### Task 4: DashboardPage — Vercel/Railway Style Resource Grid

**Files:**
- Modify: `front/src/views/admin/DashboardPage.vue` (full template + style rewrite)
- Modify: `front/src/components/SkeletonStatCard.vue` (match new card skeleton)

- [ ] **Step 1: Replace the DashboardPage template**

Replace the entire `<template>` block with:

```html
<template>
  <ion-page>
    <ion-header class="ion-no-border dashboard-header" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-menu-button aria-label="Menú de navegación"></ion-menu-button>
        </ion-buttons>
        <ion-title>Dashboard SSO</ion-title>
        <div slot="end" class="header-chips">
          <div class="chip chip-primary">
            <ion-icon :icon="people" size="small"></ion-icon>
            <span>{{ stats.totalUsers }}</span>
          </div>
          <div class="chip chip-success">
            <ion-icon :icon="key" size="small"></ion-icon>
            <span>{{ stats.activeSessions }}</span>
          </div>
          <div class="chip chip-secondary">
            <ion-icon :icon="desktop" size="small"></ion-icon>
            <span>{{ stats.totalClients }}</span>
          </div>
        </div>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <ion-progress-bar v-if="loading" type="indeterminate"></ion-progress-bar>

      <div class="dashboard-container">
        <div v-if="isLoading" class="resource-grid">
          <div v-for="n in 6" :key="n" class="resource-card-skeleton">
            <div class="skeleton-icon"></div>
            <div class="skeleton-lines">
              <div class="skeleton-line skeleton-line-title"></div>
              <div class="skeleton-line skeleton-line-desc"></div>
            </div>
          </div>
        </div>

        <div v-else class="resource-grid">
          <div class="resource-card" @click="router.push({ name: 'AdminUsers' })">
            <div class="resource-icon-wrapper icon-primary">
              <ion-icon :icon="people"></ion-icon>
            </div>
            <div class="resource-info">
              <span class="resource-title">Usuarios</span>
              <span class="resource-desc">{{ stats.totalUsers }} registrados</span>
            </div>
            <div class="resource-arrow">
              <ion-icon :icon="chevronForward"></ion-icon>
            </div>
          </div>

          <div class="resource-card" @click="router.push({ name: 'AdminSessions' })">
            <div class="resource-icon-wrapper icon-success">
              <ion-icon :icon="key"></ion-icon>
            </div>
            <div class="resource-info">
              <span class="resource-title">Sesiones</span>
              <span class="resource-desc">{{ stats.activeSessions }} activas</span>
            </div>
            <div class="resource-arrow">
              <ion-icon :icon="chevronForward"></ion-icon>
            </div>
          </div>

          <div class="resource-card" @click="router.push({ name: 'AdminClients' })">
            <div class="resource-icon-wrapper icon-secondary">
              <ion-icon :icon="desktop"></ion-icon>
            </div>
            <div class="resource-info">
              <span class="resource-title">Clientes SSO</span>
              <span class="resource-desc">{{ stats.totalClients }} configurados</span>
            </div>
            <div class="resource-arrow">
              <ion-icon :icon="chevronForward"></ion-icon>
            </div>
          </div>

          <div class="resource-card" @click="router.push({ name: 'AdminRoles' })">
            <div class="resource-icon-wrapper icon-warning">
              <ion-icon :icon="shield"></ion-icon>
            </div>
            <div class="resource-info">
              <span class="resource-title">Roles</span>
              <span class="resource-desc">Permisos y accesos</span>
            </div>
            <div class="resource-arrow">
              <ion-icon :icon="chevronForward"></ion-icon>
            </div>
          </div>

          <div class="resource-card" @click="router.push({ name: 'AdminConfig' })">
            <div class="resource-icon-wrapper icon-tertiary">
              <ion-icon :icon="settings"></ion-icon>
            </div>
            <div class="resource-info">
              <span class="resource-title">Configuración</span>
              <span class="resource-desc">Ajustes del sistema</span>
            </div>
            <div class="resource-arrow">
              <ion-icon :icon="chevronForward"></ion-icon>
            </div>
          </div>

          <div class="resource-card resource-card-status">
            <div class="resource-icon-wrapper icon-success">
              <ion-icon :icon="checkmarkCircle"></ion-icon>
            </div>
            <div class="resource-info">
              <span class="resource-title">Estado del Sistema</span>
              <span class="resource-desc">Todos los servicios operativos</span>
            </div>
            <div class="status-dots">
              <div class="status-dot online" title="API"></div>
              <div class="status-dot online" title="DB"></div>
              <div class="status-dot online" title="SSO"></div>
            </div>
          </div>
        </div>
      </div>
    </ion-content>
  </ion-page>
</template>
```

- [ ] **Step 2: Update the script — add `settings` and `checkmarkCircle` imports, remove unused imports**

Replace the icon imports:

```ts
import { people, key, desktop, shield, chevronForward, settings, checkmarkCircle } from 'ionicons/icons';
```

Remove the `SkeletonStatCard` import and the `IonGrid`, `IonRow`, `IonCol` imports since they are no longer used. The updated Ionic imports:

```ts
import {
  IonPage,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonButtons,
  IonMenuButton,
  IonContent,
  IonIcon,
  IonProgressBar,
} from '@ionic/vue';
```

Remove the `SkeletonStatCard` import line entirely:

```ts
// DELETE this line:
import SkeletonStatCard from '@/components/SkeletonStatCard.vue';
```

- [ ] **Step 3: Replace the entire `<style scoped>` block**

```css
<style scoped>
.dashboard-container {
  padding: var(--dashboard-padding);
  max-width: 1400px;
  margin: 0 auto;
}

@media (min-width: 768px) {
  .dashboard-container {
    padding: var(--dashboard-padding-lg);
  }
}

.header-chips {
  display: none;
  align-items: center;
  gap: 8px;
  padding-right: 12px;
}

@media (min-width: 768px) {
  .header-chips {
    display: flex;
  }
}

.chip {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  border: 1px solid var(--glass-border);
  background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
}

.chip ion-icon {
  font-size: 14px;
}

.chip-primary {
  color: var(--ion-color-primary);
  border-color: rgba(var(--ion-color-primary-rgb), 0.2);
}

.chip-success {
  color: var(--ion-color-success);
  border-color: rgba(var(--ion-color-success-rgb), 0.2);
}

.chip-secondary {
  color: var(--ion-color-secondary);
  border-color: rgba(var(--ion-color-secondary-rgb), 0.2);
}

.resource-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
  padding: 0;
}

@media (min-width: 576px) {
  .resource-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (min-width: 992px) {
  .resource-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

.resource-card {
  background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  border: 1px solid var(--glass-border);
  border-radius: 14px;
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 14px;
  cursor: pointer;
  transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease;
  animation: fadeInUp 0.5s ease both;
}

.resource-card:nth-child(1) { animation-delay: 0s; }
.resource-card:nth-child(2) { animation-delay: 0.05s; }
.resource-card:nth-child(3) { animation-delay: 0.1s; }
.resource-card:nth-child(4) { animation-delay: 0.15s; }
.resource-card:nth-child(5) { animation-delay: 0.2s; }
.resource-card:nth-child(6) { animation-delay: 0.25s; }

.resource-card:hover {
  transform: translateY(-2px);
  background: var(--glass-bg-hover);
  border-color: var(--glass-border-hover);
}

@media (prefers-reduced-motion: reduce) {
  .resource-card:hover {
    transform: none;
  }

  .resource-card {
    animation: none;
  }
}

.resource-icon-wrapper {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.resource-icon-wrapper ion-icon {
  font-size: 22px;
  color: #fff;
}

.icon-primary {
  background: var(--gradient-primary);
}

.icon-success {
  background: rgba(74, 222, 128, 0.2);
}

.icon-success ion-icon {
  color: var(--ion-color-success);
}

.icon-secondary {
  background: rgba(6, 182, 212, 0.2);
}

.icon-secondary ion-icon {
  color: var(--ion-color-secondary);
}

.icon-warning {
  background: rgba(245, 158, 11, 0.2);
}

.icon-warning ion-icon {
  color: var(--ion-color-warning);
}

.icon-tertiary {
  background: rgba(167, 139, 250, 0.2);
}

.icon-tertiary ion-icon {
  color: var(--ion-color-tertiary);
}

.resource-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.resource-title {
  font-family: var(--font-heading);
  font-size: 15px;
  font-weight: 600;
  color: var(--text-primary);
  line-height: 1.3;
}

.resource-desc {
  font-size: 13px;
  color: var(--text-secondary);
  margin-top: 2px;
}

.resource-arrow {
  flex-shrink: 0;
  color: var(--text-tertiary);
  display: flex;
  align-items: center;
  transition: transform 0.2s ease, color 0.2s ease;
}

.resource-arrow ion-icon {
  font-size: 18px;
}

.resource-card:hover .resource-arrow {
  transform: translateX(2px);
  color: var(--text-secondary);
}

.resource-card-status {
  cursor: default;
}

.resource-card-status:hover {
  transform: none;
  background: var(--glass-bg);
  border-color: var(--glass-border);
}

.status-dots {
  display: flex;
  gap: 6px;
  align-items: center;
  flex-shrink: 0;
}

.status-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}

.status-dot.online {
  background: var(--ion-color-success);
  box-shadow: 0 0 8px var(--ion-color-success);
}

.status-dot.offline {
  background: var(--ion-color-danger);
  box-shadow: 0 0 8px var(--ion-color-danger);
}

.resource-card-skeleton {
  background: var(--glass-bg);
  border: 1px solid var(--glass-border);
  border-radius: 14px;
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 14px;
}

.skeleton-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: var(--ion-background-color-step-100);
  animation: skeleton-pulse 1.5s ease-in-out infinite;
}

.skeleton-lines {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.skeleton-line {
  height: 12px;
  border-radius: 4px;
  background: var(--ion-background-color-step-100);
  animation: skeleton-pulse 1.5s ease-in-out infinite;
}

.skeleton-line-title {
  width: 80px;
}

.skeleton-line-desc {
  width: 120px;
  height: 10px;
}

@keyframes skeleton-pulse {
  0%, 100% { opacity: 0.4; }
  50% { opacity: 0.7; }
}

@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(16px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@media (max-width: 374px) {
  .dashboard-container {
    padding: 8px;
  }

  .resource-card {
    padding: 16px;
    gap: 10px;
  }

  .resource-icon-wrapper {
    width: 38px;
    height: 38px;
  }

  .resource-icon-wrapper ion-icon {
    font-size: 18px;
  }

  .resource-title {
    font-size: 14px;
  }

  .resource-desc {
    font-size: 12px;
  }
}
</style>
```

- [ ] **Step 4: Verify build**

Run: `cd front && pnpm run build 2>&1 | tail -20`

Expected: No new errors from this change.

---

### Task 5: SkeletonStatCard — Update to match new dashboard style

**Files:**
- Modify: `front/src/components/SkeletonStatCard.vue`

- [ ] **Step 1: Replace SkeletonStatCard template and style**

The `SkeletonStatCard` component is no longer imported in DashboardPage (replaced with inline skeleton), but it may be used elsewhere. Update it to match the new card style for consistency:

```html
<template>
  <div class="resource-card-skeleton">
    <div class="skeleton-icon"></div>
    <div class="skeleton-lines">
      <div class="skeleton-line skeleton-line-title"></div>
      <div class="skeleton-line skeleton-line-desc"></div>
    </div>
  </div>
</template>

<script setup lang="ts">
</script>

<style scoped>
.resource-card-skeleton {
  background: var(--glass-bg);
  border: 1px solid var(--glass-border);
  border-radius: 14px;
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 14px;
}

.skeleton-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: var(--ion-background-color-step-100);
  animation: skeleton-pulse 1.5s ease-in-out infinite;
}

.skeleton-lines {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.skeleton-line {
  height: 12px;
  border-radius: 4px;
  background: var(--ion-background-color-step-100);
  animation: skeleton-pulse 1.5s ease-in-out infinite;
}

.skeleton-line-title {
  width: 80px;
}

.skeleton-line-desc {
  width: 120px;
  height: 10px;
}

@keyframes skeleton-pulse {
  0%, 100% { opacity: 0.4; }
  50% { opacity: 0.7; }
}
</style>
```

- [ ] **Step 2: Verify build**

Run: `cd front && pnpm run build 2>&1 | tail -20`

Expected: No new errors from this change.

---

### Task 6: Final verification

- [ ] **Step 1: Run full frontend build**

Run: `cd front && pnpm run build 2>&1 | tail -30`

Expected: Build succeeds (or only pre-existing TS errors, zero new errors from these changes).

- [ ] **Step 2: Run linter**

Run: `cd front && pnpm run lint 2>&1 | tail -20`

Expected: Only pre-existing lint issues, no new ones from these changes.

- [ ] **Step 3: Visual check — start dev server and verify in browser**

Run: `cd front && pnpm run dev`

Manually verify:
1. `/login` — centered card with animated blobs background
2. `/register` — centered card with animated blobs background
3. `/auth/login` — same centered card (isPublic=true)
4. `/auth/register` — same centered card
5. `/admin/dashboard` — Vercel/Railway grid with stat chips in header
