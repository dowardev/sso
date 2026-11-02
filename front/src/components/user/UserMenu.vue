<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-button
    :id="triggerId"
    class="user-menu-trigger"
    fill="clear"
    aria-label="Menú de usuario"
    :aria-expanded="isOpen"
  >
    <ion-avatar class="user-menu-avatar">
      <div class="user-menu-avatar-text">{{ initials }}</div>
    </ion-avatar>
  </ion-button>

  <ion-popover
    :trigger="triggerId"
    trigger-action="click"
    dismiss-on-select
    side="bottom"
    alignment="end"
    @will-present="isOpen = true"
    @did-dismiss="isOpen = false"
  >
    <ion-list class="user-menu-list">
      <ion-list-header class="user-menu-header">{{ userName }}</ion-list-header>
      <ion-item
        v-if="showAccountLink"
        button
        lines="none"
        :detail="false"
        @click="goToAccount"
      >
        <ion-icon slot="start" :icon="personOutline" aria-hidden="true"></ion-icon>
        <ion-label>Mi Cuenta</ion-label>
      </ion-item>
      <ion-item button lines="none" :detail="false" @click="goToSecurity">
        <ion-icon slot="start" :icon="shieldCheckmarkOutline" aria-hidden="true"></ion-icon>
        <ion-label>Seguridad</ion-label>
      </ion-item>
      <ion-item button lines="none" :detail="false" @click="requestLogout">
        <ion-icon slot="start" :icon="logOutOutline" aria-hidden="true"></ion-icon>
        <ion-label>Cerrar Sesi&oacute;n</ion-label>
      </ion-item>
    </ion-list>
  </ion-popover>

  <ion-alert
    :is-open="showLogoutAlert"
    header="Cerrar Sesi&oacute;n"
    message="¿Estás seguro de que deseas cerrar la sesión?"
    :buttons="logoutAlertButtons"
    @did-dismiss="showLogoutAlert = false"
  ></ion-alert>
</template>

<script setup lang="ts">
  // 1. Imports (ionic/vue/ionicons)
  import { ref, computed, useId } from 'vue';
  import { useRouter, useRoute } from 'vue-router';
  import {
    IonAlert,
    IonAvatar,
    IonButton,
    IonIcon,
    IonItem,
    IonLabel,
    IonList,
    IonListHeader,
    IonPopover,
  } from '@ionic/vue';
  import {
    personOutline,
    shieldCheckmarkOutline,
    logOutOutline,
  } from 'ionicons/icons';

  // 2. Controllers / Composables
  import { useAuthController } from '@/controllers/authController';

  const router = useRouter();
  const route = useRoute();

  const authController = useAuthController();

  // 3. Components
  // (none)

  // 4. Interface and types imports
  // (none)

  // 5. Main (props, emits, etc.)
  // (none)

  // 6. Vars and const
  // El popover se ancla por id: es la forma que soporta ion-popover para
  // positioning y para abrir/cerrar con el trigger.
  const triggerId = `user-menu-trigger-${useId()}`;
  const isOpen = ref(false);
  const showLogoutAlert = ref(false);

  // 7. Watch
  // (none)

  // 8. Computed
  const userName = computed(() => authController.user.value?.name ?? 'Mi cuenta');

  const initials = computed(() =>
    (authController.user.value?.name ?? '')
      .split(' ')
      .map((part) => part[0])
      .join('')
      .toUpperCase()
      .slice(0, 2)
  );

  // En la propia página de cuenta el enlace "Mi Cuenta" no aporta nada:
  // se oculta para no dejar un enlace que no lleva a ninguna parte.
  const showAccountLink = computed(() => route.path !== '/account');

  // 9. OnMounted
  // (none)

  // 10. Functions (named parameters pattern)
  const goToAccount = async (): Promise<void> => {
    await router.push('/account');
  };

  const goToSecurity = async (): Promise<void> => {
    await router.push({ path: '/account', query: { tab: 'security' } });
  };

  const requestLogout = async (): Promise<void> => {
    showLogoutAlert.value = true;
  };

  const handleLogout = async (): Promise<void> => {
    showLogoutAlert.value = false;

    try {
      await authController.logout();
    } catch {
      // Un logout fallido (red caída, sesión ya expirada) no puede dejar al
      // usuario dentro de la UI autenticada: la navegación al login se hace
      // igual, y el estado local ya quedó limpio por el propio logout.
    }

    await router.replace('/login');
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
</script>

<style scoped>
  .user-menu-trigger {
    --padding-start: 0;
    --padding-end: 0;
    --min-height: 36px;
    height: 36px;
  }

  .user-menu-avatar {
    width: 32px;
    height: 32px;
  }

  .user-menu-avatar-text {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    background: var(--gradient-primary);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 600;
  }

  .user-menu-list {
    --min-width: 220px;
    background: var(--ion-background-color, #ffffff);
  }

  .user-menu-header {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-secondary, var(--ion-color-medium));
  }

  /* En desktop la entrada vive en el sidebar, así que el avatar del header
     sólo se muestra en mobile/tablet para no duplicar la navegación. */
  @media (min-width: 992px) {
    .user-menu-trigger {
      display: none;
    }
  }
</style>
