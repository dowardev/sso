<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-back-button default-href="/admin/clients" aria-label="Volver a clientes"></ion-back-button>
        </ion-buttons>
        <ion-title>Detalle de Cliente</ion-title>
        <ion-buttons slot="end">
          <UserMenu />
          <ion-button color="primary" aria-label="Editar cliente" @click="openEditModal">
            <ion-icon slot="icon-only" :icon="create"></ion-icon>
          </ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <div class="page-container-wide">
        <div v-if="loading" class="loading-container" role="status" aria-live="polite">
          <ion-spinner name="lines"></ion-spinner>
          <p>Cargando cliente...</p>
        </div>

        <ion-grid v-else-if="client" class="client-detail">
          <ion-row>
            <ion-col size="12">
              <div class="header-card">
                <div class="header-bg-circle hc-1"></div>
                <div class="header-bg-circle hc-2"></div>
                <div class="client-header">
                  <ion-icon :icon="desktopOutline" class="client-icon"></ion-icon>
                  <h2 class="client-name">{{ client.name }}</h2>
                  <p class="client-id-display">{{ client.client_id }}</p>
                  <ion-badge :color="client.active ? 'success' : 'medium'">
                    {{ client.active ? 'Activo' : 'Inactivo' }}
                  </ion-badge>
                </div>
              </div>
            </ion-col>

            <ion-col size="12" size-md="6">
              <ion-card class="info-card">
                <ion-card-header class="info-card-header">
                  <ion-card-title class="section-title">Información</ion-card-title>
                </ion-card-header>

                <ion-list class="info-list">
                  <ion-item
                    class="info-item"
                  >
                    <ion-icon slot="start" :icon="linkOutline" class="info-icon" aria-hidden="true"></ion-icon>
                    <ion-label class="value">{{ client.redirect_url || 'No configurado' }}</ion-label>
                  </ion-item>

                  <ion-item
                    class="info-item"
                  >
                    <ion-icon slot="start" :icon="calendarOutline" class="info-icon" aria-hidden="true"></ion-icon>
                    <ion-label class="value">{{ formatDate({ dateStr: client.created_at }) }}</ion-label>
                  </ion-item>

                  <ion-item
                    class="info-item"
                  >
                    <ion-icon slot="start" :icon="keyOutline" class="info-icon" aria-hidden="true"></ion-icon>
                    <ion-label class="value">{{ client.grant_types.join(', ') }}</ion-label>
                  </ion-item>

                  <ion-item
                    v-if="client.allowed_origins && client.allowed_origins.length > 0"
                    class="info-item"
                  >
                    <ion-icon slot="start" :icon="globeOutline" class="info-icon" aria-hidden="true"></ion-icon>
                    <div class="origins-list">
                      <ion-chip
                        v-for="(origin, index) in client.allowed_origins"
                        :key="index"
                        class="origin-tag"
                      >
                        {{ origin }}
                      </ion-chip>
                    </div>
                  </ion-item>
                </ion-list>
              </ion-card>
            </ion-col>

            <ion-col size="12" size-md="6">
              <ion-card class="stats-card">
                <ion-card-header class="info-card-header">
                  <ion-card-title class="section-title">Estadísticas</ion-card-title>
                </ion-card-header>

                <ion-list class="info-list">
                  <ion-item
                    class="info-item"
                  >
                    <ion-icon slot="start" :icon="peopleOutline" class="info-icon" aria-hidden="true"></ion-icon>
                    <ion-label class="value">{{ client.sessions_count || 0 }} sesiones</ion-label>
                  </ion-item>
                </ion-list>
              </ion-card>
            </ion-col>

            <ion-col size="12">
              <ClientAuthorizationsList :client-id="client.id" />
            </ion-col>
          </ion-row>
        </ion-grid>
      </div>

      <ion-modal :is-open="showEditModal" @did-dismiss="showEditModal = false">
        <ion-header class="ion-no-border" mode="ios">
          <ion-toolbar>
            <ion-buttons slot="start">
              <ion-button @click="showEditModal = false">
                <ion-icon :icon="closeOutline"></ion-icon>
              </ion-button>
            </ion-buttons>
            <ion-title>Editar Cliente</ion-title>
            <ion-buttons slot="end">
              <ion-button @click="updateClient">
                <ion-icon :icon="saveOutline"></ion-icon>
              </ion-button>
            </ion-buttons>
          </ion-toolbar>
        </ion-header>
        <ion-content>
          <form class="modal-form" @submit.prevent="updateClient">
            <ion-list>
              <ion-item>
                <ion-label position="stacked">Nombre *</ion-label>
                <ion-input v-model="clientForm.name" :class="ionicValidationClasses(clientV$.name)" @ion-blur="clientV$.name.$touch()"></ion-input>
              </ion-item>
              <div v-if="clientV$.name.$error" class="field-error">{{ clientV$.name.$errors[0].$message }}</div>
              <ion-item>
                <ion-label position="stacked">Client ID *</ion-label>
                <ion-input v-model="clientForm.client_id" :class="ionicValidationClasses(clientV$.client_id)" @ion-blur="clientV$.client_id.$touch()"></ion-input>
              </ion-item>
              <div v-if="clientV$.client_id.$error" class="field-error">{{ clientV$.client_id.$errors[0].$message }}</div>
              <ion-item>
                <ion-label position="stacked">Client Secret</ion-label>
                <div class="secret-input-row">
                  <ion-input
                    v-model="clientForm.client_secret"
                    placeholder="Opcional"
                    type="password"
                    :class="ionicValidationClasses(clientV$.client_secret)"
                    @ion-blur="clientV$.client_secret.$touch()"
                  >
                    <ion-input-password-toggle slot="end" />
                  </ion-input>
                  <ion-button
                    fill="clear"
                    size="small"
                    aria-label="Copiar secret"
                    @click="copySecret"
                  >
                    <ion-icon slot="icon-only" :icon="copyOutline"></ion-icon>
                  </ion-button>
                  <ion-button
                    fill="clear"
                    size="small"
                    aria-label="Generar secret"
                    @click="generateSecret"
                  >
                    <ion-icon slot="icon-only" :icon="keyOutline"></ion-icon>
                  </ion-button>
                </div>
              </ion-item>
              <div v-if="clientV$.client_secret.$error" class="field-error">{{ clientV$.client_secret.$errors[0].$message }}</div>
              <ion-item>
                <ion-label position="stacked">Redirect URL</ion-label>
                  <ion-input
                    v-model="clientForm.redirect_url"
                    type="url"
                    placeholder="https://"
                    :class="ionicValidationClasses(clientV$.redirect_url)"
                    @ion-blur="clientV$.redirect_url.$touch()"
                  ></ion-input>
                  <ion-note
                    v-if="securityController.requireSsl.value"
                    color="warning"
                    class="ssl-note"
                  >
                    Solo se permiten URLs con HTTPS (excepto hosts excluidos)
                  </ion-note>
                </ion-item>
                <div v-if="clientV$.redirect_url.$error" class="field-error">{{ clientV$.redirect_url.$errors[0].$message }}</div>
              <ion-item>
                <ion-label position="stacked">Orígenes Permitidos</ion-label>
                <div class="origins-input-row">
                  <ion-input
                    v-model="newOriginInput"
                    type="url"
                    placeholder="https://ejemplo.com"
                    @keydown.enter.prevent="addOrigin"
                  ></ion-input>
                  <ion-button fill="clear" size="small" aria-label="Agregar origen" @click="addOrigin">
                    <ion-icon slot="icon-only" :icon="add"></ion-icon>
                  </ion-button>
                </div>
              </ion-item>
              <ion-item
                v-if="clientForm.allowed_origins.length"
                class="origins-chips"
              >
                <div class="chips-container">
                  <ion-chip
                    v-for="(origin, index) in clientForm.allowed_origins"
                    :key="index"
                    @click="removeOrigin({ index })"
                  >
                    <ion-label>{{ origin }}</ion-label>
                    <ion-icon :icon="close" color="danger"></ion-icon>
                  </ion-chip>
                </div>
              </ion-item>
          <ion-item>
            <ion-label>Activo</ion-label>
            <ion-toggle
              slot="end"
              :checked="clientForm.active"
              @ion-change="clientForm.active = !clientForm.active"
            ></ion-toggle>
          </ion-item>
          <ion-item>
            <ion-label position="stacked">Grant Types *</ion-label>
          </ion-item>
          <ion-item>
            <ion-label>authorization_code</ion-label>
            <ion-toggle
              slot="end"
              :checked="clientForm.grant_types.includes('authorization_code')"
              @ion-change="toggleGrantType('authorization_code')"
            ></ion-toggle>
          </ion-item>
          <ion-item>
            <ion-label>client_credentials</ion-label>
            <ion-toggle
              slot="end"
              :checked="clientForm.grant_types.includes('client_credentials')"
              @ion-change="toggleGrantType('client_credentials')"
            ></ion-toggle>
          </ion-item>
            </ion-list>
            </form>
        </ion-content>
      </ion-modal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  // 1. Imports (ionic/vue/ionicons)
  import { ionicValidationClasses } from '@/utils/ionicValidation';
  import { ref, onMounted, computed } from 'vue';
  import { useRoute } from 'vue-router';
  import {
    IonPage,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonButtons,
    IonBackButton,
    IonButton,
    IonIcon,
    IonContent,
    IonBadge,
    IonList,
    IonItem,
    IonLabel,
    IonModal,
    IonInput,
    IonToggle,
    IonChip,
    IonInputPasswordToggle,
    IonNote,
    IonGrid,
    IonRow,
    IonCol,
    IonSpinner,
    IonCard,
    IonCardHeader,
    IonCardTitle,
  } from '@ionic/vue';
  import UserMenu from '@/components/user/UserMenu.vue';
  import {
    create,
    desktopOutline,
    close,
    keyOutline,
    copyOutline,
    linkOutline,
    calendarOutline,
    globeOutline,
    peopleOutline,
    add,
    closeOutline,
    saveOutline,
  } from 'ionicons/icons';
  import { useFormValidation } from '@/composables/useFormValidation';
  import { useValidation, validators } from '@/composables/useValidation';
  import { useToast } from '@/composables/useToast';

  const { handleApiError } = useValidation();
  const v = validators;
  const { showSuccess, showError } = useToast();

  // 2. Controllers
  import { useClientsController } from '@/controllers/clientsController';
  import { useSecurityController } from '@/controllers/securityController';

  const clientsController = useClientsController();
  const securityController = useSecurityController();

  // 3. Components
  import ClientAuthorizationsList from '@/components/ClientAuthorizationsList.vue';

  // 4. Interface and types imports
  // (none)

  // 5. Main (props, emits, etc.)
  const route = useRoute();
  const clientId = Number(route.params.id);

  // 6. Vars and const
  const loading = ref(false);
  const showEditModal = ref(false);
  const saving = ref(false);

  const clientForm = ref({
    name: '',
    client_id: '',
    client_secret: '',
    redirect_url: '',
    allowed_origins: [] as string[],
    grant_types: ['authorization_code'] as string[],
    active: true,
  });

  const newOriginInput = ref('');

  // 7. Watch
  // (none)

  // 8. Computed
  const client = computed(() => clientsController.selectedClient.value);

  const clientRules = computed(() => ({
    name: { required: v.required('Nombre') },
    client_id: {
      required: v.required('Client ID'),
      minLength: v.minLength('Client ID', 3),
      maxLength: v.maxLength('Client ID', 255),
      alphaNum: v.alphaNum('Client ID'),
    },
    client_secret: {
      minLength: v.minLength('Client Secret', 10),
      maxLength: v.maxLength('Client Secret', 255),
    },
    redirect_url: {
      maxLength: v.maxLength('Redirect URL', 255),
      url: v.url('Redirect URL'),
    },
    allowed_origins: {},
    grant_types: {
      required: v.required('Grant Types'),
    },
    active: {},
  }));

  const { v$: clientV$, validate: validateClient, touchAll: touchClient } =
    useFormValidation(clientRules, clientForm);

  // 9. OnMounted
  onMounted(async () => {
    await loadClient();
  });

  // 10. Functions
  const loadClient = async (): Promise<void> => {
    try {
      loading.value = true;
      const response = await clientsController.fetchClient(clientId);
      clientForm.value = {
        name: response.name,
        client_id: response.client_id,
        client_secret: response.client_secret || '',
        redirect_url: response.redirect_url || '',
        allowed_origins: response.allowed_origins || [],
        grant_types: response.grant_types || ['authorization_code'],
        active: response.active,
      };
    } catch {
      showError({ message: 'Error al cargar cliente' });
    } finally {
      loading.value = false;
    }
  };

  const formatDate = (params: { dateStr: string }): string => {
    const { dateStr } = params;
    return new Date(dateStr).toLocaleDateString('es-ES', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  const openEditModal = (): void => {
    showEditModal.value = true;
  };

  const updateClient = async (): Promise<void> => {
    const isValid = await validateClient();
    if (!isValid) {
      touchClient();
      return;
    }

    saving.value = true;
    try {
      const data = {
        name: clientForm.value.name,
        client_id: clientForm.value.client_id,
        client_secret: clientForm.value.client_secret || undefined,
        redirect_url: clientForm.value.redirect_url || undefined,
        allowed_origins: clientForm.value.allowed_origins.length
          ? clientForm.value.allowed_origins
          : undefined,
        grant_types: clientForm.value.grant_types,
        active: clientForm.value.active,
      };

      await clientsController.updateClient({
        id: clientId,
        data,
      });
      showEditModal.value = false;
      showSuccess({ message: 'Cliente actualizado correctamente' });
      await loadClient();
    } catch (err) {
      // LBDS-267: this screen has no inline error block, so the caller owns the toast.
      showError({ message: handleApiError(err) });
    } finally {
      saving.value = false;
    }
  };

  const addOrigin = (): void => {
    const input = newOriginInput.value.trim();
    if (!input) {
      newOriginInput.value = '';
      return;
    }

    let urlToCheck = input;
    if (
      !urlToCheck.startsWith('http://') &&
      !urlToCheck.startsWith('https://')
    ) {
      urlToCheck = 'https://' + urlToCheck;
    }

    try {
      const parsed = new URL(urlToCheck);
      if (parsed.pathname !== '/' || parsed.search || parsed.hash) {
        newOriginInput.value = '';
        return;
      }
      const hasDefaultPort =
        (parsed.protocol === 'https:' && parsed.port === '443') ||
        (parsed.protocol === 'http:' && parsed.port === '80');
      const normalized = hasDefaultPort
        ? `${parsed.protocol}//${parsed.hostname}`
        : `${parsed.protocol}//${parsed.host}`;
      if (clientForm.value.allowed_origins.includes(normalized)) {
        newOriginInput.value = '';
        return;
      }
      clientForm.value.allowed_origins.push(normalized);
    } catch {
      // Invalid URL - ignore silently
    }
    newOriginInput.value = '';
  };

  const removeOrigin = (params: { index: number }): void => {
    const { index } = params;
    clientForm.value.allowed_origins.splice(index, 1);
  };

  const generateSecret = (): void => {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let secret = '';
    for (let i = 0; i < 40; i++) {
      secret += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    clientForm.value.client_secret = secret;
  };

  const copySecret = async (): Promise<void> => {
    if (!clientForm.value.client_secret) return;
    try {
      await navigator.clipboard.writeText(clientForm.value.client_secret);
      showSuccess({ message: 'Secret copiado al portapapeles' });
    } catch {
      showError({ message: 'No se pudo copiar el secret' });
    }
  };

  const toggleGrantType = (grantType: string): void => {
    const index = clientForm.value.grant_types.indexOf(grantType);
    if (index === -1) {
      clientForm.value.grant_types.push(grantType);
    } else {
      clientForm.value.grant_types.splice(index, 1);
    }
  };
</script>

<style scoped>
.loading-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 300px;
  color: var(--text-tertiary);
}

.loading-container p {
  margin-top: 12px;
  font-size: 14px;
}

.header-card {
  border-radius: var(--card-border-radius);
  margin-bottom: 16px;
  overflow: hidden;
  position: relative;
}

.header-bg-circle {
  position: absolute;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.1);
}

.hc-1 {
  width: 200px;
  height: 200px;
  top: -60px;
  right: -40px;
}

.hc-2 {
  width: 120px;
  height: 120px;
  bottom: -30px;
  left: -20px;
}

.client-header {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: clamp(16px, 4vw, 24px);
  background: var(--gradient-primary);
  color: white;
  position: relative;
  z-index: 1;
}

.client-icon {
  font-size: 48px;
  margin-bottom: 12px;
}

.client-name {
  margin: 0;
  font-size: clamp(20px, 5vw, 22px);
  font-weight: 700;
  color: white;
}

.client-id-display {
  margin: 4px 0 8px 0;
  opacity: 0.85;
  font-size: clamp(13px, 3vw, 14px);
  font-family: 'SF Mono', 'Monaco', 'Inconsolata', 'Fira Code', monospace;
}

.client-detail {
  padding: 0;
  --ion-grid-padding: 0;
}

.client-detail ion-col {
  padding: 0 0 16px 0;
}

.info-card,
.stats-card {
  background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  border: 1px solid var(--glass-border);
  border-radius: var(--card-border-radius);
  box-shadow: none;
  margin: 0;
  padding: clamp(12px, 3vw, 16px);
  height: 100%;
}

.info-card-header {
  padding: 0;
}

/* The list sits on the glass card surface: no opaque native background. */
.info-list {
  --ion-item-background: transparent;
  background: transparent;
  margin: 0;
  padding: 0;
}

.section-title {
  margin: 0 0 16px 0;
  font-size: clamp(11px, 2.5vw, 12px);
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  color: var(--text-tertiary);
}

.info-item {
  --border-width: 0;
  --inner-border-width: 0;
  --background: transparent;
  --ion-item-background: transparent;
  --min-height: 44px;
  --inner-padding-top: 8px;
  --inner-padding-bottom: 8px;
  --inner-padding-start: 10px;
  --inner-padding-end: 0;
  --padding-start: 0;
  --padding-end: 0;
  border-bottom: 1px solid var(--glass-border);
}

.info-item ion-label {
  --padding-start: 0;
  --padding-end: 0;
  padding: 0;
}

.info-item:last-child {
  border-bottom: none;
}

.info-icon {
  font-size: 18px;
  color: var(--text-tertiary);
  flex-shrink: 0;
}

.info-item .value {
  color: var(--text-primary);
  font-size: clamp(13px, 3vw, 14px);
}

.origins-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 4px;
}

.origin-tag {
  --background: var(--ion-color-light);
  --color: var(--text-secondary);
  font-size: 12px;
  height: 20px;
  --padding-top: 2px;
  --padding-bottom: 2px;
  --padding-start: 8px;
  --padding-end: 8px;
  border-radius: 4px;
}

@media (min-width: 768px) {
  .info-card,
  .stats-card {
    padding: clamp(16px, 3vw, 24px);
  }
}

@media (max-width: 374px) {
  .page-container-wide {
    padding: var(--space-2, 8px);
  }
}
</style>