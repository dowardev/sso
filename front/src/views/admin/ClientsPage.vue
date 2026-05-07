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
                <ion-badge slot="end" :color="client.active ? 'success' : 'medium'">
                  {{ client.active ? 'Activo' : 'Inactivo' }}
                </ion-badge>
              </ion-item>

              <ion-item-options side="end">
                <ion-item-option color="danger" @click="deleteClient({ client })">
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
          <form @submit.prevent="saveClient" class="modal-form">
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
                <ion-input
                  v-model="clientForm.client_secret"
                  placeholder="Opcional"
                ></ion-input>
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
                <ion-label>Activo</ion-label>
                <ion-toggle v-model="clientForm.active"></ion-toggle>
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
} from '@ionic/vue';
import { add, desktopOutline, trash } from 'ionicons/icons';

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
  active: true,
});
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
    active: true,
  };
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
    active: client.active,
  };
  showModal.value = true;
};

const saveClient = async (): Promise<void> => {
  saving.value = true;
  try {
    const data = {
      name: clientForm.value.name,
      client_id: clientForm.value.client_id,
      client_secret: clientForm.value.client_secret || '',
      redirect_url: clientForm.value.redirect_url || undefined,
      active: clientForm.value.active,
    };

    if (editingClient.value) {
      await clientsController.updateClient({ id: editingClient.value.id, data });
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

const deleteClient = async (params: { client: SsoClient }): Promise<void> => {
  const { client } = params;
  try {
    await clientsController.deleteClient(client.id);
  } catch (error) {
    console.error('Error deleting client:', error);
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
  box-shadow: var(--card-shadow-light) 0px 1px 3px 0px, var(--card-shadow-heavy) 0px 0px 0px 1px;
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
</style>