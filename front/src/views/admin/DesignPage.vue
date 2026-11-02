<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-back-button default-href="/admin/config"></ion-back-button>
        </ion-buttons>
        <ion-title>Personalización</ion-title>
        <ion-buttons slot="end">
          <UserMenu />
        </ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <!-- content -->
      <div class="design-container">
        <ion-grid class="design-grid">
          <ion-row>
            <ion-col size="12" size-md="7">
              <ion-list class="design-list">
                <ion-list-header class="section-header"
                  >Apariencia</ion-list-header
                >

                <ion-item button @click="openThemePopover({ event: $event })">
                  <ion-icon slot="start" :icon="colorPalette"></ion-icon>
                  <ion-label>Tema</ion-label>
                  <div slot="end" class="theme-value">
                    <div
                      class="theme-color-preview"
                      :class="{ 'dual-color': currentTheme.name === 'auto' }"
                      :style="
                        currentTheme.name === 'auto'
                          ? { background: currentTheme.color }
                          : { backgroundColor: currentTheme.color }
                      "
                    ></div>
                    <span>{{ currentTheme.label }}</span>
                    <ion-icon
                      :icon="chevronDown"
                      class="dropdown-icon"
                    ></ion-icon>
                  </div>
                </ion-item>

                <template v-if="isAuto">
                  <ion-item
                    button
                    @click="openLightThemePopover({ event: $event })"
                  >
                    <ion-icon slot="start" :icon="sunnyOutline"></ion-icon>
                    <ion-label>Cuando el sistema está en claro</ion-label>
                    <div slot="end" class="theme-value">
                      <div
                        class="theme-color-preview"
                        :style="{ backgroundColor: autoLightThemeInfo.color }"
                      ></div>
                      <span>{{ autoLightThemeInfo.label }}</span>
                      <ion-icon
                        :icon="chevronForward"
                        class="dropdown-icon"
                      ></ion-icon>
                    </div>
                  </ion-item>

                  <ion-item
                    button
                    @click="openDarkThemePopover({ event: $event })"
                  >
                    <ion-icon slot="start" :icon="moonOutline"></ion-icon>
                    <ion-label>Cuando el sistema está en oscuro</ion-label>
                    <div slot="end" class="theme-value">
                      <div
                        class="theme-color-preview"
                        :style="{ backgroundColor: autoDarkThemeInfo.color }"
                      ></div>
                      <span>{{ autoDarkThemeInfo.label }}</span>
                      <ion-icon
                        :icon="chevronForward"
                        class="dropdown-icon"
                      ></ion-icon>
                    </div>
                  </ion-item>
                </template>
              </ion-list>

                <ion-list class="design-list">
                <ion-list-header class="section-header"
                  >Logo</ion-list-header
                >

                <div class="logo-section">
                  <div
                    class="logo-preview"
                    :class="{ 'logo-preview--plain': brandingLogoUrl }"
                  >
                    <img
                      v-if="brandingLogoUrl"
                      :src="brandingLogoUrl"
                      alt="Logo actual"
                      class="logo-preview-img logo-preview-img--custom"
                    />
                    <img
                      v-else
                      :src="lambdaLogo"
                      alt="Logo por defecto"
                      class="logo-preview-img logo-preview-img--filtered"
                    />
                  </div>

                  <label class="file-input">
                    <span class="file-input-label">Imagen del logo</span>
                    <input
                      type="file"
                      accept="image/png,image/jpeg,image/svg+xml,image/webp"
                      class="file-input-control"
                      @change="onLogoFileSelected({ event: $event })"
                    />
                  </label>

                  <div class="logo-actions">
                    <ion-button
                      expand="block"
                      class="gradient-btn"
                      :disabled="!selectedLogo || uploading"
                      @click="saveLogo"
                    >
                      <ion-spinner v-if="uploading" name="lines"></ion-spinner>
                      <span v-else>Guardar logo</span>
                    </ion-button>

                    <ion-button
                      v-if="brandingLogoUrl"
                      expand="block"
                      fill="outline"
                      color="danger"
                      :disabled="uploading"
                      @click="removeLogo"
                    >
                      Quitar logo
                    </ion-button>
                  </div>
                </div>
              </ion-list>

              <ion-list class="design-list">
                <ion-list-header class="section-header"
                  >Logo del email</ion-list-header
                >

                <div class="logo-section">
                  <div
                    class="email-logo-preview"
                    :class="{ 'email-logo-preview--plain': emailLogoUrl }"
                  >
                    <img
                      v-if="emailLogoUrl"
                      :src="emailLogoUrl"
                      alt="Logo del email actual"
                      class="email-logo-preview-img"
                    />
                    <span v-else class="email-logo-preview-placeholder">
                      Sin logo para el email
                    </span>
                  </div>

                  <label class="file-input">
                    <span class="file-input-label"
                      >Imagen del logo del email</span
                    >
                    <input
                      type="file"
                      accept="image/png,image/jpeg,image/svg+xml,image/webp"
                      class="file-input-control"
                      @change="onEmailLogoFileSelected({ event: $event })"
                    />
                  </label>

                  <div class="logo-actions">
                    <ion-button
                      expand="block"
                      class="gradient-btn"
                      :disabled="!selectedEmailLogo || uploadingEmailLogo"
                      @click="saveEmailLogo"
                    >
                      <ion-spinner
                        v-if="uploadingEmailLogo"
                        name="lines"
                      ></ion-spinner>
                      <span v-else>Guardar logo del email</span>
                    </ion-button>

                    <ion-button
                      v-if="emailLogoUrl"
                      expand="block"
                      fill="outline"
                      color="danger"
                      :disabled="uploadingEmailLogo"
                      @click="removeEmailLogo"
                    >
                      Quitar logo del email
                    </ion-button>
                  </div>
                </div>
              </ion-list>
            </ion-col>

            <ion-col size="12" size-md="5">
              <div class="theme-preview">
                <h4 class="preview-title">Vista Previa</h4>
                <div class="preview-card">
                  <div class="preview-header"></div>
                  <div class="preview-body">
                    <div class="preview-stat"></div>
                    <div class="preview-stat"></div>
                    <div class="preview-stat"></div>
                  </div>
                </div>
                <p class="preview-hint">
                  El tema se aplica automáticamente al seleccionar
                </p>
              </div>
            </ion-col>
          </ion-row>
        </ion-grid>
      </div>
      <!-- end content -->

      <!-- modals -->
      <!-- end modals -->

      <!-- popovers -->
      <!-- end popovers -->

      <!-- alerts -->
      <!-- end alerts -->
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
// 1. Imports (ionic/vue/ionicons)
import { ref, onMounted } from 'vue'
import {
  IonPage,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonButtons,
  IonBackButton,
  IonButton,
  IonContent,
  IonList,
  IonListHeader,
  IonItem,
  IonIcon,
  IonLabel,
  IonGrid,
  IonRow,
  IonCol,
  IonSpinner,
  popoverController,
} from '@ionic/vue'
  import UserMenu from '@/components/user/UserMenu.vue';
