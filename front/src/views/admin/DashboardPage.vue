<template>
  <ion-page>
    <ion-header class="ion-no-border dashboard-header" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-menu-button></ion-menu-button>
        </ion-buttons>
        <ion-title>Dashboard SSO</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <div class="dashboard-container">
        <section class="welcome-section">
          <div class="welcome-content">
            <h1 class="welcome-title">Bienvenido de nuevo</h1>
            <p class="welcome-date">{{ formattedDate }}</p>
          </div>
          <div class="welcome-icon">
            <ion-icon :icon="shield"></ion-icon>
          </div>
        </section>

        <section class="stats-section">
          <h2 class="section-title">Resumen del Sistema</h2>
          <ion-grid class="stats-grid">
            <ion-row>
              <ion-col size="12" size-sm="6" size-lg="4">
                <div class="stat-card" @click="router.push('/admin/users')">
                  <div class="stat-card-accent users-accent"></div>
                  <div class="stat-icon-wrapper">
                    <div class="stat-icon users-icon">
                      <ion-icon :icon="people"></ion-icon>
                    </div>
                  </div>
                  <div class="stat-content">
                    <span class="stat-value">{{ loading ? '...' : stats.totalUsers }}</span>
                    <span class="stat-label">Usuarios</span>
                  </div>
                  <div class="stat-arrow">
                    <ion-icon :icon="chevronForward"></ion-icon>
                  </div>
                </div>
              </ion-col>

              <ion-col size="12" size-sm="6" size-lg="4">
                <div class="stat-card" @click="router.push('/admin/sessions')">
                  <div class="stat-card-accent sessions-accent"></div>
                  <div class="stat-icon-wrapper">
                    <div class="stat-icon sessions-icon">
                      <ion-icon :icon="key"></ion-icon>
                    </div>
                  </div>
                  <div class="stat-content">
                    <span class="stat-value">{{ loading ? '...' : stats.activeSessions }}</span>
                    <span class="stat-label">Sesiones Activas</span>
                  </div>
                  <div class="stat-arrow">
                    <ion-icon :icon="chevronForward"></ion-icon>
                  </div>
                </div>
              </ion-col>

              <ion-col size="12" size-sm="6" size-lg="4">
                <div class="stat-card" @click="router.push('/admin/clients')">
                  <div class="stat-card-accent clients-accent"></div>
                  <div class="stat-icon-wrapper">
                    <div class="stat-icon clients-icon">
                      <ion-icon :icon="desktop"></ion-icon>
                    </div>
                  </div>
                  <div class="stat-content">
                    <span class="stat-value">{{ loading ? '...' : stats.totalClients }}</span>
                    <span class="stat-label">Clientes SSO</span>
                  </div>
                  <div class="stat-arrow">
                    <ion-icon :icon="chevronForward"></ion-icon>
                  </div>
                </div>
              </ion-col>
            </ion-row>
          </ion-grid>
        </section>

        <section class="system-status-section">
          <h2 class="section-title">Estado del Sistema</h2>
          <ion-grid class="status-grid">
            <ion-row>
              <ion-col size="12" size-md="4">
                <div class="status-card">
                  <div class="status-indicator online"></div>
                  <div class="status-info">
                    <span class="status-label">Servidor API</span>
                    <span class="status-value">Operativo</span>
                  </div>
                </div>
              </ion-col>

              <ion-col size="12" size-md="4">
                <div class="status-card">
                  <div class="status-indicator online"></div>
                  <div class="status-info">
                    <span class="status-label">Base de Datos</span>
                    <span class="status-value">Conectada</span>
                  </div>
                </div>
              </ion-col>

              <ion-col size="12" size-md="4">
                <div class="status-card">
                  <div class="status-indicator online"></div>
                  <div class="status-info">
                    <span class="status-label">SSO Activo</span>
                    <span class="status-value">{{ stats.activeSessions }} sesiones</span>
                  </div>
                </div>
              </ion-col>
            </ion-row>
          </ion-grid>
        </section>

        <section class="quick-actions-section">
          <h2 class="section-title">Acciones Rápidas</h2>
          <ion-grid class="quick-actions-grid">
            <ion-row>
              <ion-col size="6" size-md="3">
                <ion-button class="action-btn" @click="router.push('/admin/users')">
                  <ion-icon slot="start" :icon="personAdd"></ion-icon>
                  <span>Nuevo Usuario</span>
                </ion-button>
              </ion-col>

              <ion-col size="6" size-md="3">
                <ion-button class="action-btn" @click="router.push('/admin/clients')">
                  <ion-icon slot="start" :icon="addCircle"></ion-icon>
                  <span>Nuevo Cliente</span>
                </ion-button>
              </ion-col>

              <ion-col size="6" size-md="3">
                <ion-button class="action-btn" @click="router.push('/admin/roles')">
                  <ion-icon slot="start" :icon="pricetag"></ion-icon>
                  <span>Gestionar Roles</span>
                </ion-button>
              </ion-col>

              <ion-col size="6" size-md="3">
                <ion-button class="action-btn secondary" @click="router.push('/admin/sessions')">
                  <ion-icon slot="start" :icon="time"></ion-icon>
                  <span>Ver Sesiones</span>
                </ion-button>
              </ion-col>
            </ion-row>
          </ion-grid>
        </section>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
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
    IonButton,
    IonIcon,
    IonGrid,
    IonRow,
    IonCol,
  } from '@ionic/vue';
  import {
    people,
    key,
    desktop,
    chevronForward,
    shield,
    personAdd,
    addCircle,
    pricetag,
    time,
  } from 'ionicons/icons';
  import {
    api,
    type User,
    type SsoClient,
    type PaginatedResponse,
  } from '@/services/api';

  const router = useRouter();

  const loading = ref(true);
  const stats = ref({
    totalUsers: 0,
    activeSessions: 0,
    totalClients: 0,
  });

  const formattedDate = computed(() => {
    const now = new Date();
    const options: Intl.DateTimeFormatOptions = {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    };
    return now.toLocaleDateString('es-ES', options);
  });

  const loadStats = async () => {
    loading.value = true;
    try {
      const [usersRes, sessionsRes, clientsRes] = await Promise.all([
        api.get<PaginatedResponse<User>>('/admin/users?per_page=1'),
        api.get<PaginatedResponse<any>>('/admin/sessions?per_page=1&active=1'),
        api.get<SsoClient[]>('/admin/clients'),
      ]);

      stats.value = {
        totalUsers: usersRes.total,
        activeSessions: sessionsRes.total || 0,
        totalClients: clientsRes.length,
      };
    } catch (error) {
      console.error('Error loading stats:', error);
    } finally {
      loading.value = false;
    }
  };

  onMounted(() => {
    loadStats();
  });
