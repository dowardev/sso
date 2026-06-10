<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-menu-button aria-label="Menú de navegación"></ion-menu-button>
        </ion-buttons>
        <ion-title>Roles</ion-title>
        <ion-buttons slot="end">
          <ion-button aria-label="Crear rol" @click="openCreateModal">
            <ion-icon slot="icon-only" :icon="add"></ion-icon>
          </ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <ion-refresher slot="fixed" @ion-refresh="handleRefresh($event)">
        <ion-refresher-content pulling-text="Tira para actualizar..." refreshing-text="Actualizando..."></ion-refresher-content>
      </ion-refresher>
      <!-- content -->
      <div class="page-container">
        <div class="card-list">
          <div v-if="loading" class="skeleton-container">
            <SkeletonListItem v-for="n in 4" :key="n" />
          </div>

          <div v-else-if="roles.length === 0" class="empty-state">
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
                <p class="role-description">
                  {{ role.description || 'Sin descripción' }}
                </p>
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
                <ion-button aria-label="Editar rol" color="primary" @click="openEditModal({ role })">
                  <ion-icon slot="icon-only" :icon="create"></ion-icon>
                </ion-button>
                <ion-button
                  v-if="!role.is_protected"
                  aria-label="Eliminar rol"
                  color="danger"
                  @click="confirmDelete({ role })"
                >
                  <ion-icon slot="icon-only" :icon="trash"></ion-icon>
                </ion-button>
              </ion-buttons>
            </ion-item>
          </ion-list>
        </div>
      </div>
      <!-- end content -->

      <!-- modals -->
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
          <form class="modal-form" @submit.prevent="saveRole">
            <ion-list>
              <ion-item>
                <ion-label position="stacked">Nombre</ion-label>
                <ion-input v-model="form.name" @ion-blur="v$.name.$touch()"></ion-input>
              </ion-item>
              <div v-if="v$.name.$error" class="field-error">{{ v$.name.$errors[0].$message }}</div>
              <ion-item v-if="!editingRole">
                <ion-label position="stacked">Slug</ion-label>
                <ion-input
                  v-model="form.slug"
                  placeholder="ej: editor"
                  @ion-blur="v$.slug.$touch()"
                ></ion-input>
              </ion-item>
              <div v-if="v$.slug.$error" class="field-error">{{ v$.slug.$errors[0].$message }}</div>
              <ion-item>
                <ion-label position="stacked">Descripción</ion-label>
                <ion-textarea
                  v-model="form.description"
                  placeholder="Descripción opcional"
                ></ion-textarea>
              </ion-item>
            </ion-list>
            <div class="ion-padding">
              <ion-button type="submit" expand="block" class="gradient-btn" :disabled="saving">
                {{ saving ? 'Guardando...' : 'Guardar' }}
              </ion-button>
            </div>
          </form>
        </ion-content>
      </ion-modal>
      <!-- end modals -->

      <!-- popovers -->
      <!-- end popovers -->

      <!-- alerts -->
      <ion-alert
        :is-open="showDeleteAlert"
        header="Eliminar Rol"
        :message="`¿Estás seguro de eliminar el rol ${roleToDelete?.name}?`"
        :buttons="deleteAlertButtons"
      ></ion-alert>
      <!-- end alerts -->
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  // imports
  import { ref, onMounted, computed } from 'vue';
  import useVuelidate from '@vuelidate/core';
  import { required } from '@vuelidate/validators';

  import { useValidation, withMsg } from '@/composables/useValidation';
  const { handleApiError } = useValidation();
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
    IonModal,
    IonInput,
    IonTextarea,
    IonRefresher,
    IonRefresherContent,
    IonAlert,
  } from '@ionic/vue';
  import { add, create, trash, shieldOutline } from 'ionicons/icons';

  // controllers
  import { useRolesController } from '@/controllers/rolesController';
  const rolesController = useRolesController();

  // composables
  import { useToast } from '@/composables/useToast';
  const { showSuccess, showError } = useToast();

  // components
  import SkeletonListItem from '@/components/SkeletonListItem.vue';
  // end components

  // interface and types imports
  import type { Role } from '@/types';
  // end interface and types imports

  // main
  // end main

  // vars and const
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

  const rules = computed(() => ({
    name: { required: withMsg('El nombre es requerido', required) },
    slug: { required: withMsg('El slug es requerido', required) },
  }));

  const v$ = useVuelidate(rules, form);

  async function deleteRole(): Promise<void> {
    if (!roleToDelete.value) return;

    try {
      await rolesController.deleteRole(roleToDelete.value.id);
      showSuccess({ message: 'Rol eliminado correctamente' });
    } catch (_error) {
      showError({ message: 'Error al eliminar rol' });
    } finally {
      showDeleteAlert.value = false;
      roleToDelete.value = null;
    }
  }

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
  // end vars and const

  // watch
  // end

  // computed
  const roles = computed(() => rolesController.roles.value);
  const loading = computed(() => rolesController.loading.value);
  // end computed

  // onmounted
  onMounted(() => {
    rolesController.fetchRoles();
  });
  // end onmounted

  // functions
  const openCreateModal = (): void => {
    editingRole.value = null;
  form.value = { name: '', slug: '', description: '' };
  v$.value.$reset();
  showModal.value = true;
  };

  const openEditModal = (params: { role: Role }): void => {
    const { role } = params;
    editingRole.value = role;
  form.value = {
  name: role.name,
  slug: role.slug,
  description: role.description || '',
};
  v$.value.$reset();
  showModal.value = true;
  };

      const saveRole = async (): Promise<void> => {
    const isValid = await v$.value.$validate();
    if (!isValid) return;

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
        showSuccess({ message: 'Rol actualizado correctamente' });
      } else {
        await rolesController.createRole({
          data: {
            name: form.value.name,
            slug: form.value.slug,
            description: form.value.description || undefined,
          },
        });
        showSuccess({ message: 'Rol creado correctamente' });
      }
      showModal.value = false;
    } catch (error) {
      console.error('[Save Role Error]', error);
      handleApiError(error);
    } finally {
      saving.value = false;
    }
  };

  const confirmDelete = (params: { role: Role }): void => {
    const { role } = params;
    roleToDelete.value = role;
    showDeleteAlert.value = true;
  };

  const handleRefresh = async (event: CustomEvent): Promise<void> => {
    await rolesController.fetchRoles();
    event.detail.complete();
  };
  // end functions
</script>

<style scoped>
  .skeleton-container {
    padding: 8px 0;
  }

  .roles-list {
    background: transparent;
  }

.roles-list ion-item {
  --padding-start: 16px;
  --padding-end: 16px;
  --background: transparent;
  border-bottom: 1px solid var(--glass-border);
  transition: background 0.2s ease;
}

.roles-list ion-item:hover {
  --background: var(--glass-bg-hover);
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

  @media (min-width: 1024px) {
    .page-container {
      max-width: 800px;
    }
  }

  @media (max-width: 374px) {
    .page-container {
      padding: 8px;
    }

    .role-name {
      font-size: 14px;
    }

    .role-description {
      font-size: 12px;
    }

    .role-badges {
      flex-direction: row;
      margin-right: 4px;
    }
  }
</style>
