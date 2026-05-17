<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-menu-button></ion-menu-button>
        </ion-buttons>
        <ion-title>Usuarios</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <div class="page-container">
        <div class="search-card">
          <ion-searchbar
            v-model="search"
            placeholder="Buscar usuarios..."
            :debounce="500"
            @ion-input="handleSearch"
          ></ion-searchbar>
        </div>

        <div class="users-card">
          <div v-if="loading && users.length === 0" class="loading-container">
            <ion-spinner name="lines"></ion-spinner>
            <p>Cargando usuarios...</p>
          </div>

          <div v-else-if="users.length === 0" class="empty-container">
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
                <ion-item-option color="medium" @click="toggleAdmin({ user })">
                  <ion-icon
                    slot="icon-only"
                    :icon="user.is_admin ? removeCircle : addCircle"
                  ></ion-icon>
                </ion-item-option>
                <ion-item-option
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
    IonSpinner,
    IonInfiniteScroll,
    IonInfiniteScrollContent,
    IonAlert,
    alertController,
  } from '@ionic/vue';
  import { personRemove, removeCircle, addCircle, trash } from 'ionicons/icons';

  // controllers
  import { useUsersController } from '@/controllers/usersController';
  const usersController = useUsersController();

  // components
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
    } catch (error) {
      console.error('Error deleting user:', error);
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
    } catch (error) {
      console.error('Error toggling admin:', error);
    }
  };

  const confirmDelete = (params: { user: User }): void => {
    const { user } = params;
    userToDelete.value = user;
    showDeleteAlert.value = true;
  };
  // end functions
</script>

<style scoped>
  .page-container {
    padding: var(--dashboard-padding);
    max-width: 600px;
    margin: 0 auto;
  }

  .search-card {
    background: var(--background-contrast);
    border-radius: var(--card-border-radius);
    box-shadow:
      var(--card-shadow-light) 0px 1px 3px 0px,
      var(--card-shadow-heavy) 0px 0px 0px 1px;
    margin-bottom: 16px;
    padding: 8px;
  }

  .search-card ion-searchbar {
    --background: var(--ion-color-light);
    --border-radius: 8px;
    padding: 0;
  }

  .users-card {
    background: var(--background-contrast);
    border-radius: var(--card-border-radius);
    box-shadow:
      var(--card-shadow-light) 0px 1px 3px 0px,
      var(--card-shadow-heavy) 0px 0px 0px 1px;
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

  .users-list {
    background: transparent;
  }

  .users-list ion-item {
    --padding-start: 16px;
    --padding-end: 16px;
    --background: transparent;
    border-bottom: 1px solid var(--ion-background-color-step-200);
  }

  .users-list ion-item:last-child {
    border-bottom: none;
  }

  .avatar-placeholder {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--ion-color-primary);
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
</style>
