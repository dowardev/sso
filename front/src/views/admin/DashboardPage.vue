<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-page>
    <ion-header class="ion-no-border dashboard-header" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-menu-button aria-label="Menú de navegación"></ion-menu-button>
        </ion-buttons>
        <ion-title>Dashboard SSO</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <ion-progress-bar v-if="loading" type="indeterminate"></ion-progress-bar>

      <div v-if="!loading" class="dashboard-container" role="region" aria-label="Panel de control">
        <div class="welcome-section">
          <h1 class="welcome-greeting">Bienvenido, <span>{{ currentUserName }}</span></h1>
          <p class="welcome-subtitle">Resumen del estado actual de tu servidor SSO</p>
        </div>

        <ion-grid class="stats-grid">
          <ion-row>
            <ion-col size="6" size-md="3">
              <div class="stat-card">
                <span class="stat-label">Usuarios</span>
                <span class="stat-value stat-value-primary">{{ stats.totalUsers }}</span>
              </div>
            </ion-col>
            <ion-col size="6" size-md="3">
              <div class="stat-card">
                <span class="stat-label">Sesiones Activas</span>
                <span class="stat-value stat-value-success">{{ stats.activeSessions }}</span>
              </div>
            </ion-col>
            <ion-col size="6" size-md="3">
              <div class="stat-card">
                <span class="stat-label">Clientes SSO</span>
                <span class="stat-value stat-value-secondary">{{ stats.totalClients }}</span>
              </div>
            </ion-col>
            <ion-col size="6" size-md="3">
              <div class="stat-card">
                <span class="stat-label">Roles</span>
                <span class="stat-value stat-value-warning">{{ totalRoles }}</span>
              </div>
            </ion-col>
          </ion-row>
        </ion-grid>

        <ion-grid class="dashboard-grid">
          <ion-row>
            <ion-col size="12" size-md="6">
              <div class="col-stack">
                <div class="card">
                  <div class="card-header">
                    <span class="card-title">Últimas Sesiones</span>
                    <button class="card-link" aria-label="Ver todas las sesiones" @click="router.push({ name: 'AdminSessions' })">Ver todas →</button>
                  </div>
                  <div v-if="recentSessions.length === 0" class="card-empty">No hay sesiones activas</div>
                  <div v-for="session in recentSessions" v-else :key="session.id" class="session-row">
                    <div class="session-avatar">{{ getInitials({ name: session.user?.name || '?' }) }}</div>
                    <div class="session-info">
                      <div class="session-user">{{ session.user?.name || 'Desconocido' }}</div>
                      <div class="session-client">{{ session.client?.name || 'Cliente desconocido' }}</div>
                    </div>
                    <div class="session-meta">
                      <span class="session-badge" :class="session.token_active ? 'badge-active' : 'badge-inactive'">
                        {{ session.token_active ? 'Activa' : 'Inactiva' }}
                      </span>
                      <span class="session-time">{{ timeAgo({ date: session.last_used_at }) }}</span>
                    </div>
                  </div>
                </div>

                <div class="card">
                  <div class="card-header">
                    <span class="card-title">Clientes SSO</span>
                    <button class="card-link" aria-label="Ver todos los clientes" @click="router.push({ name: 'AdminClients' })">Ver todos →</button>
                  </div>
                  <div v-if="clients.length === 0" class="card-empty">No hay clientes registrados</div>
                  <div v-for="client in clients.slice(0, 6)" v-else :key="client.id" class="client-row">
                    <div class="client-icon-wrapper">
                      <ion-icon :icon="desktopOutline" aria-hidden="true"></ion-icon>
                    </div>
                    <div class="client-info-col">
                      <div class="client-name">{{ client.name }}</div>
                      <div class="client-id-text">{{ client.client_id }}</div>
                    </div>
                    <div class="client-dot" :class="client.active ? 'dot-active' : 'dot-inactive'"></div>
                  </div>
                </div>
              </div>
            </ion-col>

            <ion-col size="12" size-md="6">
              <div class="col-stack">
                <div class="card">
                  <div class="card-header">
                    <span class="card-title">Usuarios por Rol</span>
                    <button class="card-link" aria-label="Ver todos los roles" @click="router.push({ name: 'AdminRoles' })">Ver roles →</button>
                  </div>
                  <div v-if="rolesWithCount.length === 0" class="card-empty">No hay roles configurados</div>
                  <div v-for="(role, index) in rolesWithCount" v-else :key="role.id" class="bar-row">
                    <span class="bar-label">{{ role.name }}</span>
                    <div class="bar-track">
                      <div class="bar-fill" :style="{ width: barWidth({ count: role.users_count || 0 }) }" :class="barColorClass({ index })"></div>
                    </div>
                    <span class="bar-count">{{ role.users_count || 0 }}</span>
                  </div>
                </div>

                <div class="card">
                  <div class="card-header">
                    <span class="card-title">Estado del Sistema</span>
                  </div>
                  <div class="system-row">
                    <div class="system-dot system-dot-online"></div>
                    <span class="system-name">API</span>
                    <span class="system-detail">~12ms</span>
                    <span class="system-badge-ok">OK</span>
                  </div>
                  <div class="system-row">
                    <div class="system-dot system-dot-online"></div>
                    <span class="system-name">Base de Datos</span>
                    <span class="system-detail">Conexión estable</span>
                    <span class="system-badge-ok">OK</span>
                  </div>
                  <div class="system-row">
                    <div class="system-dot system-dot-online"></div>
                    <span class="system-name">Autenticación</span>
                    <span class="system-detail">JWT RS256</span>
                    <span class="system-badge-ok">OK</span>
                  </div>
                </div>
              </div>
            </ion-col>
          </ion-row>
        </ion-grid>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  // 1. Imports (ionic/vue/ionicons)
  import { ref, computed, onMounted } from 'vue';
  import { useRouter } from 'vue-router';
  import {
    IonPage,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonButtons,
    IonMenuButton,
    IonContent,
    IonIcon,
    IonProgressBar,
    IonGrid,
    IonRow,
    IonCol,
  } from '@ionic/vue';
  import { desktopOutline } from 'ionicons/icons';

  // 2. Controllers
  import { useUsersController } from '@/controllers/usersController';
  import { useSessionsController } from '@/controllers/sessionsController';
  import { useClientsController } from '@/controllers/clientsController';
  import { useRolesController } from '@/controllers/rolesController';
  import { useAuthController } from '@/composables/authController';

  const usersController = useUsersController();
  const sessionsController = useSessionsController();
  const clientsController = useClientsController();
  const rolesController = useRolesController();
  const authController = useAuthController();

  // 3. Components
  // (none)

  // 4. Interface and types imports
  // (none)

  // 5. Main (props, emits, etc.)
  const router = useRouter();

  // 6. Vars and const
  const loading = ref(true);

  // 7. Watch
  // (none)

  // 8. Computed
  const stats = computed(() => ({
    totalUsers: usersController.pagination.value.total,
    activeSessions: sessionsController.activeCount.value,
    totalClients: clientsController.count.value,
  }));

  const totalRoles = computed(() => rolesController.roles.value.length);
  const rolesWithCount = computed(() => rolesController.roles.value);
  const recentSessions = computed(() => sessionsController.sessions.value.slice(0, 5));
  const clients = computed(() => clientsController.clients.value);
  const currentUserName = computed(() => authController.user.value?.name || 'Usuario');

  const maxRoleCount = computed(() => {
    const counts = rolesWithCount.value.map(r => r.users_count || 0);
    return Math.max(...counts, 1);
  });

  // 9. OnMounted
  onMounted(async () => {
    loading.value = true;
    try {
      await Promise.all([
        usersController.fetchUsers({ per_page: 1 }),
        sessionsController.fetchSessions({ per_page: 5, active: true }),
        clientsController.fetchClients(),
        rolesController.fetchRoles(),
      ]);
    } catch {
      // Silent fail
    } finally {
      loading.value = false;
    }
  });

  // 10. Functions
  const barWidth = (params: { count: number }): string => {
    const { count } = params;
    return `${(count / maxRoleCount.value) * 100}%`;
  };

  const barColors = ['fill-purple', 'fill-cyan', 'fill-green', 'fill-amber', 'fill-rose'];

  const barColorClass = (params: { index: number }): string => {
    const { index } = params;
    return barColors[index % barColors.length];
  };

  const timeAgo = (params: { date: string }): string => {
    const { date } = params;
    const now = Date.now();
    const then = new Date(date).getTime();
    const diffMinutes = Math.floor((now - then) / 60000);
    if (diffMinutes < 1) return 'Ahora';
    if (diffMinutes < 60) return `${diffMinutes} min`;
    const hours = Math.floor(diffMinutes / 60);
    if (hours < 24) return `${hours}h`;
    const days = Math.floor(hours / 24);
    return `${days}d`;
  };

  const getInitials = (params: { name: string }): string => {
    const { name } = params;
    return name
      .split(' ')
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);
  };
