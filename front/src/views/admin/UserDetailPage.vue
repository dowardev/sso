<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-back-button default-href="/admin/users" aria-label="Volver a usuarios"></ion-back-button>
        </ion-buttons>
        <ion-title>Detalle de Usuario</ion-title>
        <ion-buttons slot="end">
          <ion-button color="primary" aria-label="Editar usuario" @click="openEditModal">
            <ion-icon slot="icon-only" :icon="create"></ion-icon>
          </ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <!-- content -->
      <div class="page-container-wide">
        <div v-if="loading" class="loading-container" role="status" aria-live="polite">
          <ion-spinner name="lines"></ion-spinner>
          <p>Cargando usuario...</p>
        </div>

        <ion-grid v-else-if="user" class="user-detail">
          <ion-row>
            <ion-col size="12">
              <div class="header-card">
                <div class="header-bg-circle hc-1"></div>
                <div class="header-bg-circle hc-2"></div>
                <div class="user-header">
                  <ion-avatar class="large-avatar">
                    <div class="avatar-placeholder">
                      {{ getInitials({ name: displayName }) }}
                    </div>
                  </ion-avatar>
                  <h2 class="user-name">{{ displayName }}</h2>
                  <p class="user-username">{{ displayUser }}</p>
                  <ion-badge v-if="user.is_admin" color="warning">Administrador</ion-badge>
                      <ion-badge v-if="isAdvancedUser" color="tertiary">Cifrado (ZKE)</ion-badge>
                </div>
              </div>
            </ion-col>

            <ion-col size="12" size-md="6">
              <div class="info-card">
                <h3 class="section-title">Información</h3>
                <div class="info-item">
                  <ion-icon :icon="mailOutline" class="info-icon" aria-hidden="true"></ion-icon>
                  <span class="value">{{ displayEmail }}</span>
                </div>
                <div class="info-item">
                  <ion-icon :icon="calendarOutline" class="info-icon" aria-hidden="true"></ion-icon>
                  <span class="value">{{ formatDate({ dateStr: user.created_at }) }}</span>
                </div>
                <div class="info-item">
                  <ion-icon :icon="keyOutline" class="info-icon" aria-hidden="true"></ion-icon>
                  <span class="value">{{ user.active_tokens_count }} tokens activos</span>
                </div>
              </div>
            </ion-col>

            <ion-col size="12" size-md="6">
              <div class="roles-card">
                <div class="section-header">
                  <h3 class="section-title">Roles</h3>
                  <ion-button size="small" aria-label="Editar roles" @click="openRolesModal">
                    <ion-icon slot="icon-only" :icon="create"></ion-icon>
                  </ion-button>
                </div>

                <div v-if="userRoles.length === 0" class="empty-state">
                  <ion-icon :icon="shieldOutline" size="large" aria-hidden="true"></ion-icon>
                  <p>Sin roles asignados</p>
                  <span class="empty-hint">(rol: Est&aacute;ndar)</span>
                </div>

                <div v-else class="roles-chips">
                  <ion-chip v-for="(role, index) in userRoles" :key="index" color="primary">
                    <ion-icon :icon="shieldOutline" aria-hidden="true"></ion-icon>
                    <ion-label>{{ role.name }}</ion-label>
                  </ion-chip>
                </div>
              </div>
            </ion-col>

            <ion-col size="12">
              <div class="sessions-card">
                <div class="section-header">
                  <h3 class="section-title">Sesiones</h3>
                  <ion-button
                    v-if="sessions.length > 0"
                    size="small"
                    color="danger"
                    fill="outline"
                    @click="revokeAllSessions"
                  >
                    Revocar Todas
                  </ion-button>
                </div>

                <div v-if="sessions.length === 0" class="empty-state">
                  <ion-icon :icon="keyOutline" size="large" aria-hidden="true"></ion-icon>
                  <p>No hay sesiones activas</p>
                </div>

                <ion-list v-else class="detail-list">
                  <ion-item v-for="session in sessions" :key="session.id">
                    <ion-icon
                      slot="start"
                      :icon="keyOutline"
                      :color="session.token_active ? 'success' : 'medium'"
                    ></ion-icon>
                    <ion-label>
                      <h3 class="item-title">
                        {{ session.client?.name || 'Cliente Desconocido' }}
                      </h3>
                      <p class="item-subtitle">{{ session.ip_address }}</p>
                      <p class="session-date">
                        Último uso:
                        {{ formatDate({ dateStr: session.last_used_at }) }}
                      </p>
                    </ion-label>
                    <ion-badge
                      :color="session.token_active ? 'success' : 'medium'"
                    >
                      {{ session.token_active ? 'Activa' : 'Inactiva' }}
                    </ion-badge>
                    <ion-button
                      slot="end"
                      fill="clear"
                      color="danger"
                      aria-label="Revocar sesión"
                      @click="revokeSession({ session })"
                    >
                      <ion-icon slot="icon-only" :icon="trash"></ion-icon>
                    </ion-button>
                  </ion-item>
                </ion-list>
              </div>
            </ion-col>

            <ion-col size="12">
              <UserAuthorizationsList :user-id="userId" />
            </ion-col>
          </ion-row>
        </ion-grid>
      </div>
      <!-- end content -->

      <!-- modals -->
      <ion-modal :is-open="showEditModal" @did-dismiss="showEditModal = false">
        <ion-header class="ion-no-border" mode="ios">
          <ion-toolbar>
            <ion-buttons slot="start">
              <ion-button @click="showEditModal = false">
                <ion-icon :icon="closeOutline"></ion-icon>
              </ion-button>
            </ion-buttons>
            <ion-title>Editar Usuario</ion-title>
            <ion-buttons slot="end">
              <ion-button @click="updateUser">
                <ion-icon :icon="saveOutline"></ion-icon>
              </ion-button>
            </ion-buttons>
          </ion-toolbar>
        </ion-header>
        <ion-content>
          <form class="modal-form" @submit.prevent="updateUser">
            <ion-list>
              <ion-item>
                <ion-label position="stacked">Nombre *</ion-label>
                <ion-input v-model="editForm.name" :aria-invalid="userV$.name.$dirty ? userV$.name.$invalid : undefined" @ion-blur="userV$.name.$touch()"></ion-input>
              </ion-item>
              <div v-if="userV$.name.$error" class="field-error">{{ userV$.name.$errors[0].$message }}</div>
              <ion-item>
                <ion-label position="stacked">Usuario *</ion-label>
                <ion-input v-model="editForm.user" :aria-invalid="userV$.user.$dirty ? userV$.user.$invalid : undefined" @ion-blur="userV$.user.$touch()"></ion-input>
              </ion-item>
              <div v-if="userV$.user.$error" class="field-error">{{ userV$.user.$errors[0].$message }}</div>
              <ion-item>
                <ion-label position="stacked">Email *</ion-label>
                <ion-input
                  v-model="editForm.email"
                  type="email"
                  :aria-invalid="userV$.email.$dirty ? userV$.email.$invalid : undefined"
                  @ion-blur="userV$.email.$touch()"
                ></ion-input>
              </ion-item>
              <div v-if="userV$.email.$error" class="field-error">{{ userV$.email.$errors[0].$message }}</div>
              <ion-item>
                <ion-label position="stacked">Nueva Contraseña</ion-label>
                <ion-input
                  v-model="editForm.password"
                  type="password"
                  placeholder="Dejar vacío para mantener"
                  :aria-invalid="userV$.password.$dirty ? userV$.password.$invalid : undefined"
                  @ion-blur="userV$.password.$touch()"
                ></ion-input>
              </ion-item>
              <div v-if="userV$.password.$error" class="field-error">{{ userV$.password.$errors[0].$message }}</div>
            </ion-list>
            </form>
        </ion-content>
      </ion-modal>

      <ion-modal
        :is-open="showRolesModal"
        @did-dismiss="showRolesModal = false"
      >
        <ion-header class="ion-no-border" mode="ios">
          <ion-toolbar>
            <ion-buttons slot="start">
              <ion-button @click="showRolesModal = false">
                <ion-icon :icon="closeOutline"></ion-icon>
              </ion-button>
            </ion-buttons>
            <ion-title>Asignar Roles</ion-title>
            <ion-buttons slot="end">
              <ion-button @click="saveUserRoles">
                <ion-icon :icon="saveOutline"></ion-icon>
              </ion-button>
            </ion-buttons>
          </ion-toolbar>
        </ion-header>
        <ion-content>
          <div class="roles-assignment">
            <div class="roles-list">
              <ion-list>
                <ion-item-sliding
                  v-for="(assignment, index) in roleAssignments"
                  :key="index"
                >
                  <ion-item>
                    <ion-label position="stacked">Rol</ion-label>
                    <ion-select
                      v-model="assignment.role_id"
                      :disabled="assignment.slug === 'admin' && !isAdmin"
                      placeholder="Seleccionar rol"
                      @ion-change="
                        onRoleChange({ index, roleId: $event.detail.value })
                      "
                    >
                      <ion-select-option
                        v-for="role in availableRoles"
                        :key="role.id"
                        :value="role.id"
                      >
                        {{ role.name }}
                      </ion-select-option>
                    </ion-select>
                  </ion-item>
                  <ion-item-options side="end">
                    <ion-item-option
                      color="danger"
                      aria-label="Eliminar asignación de rol"
                      @click="removeRoleAssignment({ index })"
                    >
                      <ion-icon slot="icon-only" :icon="trash"></ion-icon>
                    </ion-item-option>
                  </ion-item-options>
                </ion-item-sliding>
              </ion-list>
            </div>

            <div class="ion-padding">
              <ion-button expand="block" fill="outline" @click="addRoleAssignment">
                <ion-icon slot="start" :icon="add"></ion-icon>
                Agregar Rol
              </ion-button>
            </div>
          </div>
        </ion-content>
      </ion-modal>
      <!-- end modals -->

      <!-- popovers -->
      <!-- end popovers -->

      <!-- alerts -->
      <!-- end alerts -->
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  // 1. Imports (ionic/vue/ionicons)
  import { ref, onMounted, computed } from 'vue';
  import { useRoute } from 'vue-router';
  import {
    IonPage,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonButtons,
    IonBackButton,
    IonButton,
    IonIcon,
    IonContent,
    IonAvatar,
    IonBadge,
    IonList,
    IonItem,
    IonLabel,
    IonModal,
    IonInput,
    IonItemOptions,
    IonItemOption,
    IonItemSliding,
    IonSpinner,
    IonSelect,
    IonSelectOption,
    IonGrid,
    IonRow,
    IonCol,
    IonChip,
  } from '@ionic/vue';
  import {
    create,
    keyOutline,
    trash,
    shieldOutline,
    add,
    mailOutline,
    calendarOutline,
    closeOutline,
    saveOutline,
  } from 'ionicons/icons';
  import { useFormValidation } from '@/composables/useFormValidation';
  import { useValidation, validators } from '@/composables/useValidation';
  import { useToast } from '@/composables/useToast';

  const { handleApiError } = useValidation();
  const v = validators;
  const { showSuccess, showError } = useToast();

  // 2. Controllers
  import { useUsersController } from '@/controllers/usersController';
  import { useSessionsController } from '@/controllers/sessionsController';
  import { useClientsController } from '@/controllers/clientsController';
  import { useRolesController } from '@/controllers/rolesController';
  import { useAuthController } from '@/controllers/authController';
