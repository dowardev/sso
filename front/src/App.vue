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
          <div v-if="currentUser" class="user-info">
            <div class="user-avatar">{{ getInitials(currentUser.name) }}</div>
            <div class="user-details">
              <span class="user-name">{{ currentUser.name }}</span>
              <span class="user-email">{{ currentUser.email }}</span>
            </div>
          </div>
          <ion-list class="nav-list">
            <ion-menu-toggle :auto-hide="false">
              <ion-item
                button
                @click="navigateTo('/admin/dashboard')"
                :class="{ 'active-item': isActiveRoute('/admin/dashboard') }"
              >
                <ion-icon slot="start" :icon="speedometer"></ion-icon>
                <ion-label>Dashboard</ion-label>
              </ion-item>
            </ion-menu-toggle>
            <ion-menu-toggle :auto-hide="false">
              <ion-item
                button
                @click="navigateTo('/admin/users')"
                :class="{ 'active-item': isActiveRoute('/admin/users') }"
              >
                <ion-icon slot="start" :icon="people"></ion-icon>
                <ion-label>Usuarios</ion-label>
              </ion-item>
            </ion-menu-toggle>
            <ion-menu-toggle :auto-hide="false">
              <ion-item
                button
                @click="navigateTo('/admin/sessions')"
                :class="{ 'active-item': isActiveRoute('/admin/sessions') }"
              >
                <ion-icon slot="start" :icon="key"></ion-icon>
                <ion-label>Sesiones</ion-label>
              </ion-item>
            </ion-menu-toggle>
            <ion-menu-toggle :auto-hide="false">
              <ion-item
                button
                @click="navigateTo('/admin/clients')"
                :class="{ 'active-item': isActiveRoute('/admin/clients') }"
              >
                <ion-icon slot="start" :icon="desktop"></ion-icon>
                <ion-label>Clientes SSO</ion-label>
              </ion-item>
            </ion-menu-toggle>
            <ion-menu-toggle :auto-hide="false">
              <ion-item
                button
                @click="navigateTo('/admin/roles')"
                :class="{ 'active-item': isActiveRoute('/admin/roles') }"
              >
                <ion-icon slot="start" :icon="shield"></ion-icon>
                <ion-label>Roles</ion-label>
              </ion-item>
            </ion-menu-toggle>
            <ion-menu-toggle :auto-hide="false">
              <ion-item
                button
                @click="navigateTo('/admin/config')"
                :class="{ 'active-item': isActiveRoute('/admin/config') }"
              >
                <ion-icon slot="start" :icon="settingsOutline"></ion-icon>
                <ion-label>Configuración</ion-label>
              </ion-item>
            </ion-menu-toggle>
          </ion-list>
          <div class="nav-divider"></div>
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
  import { computed } from 'vue';
  import {
    IonApp,
    IonRouterOutlet,
    IonMenu,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonContent,
    IonList,
    IonMenuToggle,
    IonItem,
    IonIcon,
    IonLabel,
    IonButton,
    menuController,
  } from '@ionic/vue';
  import { useRouter, useRoute } from 'vue-router';
  import {
    speedometer,
    people,
    key,
    desktop,
    shield,
    logOutOutline,
    settingsOutline,
  } from 'ionicons/icons';
  import { useAuthStore } from '@/stores/auth';

  const router = useRouter();
  const route = useRoute();

  const authStore = useAuthStore();
  const isAuthenticated = authStore.isAuthenticated;
  const currentUser = authStore.user;

  const isActiveRoute = (path: string) => {
    return route.path === path || route.path.startsWith(path + '/');
  };

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
    await authStore.logout();
    router.replace('/login');
  };
</script>

<style scoped>
  .user-info {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 20px 16px;
    background: var(--gradient-primary);
    color: white;
  }

  .user-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 18px;
    color: white;
    border: 2px solid rgba(255, 255, 255, 0.3);
  }

  .user-details {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }

  .user-name {
    font-weight: 600;
    font-size: 15px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .user-email {
    font-size: 12px;
    opacity: 0.85;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .nav-list {
    padding: 8px;
    background: transparent;
  }

  .nav-list ion-item {
    --border-radius: 10px;
    --padding-start: 12px;
    --padding-end: 12px;
    margin-bottom: 4px;
    --background: transparent;
    --background-hover: var(--ion-color-light);
    --background-activated: var(--ion-color-light);
    transition: background 0.2s ease;
  }

  @media (prefers-reduced-motion: reduce) {
    .nav-list ion-item {
      transition: none;
    }
  }

  .nav-list ion-item.active-item {
    --background: var(--ion-color-primary-tint);
    --background-hover: var(--ion-color-primary-tint);
  }

  .nav-list ion-item.active-item::part(native) {
    background: var(--ion-color-primary-tint);
  }

  .nav-list ion-item.active-item ion-label {
    font-weight: 600;
    color: var(--ion-color-primary);
  }

  .nav-list ion-item.active-item ion-icon {
    color: var(--ion-color-primary);
  }

  .nav-list ion-icon {
    color: var(--text-secondary);
  }

  .nav-divider {
    height: 1px;
    background: var(--ion-background-color-step-200);
    margin: 8px 16px;
  }

  .logout-section {
    padding: 16px;
  }

  .logout-section ion-button {
    --border-radius: 10px;
  }
</style>