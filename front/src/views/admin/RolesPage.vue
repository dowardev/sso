<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-menu-button aria-label="Menú de navegación"></ion-menu-button>
        </ion-buttons>
        <ion-title>Roles</ion-title>
        <ion-buttons slot="end">
            <ion-button aria-label="Crear rol" @click="openCreateModal({})">
              <ion-icon slot="icon-only" :icon="add" aria-hidden="true"></ion-icon>
            </ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <ion-refresher slot="fixed" @ion-refresh="handleRefresh({ event: $event })">
        <ion-refresher-content pulling-text="Tira para actualizar..." refreshing-text="Actualizando..."></ion-refresher-content>
      </ion-refresher>
      <!-- content -->
      <div class="page-container">
        <div class="card-list">
          <div v-if="loading" class="skeleton-container">
            <SkeletonListItem v-for="n in 4" :key="n" />
          </div>

          <div v-else-if="roles.length === 0" class="empty-state">
            <ion-icon :icon="shieldOutline" size="large" aria-hidden="true"></ion-icon>
            <p>No se encontraron roles</p>
          </div>

          <ion-list v-else class="roles-list">
            <ion-item v-for="role in roles" :key="role.id">
              <ion-icon
                slot="start"
                :icon="shieldOutline"
                :color="role.is_protected ? 'warning' : 'primary'"
                aria-hidden="true"
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
                <ion-button color="primary" aria-label="Editar rol" @click="openEditModal({ role })">
                  <ion-icon slot="icon-only" :icon="create" aria-hidden="true"></ion-icon>
                </ion-button>
                <ion-button
                  v-if="!role.is_protected"
                  color="danger"
                  aria-label="Eliminar rol"
                  @click="confirmDelete({ role })"
                >
                  <ion-icon slot="icon-only" :icon="trash" aria-hidden="true"></ion-icon>
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
            <ion-buttons slot="start">
              <ion-button @click="showModal = false">
                <ion-icon :icon="closeOutline"></ion-icon>
              </ion-button>
            </ion-buttons>
            <ion-title>{{ editingRole ? 'Editar Rol' : 'Crear Rol' }}</ion-title>
            <ion-buttons slot="end">
              <ion-button @click="saveRole({})">
                <ion-icon :icon="saveOutline"></ion-icon>
              </ion-button>
            </ion-buttons>
          </ion-toolbar>
        </ion-header>
        <ion-content>
          <form class="modal-form" @submit.prevent="saveRole({})">
            <ion-list>
              <ion-item>
                <ion-label position="stacked">Nombre *</ion-label>
                <ion-input v-model="form.name" aria-describedby="role-name-error" :aria-invalid="roleV$.name.$dirty ? roleV$.name.$invalid : undefined" @ion-blur="roleV$.name.$touch()"></ion-input>
              </ion-item>
              <div v-if="roleV$.name.$error" id="role-name-error" class="field-error">{{ roleV$.name.$errors[0].$message }}</div>
              <ion-item v-if="!editingRole">
                <ion-label position="stacked">Slug *</ion-label>
                <ion-input
                  v-model="form.slug"
                  placeholder="ej: editor"
                  aria-describedby="role-slug-error"
                  :aria-invalid="roleV$.slug.$dirty ? roleV$.slug.$invalid : undefined"
                  @ion-blur="roleV$.slug.$touch()"
                ></ion-input>
              </ion-item>
              <div v-if="roleV$.slug.$error" id="role-slug-error" class="field-error">{{ roleV$.slug.$errors[0].$message }}</div>
              <ion-item>
                <ion-label position="stacked">Descripción</ion-label>
                <ion-textarea
                  v-model="form.description"
                  placeholder="Máx 500 caracteres"
                  aria-describedby="role-desc-error"
                  :aria-invalid="roleV$.description.$dirty ? roleV$.description.$invalid : undefined"
                  @ion-blur="roleV$.description.$touch()"
                ></ion-textarea>
              </ion-item>
              <div v-if="roleV$.description.$error" id="role-desc-error" class="field-error">{{ roleV$.description.$errors[0].$message }}</div>
            </ion-list>
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
// 1. Imports (ionic/vue/ionicons)
import { ref, onMounted, computed } from 'vue'
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
} from '@ionic/vue'
import { add, create, trash, shieldOutline, closeOutline, saveOutline } from 'ionicons/icons'

