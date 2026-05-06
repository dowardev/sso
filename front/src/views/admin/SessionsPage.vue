<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-menu-button></ion-menu-button>
        </ion-buttons>
        <ion-title>Sesiones</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <div class="page-container">
        <div class="filter-card">
          <ion-item lines="none">
            <ion-label>Solo activas</ion-label>
            <ion-toggle
              v-model="onlyActive"
              @ion-change="handleFilterChange"
            ></ion-toggle>
          </ion-item>
        </div>

        <div class="sessions-card">
          <div v-if="loading && sessions.length === 0" class="loading-container">
            <ion-spinner name="lines"></ion-spinner>
            <p>Cargando sesiones...</p>
          </div>

          <div v-else-if="sessions.length === 0" class="empty-container">
            <ion-icon :icon="keyOutline" size="large"></ion-icon>
            <p>No se encontraron sesiones</p>
          </div>

          <ion-list v-else class="sessions-list">
            <ion-item v-for="session in sessions" :key="session.id">
              <ion-icon
                slot="start"
                :icon="keyOutline"
                :color="session.token_active ? 'success' : 'medium'"
              ></ion-icon>
              <ion-label>
                <h2 class="user-name">{{ session.user?.name || 'Usuario Desconocido' }}</h2>
                <p class="user-email">{{ session.user?.email }}</p>
                <p class="client-name">
                  <ion-icon :icon="desktopOutline"></ion-icon>
                  {{ session.client?.name || 'Cliente Desconocido' }}
                </p>
                <p class="ip-address">{{ session.ip_address }}</p>
                <p class="session-date">
                  Último uso: {{ formatDate(session.last_used_at) }}
                </p>
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

          <ion-infinite-scroll
            v-if="sessions.length > 0"
            :disabled="!hasMore"
            @ion-infinite="loadMore($event)"
          >
            <ion-infinite-scroll-content
              loading-spinner="lines"
            ></ion-infinite-scroll-content>
          </ion-infinite-scroll>
        </div>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  import { ref, onMounted, computed } from 'vue';
  import {
    IonPage,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonButtons,
    IonMenuButton,
    IonContent,
    IonItem,
    IonLabel,
    IonIcon,
    IonList,
    IonBadge,
    IonButton,
    IonSpinner,
    IonInfiniteScroll,
    IonInfiniteScrollContent,
    IonToggle,
  } from '@ionic/vue';
  import { keyOutline, desktopOutline, trash } from 'ionicons/icons';
  import { useSessionsStore } from '@/stores/sessions';
  import type { UserSession } from '@/types';

  const sessionsStore = useSessionsStore();

  const sessions = computed(() => sessionsStore.sessions);
  const loading = computed(() => sessionsStore.loading);
  const hasMore = computed(() => sessionsStore.hasMore);
  const onlyActive = computed({
    get: () => sessionsStore.onlyActive,
    set: (value: boolean) => sessionsStore.setOnlyActive(value),
  });

  const handleFilterChange = () => {
    sessionsStore.fetchSessions({ active: sessionsStore.onlyActive });
  };

  const loadMore = (event: any) => {
    sessionsStore.fetchSessions({ page: sessionsStore.pagination.currentPage + 1 }).then(() => {
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
      await sessionsStore.revokeSession(session.id);
    } catch (error) {
      console.error('Error revoking session:', error);
    }
  };

  onMounted(() => {
    sessionsStore.fetchSessions({ per_page: 15 });
  });
</script>

<style scoped>
.page-container {
  padding: var(--dashboard-padding);
  max-width: 600px;
  margin: 0 auto;
}

.filter-card {
  background: var(--background-contrast);
  border-radius: var(--card-border-radius);
  box-shadow: var(--card-shadow-light) 0px 1px 3px 0px, var(--card-shadow-heavy) 0px 0px 0px 1px;
  margin-bottom: 16px;
  padding: 8px 16px;
}

.filter-card ion-item {
  --background: transparent;
}

.sessions-card {
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

.loading-container p,
.empty-container p {
  margin: 0;
  font-size: 14px;
}

.sessions-list {
  background: transparent;
}

.sessions-list ion-item {
  --padding-start: 16px;
  --padding-end: 16px;
  --background: transparent;
  border-bottom: 1px solid var(--ion-background-color-step-200);
}

.sessions-list ion-item:last-child {
  border-bottom: none;
}

.user-name {
  color: var(--text-primary);
  font-weight: 600;
  font-size: 15px;
}

.user-email {
  color: var(--text-secondary);
  font-size: 13px;
}

.client-name {
  display: flex;
  align-items: center;
  gap: 4px;
  color: var(--text-secondary);
  font-size: 13px;
  margin-top: 4px;
}

.ip-address {
  font-size: 12px;
  color: var(--text-tertiary);
  margin-top: 2px;
}

.session-date {
  font-size: 12px;
  color: var(--text-tertiary);
  margin-top: 2px;
}

.session-end {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
}

@media (min-width: 768px) {
  .page-container {
    padding: var(--dashboard-padding-lg);
  }
}
</style>