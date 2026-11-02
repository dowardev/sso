<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-page>
    <ion-header v-if="!embedMode" class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons v-if="!embedMode && !hideBackButton" slot="start">
          <ion-back-button default-href="/" aria-label="Volver al inicio" />
        </ion-buttons>
        <ion-buttons v-if="!embedMode && hideBackButton" slot="start">
          <ion-menu-button aria-label="Menú de navegación"></ion-menu-button>
        </ion-buttons>
        <ion-title>{{ title }}</ion-title>
        <ion-buttons v-if="!embedMode" slot="end">
          <UserMenu />
        </ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true" class="ion-padding">
      <div class="profile-container">
        <!-- Header del perfil -->
        <div class="profile-header">
          <ion-avatar v-if="!embedMode || showAvatar !== false" class="large-avatar">
            <div class="avatar-placeholder">
              {{ getInitials({ name: user?.name || '' }) }}
            </div>
          </ion-avatar>
          <div>
            <h1 class="profile-name">{{ user?.name || 'Cargando...' }}</h1>
            <p class="profile-username">@{{ user?.user || '' }}</p>
            <ion-badge v-if="user?.is_admin && !hideAdminBadge" color="warning">
              Administrador
            </ion-badge>
          </div>
        </div>

        <!-- Segmented control for tabs -->
        <ion-segment 
          v-if="showSegments && showSegments.length > 0"
          :scrollable="true"
          :value="activeSegment"
          class="profile-segment"
          @ion-change="segmentChanged({ event: $event })"
        >
          <!-- Profile tab -->
          <ion-segment-button 
            v-if="showTabs.includes('profile')" 
            value="profile"
          >
            <ion-label>Perfil</ion-label>
            <ion-icon slot="icon-only" :icon="personOutline" />
          </ion-segment-button>

          <!-- Applications tab -->
          <ion-segment-button 
            v-if="showTabs.includes('applications')" 
            value="applications"
          >
            <ion-label>Aplicaciones</ion-label>
            <ion-icon slot="icon-only" :icon="appsOutline" />
          </ion-segment-button>

          <!-- Sessions tab -->
          <ion-segment-button 
            v-if="showTabs.includes('sessions')" 
            value="sessions"
          >
            <ion-label>Sesiones</ion-label>
            <ion-icon slot="icon-only" :icon="keyOutline" />
          </ion-segment-button>

          <!-- Security tab -->
          <ion-segment-button 
            v-if="showTabs.includes('security')" 
            value="security"
          >
            <ion-label>Seguridad</ion-label>
            <ion-icon slot="icon-only" :icon="shieldOutline" />
          </ion-segment-button>

          <!-- Notifications tab -->
          <ion-segment-button 
            v-if="showTabs.includes('notifications')" 
            value="notifications"
          >
            <ion-label>Notificaciones</ion-label>
            <ion-icon slot="icon-only" :icon="notificationsOutline" />
          </ion-segment-button>

          <!-- Privacy tab -->
          <ion-segment-button 
            v-if="showTabs.includes('privacy')" 
            value="privacy"
          >
            <ion-label>Privacidad</ion-label>
            <ion-icon slot="icon-only" :icon="lockClosedOutline" />
          </ion-segment-button>
        </ion-segment>

        <!-- Segment content -->
        <div v-if="showSegments && showSegments.length > 0" class="segment-content">
          <!-- Profile -->
          <div v-if="activeSegment === 'profile'" class="segment-panel">
            <ProfileForm 
              v-if="showTabs.includes('profile')"
              :initial-user="safeUser"
              @saved="refreshProfile"
            />
          </div>

          <!-- Applications Authorized -->
          <div v-if="activeSegment === 'applications'" class="segment-panel">
            <AuthorizationsList
              v-if="showTabs.includes('applications')"
              :authorizations="authorizations"
              :loading="loadingAuthorizations"
              @revoke="handleRevokeAuthorization"
            />
          </div>

          <!-- Sessions History -->
          <div v-if="activeSegment === 'sessions'" class="segment-panel">
            <SessionsList
              v-if="showTabs.includes('sessions')"
              :sessions="sessions"
              :loading="loadingSessions"
              @revoke="handleRevokeSession"
              @revoke-all="handleRevokeAllSessions"
            />
          </div>

          <!-- Security / Activity Log + TOTP settings -->
          <div v-if="activeSegment === 'security'" class="segment-panel">
            <TotpSettings
              v-if="showTabs.includes('security')"
              @changed="loadActivities"
            />

            <ActivityLog
              v-if="showTabs.includes('security')"
              :activities="activities"
              :loading="loadingActivities"
              @refresh="loadActivities"
            />
          </div>

          <!-- Notifications placeholder -->
          <div v-if="activeSegment === 'notifications'" class="segment-panel">
            <div v-if="showTabs.includes('notifications')" class="placeholder-panel">
              <ion-icon :icon="notificationsOutline" size="large" class="placeholder-icon" />
              <h3>Notificaciones</h3>
              <p>Próximamente: preferencias de notificaciones por email, alertas de seguridad y notificaciones de aplicaciones.</p>
            </div>
          </div>

          <!-- Privacy placeholder -->
          <div v-if="activeSegment === 'privacy'" class="segment-panel">
            <div v-if="showTabs.includes('privacy')" class="placeholder-panel">
              <ion-icon :icon="lockClosedOutline" size="large" class="placeholder-icon" />
              <h3>Privacidad</h3>
              <p>Próximamente: visibilidad de perfil, exportación de datos (GDPR), aplicaciones conectadas y preferencias de privacidad.</p>
            </div>
          </div>
        </div>

        <!-- Fallback content if no tabs to show -->
        <div v-else class="text-center py-8">
          <p>No hay información disponible para mostrar.</p>
        </div>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  // 1. Imports (ionic/vue/ionicons)
  import { ref, onMounted, onUnmounted, computed, watch } from 'vue';
  import {
    IonPage,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonButtons,
    IonBackButton,
    IonContent,
    IonIcon,
    IonSegment,
    IonSegmentButton,
    IonAvatar,
    IonBadge,
    IonMenuButton,
  } from '@ionic/vue';
  import {
    personOutline,
    appsOutline,
    keyOutline,
    shieldOutline,
    notificationsOutline,
    lockClosedOutline,
  } from 'ionicons/icons';
  import { useValidation } from '@/composables/useValidation';
  import { useToast } from '@/composables/useToast';
  import { useRoute } from 'vue-router';

  const { handleApiError } = useValidation();
  const { showSuccess, showError } = useToast();

  // 2. Controllers
  import { useAuthController } from '@/controllers/authController';

  const authController = useAuthController();

  // 3. Components
  import UserMenu from '@/components/user/UserMenu.vue';
  import ProfileForm from '@/components/user/ProfileForm.vue';
  import AuthorizationsList from '@/components/user/AuthorizationsList.vue';
  import SessionsList from '@/components/user/SessionsList.vue';
  import ActivityLog from '@/components/user/ActivityLog.vue';
  import TotpSettings from '@/components/user/TotpSettings.vue';

  // 4. Interface and types imports
  import type { User, UserSession, EncryptionLevel } from '@/types';

  interface UserProfileResponse extends Omit<User, 'name' | 'user' | 'email' | 'login_id'> {
    name: string | null;
    user: string | null;
    email: string | null;
    login_id: string | null;
    encryption_level: EncryptionLevel;
    salt_a?: string | null;
    wrapped_dek_a?: string | null;
    encrypted_profile?: string | null;
  }

  interface UserAuthorization {
    id: number;
    status: 'authorized' | 'denied' | 'revoked';
    updated_at: string;
    client: {
      id: number;
      name: string;
      user: string;
      email: string;
      client_id: string;
      redirect_url: string | null;
    } | null;
  }

  interface ActivityLogEntry {
    id: number;
    action: string;
    description: string;
    created_at: string;
    metadata: Record<string, unknown>;
  }

  // 5. Main (props, emits, etc.)
  const props = defineProps<{
    mode?: 'full' | 'limited' | 'embed';
    showTabs?: string[];
    hideTabs?: string[];
    embedMode?: boolean;
    externalRedirect?: boolean;
    title?: string;
    showAvatar?: boolean;
    hideAdminBadge?: boolean;
    hideBackButton?: boolean;
  }>();

  // 6. Vars and const
  const route = useRoute();
  const user = ref<UserProfileResponse | null>(null);
  const authorizations = ref<UserAuthorization[]>([]);
  const sessions = ref<UserSession[]>([]);
  const activities = ref<ActivityLogEntry[]>([]);

  const activeSegment = ref('profile');
  const loading = ref(false);
  const loadingAuthorizations = ref(false);
  const loadingSessions = ref(false);
  const loadingActivities = ref(false);

  let isUnmounted = false;

  const defaultTabs = ['profile', 'applications', 'sessions', 'security', 'notifications', 'privacy'];

  // 7. Watch
  watch(
    () => route.query.tab,
    (tab) => {
      applyTabFromQuery({ tab });
    }
  );

  // 8. Computed
  const showTabs = computed(() => {
    if (props.showTabs && props.showTabs.length > 0) {
      return props.showTabs;
    }

    if (props.hideTabs && props.hideTabs.length > 0) {
      return defaultTabs.filter(tab => !props.hideTabs!.includes(tab));
    }

    if (props.mode === 'limited') {
      return ['profile', 'sessions', 'security', 'notifications', 'privacy'];
    }

    if (props.mode === 'embed') {
      return ['profile', 'security'];
    }

    return defaultTabs;
  });

  const showSegments = computed(() => {
    return showTabs.value.filter(tab => {
      switch (tab) {
        case 'profile': return true;
        case 'applications': return true;
        case 'sessions': return true;
        case 'security': return true;
        case 'notifications': return true;
        case 'privacy': return true;
        default: return false;
      }
    });
  });

  const title = computed(() => {
    if (props.title) return props.title;
    if (props.mode === 'limited') return 'Perfil de Usuario';
    if (props.mode === 'embed') return 'Perfil de Usuario';
    return 'Mi Cuenta';
  });

  const safeUser = computed(() => user.value as UserProfileResponse | null);

  // 9. OnMounted
  onMounted(async () => {
    await loadProfileOnly();
    applyTabFromQuery({ tab: route.query.tab });
  });

  onUnmounted(() => {
    isUnmounted = true;
  });

  // 10. Functions
  const getInitials = (params: { name: string }): string => {
    const { name } = params;
    return (name ?? '')
      .split(' ')
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);
  };

  const loadProfileOnly = async (): Promise<void> => {
    loading.value = true;
    try {
      const profile = await authController.getUserProfile();
      if (!isUnmounted) {
        user.value = profile as UserProfileResponse;
      }
    } catch {
      if (!isUnmounted) {
        showError({ message: 'Error al cargar datos del perfil' });
      }
    } finally {
      if (!isUnmounted) {
        loading.value = false;
      }
    }
  };

  const refreshProfile = async (): Promise<void> => {
    try {
      const profile = await authController.getUserProfile();
      user.value = profile as UserProfileResponse;
      showSuccess({ message: 'Perfil actualizado correctamente' });
    } catch {
      showError({ message: 'Error al actualizar perfil' });
    }
  };

  const loadAuthorizations = async (): Promise<void> => {
    if (isUnmounted) return;
    loadingAuthorizations.value = true;
    try {
      const auths = await authController.getUserAuthorizations({ user_id: authController.user.value?.id || 0 });
      if (!isUnmounted) {
        authorizations.value = (auths as unknown as { data: UserAuthorization[] }).data;
      }
    } catch {
      if (!isUnmounted) {
        showError({ message: 'Error al cargar autorizaciones' });
      }
    } finally {
      if (!isUnmounted) {
        loadingAuthorizations.value = false;
      }
    }
  };

  const handleRevokeAuthorization = async (clientId: number): Promise<void> => {
    try {
      await authController.revokeOAuthAuthorization({ client_id: clientId.toString() });
      showSuccess({ message: 'Acceso revocado correctamente' });
      await loadAuthorizations();
    } catch (err) {
      showError({ message: handleApiError(err) });
    }
  };

  const loadSessions = async (): Promise<void> => {
    if (isUnmounted) return;
    loadingSessions.value = true;
    try {
      const sessionData = await authController.getUserSessions();
      if (!isUnmounted) {
        sessions.value = (sessionData as UserSession[]) || [];
      }
    } catch {
      if (!isUnmounted) {
        showError({ message: 'Error al cargar sesiones' });
      }
    } finally {
      if (!isUnmounted) {
        loadingSessions.value = false;
      }
    }
  };

  const handleRevokeSession = async (sessionId: number): Promise<void> => {
    try {
      await authController.revokeUserSession({ sessionId });
      showSuccess({ message: 'Sesión revocada correctamente' });
      await loadSessions();
    } catch (err) {
      showError({ message: handleApiError(err) });
    }
  };

  const handleRevokeAllSessions = async (): Promise<void> => {
    try {
      await authController.revokeAllUserSessions();
      showSuccess({ message: 'Todas las sesiones han sido revocadas' });
      await loadSessions();
    } catch (err) {
      showError({ message: handleApiError(err) });
    }
  };

  const loadActivities = async (): Promise<void> => {
    if (isUnmounted) return;
    loadingActivities.value = true;
    try {
      const activityData = await authController.getUserActivityLog();
      if (!isUnmounted) {
        activities.value = activityData;
      }
    } catch {
      if (!isUnmounted) {
        showError({ message: 'Error al cargar actividad' });
      }
    } finally {
      if (!isUnmounted) {
        loadingActivities.value = false;
      }
    }
  };

  const segmentChanged = (params: { event: CustomEvent }): void => {
    const { event } = params;
    const newValue = event.detail.value;
    if (activeSegment.value === newValue) return;
    activeSegment.value = newValue;

    loadSegmentData({ segment: activeSegment.value });
  };

  const loadSegmentData = (params: { segment: string }): void => {
    const { segment } = params;

    switch (segment) {
      case 'applications':
        loadAuthorizations();
        break;
      case 'sessions':
        loadSessions();
        break;
      case 'security':
        loadActivities();
        break;
      default:
        break;
    }
  };

  // Permite abrir una pestaña concreta desde la URL (?tab=security), que es
  // lo que usa el enlace "Seguridad" del menú de usuario.
  const applyTabFromQuery = (params: { tab: unknown }): void => {
    const { tab } = params;
    if (typeof tab !== 'string') return;
    if (!defaultTabs.includes(tab)) return;
    if (activeSegment.value === tab) return;

    activeSegment.value = tab;
    loadSegmentData({ segment: tab });
  };