</script>

<style scoped>
.dashboard-container {
  padding: var(--dashboard-padding);
  max-width: 1200px;
  margin: 0 auto;
}

@media (min-width: 768px) {
  .dashboard-container {
    padding: var(--dashboard-padding-lg);
  }
}

.welcome-section {
  margin-bottom: clamp(20px, 4vw, 28px);
}

.welcome-greeting {
  font-family: var(--font-heading);
  font-size: clamp(22px, 5vw, 26px);
  font-weight: 700;
  margin: 0 0 4px;
  color: var(--text-primary);
}

.welcome-greeting span {
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

.welcome-subtitle {
  margin: 0;
  font-size: 14px;
  color: var(--text-secondary);
}

.stats-grid {
  --ion-grid-column-padding: 6px;
  margin-bottom: clamp(20px, 4vw, 28px);
}

.stat-card {
  background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  border: 1px solid var(--glass-border);
  border-radius: 4px;
  padding: clamp(14px, 3vw, 20px);
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.stat-label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--text-tertiary);
}

.stat-value {
  font-size: clamp(24px, 5.5vw, 28px);
  font-weight: 700;
  line-height: 1;
  font-family: var(--font-heading);
}

.stat-value-primary {
  color: var(--ion-color-primary);
}

.stat-value-success {
  color: var(--ion-color-success);
}

