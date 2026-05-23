<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-menu-button></ion-menu-button>
        </ion-buttons>
        <ion-title>Clientes SSO</ion-title>
        <ion-buttons slot="end">
          <ion-button @click="openCreateModal">
            <ion-icon slot="icon-only" :icon="add"></ion-icon>
          </ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <!-- content -->
      <div class="page-container">
        <div class="clients-card">
          <div v-if="loading" class="loading-container">
            <ion-spinner name="lines"></ion-spinner>
            <p>Cargando clientes...</p>
          </div>

          <div v-else-if="clients.length === 0" class="empty-container">
            <ion-icon :icon="desktopOutline" size="large"></ion-icon>
            <p>No hay clientes SSO</p>
            <ion-button @click="openCreateModal">Crear Cliente</ion-button>
          </div>

          <ion-list v-else class="clients-list">
            <ion-item-sliding v-for="client in clients" :key="client.id">
              <ion-item button @click="openEditModal({ client })">
                <ion-icon
                  slot="start"
                  :icon="desktopOutline"
                  :color="client.active ? 'primary' : 'medium'"
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
                <ion-badge
                  slot="end"
                  :color="client.active ? 'success' : 'medium'"
                >
                  {{ client.active ? 'Activo' : 'Inactivo' }}
                </ion-badge>
              </ion-item>

              <ion-item-options side="end">
                <ion-item-option
                  color="danger"
                  @click="deleteClient({ client })"
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
            <ion-title
              >{{ editingClient ? 'Editar' : 'Crear' }} Cliente</ion-title
            >
            <ion-buttons slot="end">
              <ion-button @click="showModal = false">Cancelar</ion-button>
            </ion-buttons>
          </ion-toolbar>
        </ion-header>
        <ion-content>
          <form class="modal-form" @submit.prevent="saveClient">
            <ion-list>
              <ion-item>
                <ion-label position="stacked">Nombre</ion-label>
                <ion-input v-model="clientForm.name" required></ion-input>
              </ion-item>
              <ion-item>
                <ion-label position="stacked">Client ID</ion-label>
                <ion-input v-model="clientForm.client_id" required></ion-input>
              </ion-item>
          <ion-item>
            <ion-label position="stacked">Client Secret</ion-label>
            <div class="secret-input-row">
              <ion-input
                v-model="clientForm.client_secret"
                placeholder="Opcional"
                type="password"
              >
                <ion-input-password-toggle slot="end" />
              </ion-input>
              <ion-button
                fill="clear"
                size="small"
                @click="copySecret"
              >
                <ion-icon slot="icon-only" :icon="copyOutline"></ion-icon>
              </ion-button>
              <ion-button
                fill="clear"
                size="small"
                @click="generateSecret"
              >
                <ion-icon slot="icon-only" :icon="keyOutline"></ion-icon>
              </ion-button>
            </div>
          </ion-item>
              <ion-item>
                <ion-label position="stacked">Redirect URL</ion-label>
                <ion-input
                  v-model="clientForm.redirect_url"
                  type="url"
                  placeholder="https://"
                ></ion-input>
              </ion-item>
              <ion-item>
                <ion-label position="stacked">Orígenes Permitidos</ion-label>
                <div class="origins-input-row">
                  <ion-input
                    v-model="newOriginInput"
                    type="url"
                    placeholder="https://ejemplo.com"
                    @keydown.enter.prevent="addOrigin"
                  ></ion-input>
                  <ion-button fill="clear" size="small" @click="addOrigin">
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
            <ion-toggle v-model="clientForm.active"></ion-toggle>
          </ion-item>
          <ion-item>
            <ion-label position="stacked">Grant Types</ion-label>
          </ion-item>
          <ion-item>
            <ion-label>authorization_code</ion-label>
            <ion-toggle
              :checked="clientForm.grant_types.includes('authorization_code')"
              @ionChange="toggleGrantType('authorization_code')"
            ></ion-toggle>
          </ion-item>
          <ion-item>
            <ion-label>client_credentials</ion-label>
            <ion-toggle
              :checked="clientForm.grant_types.includes('client_credentials')"
              @ionChange="toggleGrantType('client_credentials')"
            ></ion-toggle>
          </ion-item>
            </ion-list>
            <div class="ion-padding">
              <ion-button type="submit" expand="block" :disabled="saving">
                {{ saving ? 'Guardando...' : 'Guardar' }}
              </ion-button>
            </div>
          </form>
        </ion-content>
      </ion-modal>
      <!-- end modals -->

      <!-- popovers -->
      <!-- end popovers -->

      <!-- alerts -->
      <!-- end alerts -->
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  // imports
  import { ref, onMounted, computed } from 'vue';
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
    IonSpinner,
    IonChip,
    IonInputPasswordToggle,
  } from '@ionic/vue';
  import { add, desktopOutline, trash, close, keyOutline, copyOutline } from 'ionicons/icons';

  // controllers
  import { useClientsController } from '@/controllers/clientsController';
  const clientsController = useClientsController();

  // components
  // end components

  // interface and types imports
  import type { SsoClient } from '@/types';
  // end interface and types imports

  // main
  // end main

  // vars and const
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
  // end vars and const

  // watch
  // end

  // computed
  const clients = computed(() => clientsController.clients.value);
  const loading = computed(() => clientsController.loading.value);
  // end computed

  // onmounted
  onMounted(() => {
    clientsController.fetchClients();
  });
  // end onmounted

  // functions
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
      } else {
        await clientsController.createClient({ data });
      }

      showModal.value = false;
    } catch (error) {
      console.error('Error saving client:', error);
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

const deleteClient = async (params: { client: SsoClient }): Promise<void> => {
  const { client } = params;
  try {
    await clientsController.deleteClient({ id: client.id });
  } catch (error) {
    console.error('Error deleting client:', error);
  }
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
  // end functions
</script>

<style scoped>
  .page-container {
    padding: var(--dashboard-padding);
    max-width: 600px;
    margin: 0 auto;
  }

  .clients-card {
    background: var(--background-contrast);
    border-radius: var(--card-border-radius);
    box-shadow:
      var(--card-shadow-light) 0px 1px 3px 0px,
      var(--card-shadow-heavy) 0px 0px 0px 1px;
    overflow: hidden;
  }

  .loading-container,
  .empty-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 16px;
    color: var(--text-tertiary);
  }

  .empty-container ion-icon {
    margin-bottom: 12px;
    opacity: 0.5;
  }

  .empty-container p {
    margin: 0 0 16px 0;
    font-size: 14px;
  }

  .clients-list {
    background: transparent;
  }

  .clients-list ion-item {
    --padding-start: 16px;
    --padding-end: 16px;
    --background: transparent;
    border-bottom: 1px solid var(--ion-background-color-step-200);
  }

  .clients-list ion-item:last-child {
    border-bottom: none;
  }

  .client-name {
    color: var(--text-primary);
    font-weight: 600;
    font-size: 15px;
  }

  .client-id {
    display: flex;
    align-items: center;
    gap: 4px;
    color: var(--text-secondary);
    font-size: 13px;
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
    font-size: 12px;
    color: var(--text-tertiary);
    word-break: break-all;
    margin-top: 4px;
  }

  .modal-form {
    padding: 16px;
  }

  @media (min-width: 768px) {
    .page-container {
      padding: var(--dashboard-padding-lg);
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
</style>
