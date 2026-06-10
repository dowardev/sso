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
      <ion-refresher slot="fixed" @ion-refresh="handleRefresh($event)">
        <ion-refresher-content pulling-text="Tira para actualizar..." refreshing-text="Actualizando..."></ion-refresher-content>
      </ion-refresher>
      <div class="page-container">
        <div class="search-card">
          <ion-searchbar
            v-model="search"
            aria-label="Buscar usuarios"
            placeholder="Buscar usuarios..."
            :debounce="500"
            @ion-input="handleSearch"
          ></ion-searchbar>
        </div>

        <div class="card-list">
          <div v-if="loading && users.length === 0" class="skeleton-container">
            <SkeletonListItem v-for="n in 5" :key="n" />
          </div>

          <div v-else-if="users.length === 0" class="empty-state">
            <ion-icon :icon="personRemove" size="large"></ion-icon>
            <p>No se encontraron usuarios</p>
          </div>

          <ion-list v-else class="users-list">
            <ion-item-sliding v-for="user in users" :key="user.id">
              <ion-item button @click="router.push(`/admin/users/${user.id}`)">
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
                <ion-item-option aria-label="Alternar rol administrador" color="medium" @click="toggleAdmin({ user })">
                  <ion-icon
                    slot="icon-only"
                    :icon="user.is_admin ? removeCircle : addCircle"
                  ></ion-icon>
                </ion-item-option>
                <ion-item-option
                  aria-label="Eliminar usuario"
                  color="danger"
                  @click="confirmDelete({ user })"
                >
                  <ion-icon slot="icon-only" :icon="trash"></ion-icon>
                </ion-item-option>
              </ion-item-options>
            </ion-item-sliding>
          </ion-list>

          <ion-infinite-scroll
            v-if="users.length > 0"
            aria-label="Cargar más usuarios"
            :disabled="!hasMore"
            @ion-infinite="loadMore($event)"
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
  // imports
  import { ref, onMounted, computed } from 'vue';
  import { useRouter } from 'vue-router';
  import {
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
  } from '@ionic/vue';
  import { personRemove, removeCircle, addCircle, trash } from 'ionicons/icons';

  // controllers
  import { useUsersController } from '@/controllers/usersController';
  const usersController = useUsersController();

  // composables
  import { useToast } from '@/composables/useToast';
  const { showSuccess, showError } = useToast();

  // components
  import SkeletonListItem from '@/components/SkeletonListItem.vue';
  // end components

  // interface and types imports
  import type { User } from '@/types';
  // end interface and types imports

  // main
  const router = useRouter();
  // end main

  // vars and const
  const search = ref('');
  const showDeleteAlert = ref(false);
  const userToDelete = ref<User | null>(null);

  async function deleteUser(): Promise<void> {
    if (!userToDelete.value) return;

    try {
      await usersController.deleteUser(userToDelete.value.id);
      showSuccess({ message: 'Usuario eliminado correctamente' });
    } catch (_error) {
      showError({ message: 'Error al eliminar usuario' });
    } finally {
      showDeleteAlert.value = false;
      userToDelete.value = null;
    }
  }

  const deleteAlertButtons = [
    {
      text: 'Cancelar',
      role: 'cancel',
      handler: () => {
        showDeleteAlert.value = false;
        userToDelete.value = null;
      },
    },
    {
      text: 'Eliminar',
      role: 'destructive',
      handler: deleteUser,
    },
  ];
  // end vars and const

  // watch
  // end

  // computed
  const users = computed(() => usersController.users.value);
  const loading = computed(() => usersController.loading.value);
  const hasMore = computed(() => usersController.hasMore.value);
  // end computed

  // onmounted
  onMounted(() => {
    usersController.fetchUsers({ per_page: 15 });
  });
  // end onmounted

  // functions
  const handleSearch = (): void => {
    usersController.setSearch(search.value);
    usersController.fetchUsers({ search: search.value || undefined });
  };

  const loadMore = (event: unknown): void => {
    usersController
      .fetchUsers({ page: usersController.pagination.value.currentPage + 1 })
      .then(() => {
        if (event && typeof event === 'object' && 'target' in event) {
          (event as { target: { complete: () => void } }).target.complete();
        }
      });
  };

  const getInitials = (params: { name: string }): string => {
    const { name } = params;
    return name
      .split(' ')
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);
  };

  const toggleAdmin = async (params: { user: User }): Promise<void> => {
    const { user } = params;
    try {
      await usersController.toggleAdmin(user.id);
      showSuccess({ message: `Admin ${user.is_admin ? 'removido' : 'asignado'} correctamente` });
    } catch (_error) {
      showError({ message: 'Error al cambiar rol de administrador' });
    }
  };

  const confirmDelete = (params: { user: User }): void => {
    const { user } = params;
    userToDelete.value = user;
    showDeleteAlert.value = true;
  };

  const handleRefresh = async (event: CustomEvent): Promise<void> => {
    await usersController.fetchUsers({ per_page: 15 });
    event.detail.complete();
  };
  // end functions
</script>

<style scoped>
.skeleton-container {
    padding: 8px 0;
  }

  .users-list {
    background: transparent;
  }

.users-list ion-item {
  --padding-start: 16px;
  --padding-end: 16px;
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
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: var(--gradient-primary);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 14px;
}

  .user-name {
    color: var(--text-primary);
    font-weight: 600;
    font-size: 15px;
  }

  .user-username {
    color: var(--text-secondary);
    font-size: 13px;
    margin-top: 2px;
  }

  .user-email {
    color: var(--text-tertiary);
    font-size: 12px;
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
      padding: 8px;
    }

    .user-name {
      font-size: 14px;
    }

    .user-username {
      font-size: 12px;
    }

    .user-email {
      font-size: 11px;
    }

    .avatar-placeholder {
      width: 32px;
      height: 32px;
      font-size: 12px;
    }

    .user-badges {
      flex-direction: row;
    }
  }
</style>
