<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-menu-button aria-label="Menú de navegación"></ion-menu-button>
        </ion-buttons>
        <ion-title>Usuarios</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <ion-refresher slot="fixed" @ion-refresh="handleRefresh({ event: $event })">
        <ion-refresher-content pulling-text="Tira para actualizar..." refreshing-text="Actualizando..."></ion-refresher-content>
      </ion-refresher>
      <div class="page-container">
        <div class="search-card">
          <ion-searchbar
            v-model="search"
            placeholder="Buscar usuarios..."
            :debounce="500"
            aria-label="Buscar usuarios"
            @ion-input="handleSearch({})"
          ></ion-searchbar>
        </div>

        <div v-if="ssoClientId" class="filter-badge ion-padding-horizontal">
          <ion-badge color="primary" outline>
            Filtrado por cliente SSO: {{ ssoClientId }}
          </ion-badge>
        </div>

        <div class="card-list">
          <div v-if="loading && users.length === 0" class="skeleton-container" role="status" aria-live="polite" aria-label="Cargando usuarios">
            <SkeletonListItem v-for="n in 5" :key="n" />
          </div>

          <div v-else-if="users.length === 0" class="empty-state">
            <ion-icon :icon="personRemove" size="large" aria-hidden="true"></ion-icon>
            <p>No se encontraron usuarios</p>
          </div>

          <ion-list v-else class="users-list">
            <ion-item-sliding v-for="user in users" :key="user.id">
              <ion-item button @click="navigateToUserDetail({ userId: user.id })">
                <ion-avatar slot="start">
                  <div class="avatar-placeholder">
                    {{ getInitials({ name: user.name }) }}
                  </div>
                </ion-avatar>
                <ion-label>
                  <h2 class="user-name">{{ user.name }}</h2>
                  <p class="user-username">@{{ user.user }}</p>
                  <p class="user-email">{{ user.email }}</p>
                </ion-label>
                <div slot="end" class="user-badges">
                  <ion-badge v-if="user.is_admin" color="warning"
                    >Admin</ion-badge
                  >
                  <ion-badge color="primary"
                    >{{ user.sessions_count || 0 }} sesiones</ion-badge
                  >
                </div>
              </ion-item>

              <ion-item-options side="end">
                <ion-item-option color="medium" aria-label="Alternar rol administrador" @click="toggleAdmin({ user })">
                  <ion-icon
                    slot="icon-only"
                    :icon="user.is_admin ? removeCircle : addCircle"
                    aria-hidden="true"
                  ></ion-icon>
                </ion-item-option>
                <ion-item-option
                  color="danger"
                  aria-label="Eliminar usuario"
                  @click="confirmDelete({ user })"
                >
                  <ion-icon slot="icon-only" :icon="trash" aria-hidden="true"></ion-icon>
                </ion-item-option>
              </ion-item-options>
            </ion-item-sliding>
          </ion-list>

          <ion-infinite-scroll
            v-if="users.length > 0"
            :disabled="!hasMore"
            aria-label="Cargar más usuarios"
            @ion-infinite="loadMore({ event: $event })"
          >
            <ion-infinite-scroll-content
              loading-spinner="lines"
            ></ion-infinite-scroll-content>
          </ion-infinite-scroll>
        </div>
      </div>
      <!-- end content -->

      <!-- modals -->
      <!-- end modals -->

      <!-- popovers -->
      <!-- end popovers -->

      <!-- alerts -->
      <ion-alert
        :is-open="showDeleteAlert"
        header="Eliminar Usuario"
        :message="`¿Estás seguro de eliminar a ${userToDelete?.name}?`"
        :buttons="deleteAlertButtons"
        aria-live="assertive"
      ></ion-alert>
      <!-- end alerts -->
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
// 1. Imports (ionic/vue/ionicons)
import { ref, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import {
  onIonViewWillEnter,
  IonPage,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonButtons,
  IonMenuButton,
  IonContent,
  IonSearchbar,
  IonList,
  IonItem,
  IonLabel,
  IonAvatar,
  IonBadge,
  IonItemSliding,
  IonItemOptions,
  IonItemOption,
  IonIcon,
  IonInfiniteScroll,
  IonInfiniteScrollContent,
  IonRefresher,
  IonRefresherContent,
  IonAlert,
} from '@ionic/vue'
import { personRemove, removeCircle, addCircle, trash } from 'ionicons/icons'

// 2. Controllers / Composables
import { useUsersController } from '@/controllers/usersController'
const usersController = useUsersController()

import { useToast } from '@/composables/useToast'
const { showSuccess, showError } = useToast()

// 3. Components
import SkeletonListItem from '@/components/SkeletonListItem.vue'

// 4. Interface and types imports
import type { User } from '@/types'

// 5. Main (props, emits, etc.)
const router = useRouter()
const route = useRoute()

// 6. Vars and const
const search = ref('')
const showDeleteAlert = ref(false)
const userToDelete = ref<User | null>(null)

// 7. Watch

// 8. Computed
const users = computed(() => usersController.users.value)
const loading = computed(() => usersController.loading.value)
const hasMore = computed(() => usersController.hasMore.value)
const ssoClientId = computed(() => route.query.sso_client_id as string | undefined)

// 9. OnMounted
onIonViewWillEnter(() => {
  const ssoClientId = route.query.sso_client_id as string | undefined
  usersController.fetchUsers({ per_page: 15, sso_client_id: ssoClientId })
})

// 10. Functions (named parameters pattern)
const deleteUser = async (_params: object = {}): Promise<void> => {
  if (!userToDelete.value) return

  try {
    await usersController.deleteUser(userToDelete.value.id)
    showSuccess({ message: 'Usuario eliminado correctamente' })
  } catch {
    showError({ message: 'Error al eliminar usuario' })
  } finally {
    showDeleteAlert.value = false
    userToDelete.value = null
  }
}

const deleteAlertButtons = [
  {
    text: 'Cancelar',
    role: 'cancel',
    handler: () => {
      showDeleteAlert.value = false
      userToDelete.value = null
    },
  },
  {
    text: 'Eliminar',
    role: 'destructive',
    handler: deleteUser,
  },
]

const handleSearch = (_params: object = {}): void => {
  const ssoClientId = route.query.sso_client_id as string | undefined
  usersController.setSearch(search.value)
  usersController.fetchUsers({ search: search.value || undefined, sso_client_id: ssoClientId })
}

const loadMore = async (params: { event: unknown }): Promise<void> => {
  const { event } = params
  const ssoClientId = route.query.sso_client_id as string | undefined
  await usersController.fetchUsers({ page: usersController.pagination.value.currentPage + 1, sso_client_id: ssoClientId })
  if (event && typeof event === 'object' && 'target' in event) {
    (event as { target: { complete: () => void } }).target.complete()
  }
}

const getInitials = (params: { name: string }): string => {
  const { name } = params
  return name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2)
}

