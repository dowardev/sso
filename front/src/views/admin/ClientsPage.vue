<template>
  <ion-page>
    <ion-header>
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
      <div v-if="loading" class="loading-container">
        <ion-spinner name="lines"></ion-spinner>
      </div>

      <div v-else-if="clients.length === 0" class="empty-container">
        <ion-icon :icon="desktopOutline" size="large"></ion-icon>
        <p>No hay clientes SSO</p>
        <ion-button @click="openCreateModal">Crear Cliente</ion-button>
      </div>

      <ion-list v-else>
        <ion-item-sliding v-for="client in clients" :key="client.id">
          <ion-item button @click="openEditModal(client)">
            <ion-icon slot="start" :icon="desktopOutline" :color="client.active ? 'primary' : 'medium'"></ion-icon>
            <ion-label>
              <h2>{{ client.name }}</h2>
              <p>ID: {{ client.client_id }}</p>
              <p v-if="client.redirect_url" class="redirect-url">{{ client.redirect_url }}</p>
            </ion-label>
            <ion-badge slot="end" :color="client.active ? 'success' : 'medium'">
              {{ client.active ? 'Activo' : 'Inactivo' }}
            </ion-badge>
          </ion-item>

          <ion-item-options side="end">
            <ion-item-option color="danger" @click="deleteClient(client)">
              <ion-icon slot="icon-only" :icon="trash"></ion-icon>
            </ion-item-option>
          </ion-item-options>
        </ion-item-sliding>
      </ion-list>

      <ion-modal :is-open="showModal" @didDismiss="showModal = false">
        <ion-header>
          <ion-toolbar>
            <ion-title>{{ editingClient ? 'Editar' : 'Crear' }} Cliente</ion-title>
            <ion-buttons slot="end">
              <ion-button @click="showModal = false">Cancelar</ion-button>
            </ion-buttons>
          </ion-toolbar>
        </ion-header>
        <ion-content>
          <form @submit.prevent="saveClient">
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
                <ion-input v-model="clientForm.client_secret" placeholder="Opcional"></ion-input>
              </ion-item>
              <ion-item>
                <ion-label position="stacked">Redirect URL</ion-label>
                <ion-input v-model="clientForm.redirect_url" type="url" placeholder="https://"></ion-input>
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
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { IonPage, IonHeader, IonToolbar, IonTitle, IonButtons, IonMenuButton, IonButton, IonIcon, IonContent, IonList, IonItem, IonLabel, IonBadge, IonItemSliding, IonItemOptions, IonItemOption, IonModal, IonInput, IonToggle, IonSpinner } from '@ionic/vue';
import { add, desktopOutline, trash } from 'ionicons/icons';
import { api, type SsoClient } from '@/services/api';

const clients = ref<SsoClient[]>([]);
const loading = ref(true);
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

const loadClients = async () => {
  try {
    const response = await api.get<SsoClient[]>('/admin/clients');
    clients.value = response;
  } catch (error) {
    console.error('Error loading clients:', error);
  } finally {
    loading.value = false;
  }
};

const openCreateModal = () => {
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

const openEditModal = (client: SsoClient) => {
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

const saveClient = async () => {
  saving.value = true;
  try {
    const data = {
      name: clientForm.value.name,
      client_id: clientForm.value.client_id,
      client_secret: clientForm.value.client_secret || null,
      redirect_url: clientForm.value.redirect_url || null,
      active: clientForm.value.active,
    };

    if (editingClient.value) {
      const response = await api.put<SsoClient>(`/admin/clients/${editingClient.value.id}`, data);
      const index = clients.value.findIndex((c) => c.id === editingClient.value!.id);
      if (index !== -1) {
        clients.value[index] = response;
      }
    } else {
      const response = await api.post<SsoClient>('/admin/clients', data);
      clients.value.unshift(response);
    }

    showModal.value = false;
  } catch (error) {
    console.error('Error saving client:', error);
  } finally {
    saving.value = false;
  }
};

const deleteClient = async (client: SsoClient) => {
  try {
    await api.delete(`/admin/clients/${client.id}`);
    clients.value = clients.value.filter((c) => c.id !== client.id);
  } catch (error) {
    console.error('Error deleting client:', error);
  }
};

onMounted(() => {
  loadClients();
});
</script>

<style scoped>
.loading-container,
.empty-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 200px;
  color: var(--ion-color-medium);
}

.empty-container ion-icon {
  margin-bottom: 8px;
}

.empty-container p {
  margin-bottom: 16px;
}

.redirect-url {
  font-size: 12px;
  color: var(--ion-color-medium);
  word-break: break-all;
}
</style>
