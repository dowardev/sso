<template>
  <div class="activity-container">
    <div class="section-header">
      <h3 class="section-title">Registro de Actividad</h3>
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
      <p>Cargando actividad...</p>
    </div>

    <div v-else-if="activities.length === 0" class="empty-state">
      <ion-icon :icon="shieldOutline" size="large"></ion-icon>
      <p>Sin actividad registrada</p>
      <span class="empty-hint">El registro de actividad aún no está implementado</span>
    </div>

    <ion-list v-else class="detail-list">
      <ion-item v-for="activity in activities" :key="activity.id">
        <ion-icon
          slot="start"
          :icon="getActionIcon(activity.action)"
          color="primary"
        ></ion-icon>
        <ion-label>
          <h3 class="item-title">{{ getActionLabel(activity.action) }}</h3>
          <p class="item-subtitle">{{ activity.description }}</p>
          <p class="session-date">
            {{ formatDate(activity.created_at) }}
          </p>
        </ion-label>
        <ion-badge slot="end" color="primary">
          {{ activity.action }}
        </ion-badge>
      </ion-item>
    </ion-list>
  </div>
</template>

<script setup lang="ts">
  import { ref, onMounted, watch } from 'vue';
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
    shieldOutline,
    logOutOutline,
    keyOutline,
    lockClosedOutline,
    personAddOutline,
    createOutline,
    refreshOutline,
  } from 'ionicons/icons';

  import { useToast } from '@/composables/useToast';
  const { showError } = useToast();

  interface ActivityLogEntry {
    id: number;
    action: string;
    description: string;
    created_at: string;
    metadata: Record<string, unknown>;
  }

  const props = defineProps<{
    activities: ActivityLogEntry[];
    loading?: boolean;
  }>();

  const emit = defineEmits<{
    refresh: [];
  }>();

  const loading = ref(props.loading ?? false);
  const activities = ref<ActivityLogEntry[]>(props.activities ?? []);

  onMounted(() => {
    activities.value = props.activities ?? [];
  });

  watch(
    () => props.activities,
    (val) => { activities.value = val ?? []; },
    { deep: true }
  );

  watch(
    () => props.loading,
    (val) => { loading.value = val ?? false; }
  );

  function refresh(): void {
    emit('refresh');
  }

  function getActionIcon(action: string): string {
    switch (action) {
      case 'login':
        return 'logOutOutline';
      case 'logout':
        return 'keyOutline';
      case 'password_change':
        return 'lockClosedOutline';
      case 'profile_update':
        return 'createOutline';
      case 'authorization_granted':
        return 'personAddOutline';
      case 'authorization_revoked':
        return 'createOutline';
      case 'session_revoked':
        return 'keyOutline';
      case 'token_created':
        return 'keyOutline';
      case 'token_refreshed':
        return 'refreshOutline';
      default:
        return 'shieldOutline';
    }
  }

  function getActionLabel(action: string): string {
    switch (action) {
      case 'login':
        return 'Inicio de Sesión';
      case 'logout':
        return 'Cierre de Sesión';
      case 'password_change':
        return 'Cambio de Contraseña';
      case 'profile_update':
        return 'Actualización de Perfil';
      case 'authorization_granted':
        return 'Autorización Concedida';
      case 'authorization_revoked':
        return 'Autorización Revocada';
      case 'session_revoked':
        return 'Sesión Revocada';
      case 'token_created':
        return 'Acceso a Aplicación';
      case 'token_refreshed':
        return 'Sesión Renovada en App';
      default:
        return action;
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

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.section-title {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  color: var(--text-tertiary);
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
</style>