</script>

<style scoped>
.dashboard-container {
  padding: var(--dashboard-padding);
  max-width: 1400px;
  margin: 0 auto;
}

@media (min-width: 768px) {
  .dashboard-container {
    padding: var(--dashboard-padding-lg);
  }
}

.section-title {
  font-size: 14px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--text-tertiary);
  margin: 0 0 16px 0;
}

.welcome-section {
  background: var(--gradient-primary);
  border-radius: var(--card-border-radius);
  padding: 24px;
  margin-bottom: 24px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  position: relative;
  overflow: hidden;
}

@media (min-width: 768px) {
  .welcome-section {
    padding: 32px;
  }
}

.welcome-section::before {
  content: '';
  position: absolute;
  top: -50%;
  right: -20%;
  width: 200px;
  height: 200px;
  background: rgba(255, 255, 255, 0.1);
  border-radius: 50%;
}

.welcome-section::after {
  content: '';
  position: absolute;
  bottom: -30%;
  left: 10%;
  width: 100px;
  height: 100px;
  background: rgba(255, 255, 255, 0.08);
  border-radius: 50%;
}

.welcome-content {
  position: relative;
  z-index: 1;
}

.welcome-title {
  font-size: 24px;
  font-weight: 700;
  color: var(--welcome-section-text);
  margin: 0 0 4px 0;
}

@media (min-width: 768px) {
  .welcome-title {
    font-size: 28px;
  }
}

.welcome-date {
  font-size: 14px;
  color: rgba(255, 255, 255, 0.85);
  margin: 0;
}

