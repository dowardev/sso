<!-- SPDX-License-Identifier: MIT -->
<template>
  <div class="authorizations-container">
    <div class="section-header">
      <h3 class="section-title">Aplicaciones con Acceso</h3>
      <ion-button
        size="small"
        fill="outline"
        :disabled="loading"
        @click="refreshData"
      >
        <ion-icon slot="start" :icon="refreshOutline" />
        Actualizar
      </ion-button>
    </div>

    <div v-if="loading" class="loading-container" role="status" aria-live="polite">
      <ion-spinner name="lines"></ion-spinner>
      <p>Cargando aplicaciones...</p>
    </div>

    <div v-else-if="authorizations.length === 0" class="empty-state">
      <ion-icon :icon="linkOutline" size="large"></ion-icon>
      <p>Sin aplicaciones autorizadas</p>
      <span class="empty-hint">No has autorizado ninguna aplicación para acceder a tus datos</span>
    </div>

    <ion-list v-else class="detail-list">
      <ion-item v-for="auth in authorizations" :key="auth.id">
        <ion-icon
          slot="start"
          :icon="getStatusIcon({ status: auth.status })"
          :color="getStatusColor({ status: auth.status })"
        ></ion-icon>
        <ion-label>
          <h3 class="item-title">{{ auth.client?.name || 'Aplicación Desconocida' }}</h3>
          <p class="item-subtitle">
            <ion-badge :color="getStatusColor({ status: auth.status })">
          {{ getStatusLabel({ status: auth.status }) }}
            </ion-badge>
            <span v-if="auth.client?.client_id" class="client-id">
              ID: {{ auth.client.client_id }}
            </span>
          </p>
          <p class="session-date">
            Última actualización: {{ formatDate({ dateStr: auth.updated_at }) }}
          </p>
        </ion-label>
        <ion-badge
          slot="end"
          :color="getStatusColor({ status: auth.status })"
        >
          {{ getStatusLabel({ status: auth.status }) }}
        </ion-badge>
        <ion-button
          v-if="auth.status === 'authorized'"
          slot="end"
          fill="clear"
          color="danger"
          size="small"
          @click="revoke(auth.id)"
        >
          <ion-icon slot="icon-only" :icon="trash" />
        </ion-button>
      </ion-item>
    </ion-list>
  </div>
</template>

<script setup lang="ts">
  // 1. Imports (ionic/vue/ionicons)
  import { ref, computed, watch } from 'vue';
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
    trash,
    refreshOutline,
  } from 'ionicons/icons';

  // 2. Controllers
  // (none - emits handle revoke via parent)

  // 3. Components
  // (none)

  // 4. Interface and types imports
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

  // 5. Main (props, emits, etc.)
  const props = defineProps<{
    authorizations: Authorization[];
    loading?: boolean;
  }>();

  const emit = defineEmits<{
    revoke: [number];
    refresh: [];
  }>();

  // 6. Vars and const
  // (reactive sync via computed, no watchers needed)
  const loading = ref(props.loading || false);

  // 7. Watch
  watch(() => props.loading, (val) => {
    loading.value = val ?? false;
  });

  // 8. Computed
  const authorizations = computed(() => props.authorizations ?? []);

  // 9. OnMounted
  // (reactive via computed, no onMounted needed)

  // 10. Functions
  const refreshData = (): void => {
    emit('refresh');
  };

  const getStatusIcon = (params: { status: string }): string => {
    const { status } = params;
    switch (status) {
      case 'authorized': return 'checkmarkCircle';
      case 'denied': return 'closeCircle';
      case 'revoked': return 'warningOutline';
      default: return 'timeOutline';
    }
  };

  const getStatusColor = (params: { status: string }): string => {
    const { status } = params;
    switch (status) {
      case 'authorized': return 'success';
      case 'denied': return 'danger';
      case 'revoked': return 'warning';
      default: return 'medium';
    }
  };

  const getStatusLabel = (params: { status: string }): string => {
    const { status } = params;
    switch (status) {
      case 'authorized': return 'Autorizado';
      case 'denied': return 'Denegado';
      case 'revoked': return 'Revocado';
      default: return status;
    }
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

  const revoke = (authId: number): void => {
    emit('revoke', authId);
  };
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

.client-id {
  font-size: 11px;
  color: var(--text-tertiary);
  margin-top: 2px;
}
</style>