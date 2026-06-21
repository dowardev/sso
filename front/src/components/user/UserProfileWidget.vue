<template>
  <ion-page>
    <ion-header v-if="!embedMode" class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons v-if="!embedMode && !hideBackButton" slot="start">
          <ion-back-button default-href="/" aria-label="Volver al inicio" />
        </ion-buttons>
        <ion-title>{{ title }}</ion-title>
        <ion-buttons v-if="externalRedirect && !embedMode" slot="end">
          <!-- For external links, we might want to show different buttons -->
        </ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true" class="ion-padding">
      <div class="profile-container">
        <!-- Header del perfil -->
        <div class="profile-header">
          <ion-avatar v-if="!embedMode || showAvatar !== false" class="large-avatar">
            <div class="avatar-placeholder">
              {{ getInitials(user?.name || '') }}
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
          value="activeSegment"
          class="profile-segment"
          @ion-change="segmentChanged($event)"
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

          <!-- Security / Activity Log -->
          <div v-if="activeSegment === 'security'" class="segment-panel">
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
import { ref, onMounted, computed, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import {
  IonPage,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonButtons,
  IonBackButton,
  IonContent,
  IonIcon,
  IonInput,
  IonItem,
  IonSpinner,
  IonButton,
  IonAlert,
  IonToast,
  IonSegment,
  IonSegmentButton,
  IonAvatar,
} from '@ionic/vue';
import {
  personOutline,
  appsOutline,
  keyOutline,
  shieldOutline,
  logOutOutline,
  refreshOutline,
  notificationsOutline,
  lockClosedOutline,
} from 'ionicons/icons';

import { useAuthController } from '@/controllers/authController';
import { useAuthStore } from '@/stores/auth';
import { useValidation } from '@/composables/useValidation';
import { useToast } from '@/composables/useToast';

import ProfileForm from '@/components/user/ProfileForm.vue';
import AuthorizationsList from '@/components/user/AuthorizationsList.vue';
import SessionsList from '@/components/user/SessionsList.vue';
import ActivityLog from '@/components/user/ActivityLog.vue';

interface User {
  id?: number;
  name?: string;
  user?: string;
  email?: string;
  is_admin?: boolean;
  created_at?: string;
  sessions_count?: number;
  tokens_count?: number;
  roles?: Array<{
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
    user: string;
    email: string;
    client_id: string;
    redirect_url: string | null;
  } | null;
}

interface UserSession {
  id: number;
  client: {
    id: number;
    name: string;
    user: string;
    email: string;
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

const authController = useAuthController();
const authStore = useAuthStore();
const { handleApiError } = useValidation();
const { showSuccess, showError } = useToast();

const router = useRouter();
const route = useRoute();

const user = ref<User | null>(null);
const authorizations = ref<UserAuthorization[]>([]);
const sessions = ref<UserSession[]>([]);
const activities = ref<ActivityLogEntry[]>([]);

const activeSegment = ref('profile');
const loading = ref(false);
const loadingAuthorizations = ref(false);
const loadingSessions = ref(false);
const loadingActivities = ref(false);

// Default tabs
const defaultTabs = ['profile', 'applications', 'sessions', 'security', 'notifications', 'privacy'];

// Determine which tabs to show based on props
const showTabs = computed(() => {
  if (props.showTabs && props.showTabs.length > 0) {
    return props.showTabs;
  }
  
  if (props.hideTabs && props.hideTabs.length > 0) {
    return defaultTabs.filter(tab => !props.hideTabs!.includes(tab));
  }
  
  // Based on mode
  if (props.mode === 'limited') {
    return ['profile', 'sessions', 'security', 'notifications', 'privacy']; // Minimal set for limited mode
  }
  
  if (props.mode === 'embed') {
    return ['profile', 'security']; // Embed mode also minimal
  }
  
  // Full mode (default)
  return defaultTabs;
});

// Determine which segments to actually show (must be in showTabs and have content)
const showSegments = computed(() => {
  return showTabs.value.filter(tab => {
    switch (tab) {
      case 'profile': return true; // Always show profile if in tabs
      case 'applications': return authorizations.value.length > 0 || true; // Show even if empty
      case 'sessions': return sessions.value.length > 0 || true; // Show even if empty
      case 'security': return activities.value.length > 0 || true; // Show even if empty
      case 'notifications': return true;
      case 'privacy': return true;
      default: return false;
    }
  });
});

// Title based on mode or prop
const title = computed(() => {
  if (props.title) return props.title;
  if (props.mode === 'limited') return 'Perfil de Usuario';
  if (props.mode === 'embed') return 'Perfil de Usuario';
  return 'Mi Cuenta';
});

const safeUser = computed(() => user.value as {
  id: number;
  name: string;
  user: string;
  email: string;
  is_admin: boolean;
  created_at: string;
  sessions_count: number;
  tokens_count: number;
  roles: Array<{ id: number; name: string; slug: string; description: string | null; is_protected: boolean }>;
} | null);

// Get initials function
function getInitials(name: string): string {
  return name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2);
}

// Load all data
const loadAllData = async (): Promise<void> => {
  loading.value = true;
  try {
    const [profile, auths, userSessions, activityLog] = await Promise.allSettled([
      authController.getUserProfile(),
      authController.getUserAuthorizations({ user_id: authStore.user?.id || 0 }),
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
    console.error('[UserProfileWidget] Error loading data:', err);
    showError({ message: 'Error al cargar datos del perfil' });
  } finally {
    loading.value = false;
  }
};

// Refresh profile
const refreshProfile = async (): Promise<void> => {
  try {
    const profile = await authController.getUserProfile();
    user.value = profile;
    authStore.$patch({ user: profile });
    showSuccess({ message: 'Perfil actualizado correctamente' });
  } catch (err) {
    console.error('[UserProfileWidget] Error refreshing profile:', err);
    showError({ message: 'Error al actualizar perfil' });
  }
};

// Load authorizations
const loadAuthorizations = async (): Promise<void> => {
  loadingAuthorizations.value = true;
  try {
    const auths = await authController.getUserAuthorizations({ user_id: authStore.user?.id || 0 });
    authorizations.value = auths.data as unknown as UserAuthorization[];
  } catch (err) {
    console.error('[UserProfileWidget] Error loading authorizations:', err);
    showError({ message: 'Error al cargar autorizaciones' });
  } finally {
    loadingAuthorizations.value = false;
  }
};

// Handle revoke authorization
const handleRevokeAuthorization = async (clientId: number): Promise<void> => {
  try {
    await authController.revokeOAuthAuthorization({ client_id: clientId.toString() });
    showSuccess({ message: 'Acceso revocado correctamente' });
    await loadAuthorizations();
  } catch (err) {
    console.error('[UserProfileWidget] Error revoking authorization:', err);
    showError({ message: handleApiError(err) });
  }
};

// Load sessions
const loadSessions = async (): Promise<void> => {
  loadingSessions.value = true;
  try {
    const userSessions = await authController.getUserSessions();
    sessions.value = userSessions as UserSession[] || [];
  } catch (err) {
    console.error('[UserProfileWidget] Error loading sessions:', err);
    showError({ message: 'Error al cargar sesiones' });
  } finally {
    loadingSessions.value = false;
  }
};

// Handle revoke session
const handleRevokeSession = async (sessionId: number): Promise<void> => {
  try {
    await authController.revokeUserSession(sessionId);
    showSuccess({ message: 'Sesión revocada correctamente' });
    await loadSessions();
  } catch (err) {
    console.error('[UserProfileWidget] Error revoking session:', err);
    showError({ message: handleApiError(err) });
  }
};

// Handle revoke all sessions
const handleRevokeAllSessions = async (): Promise<void> => {
  try {
    await authController.revokeAllUserSessions();
    showSuccess({ message: 'Todas las sesiones han sido revocadas' });
    await loadSessions();
  } catch (err) {
    console.error('[UserProfileWidget] Error revoking all sessions:', err);
    showError({ message: handleApiError(err) });
  }
};

// Load activities
const loadActivities = async (): Promise<void> => {
  loadingActivities.value = true;
  try {
    activities.value = await authController.getUserActivityLog();
  } catch (err) {
    console.error('[UserProfileWidget] Error loading activities:', err);
    showError({ message: 'Error al cargar actividad' });
  } finally {
    loadingActivities.value = false;
  }
};

// Segment change handler
const segmentChanged = (event: CustomEvent): void => {
  const newValue = event.detail.value;
  if (activeSegment.value === newValue) return;
  activeSegment.value = newValue;

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
    default:
      break;
  }
};

// Initial load
onMounted(async () => {
  await loadAllData();
});

// Watch for user changes (if needed)
watch(
  () => authStore.user,
  async (newUser, oldUser) => {
    if (newUser !== oldUser) {
      await loadAllData();
    }
  }
);
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

.placeholder-panel {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 24px;
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
  font-size: 18px;
  color: var(--text-primary);
}

.placeholder-panel p {
  font-size: 14px;
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
</style>