</script>

<style scoped>
.profile-container {
  max-width: 600px;
  margin: 0 auto;
}

.profile-header {
  text-align: center;
  margin-bottom: clamp(20px, 4vw, 24px);
  padding: clamp(12px, 3vw, 16px) 0;
}

.large-avatar {
  width: 96px;
  height: 96px;
  margin: 0 auto 16px;
}

.avatar-placeholder {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  background: linear-gradient(135deg, #8B5CF6, #06B6D4);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 32px;
}

.profile-name {
  margin: 0 0 4px 0;
  font-size: clamp(22px, 5vw, 24px);
  font-weight: 700;
  color: var(--text-primary);
}

.profile-username {
  margin: 0 0 12px 0;
  color: var(--text-tertiary);
  font-size: clamp(14px, 3vw, 15px);
}

.segment-content {
  margin-top: var(--space-4, 16px);
}

.segment-panel {
  animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.placeholder-panel {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: clamp(32px, 8vw, 48px) clamp(16px, 4vw, 24px);
  text-align: center;
  color: var(--text-tertiary);
}

.placeholder-icon {
  font-size: 48px;
  margin-bottom: 16px;
  opacity: 0.5;
}

.placeholder-panel h3 {
  margin: 0 0 8px;
  font-size: clamp(16px, 4vw, 18px);
  color: var(--text-primary);
}

.placeholder-panel p {
  font-size: clamp(13px, 3vw, 14px);
  max-width: 360px;
  line-height: 1.5;
}

.text-center {
  text-align: center;
}

.py-8 {
  padding-top: 2rem;
  padding-bottom: 2rem;
}

@media (max-width: 374px) {
  .profile-container { padding: 0 var(--space-2, 8px); }
}
</style>