import {
  colorPalette,
  chevronDown,
  chevronForward,
  sunnyOutline,
  moonOutline,
} from 'ionicons/icons'
import lambdaLogo from '@/assets/images/lambda_v1.svg'

// 2. Controllers / Composables
import {
  useThemeController,
  type ResolvedThemeName,
} from '@/controllers/themeController'
import { useBranding } from '@/composables/useBranding'
import { useEmailBranding } from '@/composables/useEmailBranding'
import { useToast } from '@/composables/useToast'
const themeController = useThemeController()
const {
  logoUrl: brandingLogoUrl,
  loadBranding,
  uploadLogo,
  removeLogo: removeBrandingLogo,
} = useBranding()
const {
  logoUrl: emailLogoUrl,
  loadEmailLogo,
  uploadEmailLogo,
  removeEmailLogo: removeEmailBrandingLogo,
} = useEmailBranding()
const { showSuccess, showError } = useToast()

// 3. Components
import ThemePopover from '@/components/ThemePopover.vue'

// 4. Interface and types imports

// 5. Main (props, emits, etc.)

// 6. Vars and const
const currentTheme = themeController.currentTheme
const isAuto = themeController.isAuto
const autoLightThemeInfo = themeController.autoLightThemeInfo
const autoDarkThemeInfo = themeController.autoDarkThemeInfo