import { useAuthStore } from '@/stores/auth';

  const usersController = useUsersController();
  const sessionsController = useSessionsController();
  const clientsController = useClientsController();
  const rolesController = useRolesController();
  const authController = useAuthController();
const authStore = useAuthStore();

  // 3. Components
  import UserAuthorizationsList from '@/components/UserAuthorizationsList.vue';

  // 4. Interface and types imports
  import type { UserSession } from '@/types';

  // 5. Main (props, emits, etc.)
  const route = useRoute();
  const userId = Number(route.params.id);

  // 6. Vars and const
  const updating = ref(false);
  const showEditModal = ref(false);
  const showRolesModal = ref(false);
  const savingRoles = ref(false);

  const editForm = ref({
    name: '',
    user: '',
    email: '',
    password: '',
  });

  // 7. Watch
  // (none)

  // 8. Computed
  const user = computed(() => usersController.selectedUser.value);
  const sessions = computed(() => user.value?.sessions || []);
  const loading = computed(() => usersController.loading.value);
  const availableRoles = computed(() => rolesController.roles.value);
  const userRoles = computed(() => usersController.userRoles.value);
  const roleAssignments = computed(() => usersController.roleAssignments.value);
  const isAdmin = computed(() => authController.user.value?.is_admin || false);

  // ZKE: advanced users have null PII on the server. Show the decrypted profile
  // for the current user (self) and clear placeholders for everyone else.
  const decryptedProfile = computed(() => authStore.profile);
  const isSelf = computed(() => !!user.value && authStore.user?.id === user.value.id);
  const isAdvancedUser = computed(() => user.value?.encryption_level === 'advanced');
  const displayName = computed(() => {
    if (user.value?.name) return user.value.name;
    if (isSelf.value && decryptedProfile.value?.name) return decryptedProfile.value.name;
    return 'Usuario cifrado';
  });
  const displayUser = computed(() => {
    if (user.value?.user) return '@' + user.value.user;
    if (isSelf.value && decryptedProfile.value?.user) return '@' + decryptedProfile.value.user;
    return 'Cifrado';
  });
  const displayEmail = computed(() => {
    if (user.value?.email) return user.value.email;
    if (isSelf.value && decryptedProfile.value?.email) return decryptedProfile.value.email;
    return 'No disponible (cifrado)';
  });

  const userRules = computed(() => ({
    name: { required: v.required('Nombre'), maxLength: v.maxLength('Nombre', 255) },
    user: {
      required: v.required('Usuario'),
      minLength: v.minLength('Usuario', 3),
      maxLength: v.maxLength('Usuario', 255),
      alphaNum: v.alphaNum('Usuario'),
    },
    email: {
      required: v.required('Email'),
      email: v.email('Email'),
      maxLength: v.maxLength('Email', 255),
    },
    password: {
      minLength: v.minLength('Nueva Contrase\u00f1a', 8),
      passwordComplex: v.passwordComplex('Nueva Contrase\u00f1a'),
    },
  }));

  const { v$: userV$, validate: validateUser, touchAll: touchUser } =
    useFormValidation(userRules, editForm);

  // 9. OnMounted
  onMounted(() => {
    loadUser();
  });

  // 10. Functions
  const loadUser = async (): Promise<void> => {
    try {
      const response = await usersController.fetchUser(userId);
      editForm.value = {
        name: response.name,
        user: response.user,
        email: response.email,
        password: '',
      };
    } catch {
      showError({ message: 'Error al cargar usuario' });
    }
  };

  const getInitials = (params: { name: string }): string => {
    const { name } = params;
    return (name ?? '')
      .split(' ')
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);
  };

  const formatDate = (params: { dateStr: string }): string => {
    const { dateStr } = params;
    return new Date(dateStr).toLocaleDateString('es-ES', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  const openEditModal = (): void => {
    showEditModal.value = true;
  };

  const updateUser = async (): Promise<void> => {
    const isValid = await validateUser();
    if (!isValid) {
      touchUser();
      return;
    }

    updating.value = true;
    try {
      const data: {
        name: string;
        user: string;
        email: string;
        password?: string;
      } = {
        name: editForm.value.name,
        user: editForm.value.user,
        email: editForm.value.email,
      };

      if (editForm.value.password) {
        data.password = editForm.value.password;
      }

      await usersController.updateUser({ id: userId, data });
      showEditModal.value = false;
      showSuccess({ message: 'Usuario actualizado correctamente' });
    } catch (err) {
      handleApiError(err);
    } finally {
      updating.value = false;
    }
  };

  const revokeSession = async (params: {
    session: UserSession;
  }): Promise<void> => {
    const { session } = params;
    try {
      await sessionsController.revokeSession(session.id);
      if (user.value) {
        user.value.active_tokens_count--;
      }
      showSuccess({ message: 'Sesión revocada correctamente' });
    } catch {
      showError({ message: 'Error al revocar sesión' });
    }
  };

  const revokeAllSessions = async (): Promise<void> => {
    try {
      await usersController.revokeUserSessions(userId);
      showSuccess({ message: 'Todas las sesiones fueron revocadas' });
    } catch {
      showError({ message: 'Error al revocar sesiones' });
    }
  };

  const openRolesModal = async (): Promise<void> => {
    try {
      await Promise.all([
        rolesController.fetchRoles(),
        clientsController.fetchClients(),
        usersController.fetchUserRoles(userId),
      ]);
      usersController.setRoleAssignments(usersController.userRoles.value.map(
        (r: { id: number; name: string; slug: string }) => ({
          role_id: r.id,
          name: r.name,
          slug: r.slug,
        })
      ));
      showRolesModal.value = true;
    } catch {
      showError({ message: 'Error al cargar datos de roles' });
    }
  };

  const addRoleAssignment = (): void => {
    roleAssignments.value.push({
      role_id: null,
    });
  };

  const removeRoleAssignment = (params: { index: number }): void => {
    const { index } = params;
    roleAssignments.value.splice(index, 1);
  };

  const onRoleChange = (params: { index: number; roleId: number }): void => {
    const { index, roleId } = params;
    const role = availableRoles.value.find((r) => r.id === roleId);
    if (role) {
      roleAssignments.value[index].name = role.name;
      roleAssignments.value[index].slug = role.slug;
    }
  };

  const saveUserRoles = async (): Promise<void> => {
    const validAssignments = roleAssignments.value.filter(
      (a) => a.role_id !== null
    );

    if (validAssignments.length === 0) {
      showError({ message: 'Debe seleccionar al menos un rol' });
      return;
    }

    savingRoles.value = true;
    try {
      const rolesToSave = validAssignments.map((a) => ({
        role_id: a.role_id!,
      }));

      await usersController.saveUserRoles({
        id: userId,
        assignments: rolesToSave,
      });
      showRolesModal.value = false;
      showSuccess({ message: 'Roles guardados correctamente' });
    } catch {
      showError({ message: 'Error al guardar roles' });
    } finally {
      savingRoles.value = false;
    }
  };
</script>

<style scoped>
  .loading-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 300px;
    color: var(--text-tertiary);
  }

  .loading-container p {
    margin-top: 12px;
    font-size: 14px;
  }

  .header-card {
    border-radius: var(--card-border-radius);
    margin-bottom: 16px;
    overflow: hidden;
    position: relative;
  }

  .header-bg-circle {
    position: absolute;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.1);
  }

  .hc-1 {
    width: 200px;
    height: 200px;
    top: -60px;
    right: -40px;
  }

  .hc-2 {
    width: 120px;
    height: 120px;
    bottom: -30px;
    left: -20px;
  }