// 2. Controllers / Composables
import { useRolesController } from '@/controllers/rolesController'
const rolesController = useRolesController()

import { useFormValidation } from '@/composables/useFormValidation'
import { useValidation, validators } from '@/composables/useValidation'
const { handleApiError } = useValidation()
const v = validators

import { useToast } from '@/composables/useToast'
const { showSuccess, showError } = useToast()

// 3. Components
import SkeletonListItem from '@/components/SkeletonListItem.vue'

// 4. Interface and types imports
import type { Role } from '@/types'

// 5. Main (props, emits, etc.)

// 6. Vars and const
const saving = ref(false)
const showModal = ref(false)
const showDeleteAlert = ref(false)
const editingRole = ref<Role | null>(null)
const roleToDelete = ref<Role | null>(null)

const form = ref({
  name: '',
  slug: '',
  description: '',
})

// 7. Watch

// 8. Computed
const roles = computed(() => rolesController.roles.value)
const loading = computed(() => rolesController.loading.value)

const roleRules = computed(() => ({
  name: { required: v.required('Nombre'), maxLength: v.maxLength('Nombre', 255) },
  slug: editingRole.value
    ? {}
    : {
        required: v.required('Slug'),
        minLength: v.minLength('Slug', 2),
        maxLength: v.maxLength('Slug', 255),
        alphaNum: v.alphaNum('Slug'),
      },
  description: { maxLength: v.maxLength('Descripción', 500) },
}))

const { v$: roleV$, validate: validateRole, touchAll: touchRole } =
  useFormValidation(roleRules, form)

// 9. OnMounted
onMounted(() => {
  rolesController.fetchRoles()
})

// 10. Functions (named parameters pattern)
const deleteRole = async (_params: object = {}): Promise<void> => {
  if (!roleToDelete.value) return

  try {
    await rolesController.deleteRole(roleToDelete.value.id)
    showSuccess({ message: 'Rol eliminado correctamente' })
  } catch {
    showError({ message: 'Error al eliminar rol' })
  } finally {
    showDeleteAlert.value = false
    roleToDelete.value = null
  }
}

const deleteAlertButtons = [
  {
    text: 'Cancelar',
    role: 'cancel',
    handler: () => {
      showDeleteAlert.value = false
      roleToDelete.value = null
    },
  },
  {
    text: 'Eliminar',
    role: 'destructive',
    handler: deleteRole,
  },
]

const openCreateModal = (_params: object = {}): void => {
  editingRole.value = null
  form.value = { name: '', slug: '', description: '' }
  showModal.value = true
}

const openEditModal = (params: { role: Role }): void => {
  const { role } = params
  editingRole.value = role
  form.value = {
    name: role.name,
    slug: role.slug,
    description: role.description || '',
  }
  showModal.value = true
}

const saveRole = async (_params: object = {}): Promise<void> => {
  const isValid = await validateRole()
  if (!isValid) {
    touchRole()
    return
  }

  saving.value = true
  try {
    if (editingRole.value) {
      await rolesController.updateRole({
        id: editingRole.value.id,
        data: {
          name: form.value.name,
          description: form.value.description || undefined,
        },
      })
      showSuccess({ message: 'Rol actualizado correctamente' })
    } else {
      await rolesController.createRole({
        data: {
          name: form.value.name,
          slug: form.value.slug,
          description: form.value.description || undefined,
        },
      })
      showSuccess({ message: 'Rol creado correctamente' })
    }
    showModal.value = false
  } catch (err) {
    // LBDS-267: this screen has no inline error block, so the caller owns the toast.
    showError({ message: handleApiError(err) })
  } finally {
    saving.value = false
  }
}

const confirmDelete = (params: { role: Role }): void => {
  const { role } = params
  roleToDelete.value = role
  showDeleteAlert.value = true
}

const handleRefresh = async (params: { event: CustomEvent }): Promise<void> => {
  const { event } = params
  await rolesController.fetchRoles()
  event.detail.complete()
}
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
  padding: 0;
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
