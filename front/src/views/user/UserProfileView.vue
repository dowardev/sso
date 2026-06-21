<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-back-button default-href="/" aria-label="Volver al inicio"></ion-back-button>
        </ion-buttons>
        <ion-title>Mi Cuenta</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true" class="ion-padding">
      <div class="profile-container">
        <!-- Header del perfil -->
        <div class="profile-header">
          <ion-avatar class="large-avatar">
            <div class="avatar-placeholder">
              {{ getInitials(user?.name || '') }}
            </div>
          </ion-avatar>
          <h1 class="profile-name">{{ user?.name || 'Cargando...' }}</h1>
          <p class="profile-username">@{{ user?.user || '' }}</p>
          <ion-badge v-if="user?.is_admin" color="warning">Administrador</ion-badge>
        </div>

        <ion-segment value="profile" @ion-change="segmentChanged($event)">
          <ion-segment-button value="profile">
            <ion-label>Perfil</ion-label>
            <ion-icon slot="icon-only" :icon="personOutline"></ion-icon>
          </ion-segment-button>
          <ion-segment-button value="applications">
            <ion-label>Aplicaciones</ion-label>
            <ion-icon slot="icon-only" :icon="appsOutline"></ion-icon>
          </ion-segment-button>
          <ion-segment-button value="sessions">
            <ion-label>Sesiones</ion-label>
            <ion-icon slot="icon-only" :icon="keyOutline"></ion-icon>
          </ion-segment-button>
          <ion-segment-button value="security">
            <ion-label>Seguridad</ion-label>
            <ion-icon slot="icon-only" :icon="shieldOutline"></ion-icon>
          </ion-segment-button>
        </ion-segment>

        <div class="segment-content">
          <!-- Perfil -->
          <div v-if="activeSegment === 'profile'" class="segment-panel">
            <ProfileForm
              :initial-user="user"
              @saved="refreshProfile"
            />
          </div>

          <!-- Aplicaciones Autorizadas -->
          <div v-if="activeSegment === 'applications'" class="segment-panel">
            <AuthorizationsList
              :authorizations="authorizations"
              @revoke="handleRevokeAuthorization"
              :loading="loadingAuthorizations"
            />
          </div>

          <!-- Historial de Sesiones -->
          <div v-if="activeSegment === 'sessions'" class="segment-panel">
            <SessionsList
              :sessions="sessions"
              @revoke="handleRevokeSession"
              @revoke-all="handleRevokeAllSessions"
              :loading="loadingSessions"
            />
          </div>

          <!-- Seguridad / Registro de Actividad -->
          <div v-if="activeSegment === 'security'" class="segment-panel">
            <ActivityLog :activities="activities" :loading="loadingActivities" />
          </div>
        </div>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  import { ref, onMounted, computed } from 'vue';
  import { useRouter } from 'vue-router';
  import {
    IonPage,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonButtons,
    IonBackButton,
    IonContent,
    IonSegment,
    IonSegmentButton,
    IonLabel,
    IonIcon,
    IonBadge,
    IonAvatar,
    IonSpinner,
    IonButton,
  } from '@ionic/vue';
  import {
    personOutline,
    appsOutline,
    keyOutline,
    shieldOutline,
    logOutOutline,
    refreshOutline,
  } from 'ionicons/icons';

import { useAuthController } from '@/controllers/authController';
import { useAuthStore } from '@/stores/auth';
import { useValidation } from '@/composables/useValidation';
import { useToast } from '@/composables/useToast';

import ProfileForm from '@/components/user/ProfileForm.vue';
import AuthorizationsList from '@/components/user/AuthorizationsList.vue';
import SessionsList from '@/components/user/SessionsList.vue';
import ActivityLog from '@/components/user/ActivityLog.vue';

const authController = useAuthController();
const authStore = useAuthStore();
const { handleApiError } = useValidation();
const { showSuccess, showError } = useToast();

const router = useRouter();

// Type definitions
interface User {
  id: number;
  name: string;
  user: string;
  email: string;
  is_admin: boolean;
  created_at: string;
  sessions_count: number;
  tokens_count: number;
  roles: Array<{
    id: number;
    name: string;
    slug: string;
    description: string | null;
    is_protected: boolean;
  }>;
}

interface UserAuthorization {
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

interface UserSession {
  id: number;
  client: {
    id: number;
    name: string;
    client_id: string;
  } | null;
  ip_address: string;
  user_agent: string;
  last_used_at: string;
  created_at: string;
  token_active: boolean;
}

interface ActivityLogEntry {
  id: number;
  action: string;
  description: string;
  created_at: string;
  metadata: Record<string, unknown>;
}

const user = ref<User | null>(null);
const authorizations = ref<UserAuthorization[]>([]);
const sessions = ref<UserSession[]>([]);
const activities = ref<ActivityLogEntry[]>([]);

const activeSegment = ref('profile');
const loading = ref(false);
const loadingAuthorizations = ref(false);
const loadingSessions = ref(false);
const loadingActivities = ref(false);

const authUser = computed(() => (authStore.$state.user as User | null));