const selectedLogo = ref<File | null>(null)
const uploading = ref(false)
const selectedEmailLogo = ref<File | null>(null)
const uploadingEmailLogo = ref(false)

const setAutoLightTheme = (name: ResolvedThemeName): void => {
  themeController.setAutoLightTheme({ themeName: name })
}
const setAutoDarkTheme = (name: ResolvedThemeName): void => {
  themeController.setAutoDarkTheme({ themeName: name })
}

// 7. Watch

// 8. Computed

// 9. OnMounted
onMounted(() => {
  void loadBranding()
  void loadEmailLogo()
})

// 10. Functions (named parameters pattern)
const openThemePopover = async (params: { event: Event }): Promise<void> => {
  const { event } = params
  const popover = await popoverController.create({
    component: ThemePopover,
    event: event,
    translucent: true,
  })
  await popover.present()
}

const openLightThemePopover = async (params: {
  event: Event
}): Promise<void> => {
  const { event } = params
  const popover = await popoverController.create({
    component: ThemePopover,
    componentProps: {
      excludeAuto: true,
      onThemeSelect: (name: ResolvedThemeName) => {
        setAutoLightTheme(name)
        popover.dismiss()
      },
    },
    event: event,
    translucent: true,
  })
  await popover.present()
}

const openDarkThemePopover = async (params: {
  event: Event
}): Promise<void> => {
  const { event } = params
  const popover = await popoverController.create({
    component: ThemePopover,
    componentProps: {
      excludeAuto: true,
      onThemeSelect: (name: ResolvedThemeName) => {
        setAutoDarkTheme(name)
        popover.dismiss()
      },
    },
    event: event,
    translucent: true,
  })
  await popover.present()
}

const onLogoFileSelected = (params: { event: Event }): void => {
  const { event } = params
  const input = event.target as HTMLInputElement
  selectedLogo.value = input.files?.[0] ?? null
}

const saveLogo = async (): Promise<void> => {
  if (!selectedLogo.value) return
  uploading.value = true
  try {
    await uploadLogo(selectedLogo.value)
    selectedLogo.value = null
    showSuccess({ message: 'Logo actualizado correctamente' })
  } catch (err: unknown) {
    showError({
      message: err instanceof Error ? err.message : 'Error al subir el logo',
    })
  } finally {
    uploading.value = false
  }
}

const removeLogo = async (): Promise<void> => {
  uploading.value = true
  try {
    await removeBrandingLogo()
    showSuccess({ message: 'Logo eliminado correctamente' })
  } catch (err: unknown) {
    showError({
      message: err instanceof Error ? err.message : 'Error al quitar el logo',
    })
  } finally {
    uploading.value = false
  }
}

const onEmailLogoFileSelected = (params: { event: Event }): void => {
  const { event } = params
  const input = event.target as HTMLInputElement
  selectedEmailLogo.value = input.files?.[0] ?? null
}

const saveEmailLogo = async (): Promise<void> => {
  if (!selectedEmailLogo.value) return
  uploadingEmailLogo.value = true
  try {
    await uploadEmailLogo(selectedEmailLogo.value)
    selectedEmailLogo.value = null
    showSuccess({ message: 'Logo del email actualizado correctamente' })
  } catch (err: unknown) {
    showError({
      message:
        err instanceof Error ? err.message : 'Error al subir el logo del email',
    })
  } finally {
    uploadingEmailLogo.value = false
  }
}

const removeEmailLogo = async (): Promise<void> => {
  uploadingEmailLogo.value = true
  try {
    await removeEmailBrandingLogo()
    showSuccess({ message: 'Logo del email eliminado correctamente' })
  } catch (err: unknown) {
    showError({
      message:
        err instanceof Error ? err.message : 'Error al quitar el logo del email',
    })
  } finally {
    uploadingEmailLogo.value = false
  }
}
</script>

