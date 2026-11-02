<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-menu-button aria-label="Menú de navegación"></ion-menu-button>
        </ion-buttons>
        <ion-title>Clientes SSO</ion-title>
        <ion-buttons slot="end">
          <UserMenu />
            <ion-button aria-label="Crear cliente" @click="openCreateModal">
              <ion-icon slot="icon-only" :icon="add" aria-hidden="true"></ion-icon>
            </ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <ion-refresher slot="fixed" @ion-refresh="handleRefresh($event)">
        <ion-refresher-content pulling-text="Tira para actualizar..." refreshing-text="Actualizando..."></ion-refresher-content>
      </ion-refresher>
      <!-- content -->
      <div class="page-container">
        <div class="card-list">
          <div v-if="loading" class="skeleton-container" role="status" aria-live="polite" aria-label="Cargando clientes">
            <SkeletonListItem v-for="n in 4" :key="n" />
          </div>

          <div v-else-if="clients.length === 0" class="empty-state">
            <ion-icon :icon="desktopOutline" size="large" aria-hidden="true"></ion-icon>
            <p>No hay clientes SSO</p>
            <ion-button class="gradient-btn" size="small" @click="openCreateModal">Crear Cliente</ion-button>
          </div>

           <ion-list v-else class="clients-list">
            <ion-item-sliding v-for="client in clients" :key="client.id">
              <ion-item @click="goToClientDetail({ clientId: client.id })">
                <ion-icon
                  slot="start"
                  :icon="desktopOutline"
                  :color="client.active ? 'primary' : 'medium'"
                  aria-hidden="true"
                ></ion-icon>
                <ion-label>
                  <h2 class="client-name">{{ client.name }}</h2>
                  <p class="client-id">
                    <span class="id-label">ID:</span>
                    <code>{{ client.client_id }}</code>
                  </p>
                  <p v-if="client.redirect_url" class="redirect-url">
                    {{ client.redirect_url }}
                  </p>
                </ion-label>
                <div slot="end" class="client-actions">
                  <ion-badge
                    :color="client.active ? 'success' : 'medium'"
                  >
                    {{ client.active ? 'Activo' : 'Inactivo' }}
                  </ion-badge>
                  <ion-icon
                    v-if="hasSslViolation({ redirectUrl: client.redirect_url })"
                    :icon="warningOutline"
                    color="warning"
                    class="ssl-warning-icon"
                  ></ion-icon>
                  <ion-button
                    fill="clear"
                    color="primary"
                    size="small"
                    aria-label="Editar cliente"
                    @click.stop="openEditModal({ client })"
                  >
                    <ion-icon slot="icon-only" :icon="create" aria-hidden="true"></ion-icon>
                  </ion-button>
                  <ion-button
                    fill="clear"
                    color="danger"
                    size="small"
                    aria-label="Eliminar cliente"
                    @click.stop="confirmDelete({ client })"
                  >
                  <ion-icon slot="icon-only" :icon="trash" aria-hidden="true"></ion-icon>
                  </ion-button>
                </div>
              </ion-item>

              <ion-item-options side="end">
                <ion-item-option
                  color="danger"
                  aria-label="Eliminar cliente"
                  @click="confirmDelete({ client })"
                >
                  <ion-icon slot="icon-only" :icon="trash"></ion-icon>
                </ion-item-option>
              </ion-item-options>
            </ion-item-sliding>
          </ion-list>
        </div>
      </div>
      <!-- end content -->

      <!-- modals -->
      <ion-modal :is-open="showModal" @did-dismiss="showModal = false">
        <ion-header class="ion-no-border" mode="ios">
          <ion-toolbar>
            <ion-buttons slot="start">
              <ion-button @click="showModal = false">
                <ion-icon :icon="closeOutline"></ion-icon>
              </ion-button>
            </ion-buttons>
            <ion-title
              >{{ editingClient ? 'Editar' : 'Crear' }} Cliente</ion-title
            >
            <ion-buttons slot="end">
              <ion-button @click="saveClient">
                <ion-icon :icon="saveOutline"></ion-icon>
              </ion-button>
            </ion-buttons>
          </ion-toolbar>
        </ion-header>
        <ion-content>
          <form class="modal-form" @submit.prevent="saveClient">
            <ion-list>
              <ion-item>
                <ion-label position="stacked">Nombre *</ion-label>
                <ion-input v-model="clientForm.name" aria-describedby="client-name-error" :aria-invalid="clientV$.name.$dirty ? clientV$.name.$invalid : undefined" @ion-blur="clientV$.name.$touch()"></ion-input>
              </ion-item>
              <div v-if="clientV$.name.$error" id="client-name-error" class="field-error">{{ clientV$.name.$errors[0].$message }}</div>
              <ion-item>
                <ion-label position="stacked">Client ID *</ion-label>
                <ion-input v-model="clientForm.client_id" aria-describedby="client-client-id-error" :aria-invalid="clientV$.client_id.$dirty ? clientV$.client_id.$invalid : undefined" @ion-blur="clientV$.client_id.$touch()"></ion-input>
              </ion-item>
              <div v-if="clientV$.client_id.$error" id="client-client-id-error" class="field-error">{{ clientV$.client_id.$errors[0].$message }}</div>
          <ion-item>
            <ion-label position="stacked">Client Secret</ion-label>
            <div class="secret-input-row">
              <ion-input
                v-model="clientForm.client_secret"
                placeholder="Opcional"
                type="password"
                aria-describedby="client-secret-error"
                :aria-invalid="clientV$.client_secret.$dirty ? clientV$.client_secret.$invalid : undefined"
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
                  <ion-icon slot="icon-only" :icon="copyOutline" aria-hidden="true"></ion-icon>
              </ion-button>
              <ion-button
                fill="clear"
                size="small"
                aria-label="Generar secret"
                @click="generateSecret"
              >
                  <ion-icon slot="icon-only" :icon="keyOutline" aria-hidden="true"></ion-icon>
              </ion-button>
            </div>
          </ion-item>
          <div v-if="clientV$.client_secret.$error" id="client-secret-error" class="field-error">{{ clientV$.client_secret.$errors[0].$message }}</div>
              <ion-item>
                <ion-label position="stacked">Redirect URL</ion-label>
                  <ion-input
                    v-model="clientForm.redirect_url"
                    type="url"
                    placeholder="https://"
                    aria-describedby="client-redirect-error"
                    :aria-invalid="clientV$.redirect_url.$dirty ? clientV$.redirect_url.$invalid : undefined"
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
                <div v-if="clientV$.redirect_url.$error" id="client-redirect-error" class="field-error">{{ clientV$.redirect_url.$errors[0].$message }}</div>
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
                    <ion-icon slot="icon-only" :icon="add" aria-hidden="true"></ion-icon>
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
                    role="button"
                    tabindex="0"
                    :aria-label="'Eliminar ' + origin"
                    @click="removeOrigin({ index })"
                    @keydown.enter.prevent="removeOrigin({ index })"
                    @keydown.space.prevent="removeOrigin({ index })"
                  >
                    <ion-label>{{ origin }}</ion-label>
                    <ion-icon :icon="close" color="danger" aria-hidden="true"></ion-icon>
                  </ion-chip>
                </div>
              </ion-item>
          <ion-item>
            <ion-label>Activo</ion-label>
            <ion-toggle
              :checked="clientForm.active"
              @ion-change="clientForm.active = !clientForm.active"
            ></ion-toggle>
          </ion-item>
          <ion-item>
            <ion-label position="stacked">Grant Types *</ion-label>
          </ion-item>
          <ion-item role="group" aria-label="Tipos de concesión">
            <div class="grant-types-group">
              <ion-item lines="none" class="grant-type-item">
                <ion-label>authorization_code</ion-label>
                <ion-toggle
                  :checked="clientForm.grant_types.includes('authorization_code')"
                  @ion-change="toggleGrantType('authorization_code')"
                ></ion-toggle>
              </ion-item>
              <ion-item lines="none" class="grant-type-item">
                <ion-label>client_credentials</ion-label>
                <ion-toggle
                  :checked="clientForm.grant_types.includes('client_credentials')"
                  @ion-change="toggleGrantType('client_credentials')"
                ></ion-toggle>
              </ion-item>
            </div>
          </ion-item>
            </ion-list>
            </form>
        </ion-content>
      </ion-modal>
      <!-- end modals -->

      <!-- popovers -->
      <!-- end popovers -->

      <!-- alerts -->
      <ion-alert
        :is-open="showDeleteAlert"
        header="Eliminar Cliente"
        :message="`¿Estás seguro de eliminar el cliente ${clientToDelete?.name}?`"
        :buttons="deleteAlertButtons"
      ></ion-alert>
      <!-- end alerts -->
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  // 1. Imports (ionic/vue/ionicons)
  import { ref, onMounted, computed } from 'vue';
  import { useRouter } from 'vue-router';
  import {
    IonPage,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonButtons,
    IonMenuButton,
    IonButton,
    IonIcon,
    IonContent,
    IonList,
    IonItem,
    IonLabel,
    IonBadge,
    IonItemSliding,
    IonItemOptions,
    IonItemOption,
    IonModal,
    IonInput,
    IonToggle,
    IonChip,
    IonInputPasswordToggle,
    IonNote,
    IonRefresher,
    IonRefresherContent,
    IonAlert,
  } from '@ionic/vue';
  import UserMenu from '@/components/user/UserMenu.vue';
  import { add, desktopOutline, trash, close, keyOutline, copyOutline, warningOutline, create, closeOutline, saveOutline } from 'ionicons/icons';
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

  const router = useRouter();

  // 3. Components
  import SkeletonListItem from '@/components/SkeletonListItem.vue';

  // 4. Interface and types imports
  import type { SsoClient } from '@/types';

  // 5. Main (props, emits, etc.)
  // (none)

  // 6. Vars and const
  const showModal = ref(false);
  const editingClient = ref<SsoClient | null>(null);
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
  const showDeleteAlert = ref(false);
  const clientToDelete = ref<SsoClient | null>(null);

  // 7. Watch
  // (none)

  // 8. Computed
  const clients = computed(() => clientsController.clients.value);
  const loading = computed(() => clientsController.loading.value);

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
    await securityController.fetchSettings();
    clientsController.fetchClients();
  });

  // 10. Functions
  const openCreateModal = (): void => {
    editingClient.value = null;
    clientForm.value = {
      name: '',
      client_id: '',
      client_secret: '',
      redirect_url: '',
      allowed_origins: [],
      grant_types: ['authorization_code'],
      active: true,
    };
    newOriginInput.value = '';
    showModal.value = true;
  };

  const openEditModal = (params: { client: SsoClient }): void => {
    const { client } = params;
    editingClient.value = client;
    clientForm.value = {
      name: client.name,
      client_id: client.client_id,
      client_secret: client.client_secret || '',
      redirect_url: client.redirect_url || '',
      allowed_origins: client.allowed_origins || [],
      grant_types: client.grant_types || ['authorization_code'],
      active: client.active,
    };
    newOriginInput.value = '';
    showModal.value = true;
  };

  const saveClient = async (): Promise<void> => {
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

      if (editingClient.value) {
        await clientsController.updateClient({
          id: editingClient.value.id,
          data,
        });
        showSuccess({ message: 'Cliente actualizado correctamente' });
      } else {
        await clientsController.createClient({ data });
        showSuccess({ message: 'Cliente creado correctamente' });
      }

      showModal.value = false;
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

  const confirmDelete = (params: { client: SsoClient }): void => {
    const { client } = params;
    clientToDelete.value = client;
    showDeleteAlert.value = true;
  };

  const deleteClient = async (): Promise<void> => {
    if (!clientToDelete.value) return;
    try {
      await clientsController.deleteClient({ id: clientToDelete.value.id });
      showSuccess({ message: 'Cliente eliminado correctamente' });
    } catch {
      showError({ message: 'Error al eliminar cliente' });
    } finally {
      showDeleteAlert.value = false;
      clientToDelete.value = null;
    }
  };

  const deleteAlertButtons = [
    {
      text: 'Cancelar',
      role: 'cancel',
      handler: () => {
        showDeleteAlert.value = false;
        clientToDelete.value = null;
      },
    },
    {
      text: 'Eliminar',
      role: 'destructive',
      handler: deleteClient,
    },
  ];

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
    } catch {
      // Ignore clipboard errors
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

  const handleRefresh = async (event: CustomEvent): Promise<void> => {
    await clientsController.fetchClients();
    event.detail.complete();
  };

  const goToClientDetail = (params: { clientId: number }): void => {
    router.push({ name: 'AdminClientDetail', params: { id: params.clientId } });
  };

  const hasSslViolation = (params: { redirectUrl: string | null }): boolean => {
    const { redirectUrl } = params;
    if (!redirectUrl || !securityController.requireSsl.value) return false;
    if (redirectUrl.startsWith('https://')) return false;

    const defaultExclusions = ['localhost', '127.0.0.1', '::1'];
    const host = (() => {
      try { return new URL(redirectUrl).hostname; } catch { return ''; }
    })();

    if (defaultExclusions.includes(host)) return false;
    if (securityController.sslExclusions.value.includes(host)) return false;

    return true;
  };
</script>

<style scoped>
  .skeleton-container {
    padding: var(--space-2, 8px) 0;
  }

  .clients-list {
    background: transparent;
  }

.clients-list ion-item {
  --padding-start: clamp(12px, 3vw, 16px);
  --padding-end: clamp(12px, 3vw, 16px);
  --background: transparent;
  border-bottom: 1px solid var(--glass-border);
  transition: background 0.2s ease;
}

.clients-list ion-item:hover {
  --background: var(--glass-bg-hover);
}

  .clients-list ion-item:last-child {
    border-bottom: none;
  }

  .client-name {
    color: var(--text-primary);
    font-weight: 600;
    font-size: clamp(14px, 3.5vw, 15px);
  }

  .client-id {
    display: flex;
    align-items: center;
    gap: 4px;
    color: var(--text-secondary);
    font-size: clamp(12px, 3vw, 13px);
    margin-top: 2px;
  }

  .id-label {
    font-weight: 500;
  }

  .client-id code {
    font-family: 'SF Mono', 'Monaco', 'Inconsolata', 'Fira Code', monospace;
    font-size: 12px;
    background: var(--ion-color-light);
    padding: 2px 6px;
    border-radius: 4px;
    color: var(--text-primary);
  }

  .redirect-url {
    font-size: clamp(11px, 2.5vw, 12px);
    color: var(--text-tertiary);
    word-break: break-all;
    margin-top: 4px;
  }

  .client-actions {
    display: flex;
    align-items: center;
    gap: 4px;
  }

  .modal-form {
    padding: 16px;
  }

  @media (min-width: 768px) {
    .page-container {
      padding: var(--dashboard-padding-lg);
    }
  }

  @media (min-width: 1024px) {
    .page-container {
      max-width: 800px;
    }
  }

  @media (max-width: 374px) {
    .page-container {
      padding: var(--space-2, 8px);
    }
  }

  .origins-input-row {
    display: flex;
    align-items: center;
    width: 100%;
    gap: 4px;
  }

  .origins-input-row ion-input {
    flex: 1;
  }

  .origins-chips {
    --padding-start: 16px;
  }

  .chips-container {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 4px 0;
  }

.chips-container ion-chip {
  cursor: pointer;
}

.secret-input-row {
  display: flex;
  align-items: center;
  width: 100%;
  gap: 4px;
}

.secret-input-row ion-input {
  flex: 1;
}

.ssl-warning-icon {
  font-size: 18px;
  margin-right: 8px;
}

.ssl-note {
  display: block;
  font-size: 12px;
  padding: 4px 16px 8px;
}

.grant-types-group {
  width: 100%;
}

.grant-type-item {
  --padding-start: 0;
  --padding-end: 0;
  --inner-padding-end: 0;
  --min-height: 44px;
  --background: transparent;
}
</style>