const toggleAdmin = async (params: { user: User }): Promise<void> => {
  const { user } = params
  try {
    const response = await usersController.toggleAdmin({
      id: user.id,
      action: user.is_admin ? 'remove' : 'assign',
    })
    user.is_admin = response.is_admin
    showSuccess({ message: `Admin ${response.is_admin ? 'asignado' : 'removido'} correctamente` })
  } catch {
    showError({ message: 'Error al cambiar rol de administrador' })
  }
}

const confirmDelete = (params: { user: User }): void => {
  const { user } = params
  userToDelete.value = user
  showDeleteAlert.value = true
}

const handleRefresh = async (params: { event: CustomEvent }): Promise<void> => {
  const { event } = params
  const ssoClientId = route.query.sso_client_id as string | undefined
  await usersController.fetchUsers({ per_page: 15, sso_client_id: ssoClientId })
  event.detail.complete()
}

const navigateToUserDetail = (params: { userId: number }): void => {
  const { userId } = params
  router.push(`/admin/users/${userId}`)
}
</script>

<style scoped>
.skeleton-container {
  padding: var(--space-2, 8px) 0;
}

.users-list {
  background: transparent;
}

.users-list ion-item {
  --padding-start: clamp(12px, 3vw, 16px);
  --padding-end: clamp(12px, 3vw, 16px);
  --background: transparent;
  border-bottom: 1px solid var(--glass-border);
  transition: background 0.2s ease;
}

.users-list ion-item:hover {
  --background: var(--glass-bg-hover);
}

.users-list ion-item:last-child {
  border-bottom: none;
}

.avatar-placeholder {
  width: clamp(36px, 8vw, 40px);
  height: clamp(36px, 8vw, 40px);
  border-radius: 50%;
  background: var(--gradient-primary);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: clamp(12px, 3vw, 14px);
}

.user-name {
  color: var(--text-primary);
  font-weight: 600;
  font-size: clamp(14px, 3.5vw, 15px);
}

.user-username {
  color: var(--text-secondary);
  font-size: clamp(12px, 3vw, 13px);
  margin-top: 2px;
}

.user-email {
  color: var(--text-tertiary);
  font-size: clamp(11px, 2.5vw, 12px);
}

.user-badges {
  display: flex;
  flex-direction: column;
  gap: 4px;
  align-items: flex-end;
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
    padding: var(--space-2, 8px);
  }

  .user-badges {
    flex-direction: row;
  }
}

.search-card {
  margin-bottom: var(--space-4, 16px);
}

.filter-badge {
  margin-bottom: var(--space-3, 12px);
}
</style>