<style scoped>
.design-container {
  padding: clamp(12px, 3vw, 16px);
  max-width: 900px;
  margin: 0 auto;
}

.design-grid {
  padding: 0;
  --ion-grid-padding: 0;
}

.design-grid ion-col {
  padding: 0 0 24px 0;
}

.design-list {
  background: transparent;
}

.section-header {
  font-size: clamp(12px, 2.5vw, 13px);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--text-tertiary);
  padding-left: 0;
}

.design-list ion-item {
  --border-radius: 4px;
  --background: var(--background-contrast);
  --padding-start: clamp(10px, 2.5vw, 12px);
  --padding-end: clamp(10px, 2.5vw, 12px);
  margin-bottom: var(--space-2, 8px);
}

.design-list ion-item:active {
  --background: var(--ion-color-light);
}

.design-list ion-icon {
  color: var(--text-secondary);
}

.design-list ion-label {
  color: var(--text-primary);
}

.theme-value {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--text-secondary);
  font-size: clamp(13px, 3vw, 14px);
}

.theme-color-preview {
  width: 16px;
  height: 16px;
  border-radius: 50%;
}

.theme-color-preview.dual-color {
  background: linear-gradient(
    135deg,
    var(--ion-color-primary) 50%,
    var(--ion-color-secondary, #4d91ff) 50%
  );
}

.dropdown-icon {
  font-size: 16px;
  color: var(--text-tertiary);
}

.theme-preview {
  position: sticky;
  top: 16px;
}

.preview-title {
  margin: 0 0 16px 0;
  font-size: 14px;
  font-weight: 600;
  color: var(--text-primary);
}

.preview-card {
  background: var(--background-contrast);
  border-radius: 4px;
  overflow: hidden;
  box-shadow:
    var(--card-shadow-light) 0px 1px 3px 0px,
    var(--card-shadow-heavy) 0px 0px 0px 1px;
}

.preview-header {
  height: 48px;
  background: var(--ion-color-primary);
}

.preview-body {
  padding: 16px;
  display: flex;
  gap: 12px;
}

.preview-stat {
  flex: 1;
  height: 60px;
  background: var(--ion-color-light);
  border-radius: 4px;
}

.preview-hint {
  margin: 12px 0 0 0;
  font-size: 12px;
  color: var(--text-tertiary);
  text-align: center;
}

.logo-section {
  background: var(--background-contrast);
  border-radius: 4px;
  padding: 16px;
  margin-bottom: var(--space-2, 8px);
}

.logo-preview {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: var(--gradient-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 16px;
}

.logo-preview--plain {
  width: auto;
  height: auto;
  background: transparent;
}

.logo-preview-img {
  max-width: 32px;
  height: auto;
}

.logo-preview-img--custom {
  max-width: 96px;
  max-height: 96px;
}

.logo-preview-img--filtered {
  filter: brightness(0) invert(1);
}

.email-logo-preview {
  max-width: 280px;
  margin: 0 auto 16px;
  padding: 12px;
  border-radius: 4px;
  border: 1px dashed var(--text-tertiary);
  display: flex;
  align-items: center;
  justify-content: center;
}

.email-logo-preview--plain {
  border-style: solid;
}

.email-logo-preview-img {
  max-width: 100%;
  max-height: 48px;
  width: auto;
  height: auto;
  object-fit: contain;
}

.email-logo-preview-placeholder {
  font-size: 12px;
  color: var(--text-tertiary);
  text-align: center;
}

.file-input {
  display: block;
}

.file-input-label {
  display: block;
  font-size: 12px;
  color: var(--text-tertiary);
  margin-bottom: 8px;
}

.file-input-control {
  width: 100%;
  font-size: 13px;
  color: var(--text-secondary);
}

.logo-actions {
  margin-top: 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

@media (min-width: 768px) {
  .design-container {
    padding: clamp(16px, 3vw, 24px);
  }

  .design-grid ion-col {
    padding: 0 0 0 24px;
  }

  .design-grid ion-col:first-child {
    padding-left: 0;
  }
}
</style>
