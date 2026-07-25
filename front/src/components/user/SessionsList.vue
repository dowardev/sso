<!-- SPDX-License-Identifier: MIT -->
<template>
  <div class="section-header">
      <h3 class="section-title">Historial de Sesiones</h3>
      <ion-button
        v-if="sessions.length > 0"
        size="small"
        fill="outline"
        color="danger"
        :disabled="loading"
        @click="handleRevokeAll"
      >
        <ion-icon slot="start" :icon="trash" />
        Revocar Todas
      </ion-button>
    </div>

    <div v-if="loading" class="loading-container" role="status" aria-live="polite">
      <ion-spinner name="lines"></ion-spinner>
      <p>Cargando sesiones...</p>
    </div>

    <div v-else-if="sessions.length === 0" class="empty-state">
      <ion-icon :icon="keyOutline" size="large"></ion-icon>
      <p>No hay sesiones activas</p>
      <span class="empty-hint">No hay sesiones registradas actualmente</span>
    </div>

    <ion-list v-else class="detail-list">
      <ion-item v-for="session in sessions" :key="session.id" button :detail="false" @click="toggleExpand({ sessionId: session.id })">
        <ion-icon
          slot="start"
          :icon="getDeviceIcon({ session })"
          :color="session.token_active ? 'success' : 'medium'"
        ></ion-icon>
        <ion-label>
          <h3 class="item-title">
            {{ session.client?.name || getDeviceName({ session }) || 'Cliente Desconocido' }}
          </h3>
          <p class="item-subtitle">{{ getDeviceDetail({ session }) }}</p>
          <p class="session-date">
            Último uso: {{ formatDate({ dateStr: session.last_used_at }) }}
          </p>
        </ion-label>
        <div v-if="expandedSessions.has(session.id)" class="session-details">
          <div class="detail-row">
            <ion-icon :icon="globeOutline" size="small" />
            <span>IP: {{ session.ip_address }}</span>
          </div>
          <div class="detail-row">
            <ion-icon :icon="laptopOutline" size="small" />
            <span>{{ getDeviceType({ session }) }}</span>
          </div>
          <div class="detail-row">
            <ion-icon :icon="calendarOutline" size="small" />
            <span>Creada: {{ formatDate({ dateStr: session.created_at }) }}</span>
          </div>
        </div>
        <ion-badge
          slot="end"
          :color="session.token_active ? 'success' : 'medium'"
        >
          {{ session.token_active ? 'Activa' : 'Inactiva' }}
        </ion-badge>
        <ion-button
          slot="end"
          fill="clear"
          color="danger"
          size="small"
          @click.stop="revoke(session.id)"
        >
          <ion-icon slot="icon-only" :icon="trash" />
        </ion-button>
      </ion-item>
    </ion-list>
</template>

<script setup lang="ts">
  // 1. Imports (ionic/vue/ionicons)
  import { ref, onMounted, watch } from 'vue';
  import { UAParser } from 'ua-parser-js';
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
    keyOutline,
    trash,
    globeOutline,
    laptopOutline,
    calendarOutline,
    phonePortraitOutline,
    tabletPortraitOutline,
    logoChrome,
    logoFirefox,
  } from 'ionicons/icons';

  // 2. Controllers
  // (none)

  // 3. Components
  // (none)

  // 4. Interface and types imports
  import type { UserSession } from '@/types';

  // 5. Main (props, emits, etc.)
  const props = defineProps<{
    sessions: UserSession[];
    loading?: boolean;
  }>();

  const emit = defineEmits<{
    revoke: [number];
    revokeAll: [];
  }>();

  // 6. Vars and const
  const loading = ref(props.loading ?? false);
  const sessions = ref<UserSession[]>(props.sessions ?? []);
  const expandedSessions = ref(new Set<number>());

  // 7. Watch
  watch(
    () => props.sessions,
    (val) => { sessions.value = val ?? []; },
    { deep: true }
  );

  watch(
    () => props.loading,
    (val) => { loading.value = val ?? false; }
  );

  // 8. Computed
  // (none)

  // 9. OnMounted
  onMounted(() => {
    sessions.value = props.sessions ?? [];
  });

  // 10. Functions
  const toggleExpand = (params: { sessionId: number }): void => {
    const { sessionId } = params;
    const set = new Set(expandedSessions.value);
    if (set.has(sessionId)) {
      set.delete(sessionId);
    } else {
      set.add(sessionId);
    }
    expandedSessions.value = set;
  };

  const parseUA = (userAgent: string): UAParser.IResult | null => {
    if (!userAgent || userAgent === '-' || userAgent === '') return null;
    try {
      const parser = new UAParser(userAgent);
      return parser.getResult();
    } catch {
      return null;
    }
  };

  const getDeviceInfo = (params: { session: UserSession }): { browser: string; os: string; device: string; type: string } => {
    const { session } = params;
    const parsed = parseUA(session.user_agent);
    if (!parsed) {
      return { browser: 'Desconocido', os: 'Desconocido', device: 'Desconocido', type: 'desktop' };
    }
    const browser = parsed.browser.name || 'Desconocido';
    const browserVer = parsed.browser.version ? ` ${parsed.browser.version.split('.')[0]}` : '';
    const os = parsed.os.name || 'Desconocido';
    const device = parsed.device.model || '';
    const vendor = parsed.device.vendor || '';
    const type = parsed.device.type || 'desktop';
    const deviceStr = [vendor, device].filter(Boolean).join(' ') || os;
    return { browser: `${browser}${browserVer}`, os, device: deviceStr, type };
  };

  const getDeviceIcon = (params: { session: UserSession }): string => {
    const { session } = params;
    const parsed = parseUA(session.user_agent);
    if (!parsed) return laptopOutline;
    const type = parsed.device.type || 'desktop';
    if (type === 'mobile') return phonePortraitOutline;
    if (type === 'tablet') return tabletPortraitOutline;
    const browser = parsed.browser.name?.toLowerCase() || '';
    if (browser.includes('chrome') || browser.includes('edge')) return logoChrome;
    if (browser.includes('firefox')) return logoFirefox;
    if (browser.includes('safari')) return globeOutline;
    return laptopOutline;
  };

  const getDeviceName = (params: { session: UserSession }): string => {
    const { session } = params;
    const info = getDeviceInfo({ session });
    if (info.browser !== 'Desconocido') {
      return `${info.browser} — ${info.os}`;
    }
    return '';
  };

  const getDeviceDetail = (params: { session: UserSession }): string => {
    const { session } = params;
    const info = getDeviceInfo({ session });
    if (info.device !== 'Desconocido') {
      return `${info.device} · ${session.ip_address}`;
    }
    return session.ip_address;
  };

  const getDeviceType = (params: { session: UserSession }): string => {
    const { session } = params;
    const info = getDeviceInfo({ session });
    const typeMap: Record<string, string> = {
      desktop: 'Ordenador',
      mobile: 'Teléfono',
      tablet: 'Tablet',
      console: 'Consola',
      wearable: 'Reloj',
      embedded: 'Dispositivo',
    };
    return typeMap[info.type] || info.type;
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

  const revoke = (sessionId: number): void => {
    emit('revoke', sessionId);
  };

  const handleRevokeAll = (): void => {
    emit('revokeAll');
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

.session-details {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 10px 16px;
  margin: 0 16px 8px;
  background: rgba(var(--ion-color-primary-rgb), 0.05);
  border-radius: 4px;
}

.detail-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  color: var(--text-secondary);
}

.detail-row ion-icon {
  font-size: 14px;
  flex-shrink: 0;
}
</style>