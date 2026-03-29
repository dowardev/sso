<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-menu-button></ion-menu-button>
        </ion-buttons>
        <ion-title>Sesiones</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <div class="filters-container">
        <ion-item>
          <ion-label>Solo activas</ion-label>
          <ion-toggle v-model="onlyActive" @ionChange="handleFilterChange"></ion-toggle>
        </ion-item>
      </div>

      <div class="sessions-list">
        <div v-if="loading" class="loading-container">
          <ion-spinner name="lines"></ion-spinner>
        </div>

        <div v-else-if="sessions.length === 0" class="empty-container">
          <ion-icon :icon="keyOutline" size="large"></ion-icon>
          <p>No se encontraron sesiones</p>
        </div>

        <ion-list v-else>
          <ion-item v-for="session in sessions" :key="session.id">
            <ion-icon
              slot="start"
              :icon="keyOutline"
              :color="session.token_active ? 'success' : 'medium'"
            ></ion-icon>
            <ion-label>
              <h2>{{ session.user?.name || 'Usuario Desconocido' }}</h2>
              <p>{{ session.user?.email }}</p>
              <p class="client-name">
                <ion-icon :icon="desktopOutline"></ion-icon>
                {{ session.client?.name || 'Cliente Desconocido' }}
              </p>
              <p class="ip-address">{{ session.ip_address }}</p>
              <p class="session-date">Último uso: {{ formatDate(session.last_used_at) }}</p>
            </ion-label>
            <div slot="end" class="session-end">
              <ion-badge :color="session.token_active ? 'success' : 'medium'">
                {{ session.token_active ? 'Activa' : 'Inactiva' }}
              </ion-badge>
              <ion-button
                fill="clear"
                color="danger"
                @click="revokeSession(session)"
              >
                <ion-icon slot="icon-only" :icon="trash"></ion-icon>
              </ion-button>
            </div>
          </ion-item>
        </ion-list>

        <ion-infinite-scroll @ionInfinite="loadMore($event)" :disabled="!hasMore">
          <ion-infinite-scroll-content loading-spinner="lines"></ion-infinite-scroll-content>
        </ion-infinite-scroll>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { IonPage, IonHeader, IonToolbar, IonTitle, IonButtons, IonMenuButton, IonContent, IonItem, IonLabel, IonIcon, IonList, IonBadge, IonButton, IonSpinner, IonInfiniteScroll, IonInfiniteScrollContent, IonToggle } from '@ionic/vue';
import { keyOutline, desktopOutline, trash } from 'ionicons/icons';
import { api, type UserSession, type PaginatedResponse } from '@/services/api';

const sessions = ref<UserSession[]>([]);
const loading = ref(true);
const onlyActive = ref(false);
const page = ref(1);
const hasMore = ref(true);

const loadSessions = async (reset = false) => {
  if (reset) {
    page.value = 1;
    sessions.value = [];
    hasMore.value = true;
  }

  loading.value = true;
  try {
    const params = new URLSearchParams({
      page: page.value.toString(),
      per_page: '15',
    });

    if (onlyActive.value) {
      params.append('active', '1');
    }

    const response = await api.get<PaginatedResponse<UserSession>>(`/admin/sessions?${params}`);

    if (reset) {
      sessions.value = response.data;
    } else {
      sessions.value = [...sessions.value, ...response.data];
    }

    hasMore.value = response.current_page < response.last_page;
  } catch (error) {
    console.error('Error loading sessions:', error);
  } finally {
    loading.value = false;
  }
};

const handleFilterChange = () => {
  loadSessions(true);
};

const loadMore = (event: any) => {
  page.value++;
  loadSessions().then(() => {
    event.target.complete();
  });
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

const revokeSession = async (session: UserSession) => {
  try {
    await api.delete(`/admin/sessions/${session.id}`);
    sessions.value = sessions.value.filter((s) => s.id !== session.id);
  } catch (error) {
    console.error('Error revoking session:', error);
  }
};

onMounted(() => {
  loadSessions();
});
</script>

<style scoped>
.filters-container {
  padding: 8px 16px;
}

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

.client-name {
  display: flex;
  align-items: center;
  gap: 4px;
}

.ip-address {
  font-size: 12px;
  color: var(--ion-color-medium);
}

.session-date {
  font-size: 11px;
  color: var(--ion-color-light);
}

.session-end {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
}
</style>
