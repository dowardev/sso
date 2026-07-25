<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-app>
    <template v-if="showSidebar">
      <ion-split-pane when="md" content-id="main-content">
      <ion-menu side="start" content-id="main-content">
        <ion-header class="ion-no-border">
          <ion-toolbar class="sidebar-header-toolbar">
            <div class="sidebar-brand" aria-label="SSO Server">
              <img alt="SSO Server" src="@/assets/images/lambda_v1.svg" class="sidebar-logo" />
              <span class="sidebar-brand-text">SSO Server</span>
            </div>
          </ion-toolbar>
        </ion-header>
        <ion-content>
          <div v-if="currentUser" class="user-info">
            <div class="user-avatar" aria-hidden="true">
              {{ getInitials({ name: currentUser.name }) }}
            </div>
            <div class="user-details">
              <span class="user-name">{{ currentUser.name }}</span>
              <span class="user-email">{{ currentUser.email }}</span>
              <ion-badge v-if="currentUser.is_admin" color="warning" size="small">Admin</ion-badge>
            </div>
          </div>

          <template v-if="currentUser?.is_admin">
            <ion-list-header class="nav-section-label">Navegaci&oacute;n</ion-list-header>
            <ion-list class="nav-list" role="navigation" aria-label="Menú de navegación principal">
              <ion-menu-toggle :auto-hide="false">
                <ion-item
                  button
                  lines="none"
                  :class="{ 'active-item': isActiveRoute({ path: '/admin/dashboard' }) }"
                  @click="navigateTo({ url: '/admin/dashboard' })"
                >
                  <div slot="start" class="nav-icon-wrapper" :class="{ 'active-icon': isActiveRoute({ path: '/admin/dashboard' }) }">
                    <ion-icon :icon="speedometer" aria-hidden="true"></ion-icon>
                  </div>
                  <ion-label>Dashboard</ion-label>
                </ion-item>
              </ion-menu-toggle>
              <ion-menu-toggle :auto-hide="false">
                <ion-item
                  button
                  lines="none"
                  :class="{ 'active-item': isActiveRoute({ path: '/admin/users' }) }"
                  @click="navigateTo({ url: '/admin/users' })"
                >
                  <div slot="start" class="nav-icon-wrapper" :class="{ 'active-icon': isActiveRoute({ path: '/admin/users' }) }">
                    <ion-icon :icon="people" aria-hidden="true"></ion-icon>
                  </div>
                  <ion-label>Usuarios</ion-label>
                </ion-item>
              </ion-menu-toggle>
              <ion-menu-toggle :auto-hide="false">
                <ion-item
                  button
                  lines="none"
                  :class="{ 'active-item': isActiveRoute({ path: '/admin/sessions' }) }"
                  @click="navigateTo({ url: '/admin/sessions' })"
                >
                  <div slot="start" class="nav-icon-wrapper" :class="{ 'active-icon': isActiveRoute({ path: '/admin/sessions' }) }">
                    <ion-icon :icon="key" aria-hidden="true"></ion-icon>
                  </div>
                  <ion-label>Sesiones</ion-label>
                </ion-item>
              </ion-menu-toggle>
              <ion-menu-toggle :auto-hide="false">
                <ion-item
                  button
                  lines="none"
                  :class="{ 'active-item': isActiveRoute({ path: '/admin/clients' }) }"
                  @click="navigateTo({ url: '/admin/clients' })"
                >
                  <div slot="start" class="nav-icon-wrapper" :class="{ 'active-icon': isActiveRoute({ path: '/admin/clients' }) }">
                    <ion-icon :icon="desktop" aria-hidden="true"></ion-icon>
                  </div>
                  <ion-label>Clientes SSO</ion-label>
                </ion-item>
              </ion-menu-toggle>
              <ion-menu-toggle :auto-hide="false">
                <ion-item
                  button
                  lines="none"
                  :class="{ 'active-item': isActiveRoute({ path: '/admin/roles' }) }"
                  @click="navigateTo({ url: '/admin/roles' })"
                >
                  <div slot="start" class="nav-icon-wrapper" :class="{ 'active-icon': isActiveRoute({ path: '/admin/roles' }) }">
                    <ion-icon :icon="shield" aria-hidden="true"></ion-icon>
                  </div>
                  <ion-label>Roles</ion-label>
                </ion-item>
              </ion-menu-toggle>
            </ion-list>

            <ion-list-header class="nav-section-label">Cuenta</ion-list-header>
            <ion-list class="nav-list" role="navigation" aria-label="Menú de cuenta">
              <ion-menu-toggle :auto-hide="false">
                <ion-item
                  button
                  lines="none"
                  :class="{ 'active-item': isActiveRoute({ path: '/admin/config' }) }"
                  @click="navigateTo({ url: '/admin/config' })"
                >
                  <div slot="start" class="nav-icon-wrapper" :class="{ 'active-icon': isActiveRoute({ path: '/admin/config' }) }">
                    <ion-icon :icon="settingsOutline" aria-hidden="true"></ion-icon>
                  </div>
                  <ion-label>Configuraci&oacute;n</ion-label>
                </ion-item>
              </ion-menu-toggle>
              <ion-menu-toggle :auto-hide="false">
                <ion-item
                  button
                  lines="none"
                  :class="{ 'active-item': isActiveRoute({ path: '/admin/health' }) }"
                  @click="navigateTo({ url: '/admin/health' })"
                >
                  <div slot="start" class="nav-icon-wrapper" :class="{ 'active-icon': isActiveRoute({ path: '/admin/health' }) }">
                    <ion-icon :icon="pulseOutline" aria-hidden="true"></ion-icon>
                  </div>
                  <ion-label>Salud</ion-label>
                </ion-item>
              </ion-menu-toggle>
            </ion-list>
          </template>

          <div class="logout-section">
            <ion-button expand="block" fill="outline" class="logout-btn" @click="showLogoutAlert = true">
              <ion-icon slot="start" :icon="logOutOutline" aria-hidden="true"></ion-icon>
              Cerrar Sesi&oacute;n
            </ion-button>
          </div>
        </ion-content>
      </ion-menu>

      <ion-alert
        :is-open="showLogoutAlert"
        header="Cerrar Sesi&oacute;n"
        message="¿Estás seguro de que deseas cerrar la sesión?"
        :buttons="logoutAlertButtons"
        @did-dismiss="showLogoutAlert = false"
      ></ion-alert>

      <ion-router-outlet id="main-content" />
    </ion-split-pane>
    </template>
    <ion-router-outlet v-else />
  </ion-app>