.user-header {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 24px;
  background: var(--gradient-primary);
  color: white;
  position: relative;
  z-index: 1;
}

  .large-avatar {
    width: 80px;
    height: 80px;
    margin-bottom: 12px;
  }

  .avatar-placeholder {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 28px;
  }

  .user-name {
    margin: 0;
    font-size: 22px;
    font-weight: 700;
    color: white;
  }

  .user-username {
    margin: 4px 0 8px 0;
    opacity: 0.85;
    font-size: 14px;
  }

  .user-detail {
    padding: 0;
    --ion-grid-padding: 0;
  }

  .user-detail ion-col {
    padding: 0 0 16px 0;
  }

.info-card,
.roles-card,
.sessions-card {
  background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  border: 1px solid var(--glass-border);
  border-radius: var(--card-border-radius);
  padding: 16px;
  height: 100%;
}

  .section-title {
    margin: 0 0 16px 0;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-tertiary);
  }

  .section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
  }

  .section-header .section-title {
    margin: 0;
  }

.info-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 0;
  border-bottom: 1px solid var(--glass-border);
}

.info-item:last-child {
  border-bottom: none;
}

  .info-icon {
    font-size: 18px;
    color: var(--text-tertiary);
    flex-shrink: 0;
  }

  .info-item .value {
    color: var(--text-primary);
    font-size: 14px;
  }

  .roles-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 32px;
    color: var(--text-tertiary);
    text-align: center;
  }

  .empty-state ion-icon {
    margin-bottom: 12px;
    opacity: 0.5;
  }

  .empty-state p {
    margin: 0;
    font-size: 14px;
  }

  .empty-hint {
    font-size: 12px;
    opacity: 0.7;
    margin-top: 4px;
  }

  .detail-list {
    background: transparent;
    margin: 0 -16px;
    padding: 0;
  }

