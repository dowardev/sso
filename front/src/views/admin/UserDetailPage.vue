<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-back-button default-href="/admin/users"></ion-back-button>
        </ion-buttons>
        <ion-title>Detalle de Usuario</ion-title>
        <ion-buttons slot="end">
          <ion-button @click="openEditModal" color="primary">
            <ion-icon slot="icon-only" :icon="create"></ion-icon>
          </ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <div v-if="loading" class="loading-container">
        <ion-spinner name="lines"></ion-spinner>
      </div>

      <div v-else-if="user" class="user-detail">
        <div class="user-header">
          <ion-avatar class="large-avatar">
            <div class="avatar-placeholder">{{ getInitials(user.name) }}</div>
          </ion-avatar>
          <h2>{{ user.name }}</h2>
          <p>@{{ user.user }}</p>
          <ion-badge v-if="user.is_admin" color="warning">Administrador</ion-badge>
        </div>

        <div class="info-section">
          <h3>Información</h3>
          <div class="info-item">
            <span class="label">Email:</span>
            <span>{{ user.email }}</span>
          </div>
          <div class="info-item">
            <span class="label">Creado:</span>
            <span>{{ formatDate(user.created_at) }}</span>
          </div>
          <div class="info-item">
            <span class="label">Tokens:</span>
            <span>{{ user.active_tokens_count }} activos</span>
          </div>
        </div>

        <div class="sessions-section">
          <div class="section-header">
            <h3>Sesiones</h3>
            <ion-button
              v-if="sessions.length > 0"
              size="small"
              color="danger"
              fill="outline"
              @click="revokeAllSessions"
            >
              Revocar Todas
            </ion-button>
          </div>

          <div v-if="sessions.length === 0" class="empty-sessions">
            <ion-icon :icon="keyOutline" size="large"></ion-icon>
            <p>No hay sesiones activas</p>
          </div>

          <ion-list v-else>
            <ion-item v-for="session in sessions" :key="session.id">
              <ion-icon slot="start" :icon="keyOutline" :color="session.token_active ? 'success' : 'medium'"></ion-icon>
              <ion-label>
                <h3>{{ session.client?.name || 'Cliente Desconocido' }}</h3>
                <p>{{ session.ip_address }}</p>
                <p class="session-date">Último uso: {{ formatDate(session.last_used_at) }}</p>
              </ion-label>
              <ion-badge :color="session.token_active ? 'success' : 'medium'">
                {{ session.token_active ? 'Activa' : 'Inactiva' }}
              </ion-badge>
              <ion-button
                slot="end"
                fill="clear"
                color="danger"
                @click="revokeSession(session)"
              >
                <ion-icon slot="icon-only" :icon="trash"></ion-icon>
              </ion-button>
            </ion-item>
          </ion-list>
        </div>
      </div>

      <ion-modal :is-open="showEditModal" @didDismiss="showEditModal = false">
        <ion-header>
          <ion-toolbar>
            <ion-title>Editar Usuario</ion-title>
            <ion-buttons slot="end">
              <ion-button @click="showEditModal = false">Cancelar</ion-button>
            </ion-buttons>
          </ion-toolbar>
        </ion-header>
        <ion-content>
          <form @submit.prevent="updateUser">
            <ion-list>
              <ion-item>
                <ion-label position="stacked">Nombre</ion-label>
                <ion-input v-model="editForm.name" required></ion-input>
              </ion-item>
              <ion-item>
                <ion-label position="stacked">Usuario</ion-label>
                <ion-input v-model="editForm.user" required></ion-input>
              </ion-item>
              <ion-item>
                <ion-label position="stacked">Email</ion-label>
                <ion-input v-model="editForm.email" type="email" required></ion-input>
              </ion-item>
              <ion-item>
                <ion-label position="stacked">Nueva Contraseña</ion-label>
                <ion-input v-model="editForm.password" type="password" placeholder="Dejar vacío para mantener"></ion-input>
              </ion-item>
            </ion-list>
            <div class="ion-padding">
              <ion-button type="submit" expand="block" :disabled="updating">
                {{ updating ? 'Guardando...' : 'Guardar Cambios' }}
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
import { useRoute, useRouter } from 'vue-router';
import { IonPage, IonHeader, IonToolbar, IonTitle, IonButtons, IonBackButton, IonButton, IonIcon, IonContent, IonAvatar, IonBadge, IonList, IonItem, IonLabel, IonModal, IonInput, IonItemOptions, IonItemOption, IonSpinner } from '@ionic/vue';
import { create, keyOutline, trash } from 'ionicons/icons';
import { api, type UserDetail, type UserSession } from '@/services/api';

