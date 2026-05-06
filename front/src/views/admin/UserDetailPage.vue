<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-back-button default-href="/admin/users"></ion-back-button>
        </ion-buttons>
        <ion-title>Detalle de Usuario</ion-title>
        <ion-buttons slot="end">
          <ion-button color="primary" @click="openEditModal">
            <ion-icon slot="icon-only" :icon="create"></ion-icon>
          </ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <div class="page-container">
        <div v-if="loading" class="loading-container">
          <ion-spinner name="lines"></ion-spinner>
          <p>Cargando usuario...</p>
        </div>

        <ion-grid v-else-if="user" class="user-detail">
            <ion-row>
              <ion-col size="12">
                <div class="header-card">
                  <div class="user-header">
                    <ion-avatar class="large-avatar">
                      <div class="avatar-placeholder">{{ getInitials(user.name) }}</div>
                    </ion-avatar>
                    <h2 class="user-name">{{ user.name }}</h2>
                    <p class="user-username">@{{ user.user }}</p>
                    <ion-badge v-if="user.is_admin" color="warning"
                      >Administrador</ion-badge
                    >
                  </div>
                </div>
              </ion-col>

              <ion-col size="12" size-md="6">
                <div class="info-card">
                  <h3 class="section-title">Información</h3>
                  <div class="info-item">
                    <span class="label">Email</span>
                    <span class="value">{{ user.email }}</span>
                  </div>
                  <div class="info-item">
                    <span class="label">Creado</span>
                    <span class="value">{{ formatDate(user.created_at) }}</span>
                  </div>
                  <div class="info-item">
                    <span class="label">Tokens</span>
                    <span class="value">{{ user.active_tokens_count }} activos</span>
                  </div>
                </div>
              </ion-col>

              <ion-col size="12" size-md="6">
                <div class="roles-card">
                  <div class="section-header">
                    <h3 class="section-title">Roles</h3>
                    <ion-button size="small" @click="openRolesModal">
                      <ion-icon slot="icon-only" :icon="create"></ion-icon>
                    </ion-button>
                  </div>

                  <div v-if="userRoles.length === 0" class="empty-state">
                    <ion-icon :icon="shieldOutline" size="large"></ion-icon>
                    <p>Sin roles asignados</p>
                    <span class="empty-hint">(rol: Estándar)</span>
                  </div>

                  <ion-list v-else class="detail-list">
                    <ion-item v-for="(role, index) in userRoles" :key="index">
                      <ion-icon
                        slot="start"
                        :icon="shieldOutline"
                        color="primary"
                      ></ion-icon>
                      <ion-label>
                        <h3 class="item-title">{{ role.name }}</h3>
                        <p v-if="role.sso_client_name" class="item-subtitle">
                          App: {{ role.sso_client_name }}
                        </p>
                        <p v-else class="item-subtitle global-role">Global</p>
                      </ion-label>
                      <ion-badge v-if="role.sso_client_id" color="secondary"
                        >App</ion-badge
                      >
                      <ion-badge v-else color="medium">Global</ion-badge>
                    </ion-item>
                  </ion-list>
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
                    <ion-icon :icon="keyOutline" size="large"></ion-icon>
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
                        <h3 class="item-title">{{ session.client?.name || 'Cliente Desconocido' }}</h3>
                        <p class="item-subtitle">{{ session.ip_address }}</p>
                        <p class="session-date">
                          Último uso: {{ formatDate(session.last_used_at) }}
                        </p>
                      </ion-label>
                      <ion-badge :color="session.token_active ? 'success' : 'medium'">
                        {{ session.token_active ? 'Activa' : 'Inactiva' }}
                      </ion-badge>
                      <ion-button
                        slot="end"
                        fill="clear"
                        color="danger"
                        @click="revokeSession(session)"
                      >
                        <ion-icon slot="icon-only" :icon="trash"></ion-icon>
                      </ion-button>
                    </ion-item>
                  </ion-list>
                </div>
              </ion-col>
            </ion-row>
          </ion-grid>
      </div>

      <ion-modal :is-open="showEditModal" @did-dismiss="showEditModal = false">
        <ion-header class="ion-no-border" mode="ios">
          <ion-toolbar>
            <ion-title>Editar Usuario</ion-title>
            <ion-buttons slot="end">
              <ion-button @click="showEditModal = false">Cancelar</ion-button>
            </ion-buttons>
          </ion-toolbar>
        </ion-header>
        <ion-content>
          <form @submit.prevent="updateUser" class="modal-form">
            <ion-list>
              <ion-item>
                <ion-label position="stacked">Nombre</ion-label>
                <ion-input v-model="editForm.name" required></ion-input>
              </ion-item>
              <ion-item>
                <ion-label position="stacked">Usuario</ion-label>
                <ion-input v-model="editForm.user" required></ion-input>
              </ion-item>
              <ion-item>
                <ion-label position="stacked">Email</ion-label>
                <ion-input
                  v-model="editForm.email"
                  type="email"
                  required
                ></ion-input>
              </ion-item>
              <ion-item>
                <ion-label position="stacked">Nueva Contraseña</ion-label>
                <ion-input
                  v-model="editForm.password"
                  type="password"
                  placeholder="Dejar vacío para mantener"
                ></ion-input>
              </ion-item>
            </ion-list>
            <div class="ion-padding">
              <ion-button type="submit" expand="block" :disabled="updating">
                {{ updating ? 'Guardando...' : 'Guardar Cambios' }}
              </ion-button>
            </div>
          </form>
        </ion-content>
      </ion-modal>

      <ion-modal
        :is-open="showRolesModal"
        @did-dismiss="showRolesModal = false"
      >
        <ion-header class="ion-no-border" mode="ios">
          <ion-toolbar>
            <ion-title>Asignar Roles</ion-title>
            <ion-buttons slot="end">
              <ion-button @click="showRolesModal = false">Cancelar</ion-button>
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
                      @ion-change="onRoleChange(index, $event.detail.value)"
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
                  <ion-item>
                    <ion-label position="stacked"
                      >Aplicación (opcional)</ion-label
                    >
                    <ion-select
                      v-model="assignment.sso_client_id"
                      placeholder="Global (todas las apps)"
                      @ion-change="onClientChange(index, $event.detail.value)"
                    >
                      <ion-select-option :value="null"
                        >Global (todas las apps)</ion-select-option
                      >
                      <ion-select-option
                        v-for="client in availableClients"
                        :key="client.id"
                        :value="client.id"
                      >
                        {{ client.name }}
                      </ion-select-option>
                    </ion-select>
                  </ion-item>
                  <ion-item-options side="end">
                    <ion-item-option
                      color="danger"
                      @click="removeRoleAssignment(index)"
                    >
                      <ion-icon slot="icon-only" :icon="trash"></ion-icon>
                    </ion-item-option>
                  </ion-item-options>
                </ion-item-sliding>
              </ion-list>
            </div>

            <div class="ion-padding">
              <ion-button
                expand="block"
                fill="outline"
                @click="addRoleAssignment"
              >
                <ion-icon slot="start" :icon="add"></ion-icon>
                Agregar Rol
              </ion-button>
              <ion-button
                expand="block"
                :disabled="savingRoles"
                style="margin-top: 8px"
                @click="saveUserRoles"
              >
                {{ savingRoles ? 'Guardando...' : 'Guardar Roles' }}
              </ion-button>
            </div>
          </div>
        </ion-content>
      </ion-modal>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  import { ref, onMounted, computed } from 'vue';
  import { useRoute, useRouter } from 'vue-router';
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
  } from '@ionic/vue';
  import {
    create,
    keyOutline,
    trash,
    shieldOutline,
    add,
  } from 'ionicons/icons';
  import { useUsersStore } from '@/stores/users';
  import { useSessionsStore } from '@/stores/sessions';
  import { useClientsStore } from '@/stores/clients';
  import { useRolesStore } from '@/stores/roles';
  import { useAuthStore } from '@/stores/auth';
  import type { UserDetail, UserSession, Role, SsoClient } from '@/types';

  const route = useRoute();
  const router = useRouter();

  const usersStore = useUsersStore();
  const sessionsStore = useSessionsStore();
  const clientsStore = useClientsStore();
  const rolesStore = useRolesStore();
  const authStore = useAuthStore();

  const user = computed(() => usersStore.selectedUser);
  const sessions = computed(() => user.value?.sessions || []);
  const loading = computed(() => usersStore.loading);
  const updating = ref(false);
  const showEditModal = ref(false);

  const availableRoles = computed(() => rolesStore.roles);
  const availableClients = computed(() => clientsStore.clients);
  const userRoles = computed(() => usersStore.userRoles);
  const roleAssignments = computed({
    get: () => usersStore.roleAssignments,
    set: (value) => { usersStore.roleAssignments = value; },
  });
  const showRolesModal = ref(false);
  const savingRoles = ref(false);
  const isAdmin = computed(() => authStore.user?.is_admin || false);

  const editForm = ref({
    name: '',
    user: '',
    email: '',
    password: '',
  });

  const userId = Number(route.params.id);

  const loadUser = async () => {
    try {
      const response = await usersStore.fetchUser(userId);
      editForm.value = {
        name: response.name,
        user: response.user,
        email: response.email,
        password: '',
      };
    } catch (error) {
      console.error('Error loading user:', error);
    }
  };

  const getInitials = (name: string) => {
    return name
      .split(' ')
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);
  };

  const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleDateString('es-ES', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  const openEditModal = () => {
    showEditModal.value = true;
  };

  const updateUser = async () => {
    updating.value = true;
    try {
      const data: { name: string; user: string; email: string; password?: string } = {
        name: editForm.value.name,
        user: editForm.value.user,
        email: editForm.value.email,
      };

      if (editForm.value.password) {
        data.password = editForm.value.password;
      }

      await usersStore.updateUser(userId, data);
      showEditModal.value = false;
    } catch (error) {
      console.error('Error updating user:', error);
    } finally {
      updating.value = false;
    }
  };

  const revokeSession = async (session: UserSession) => {
    try {
      await sessionsStore.revokeSession(session.id);
      if (user.value) {
        user.value.active_tokens_count--;
      }
    } catch (error) {
      console.error('Error revoking session:', error);
    }
  };

  const revokeAllSessions = async () => {
    try {
      await usersStore.revokeUserSessions(userId);
    } catch (error) {
      console.error('Error revoking sessions:', error);
    }
  };

  const openRolesModal = async () => {
    try {
      await Promise.all([
        rolesStore.fetchRoles(),
        clientsStore.fetchClients(),
        usersStore.fetchUserRoles(userId),
      ]);
      roleAssignments.value = usersStore.userRoles.map((r) => ({
        role_id: r.id,
        sso_client_id: r.sso_client_id,
        name: r.name,
        slug: r.slug,
      }));
      showRolesModal.value = true;
    } catch (error) {
      console.error('Error loading roles data:', error);
    }
  };

  const addRoleAssignment = () => {
    roleAssignments.value.push({
      role_id: null,
      sso_client_id: null,
    });
  };

  const removeRoleAssignment = (index: number) => {
    roleAssignments.value.splice(index, 1);
  };

  const onRoleChange = (index: number, roleId: number) => {
    const role = availableRoles.value.find((r) => r.id === roleId);
    if (role) {
      roleAssignments.value[index].name = role.name;
      roleAssignments.value[index].slug = role.slug;
    }
  };

  const onClientChange = (index: number, clientId: number | null) => {
    roleAssignments.value[index].sso_client_id = clientId;
  };

  const saveUserRoles = async () => {
    const validAssignments = roleAssignments.value.filter(
      (a) => a.role_id !== null
    );

    if (validAssignments.length === 0) {
      console.error('Debe seleccionar al menos un rol');
      return;
    }

    savingRoles.value = true;
    try {
      const rolesToSave = validAssignments.map((a) => ({
        role_id: a.role_id!,
        sso_client_id: a.sso_client_id,
      }));

      await usersStore.saveUserRoles(userId, rolesToSave);
      showRolesModal.value = false;
    } catch (error) {
      console.error('Error saving roles:', error);
    } finally {
      savingRoles.value = false;
    }
  };

  onMounted(() => {
    loadUser();
  });
</script>

<style scoped>
.page-container {
  padding: var(--dashboard-padding);
  max-width: 900px;
  margin: 0 auto;
}

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
  background: var(--background-contrast);
  border-radius: var(--card-border-radius);
  box-shadow: var(--card-shadow-light) 0px 1px 3px 0px, var(--card-shadow-heavy) 0px 0px 0px 1px;
  margin-bottom: 16px;
  overflow: hidden;
}

.user-header {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 24px;
  background: var(--gradient-primary);
  color: white;
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
  background: var(--background-contrast);
  color: var(--gradient-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 28px;
}

.user-name {
  margin: 0;
  font-size: 24px;
  font-weight: 600;
}

.user-username {
  margin: 4px 0 8px 0;
  opacity: 0.9;
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
  background: var(--background-contrast);
  border-radius: var(--card-border-radius);
  box-shadow: var(--card-shadow-light) 0px 1px 3px 0px, var(--card-shadow-heavy) 0px 0px 0px 1px;
  padding: 16px;
  height: 100%;
}

.section-title {
  margin: 0 0 16px 0;
  font-size: 14px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
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
  justify-content: space-between;
  padding: 12px 0;
  border-bottom: 1px solid var(--ion-background-color-step-200);
}

.info-item:last-child {
  border-bottom: none;
}

.info-item .label {
  font-weight: 500;
  color: var(--text-secondary);
}

.info-item .value {
  color: var(--text-primary);
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
  border-bottom: 1px solid var(--ion-background-color-step-200);
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
  .page-container {
    padding: var(--dashboard-padding-lg);
  }

  .info-card,
  .roles-card,
  .sessions-card {
    padding: 24px;
  }
}
</style>