</template>

<script setup lang="ts">
  import {
    IonApp,
    IonRouterOutlet,
    IonSplitPane,
    IonMenu,
    IonHeader,
    IonToolbar,
    IonContent,
    IonList,
    IonListHeader,
    IonMenuToggle,
    IonItem,
    IonIcon,
    IonLabel,
    IonButton,
    IonBadge,
    IonAlert,
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
    pulseOutline,
  } from 'ionicons/icons';

import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useAuthController } from '@/controllers/authController';
import { useThemeStore } from '@/stores/theme';

  const router = useRouter();
  const route = useRoute();

  const authController = useAuthController();
  const isAuthenticated = authController.isAuthenticated;
  const currentUser = authController.user;

  const showSidebar = computed(() => {
    if (!isAuthenticated.value) return false;
    return route.matched.some(record => record.meta.requiresAuth);
  });
  let sessionCheckInterval: ReturnType<typeof setInterval> | null = null;
  const showLogoutAlert = ref(false);

  const handleLogout = async (): Promise<void> => {
    await menuController.close();
    await authController.logout();
    router.replace('/login');
  };

  const logoutAlertButtons = [
    {
      text: 'Cancelar',
      role: 'cancel',
    },
    {
      text: 'Cerrar sesión',
      role: 'destructive',
      handler: handleLogout,
    },
  ];

  onMounted(() => {
    const themeStore = useThemeStore();
    themeStore.init();
    sessionCheckInterval = setInterval(checkSession, 5 * 60 * 1000);
  });

  onUnmounted(() => {
    if (sessionCheckInterval) {
      clearInterval(sessionCheckInterval);
    }
  });

  const isActiveRoute = (params: { path: string }): boolean => {
    const { path } = params;
    return route.path === path || route.path.startsWith(path + '/');
  };

  const navigateTo = async (params: { url: string }): Promise<void> => {
    const { url } = params;
    await menuController.close();
    router.push(url);
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

  const checkSession = async (): Promise<void> => {
    if (!authController.isAuthenticated) return;
    try {
      const authenticated = await authController.checkSession();
      if (!authenticated) {
        await handleLogout();
      }
    } catch {
      // Ignore network errors
    }
  };
</script>

<style scoped>
ion-split-pane {
  --side-width: 260px;
  --side-max-width: 280px;
}


.sidebar-header-toolbar {
  --background: var(--gradient-primary);
  --border-width: 0;
}

  .sidebar-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 4px;
  }

  .sidebar-logo {
    max-width: 32px;
    height: auto;
    filter: brightness(0) invert(1);
  }

  .sidebar-brand-text {
    font-family: var(--font-heading);
    font-size: 18px;
    font-weight: 700;
    color: #ffffff;
  }

