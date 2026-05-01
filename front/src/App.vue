<template>
  <ion-app>
    <template v-if="isAuthenticated">
      <ion-menu side="start" content-id="main-content" type="overlay">
        <ion-header>
          <ion-toolbar>
            <ion-title>SSO Admin</ion-title>
          </ion-toolbar>
        </ion-header>
        <ion-content>
          <div class="user-info" v-if="currentUser">
            <div class="user-avatar">{{ getInitials(currentUser.name) }}</div>
            <div class="user-details">
              <span class="user-name">{{ currentUser.name }}</span>
              <span class="user-email">{{ currentUser.email }}</span>
            </div>
          </div>
          <ion-list>
            <ion-menu-toggle :auto-hide="false">
              <ion-item button @click="navigateTo('/admin/dashboard')">
                <ion-icon slot="start" :icon="speedometer"></ion-icon>
                <ion-label>Dashboard</ion-label>
              </ion-item>
            </ion-menu-toggle>
            <ion-menu-toggle :auto-hide="false">
              <ion-item button @click="navigateTo('/admin/users')">
                <ion-icon slot="start" :icon="people"></ion-icon>
                <ion-label>Usuarios</ion-label>
              </ion-item>
            </ion-menu-toggle>
            <ion-menu-toggle :auto-hide="false">
              <ion-item button @click="navigateTo('/admin/sessions')">
                <ion-icon slot="start" :icon="key"></ion-icon>
                <ion-label>Sesiones</ion-label>
              </ion-item>
            </ion-menu-toggle>
            <ion-menu-toggle :auto-hide="false">
              <ion-item button @click="navigateTo('/admin/clients')">
                <ion-icon slot="start" :icon="desktop"></ion-icon>
                <ion-label>Clientes SSO</ion-label>
              </ion-item>
            </ion-menu-toggle>
            <ion-menu-toggle :auto-hide="false">
              <ion-item button @click="navigateTo('/admin/roles')">
                <ion-icon slot="start" :icon="shield"></ion-icon>
                <ion-label>Roles</ion-label>
              </ion-item>
            </ion-menu-toggle>
          </ion-list>
          <div class="logout-section">
            <ion-button expand="block" color="danger" @click="handleLogout">
              <ion-icon slot="start" :icon="logOutOutline"></ion-icon>
              Cerrar Sesión
            </ion-button>
          </div>
        </ion-content>
      </ion-menu>
      <ion-router-outlet id="main-content" />
    </template>
    <ion-router-outlet v-else />
  </ion-app>
</template>

<script setup lang="ts">
import { IonApp, IonRouterOutlet, IonMenu, IonHeader, IonToolbar, IonTitle, IonContent, IonList, IonMenuToggle, IonItem, IonIcon, IonLabel, IonButton, menuController } from '@ionic/vue';
import { useRouter } from 'vue-router';
import { speedometer, people, key, desktop, shield, logOutOutline } from 'ionicons/icons';
import { isAuthenticated, currentUser, auth } from '@/services/auth';

const router = useRouter();

const navigateTo = async (url: string) => {
  await menuController.close();
  router.push(url);
};

const getInitials = (name: string) => {
  return name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2);
};

const handleLogout = async () => {
  await menuController.close();
  await auth.logout();
  router.replace('/login');
};
</script>

<style scoped>
.user-info {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
}

.user-avatar {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  background: white;
  color: #667eea;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
  font-size: 18px;
}

.user-details {
  display: flex;
  flex-direction: column;
}

.user-name {
  font-weight: bold;
  font-size: 14px;
}

.user-email {
  font-size: 12px;
  opacity: 0.9;
}

.logout-section {
  padding: 16px;
  margin-top: auto;
}

.logout-section ion-button {
  --border-radius: 8px;
}
</style>