.welcome-icon {
  width: 56px;
  height: 56px;
  background: rgba(255, 255, 255, 0.2);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  z-index: 1;
}

.welcome-icon ion-icon {
  font-size: 28px;
  color: white;
}

.stats-section {
  margin-bottom: 24px;
}

.stats-grid {
  padding: 0;
  --ion-grid-padding: 0;
}

.stats-grid ion-col {
  padding: 0 0 12px 0;
}

.stat-card {
  background: var(--background-contrast);
  border-radius: var(--card-border-radius);
  padding: 16px;
  display: flex;
  align-items: center;
  gap: 12px;
  box-shadow: var(--card-shadow-light) 0px 1px 3px 0px, var(--card-shadow-heavy) 0px 0px 0px 1px;
  cursor: pointer;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
  position: relative;
  overflow: hidden;
  height: 100%;
}

@media (min-width: 768px) {
  .stat-card {
    padding: 20px;
    gap: 16px;
  }
}

.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
}

@media (prefers-reduced-motion: reduce) {
  .stat-card:hover {
    transform: none;
  }
}

.stat-card-accent {
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 4px;
  border-radius: 4px 0 0 4px;
}

.users-accent {
  background: var(--gradient-users);
}

.sessions-accent {
  background: var(--gradient-sessions);
}

.clients-accent {
  background: var(--gradient-clients);
}

.stat-icon-wrapper {
  flex-shrink: 0;
}

.stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
}

@media (min-width: 768px) {
  .stat-icon {
    width: 48px;
    height: 48px;
  }
}

.stat-icon ion-icon {
  font-size: 22px;
  color: #fff;
}

.users-icon {
  background: var(--gradient-users);
}

.sessions-icon {
  background: var(--gradient-sessions);
}

.clients-icon {
  background: var(--gradient-clients);
}

.stat-content {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.stat-value {
  font-size: 26px;
  font-weight: 700;
  color: var(--text-primary);
  line-height: 1.2;
}

@media (min-width: 768px) {
  .stat-value {
    font-size: 32px;
  }
}

.stat-label {
  font-size: 13px;
  font-weight: 500;
  color: var(--text-secondary);
  margin-top: 2px;
}

.stat-arrow {
  flex-shrink: 0;
  color: var(--text-tertiary);
  display: flex;
  align-items: center;
}

.stat-arrow ion-icon {
  font-size: 20px;
}

.system-status-section {
  margin-bottom: 24px;
}

.status-grid {
  padding: 0;
  --ion-grid-padding: 0;
}

.status-grid ion-col {
  padding: 0 0 12px 0;
}

.status-card {
  background: var(--system-status-bg);
  border-radius: 12px;
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 12px;
  box-shadow: var(--card-shadow-subtle) 0px 0px 0px 1px;
  height: 100%;
}

.status-indicator {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}

.status-indicator.online {
  background: var(--ion-color-success);
  box-shadow: 0 0 8px var(--ion-color-success);
}

.status-indicator.offline {
  background: var(--ion-color-danger);
  box-shadow: 0 0 8px var(--ion-color-danger);
}

.status-info {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.status-label {
  font-size: 12px;
  font-weight: 500;
  color: var(--text-tertiary);
}

.status-value {
  font-size: 14px;
  font-weight: 600;
  color: var(--text-primary);
}

.quick-actions-section {
  margin-bottom: 24px;
}

.quick-actions-grid {
  padding: 0;
  --ion-grid-padding: 0;
}

.quick-actions-grid ion-col {
  padding: 0 0 12px 0;
}

.action-btn {
  --border-radius: 12px;
  --padding-start: 16px;
  --padding-end: 16px;
  height: 48px;
  font-size: 13px;
  font-weight: 500;
  text-transform: none;
  --background: var(--ion-color-primary);
  --color: white;
  margin: 0;
  width: 100%;
}

.action-btn.secondary {
  --background: var(--ion-color-light);
  --color: var(--ion-color-dark);
}

.action-btn ion-icon {
  font-size: 18px;
  margin-right: 8px;
}

@media (prefers-reduced-motion: reduce) {
  .stat-card,
  .action-btn {
    transition: none;
  }
}
</style>