.user-info {
  display: flex;
  align-items: center;
  gap: var(--space-3, 12px);
  padding: clamp(16px, 3vw, 20px) var(--space-4, 16px);
  background: var(--glass-bg);
  border-bottom: 1px solid var(--glass-border);
}

  .user-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: var(--ion-color-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 16px;
    color: white;
    flex-shrink: 0;
  }

  .user-details {
    display: flex;
    flex-direction: column;
    min-width: 0;
    gap: 2px;
  }

  .user-name {
    font-weight: 600;
    font-size: 14px;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .user-email {
    font-size: 12px;
    color: var(--text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .nav-section-label {
    padding: 16px 16px 4px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-tertiary);
    min-height: auto;
  }

  .nav-list {
    padding: 4px 0;
    background: transparent;
  }

  .nav-list ion-item {
    --border-radius: 0;
    --padding-start: 12px;
    --padding-end: 12px;
    --min-height: 44px;
    margin-bottom: 2px;
    --background: transparent;
    --background-hover: transparent;
    --background-activated: transparent;
    transition: background 0.2s ease;
  }

  .nav-list ion-item:hover {
    --background: var(--ion-background-color-step-50);
  }

.nav-list ion-item.active-item {
  --background: rgba(var(--ion-color-primary-rgb), 0.08);
  border-left: 4px solid var(--ion-color-primary);
}

.nav-list ion-item.active-item ion-label {
  font-weight: 600;
  color: var(--ion-color-primary);
}

  .nav-icon-wrapper {
    width: 32px;
    height: 32px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    transition: background 0.2s ease;
    flex-shrink: 0;
  }

.nav-icon-wrapper.active-icon {
  background: var(--gradient-primary);
}

.nav-icon-wrapper ion-icon {
  font-size: 18px;
  color: var(--text-secondary);
  transition: color 0.2s ease;
}

.nav-icon-wrapper.active-icon ion-icon {
  color: #ffffff;
}

  @media (prefers-reduced-motion: reduce) {
    .nav-list ion-item,
    .nav-icon-wrapper {
      transition: none;
    }
  }

.logout-section {
  padding: var(--space-3, 12px) var(--space-4, 16px);
  border-top: 1px solid var(--glass-border);
  margin-top: 8px;
}

  .logout-btn {
    --border-radius: 4px;
    --border-color: var(--ion-background-color-step-300);
    --color: var(--text-secondary);
    font-size: 13px;
    transition: border-color 0.2s ease, color 0.2s ease;
  }

  .logout-btn:hover {
    --border-color: var(--ion-color-danger);
    --color: var(--ion-color-danger);
  }

  @media (prefers-reduced-motion: reduce) {
    .logout-btn {
      transition: none;
    }
  }
</style>