.detail-list ion-item {
  --padding-start: 16px;
  --padding-end: 16px;
  --background: transparent;
  border-bottom: 1px solid var(--glass-border);
  transition: background 0.2s ease;
}

.detail-list ion-item:hover {
  --background: var(--glass-bg-hover);
}

.detail-list ion-item:last-child {
  border-bottom: none;
}

  .item-title {
    color: var(--text-primary);
    font-weight: 600;
    font-size: 15px;
  }

  .item-subtitle {
    color: var(--text-secondary);
    font-size: 13px;
  }

  .item-subtitle.global-role {
    font-style: italic;
  }

  .session-date {
    font-size: 12px;
    color: var(--text-tertiary);
    margin-top: 4px;
  }

  .roles-assignment {
    padding: 0;
  }

  .roles-list ion-item {
    --padding-start: 16px;
  }

  .modal-form {
    padding: 16px;
  }

  @media (min-width: 768px) {
    .info-card,
    .roles-card,
    .sessions-card {
      padding: 24px;
    }
  }

  @media (max-width: 374px) {
    .user-header {
      padding: 16px;
    }

    .user-name {
      font-size: 20px;
    }

    .user-username {
      font-size: 13px;
    }

    .large-avatar {
      width: 64px;
      height: 64px;
    }

    .info-item {
      padding: 8px 0;
      gap: 8px;
    }

    .info-card,
    .roles-card,
    .sessions-card {
      padding: 12px;
    }
  }
</style>
