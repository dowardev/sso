<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-menu-button></ion-menu-button>
        </ion-buttons>
        <ion-title>Dashboard SSO</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <div class="dashboard-container">
        <div class="stats-grid">
          <div class="stat-card" @click="router.push('/admin/users')">
            <div class="stat-icon users-icon">
              <ion-icon :icon="people"></ion-icon>
            </div>
            <div class="stat-info">
              <span class="stat-value">{{ stats.totalUsers }}</span>
              <span class="stat-label">Usuarios</span>
            </div>
          </div>

          <div class="stat-card" @click="router.push('/admin/sessions')">
            <div class="stat-icon sessions-icon">
              <ion-icon :icon="key"></ion-icon>
            </div>
            <div class="stat-info">
              <span class="stat-value">{{ stats.activeSessions }}</span>
              <span class="stat-label">Sesiones Activas</span>
            </div>
          </div>

          <div class="stat-card" @click="router.push('/admin/clients')">
            <div class="stat-icon clients-icon">
              <ion-icon :icon="desktop"></ion-icon>
            </div>
            <div class="stat-info">
              <span class="stat-value">{{ stats.totalClients }}</span>
              <span class="stat-label">Clientes SSO</span>
            </div>
          </div>
        </div>

        <div class="recent-section">
          <h3>Acciones Rápidas</h3>
          <div class="quick-actions">
            <ion-button expand="block" @click="router.push('/admin/users')">
              <ion-icon slot="start" :icon="people"></ion-icon>
              Gestionar Usuarios
            </ion-button>
            <ion-button expand="block" @click="router.push('/admin/sessions')">
              <ion-icon slot="start" :icon="key"></ion-icon>
              Ver Sesiones
            </ion-button>
            <ion-button expand="block" @click="router.push('/admin/clients')">
              <ion-icon slot="start" :icon="desktop"></ion-icon>
              Gestionar Clientes
            </ion-button>
          </div>
        </div>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { IonPage, IonHeader, IonToolbar, IonTitle, IonButtons, IonMenuButton, IonContent, IonButton, IonIcon } from '@ionic/vue';
import { people, key, desktop } from 'ionicons/icons';
import { api, type User, type SsoClient, type PaginatedResponse } from '@/services/api';

const router = useRouter();

const stats = ref({
  totalUsers: 0,
  activeSessions: 0,
  totalClients: 0,
});

const loadStats = async () => {
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
  }
};

onMounted(() => {
  loadStats();
});
</script>

<style scoped>
.dashboard-container {
  padding: 16px;
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}

.stat-card {
  background: var(--ion-item-background, #fff);
  border-radius: 12px;
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 16px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  cursor: pointer;
  transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.stat-icon {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.stat-icon ion-icon {
  font-size: 24px;
  color: #fff;
}

.users-icon {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.sessions-icon {
  background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
}

.clients-icon {
  background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}

.stat-info {
  display: flex;
  flex-direction: column;
}

.stat-value {
  font-size: 28px;
  font-weight: bold;
  color: #333;
}

.stat-label {
  font-size: 14px;
  color: #666;
}

.recent-section h3 {
  margin: 0 0 16px 0;
  font-size: 18px;
  color: #333;
}

.quick-actions {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.quick-actions ion-button {
  --border-radius: 8px;
}
</style>
