<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-menu-button aria-label="Menú de navegación"></ion-menu-button>
        </ion-buttons>
        <ion-title>Sesiones</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <ion-refresher slot="fixed" @ion-refresh="handleRefresh($event)">
        <ion-refresher-content pulling-text="Tira para actualizar..." refreshing-text="Actualizando..."></ion-refresher-content>
      </ion-refresher>
      <div class="page-container">
        <div class="filter-card">
          <ion-item lines="none">
            <ion-label>Solo activas</ion-label>
            <ion-toggle
              v-model="onlyActive"
              aria-label="Filtrar solo sesiones activas"
              @ion-change="handleFilterChange"
            ></ion-toggle>
          </ion-item>
        </div>

        <div class="card-list">
          <div
            v-if="loading && sessions.length === 0"
            class="skeleton-container"
          >
            <SkeletonListItem v-for="n in 5" :key="n" />
          </div>

          <div v-else-if="sessions.length === 0" class="empty-state">
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
                <h2 class="user-name">
                  {{ session.user?.name || 'Usuario Desconocido' }}
                </h2>
                <p class="user-email">{{ session.user?.email }}</p>
                <p class="client-name">
                  <ion-icon :icon="desktopOutline"></ion-icon>
                  {{ session.client?.name || 'Cliente Desconocido' }}
                </p>
                <p class="ip-address">{{ session.ip_address }}</p>
                <p class="session-date">
                  Último uso:
                  {{ formatDate({ dateStr: session.last_used_at }) }}
                </p>
              </ion-label>
              <div slot="end" class="session-end">
                <ion-badge :color="session.token_active ? 'success' : 'medium'">
                  {{ session.token_active ? 'Activa' : 'Inactiva' }}
                </ion-badge>
                <ion-button
                  fill="clear"
                  color="danger"
                  aria-label="Revocar sesión"
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
            aria-label="Cargar más sesiones"
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
  import { onMounted, computed } from 'vue';
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
    IonInfiniteScroll,
    IonInfiniteScrollContent,
    IonToggle,
    IonRefresher,
    IonRefresherContent,
  } from '@ionic/vue';
  import { keyOutline, desktopOutline, trash } from 'ionicons/icons';

  // controllers
  import { useSessionsController } from '@/controllers/sessionsController';
  const sessionsController = useSessionsController();

  // composables
  import { useToast } from '@/composables/useToast';
  const { showSuccess, showError } = useToast();

  // components
  import SkeletonListItem from '@/components/SkeletonListItem.vue';
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
    sessionsController.fetchSessions({
      active: sessionsController.onlyActive.value,
    });
  };

  const loadMore = (event: unknown): void => {
    sessionsController
      .fetchSessions({
        page: sessionsController.pagination.value.currentPage + 1,
      })
      .then(() => {
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

  const revokeSession = async (params: {
    session: UserSession;
  }): Promise<void> => {
    const { session } = params;
    try {
      await sessionsController.revokeSession(session.id);
      showSuccess({ message: 'Sesión revocada correctamente' });
    } catch {
      showError({ message: 'Error al revocar sesión' });
    }
  };

  const handleRefresh = async (event: CustomEvent): Promise<void> => {
    await sessionsController.fetchSessions({ per_page: 15 });
    event.detail.complete();
  };
  // end functions
</script>

<style scoped>
.filter-card {
  background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  border: 1px solid var(--glass-border);
  border-radius: var(--card-border-radius);
  margin-bottom: 16px;
  padding: 8px 16px;
}

  .filter-card ion-item {
    --background: transparent;
  }

  .skeleton-container {
    padding: 8px 0;
  }

  .sessions-list {
    background: transparent;
  }

.sessions-list ion-item {
  --padding-start: 16px;
  --padding-end: 16px;
  --background: transparent;
  border-bottom: 1px solid var(--glass-border);
  transition: background 0.2s ease;
}

.sessions-list ion-item:hover {
  --background: var(--glass-bg-hover);
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

  @media (min-width: 1024px) {
    .page-container {
      max-width: 800px;
    }
  }

  @media (max-width: 374px) {
    .page-container {
      padding: 8px;
    }

    .user-name {
      font-size: 14px;
    }

    .user-email {
      font-size: 12px;
    }

    .client-name {
      font-size: 12px;
    }

    .ip-address {
      font-size: 11px;
    }

    .session-date {
      font-size: 11px;
    }
  }
</style>