  onMounted(async () => {
    await loadAllData();
  });

  async function loadAllData(): Promise<void> {
    loading.value = true;
    try {
      const [profile, auths, userSessions, activityLog] = await Promise.allSettled([
        authController.getUserProfile(),
        authController.getUserAuthorizations({ user_id: authUser.value?.id || 0 }),
        authController.getUserSessions(),
        authController.getUserActivityLog(),
      ]);

      if (profile.status === 'fulfilled') {
        user.value = profile.value;
      }

      if (auths.status === 'fulfilled') {
        authorizations.value = auths.value.data as UserAuthorization[] || [];
      }

      if (userSessions.status === 'fulfilled') {
        sessions.value = userSessions.value as UserSession[] || [];
      }

      if (activityLog.status === 'fulfilled') {
        activities.value = activityLog.value as ActivityLogEntry[] || [];
      }
    } catch (err) {
      console.error('[UserProfile] Error loading data:', err);
      showError({ message: 'Error al cargar datos del perfil' });
    } finally {
      loading.value = false;
    }
  }

  async function refreshProfile(): Promise<void> {
    try {
      const profile = await authController.getUserProfile();
      user.value = profile;
      authStore.$patch({ user: profile });
      showSuccess({ message: 'Perfil actualizado correctamente' });
    } catch (err) {
      console.error('[UserProfile] Error refreshing profile:', err);
      showError({ message: 'Error al actualizar perfil' });
    }
  }

  async function loadAuthorizations(): Promise<void> {
    loadingAuthorizations.value = true;
    try {
      const auths = await authController.getUserAuthorizations({ user_id: authUser.value?.id || 0 });
      authorizations.value = auths.data as UserAuthorization[] || [];
    } catch (err) {
      console.error('[UserProfile] Error loading authorizations:', err);
    } finally {
      loadingAuthorizations.value = false;
    }
  }

  async function handleRevokeAuthorization(clientId: number): Promise<void> {
    try {
      await authController.revokeOAuthAuthorization({ client_id: clientId.toString() });
      showSuccess({ message: 'Acceso revocado correctamente' });
      await loadAuthorizations();
    } catch (err) {
      console.error('[UserProfile] Error revoking authorization:', err);
      showError({ message: handleApiError(err) });
    }
  }

  async function loadSessions(): Promise<void> {
    loadingSessions.value = true;
    try {
      const userSessions = await authController.getUserSessions();
      sessions.value = userSessions as UserSession[] || [];
    } catch (err) {
      console.error('[UserProfile] Error loading sessions:', err);
    } finally {
      loadingSessions.value = false;
    }
  }

  async function handleRevokeSession(sessionId: number): Promise<void> {
    try {
      await authController.revokeUserSession(sessionId);
      showSuccess({ message: 'Sesión revocada correctamente' });
      await loadSessions();
    } catch (err) {
      console.error('[UserProfile] Error revoking session:', err);
      showError({ message: handleApiError(err) });
    }
  }

  async function handleRevokeAllSessions(): Promise<void> {
    try {
      await authController.revokeAllUserSessions();
      showSuccess({ message: 'Todas las sesiones han sido revocadas' });
      await loadSessions();
    } catch (err) {
      console.error('[UserProfile] Error revoking all sessions:', err);
      showError({ message: handleApiError(err) });
    }
  }

  async function loadActivities(): Promise<void> {
    loadingActivities.value = true;
    try {
      const activityLog = await authController.getUserActivityLog();
      activities.value = activityLog as ActivityLogEntry[] || [];
    } catch (err) {
      console.error('[UserProfile] Error loading activities:', err);
    } finally {
      loadingActivities.value = false;
    }
  }

  function segmentChanged(event: CustomEvent): void {
    activeSegment.value = event.detail.value;

    switch (activeSegment.value) {
      case 'applications':
        loadAuthorizations();
        break;
      case 'sessions':
        loadSessions();
        break;
      case 'security':
        loadActivities();
        break;
    }
  }

  function getInitials(name: string): string {
    return name
      .split(' ')
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);
  }
</script>

<style scoped>
.profile-container {
  max-width: 600px;
  margin: 0 auto;
}

.profile-header {
  text-align: center;
  margin-bottom: 24px;
  padding: 16px 0;
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
  font-size: 24px;
  font-weight: 700;
  color: var(--text-primary);
}

.profile-username {
  margin: 0 0 12px 0;
  color: var(--text-tertiary);
  font-size: 15px;
}

.segment-content {
  margin-top: 16px;
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
</style>