const route = useRoute();
const router = useRouter();

const user = ref<UserDetail | null>(null);
const sessions = ref<UserSession[]>([]);
const loading = ref(true);
const updating = ref(false);
const showEditModal = ref(false);

const editForm = ref({
  name: '',
  user: '',
  email: '',
  password: '',
});

const userId = Number(route.params.id);

const loadUser = async () => {
  try {
    const response = await api.get<UserDetail>(`/admin/users/${userId}`);
    user.value = response;
    sessions.value = response.sessions || [];
    editForm.value = {
      name: response.name,
      user: response.user,
      email: response.email,
      password: '',
    };
  } catch (error) {
    console.error('Error loading user:', error);
  } finally {
    loading.value = false;
  }
};

const getInitials = (name: string) => {
  return name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2);
};

const formatDate = (dateStr: string) => {
  return new Date(dateStr).toLocaleDateString('es-ES', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};

const openEditModal = () => {
  showEditModal.value = true;
};

const updateUser = async () => {
  updating.value = true;
  try {
    const data: any = {
      name: editForm.value.name,
      user: editForm.value.user,
      email: editForm.value.email,
    };

    if (editForm.value.password) {
      data.password = editForm.value.password;
      data.password_confirmation = editForm.value.password;
    }

    await api.put(`/admin/users/${userId}`, data);
    user.value = { ...user.value!, ...data };
    showEditModal.value = false;
  } catch (error) {
    console.error('Error updating user:', error);
  } finally {
    updating.value = false;
  }
};

const revokeSession = async (session: UserSession) => {
  try {
    await api.delete(`/admin/sessions/${session.id}`);
    sessions.value = sessions.value.filter((s) => s.id !== session.id);
    if (user.value) {
      user.value.active_tokens_count--;
    }
  } catch (error) {
    console.error('Error revoking session:', error);
  }
};

const revokeAllSessions = async () => {
  try {
    await api.delete(`/admin/users/${userId}/sessions`);
    sessions.value = [];
    if (user.value) {
      user.value.active_tokens_count = 0;
    }
  } catch (error) {
    console.error('Error revoking sessions:', error);
  }
};

onMounted(() => {
  loadUser();
});
</script>

<style scoped>
.loading-container {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
}

.user-header {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 24px;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
}

.large-avatar {
  width: 80px;
  height: 80px;
  margin-bottom: 12px;
}

.avatar-placeholder {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  background: white;
  color: #667eea;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
  font-size: 28px;
}

.user-header h2 {
  margin: 0;
  font-size: 24px;
}

.user-header p {
  margin: 4px 0;
  opacity: 0.9;
}

.info-section,
.sessions-section {
  padding: 16px;
}

h3 {
  margin: 0 0 12px 0;
  font-size: 16px;
  color: #333;
}

.info-item {
  display: flex;
  justify-content: space-between;
  padding: 8px 0;
  border-bottom: 1px solid #eee;
}

.info-item .label {
  font-weight: 500;
  color: #666;
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

.section-header h3 {
  margin: 0;
}

.empty-sessions {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 32px;
  color: var(--ion-color-medium);
}

.empty-sessions ion-icon {
  margin-bottom: 8px;
}

.session-date {
  font-size: 12px;
  color: var(--ion-color-medium);
}
</style>
