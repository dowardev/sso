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
                  Último uso: {{ formatDate({ dateStr: session.last_used_at }) }}
                </p>
              </ion-label>
              <div slot="end" class="session-end">
                <ion-badge :color="session.token_active ? 'success' : 'medium'">
                  {{ session.token_active ? 'Activa' : 'Inactiva' }}
                </ion-badge>
                <ion-button
                  fill="clear"
                  color="danger"
                  @click="revokeSession({ session })"
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
// imports
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

// controllers
import { useSessionsController } from '@/controllers/sessionsController';
const sessionsController = useSessionsController();

// components
// end components

// interface and types imports
import type { UserSession } from '@/types';
// end interface and types imports

// main
// end main

// vars and const
// end vars and const

// watch
// end

// computed
const sessions = computed(() => sessionsController.sessions.value);
const loading = computed(() => sessionsController.loading.value);
const hasMore = computed(() => sessionsController.hasMore.value);
const onlyActive = computed({
  get: () => sessionsController.onlyActive.value,
  set: (value: boolean) => sessionsController.setOnlyActive(value),
});
// end computed

// onmounted
onMounted(() => {
  sessionsController.fetchSessions({ per_page: 15 });
});
// end onmounted

// functions
const handleFilterChange = (): void => {
  sessionsController.fetchSessions({ active: sessionsController.onlyActive.value });
};

const loadMore = (event: unknown): void => {
  sessionsController.fetchSessions({ page: sessionsController.pagination.value.currentPage + 1 }).then(() => {
    if (event && typeof event === 'object' && 'target' in event) {
      (event as { target: { complete: () => void } }).target.complete();
    }
  });
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

const revokeSession = async (params: { session: UserSession }): Promise<void> => {
  const { session } = params;
  try {
    await sessionsController.revokeSession(session.id);
  } catch (error) {
    console.error('Error revoking session:', error);
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