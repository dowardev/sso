<template>
  <div class="authorizations-card">
    <div class="section-header">
      <h3 class="section-title">Aplicaciones Autorizadas</h3>
      <ion-button
        size="small"
        fill="outline"
        :disabled="loading"
        @click="refresh"
      >
        <ion-icon slot="start" :icon="refreshOutline" />
        Actualizar
      </ion-button>
    </div>

    <div v-if="loading" class="loading-container" role="status" aria-live="polite">
      <ion-spinner name="lines"></ion-spinner>
      <p>Cargando autorizaciones...</p>
    </div>

    <div v-else-if="authorizations.length === 0" class="empty-state">
      <ion-icon :icon="linkOutline" size="large"></ion-icon>
      <p>Sin aplicaciones autorizadas</p>
      <span class="empty-hint">El usuario no ha autorizado ninguna aplicación</span>
    </div>

    <ion-list v-else class="detail-list">
      <ion-item v-for="auth in authorizations" :key="auth.id">
        <ion-icon
          slot="start"
          :icon="getStatusIcon(auth.status)"
          :color="getStatusColor(auth.status)"
        ></ion-icon>
        <ion-label>
          <h3 class="item-title">{{ auth.client?.name || 'Aplicación Desconocida' }}</h3>
          <p class="item-subtitle">
            <ion-badge :color="getStatusColor(auth.status)">
              {{ getStatusLabel(auth.status) }}
            </ion-badge>
            <span v-if="auth.client?.client_id" class="client-id">
              ID: {{ auth.client.client_id }}
            </span>
          </p>
          <p class="session-date">
            Última actualización: {{ formatDate(auth.updated_at) }}
          </p>
        </ion-label>
        <ion-badge
          slot="end"
          :color="getStatusColor(auth.status)"
        >
          {{ getStatusLabel(auth.status) }}
        </ion-badge>
        <ion-button
          v-if="auth.status === 'authorized'"
          slot="end"
          fill="clear"
          color="danger"
          size="small"
          @click="revokeAuthorization(auth.id, auth.client?.name)"
        >
          <ion-icon slot="icon-only" :icon="trash" />
        </ion-button>
      </ion-item>
    </ion-list>
  </div>
</template>

<script setup lang="ts">
  import { ref, onMounted } from 'vue';
  import {
    IonList,
    IonItem,
    IonLabel,
    IonIcon,
    IonBadge,
    IonButton,
    IonSpinner,
  } from '@ionic/vue';
  import {
    linkOutline,
    checkmarkCircle,
    closeCircle,
    timeOutline,
    trash,
    refreshOutline,
    warningOutline,
  } from 'ionicons/icons';

  import { useAuthController } from '@/controllers/authController';
  import { useValidation } from '@/composables/useValidation';
  const authController = useAuthController();
  const { handleApiError } = useValidation();

  import { useToast } from '@/composables/useToast';
  const { showSuccess, showError } = useToast();

  interface Authorization {
    id: number;
    status: 'authorized' | 'denied' | 'revoked';
    updated_at: string;
    client: {
      id: number;
      name: string;
      client_id: string;
      redirect_url: string | null;
    } | null;
  }

  const props = defineProps<{
    userId: number;
  }>();

  const loading = ref(false);
  const authorizations = ref<Authorization[]>([]);

  onMounted(() => {
    loadAuthorizations();
  });

  async function loadAuthorizations(): Promise<void> {
    loading.value = true;
    try {
      const response = await authController.getUserAuthorizations({
        user_id: props.userId,
      });
      authorizations.value = (response.data || response) as Authorization[];
    } catch (err) {
      console.error('[Load Authorizations Error]', err);
      authorizations.value = [];
    } finally {
      loading.value = false;
    }
  }

  async function refresh(): Promise<void> {
    await loadAuthorizations();
  }

  function getStatusIcon(status: string): string {
    switch (status) {
      case 'authorized':
        return 'checkmarkCircle';
      case 'denied':
        return 'closeCircle';
      case 'revoked':
        return 'warningOutline';
      default:
        return 'timeOutline';
    }
  }

  function getStatusColor(status: string): string {
    switch (status) {
      case 'authorized':
        return 'success';
      case 'denied':
        return 'danger';
      case 'revoked':
        return 'warning';
      default:
        return 'medium';
    }
  }

  function getStatusLabel(status: string): string {
    switch (status) {
      case 'authorized':
        return 'Autorizado';
      case 'denied':
        return 'Denegado';
      case 'revoked':
        return 'Revocado';
      default:
        return status;
    }
  }

  function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('es-ES', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  }

  async function revokeAuthorization(authId: number, appName?: string): Promise<void> {
    try {
      // We need to get the client_id from the auth object first
      // For now, we'll show a confirmation and call the revoke endpoint
      // The actual implementation would need the client_id
      showError({ message: 'Función de revocación en desarrollo' });
    } catch (err) {
      console.error('[Revoke Error]', err);
      showError({ message: 'Error al revocar autorización' });
    }
  }
</script>

<style scoped>
.loading-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 32px;
  color: var(--text-tertiary);
}

.loading-container p {
  margin-top: 12px;
  font-size: 14px;
}

.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 32px;
  color: var(--text-tertiary);
  text-align: center;
}

.empty-state ion-icon {
  margin-bottom: 12px;
  opacity: 0.5;
}

.empty-state p {
  margin: 0;
  font-size: 14px;
}

.empty-hint {
  font-size: 12px;
  opacity: 0.7;
  margin-top: 4px;
}

.detail-list {
  background: transparent;
  margin: 0 -16px;
  padding: 0;
}

.detail-list ion-item {
  --padding-start: 16px;
  --padding-end: 16px;
  --background: transparent;
  border-bottom: 1px solid var(--glass-border);
  transition: background 0.2s ease;
}

.detail-list ion-item:hover {
  --background: var(--glass-bg-hover);
}

.detail-list ion-item:last-child {
  border-bottom: none;
}

.item-title {
  color: var(--text-primary);
  font-weight: 600;
  font-size: 15px;
}

.item-subtitle {
  color: var(--text-secondary);
  font-size: 13px;
}

.session-date {
  font-size: 12px;
  color: var(--text-tertiary);
  margin-top: 4px;
}

.client-id {
  font-size: 11px;
  color: var(--text-tertiary);
  margin-top: 2px;
}
</style>