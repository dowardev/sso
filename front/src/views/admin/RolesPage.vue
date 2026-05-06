<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-menu-button></ion-menu-button>
        </ion-buttons>
        <ion-title>Roles</ion-title>
        <ion-buttons slot="end">
          <ion-button @click="openCreateModal">
            <ion-icon slot="icon-only" :icon="add"></ion-icon>
          </ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <div class="page-container">
        <div class="roles-card">
          <div v-if="loading" class="loading-container">
            <ion-spinner name="lines"></ion-spinner>
            <p>Cargando roles...</p>
          </div>

          <div v-else-if="roles.length === 0" class="empty-container">
            <ion-icon :icon="shieldOutline" size="large"></ion-icon>
            <p>No se encontraron roles</p>
          </div>

          <ion-list v-else class="roles-list">
            <ion-item v-for="role in roles" :key="role.id">
              <ion-icon
                slot="start"
                :icon="shieldOutline"
                :color="role.is_protected ? 'warning' : 'primary'"
              ></ion-icon>
              <ion-label>
                <h2 class="role-name">{{ role.name }}</h2>
                <p class="role-description">{{ role.description || 'Sin descripción' }}</p>
              </ion-label>
              <div slot="end" class="role-badges">
                <ion-badge v-if="role.is_protected" color="warning"
                  >Protegido</ion-badge
                >
                <ion-badge color="medium"
                  >{{ role.users_count || 0 }} usuarios</ion-badge
                >
              </div>
              <ion-buttons slot="end">
                <ion-button color="primary" @click="openEditModal(role)">
                  <ion-icon slot="icon-only" :icon="create"></ion-icon>
                </ion-button>
                <ion-button
                  v-if="!role.is_protected"
                  color="danger"
                  @click="confirmDelete(role)"
                >
                  <ion-icon slot="icon-only" :icon="trash"></ion-icon>
                </ion-button>
              </ion-buttons>
            </ion-item>
          </ion-list>
        </div>
      </div>

      <ion-modal :is-open="showModal" @did-dismiss="showModal = false">
        <ion-header class="ion-no-border" mode="ios">
          <ion-toolbar>
            <ion-title>{{
              editingRole ? 'Editar Rol' : 'Crear Rol'
            }}</ion-title>
            <ion-buttons slot="end">
              <ion-button @click="showModal = false">Cancelar</ion-button>
            </ion-buttons>
          </ion-toolbar>
        </ion-header>
        <ion-content>
          <form @submit.prevent="saveRole" class="modal-form">
            <ion-list>
              <ion-item>
                <ion-label position="stacked">Nombre</ion-label>
                <ion-input v-model="form.name" required></ion-input>
              </ion-item>
              <ion-item v-if="!editingRole">
                <ion-label position="stacked">Slug</ion-label>
                <ion-input
                  v-model="form.slug"
                  placeholder="ej: editor"
                  required
                ></ion-input>
              </ion-item>
              <ion-item>
                <ion-label position="stacked">Descripción</ion-label>
                <ion-textarea
                  v-model="form.description"
                  placeholder="Descripción opcional"
                ></ion-textarea>
              </ion-item>
            </ion-list>
            <div class="ion-padding">
              <ion-button type="submit" expand="block" :disabled="saving">
                {{ saving ? 'Guardando...' : 'Guardar' }}
              </ion-button>
            </div>
          </form>
        </ion-content>
      </ion-modal>

      <ion-alert
        :is-open="showDeleteAlert"
        header="Eliminar Rol"
        :message="`¿Estás seguro de eliminar el rol ${roleToDelete?.name}?`"
        :buttons="deleteAlertButtons"
      ></ion-alert>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  import { ref, onMounted, computed } from 'vue';
  import {
    IonPage,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonButtons,
    IonMenuButton,
    IonContent,
    IonList,
    IonItem,
    IonLabel,
    IonBadge,
    IonButton,
    IonIcon,
    IonSpinner,
    IonModal,
    IonInput,
    IonTextarea,
    IonAlert,
    alertController,
  } from '@ionic/vue';
  import { add, create, trash, shieldOutline } from 'ionicons/icons';
  import { useRolesController } from '@/controllers/rolesController';
  import type { Role } from '@/types';

  const rolesController = useRolesController();

  const roles = computed(() => rolesController.roles.value);
  const loading = computed(() => rolesController.loading.value);

  const saving = ref(false);
  const showModal = ref(false);
  const showDeleteAlert = ref(false);
  const editingRole = ref<Role | null>(null);
  const roleToDelete = ref<Role | null>(null);

  const form = ref({
    name: '',
    slug: '',
    description: '',
  });

  const openCreateModal = (): void => {
    editingRole.value = null;
    form.value = { name: '', slug: '', description: '' };
    showModal.value = true;
  };

  const openEditModal = (role: Role) => {
    editingRole.value = role;
    form.value = {
      name: role.name,
      slug: role.slug,
      description: role.description || '',
    };
    showModal.value = true;
  };

  const saveRole = async () => {
    saving.value = true;
    try {
      if (editingRole.value) {
        await rolesController.updateRole({ 
          id: editingRole.value.id, 
          data: {
            name: form.value.name,
            description: form.value.description || undefined,
          },
        });
      } else {
        await rolesController.createRole({ 
          data: {
            name: form.value.name,
            slug: form.value.slug,
            description: form.value.description || undefined,
          },
        });
      }
      showModal.value = false;
    } catch (error) {
      console.error('Error saving role:', error);
    } finally {
      saving.value = false;
    }
  };

  const confirmDelete = (role: Role) => {
    roleToDelete.value = role;
    showDeleteAlert.value = true;
  };

  const deleteRole = async () => {
    if (!roleToDelete.value) return;

    try {
      await rolesController.deleteRole(roleToDelete.value.id);
    } catch (error) {
      console.error('Error deleting role:', error);
    } finally {
      showDeleteAlert.value = false;
      roleToDelete.value = null;
    }
  };

  const deleteAlertButtons = [
    {
      text: 'Cancelar',
      role: 'cancel',
      handler: () => {
        showDeleteAlert.value = false;
        roleToDelete.value = null;
      },
    },
    {
      text: 'Eliminar',
      role: 'destructive',
      handler: deleteRole,
    },
  ];

  onMounted(() => {
    rolesController.fetchRoles();
  });