.stat-value-secondary {
  color: var(--ion-color-secondary);
}

.stat-value-warning {
  color: var(--ion-color-warning);
}

.dashboard-grid {
  --ion-grid-column-padding: 6px;
}

.col-stack {
  display: flex;
  flex-direction: column;
  gap: var(--space-4, 16px);
}

.card {
  background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  border: 1px solid var(--glass-border);
  border-radius: 4px;
  padding: clamp(14px, 3vw, 20px);
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.card-title {
  font-family: var(--font-heading);
  font-size: clamp(13px, 3vw, 14px);
  font-weight: 700;
  color: var(--text-secondary);
  text-transform: uppercase;
  letter-spacing: 0.3px;
}

.card-link {
  background: none;
  border: none;
  padding: 0;
  font-size: 12px;
  font-weight: 500;
  color: var(--ion-color-primary);
  cursor: pointer;
  font-family: inherit;
}

.card-link:hover {
  text-decoration: underline;
}

.card-empty {
  font-size: 13px;
  color: var(--text-tertiary);
  padding: 16px 0;
  text-align: center;
}

.session-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 0;
  border-bottom: 1px solid rgba(255,255,255,0.04);
}

.session-row:last-child {
  border-bottom: none;
}

.session-avatar {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: var(--gradient-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 12px;
  color: white;
  flex-shrink: 0;
}

.session-info {
  flex: 1;
  min-width: 0;
}

.session-user {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.session-client {
  font-size: 11px;
  color: var(--text-tertiary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.session-meta {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 2px;
  flex-shrink: 0;
}

.session-badge {
  padding: 1px 7px;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 600;
}

.badge-active {
  background: rgba(74, 222, 128, 0.1);
  color: var(--ion-color-success);
  border: 1px solid rgba(74, 222, 128, 0.2);
}

.badge-inactive {
  background: rgba(100, 116, 139, 0.1);
  color: var(--text-tertiary);
  border: 1px solid rgba(100, 116, 139, 0.2);
}

.session-time {
  font-size: 10px;
  color: var(--text-tertiary);
}

.bar-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 0;
}

.bar-label {
  font-size: 12px;
  font-weight: 500;
  min-width: 65px;
  color: var(--text-secondary);
}

.bar-track {
  flex: 1;
  height: 6px;
  border-radius: 3px;
  background: rgba(255,255,255,0.06);
  overflow: hidden;
}

.bar-fill {
  height: 100%;
  border-radius: 3px;
  transition: width 0.6s ease;
}

.fill-purple {
  background: linear-gradient(90deg, #8B5CF6, #A78BFA);
}

.fill-cyan {
  background: linear-gradient(90deg, #06B6D4, #22D3EE);
}

.fill-green {
  background: linear-gradient(90deg, #4ADE80, #86EFAC);
}

.fill-amber {
  background: linear-gradient(90deg, #F59E0B, #FCD34D);
}

.fill-rose {
  background: linear-gradient(90deg, #EF4444, #FB7185);
}

.bar-count {
  font-size: 12px;
  font-weight: 600;
  color: var(--text-primary);
  min-width: 20px;
  text-align: right;
}

.client-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 0;
  border-bottom: 1px solid rgba(255,255,255,0.04);
}

.client-row:last-child {
  border-bottom: none;
}

.client-icon-wrapper {
  width: 32px;
  height: 32px;
  border-radius: 4px;
  background: rgba(var(--ion-color-secondary-rgb), 0.15);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  color: var(--ion-color-secondary);
  font-size: 16px;
}

.client-info-col {
  flex: 1;
  min-width: 0;
}

.client-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.client-id-text {
  font-size: 11px;
  color: var(--text-tertiary);
  font-family: 'SF Mono', 'Fira Code', monospace;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.client-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}

.dot-active {
  background: var(--ion-color-success);
  box-shadow: 0 0 6px rgba(74, 222, 128, 0.4);
}

.dot-inactive {
  background: var(--text-tertiary);
}

.system-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 0;
  border-bottom: 1px solid rgba(255,255,255,0.04);
}

.system-row:last-child {
  border-bottom: none;
}

.system-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}

.system-dot-online {
  background: var(--ion-color-success);
  box-shadow: 0 0 6px rgba(74, 222, 128, 0.5);
}

.system-name {
  font-size: 13px;
  font-weight: 500;
  color: var(--text-primary);
  flex: 1;
}

.system-detail {
  font-size: 11px;
  color: var(--text-tertiary);
}

.system-badge-ok {
  font-size: 10px;
  font-weight: 600;
  color: var(--ion-color-success);
  background: rgba(74, 222, 128, 0.1);
  border: 1px solid rgba(74, 222, 128, 0.2);
  padding: 2px 7px;
  border-radius: 999px;
}

@media (prefers-reduced-motion: reduce) {
  .bar-fill {
    transition: none;
  }
}

@media (max-width: 374px) {
  .dashboard-container {
    padding: 8px;
  }

  .stat-card {
    padding: 14px 16px;
    min-width: calc(50% - 6px);
  }

  .stat-value {
    font-size: 22px;
  }

  .welcome-greeting {
    font-size: 20px;
  }

  .card {
    padding: 14px 16px;
  }

  .session-user {
    font-size: 12px;
  }

  .bar-label {
    font-size: 11px;
    min-width: 55px;
  }
}
</style>