</script>

<style scoped>
.page-container {
  padding: var(--dashboard-padding);
  max-width: 600px;
  margin: 0 auto;
}

.roles-card {
  background: var(--background-contrast);
  border-radius: var(--card-border-radius);
  box-shadow: var(--card-shadow-light) 0px 1px 3px 0px, var(--card-shadow-heavy) 0px 0px 0px 1px;
  overflow: hidden;
}

.loading-container,
.empty-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 16px;
  color: var(--text-tertiary);
}

.empty-container ion-icon {
  margin-bottom: 12px;
  opacity: 0.5;
}

.loading-container p,
.empty-container p {
  margin: 0;
  font-size: 14px;
}

.roles-list {
  background: transparent;
}

.roles-list ion-item {
  --padding-start: 16px;
  --padding-end: 16px;
  --background: transparent;
  border-bottom: 1px solid var(--ion-background-color-step-200);
}

.roles-list ion-item:last-child {
  border-bottom: none;
}

.role-name {
  color: var(--text-primary);
  font-weight: 600;
  font-size: 15px;
}

.role-description {
  color: var(--text-secondary);
  font-size: 13px;
  margin-top: 2px;
}

.role-badges {
  display: flex;
  flex-direction: column;
  gap: 4px;
  align-items: flex-end;
  margin-right: 8px;
}

.modal-form {
  padding: 16px;
}

@media (min-width: 768px) {
  .page-container {
    padding: var(--dashboard-padding-lg);
  }
}
</style>