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
                    {{ getInitials(user.name) }}
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
                <ion-item-option color="medium" @click="toggleAdmin(user)">
                  <ion-icon
                    slot="icon-only"
                    :icon="user.is_admin ? removeCircle : addCircle"
                  ></ion-icon>
                </ion-item-option>
                <ion-item-option color="danger" @click="confirmDelete(user)">
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

      <ion-alert
        :is-open="showDeleteAlert"
        header="Eliminar Usuario"
        :message="`¿Estás seguro de eliminar a ${userToDelete?.name}?`"
        :buttons="deleteAlertButtons"
      ></ion-alert>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  import { ref, onMounted } from 'vue';
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
  import { api, type User, type PaginatedResponse } from '@/services/api';

  const router = useRouter();

  const users = ref<User[]>([]);
  const loading = ref(true);
  const search = ref('');
  const page = ref(1);
  const hasMore = ref(true);

  const showDeleteAlert = ref(false);
  const userToDelete = ref<User | null>(null);

  const loadUsers = async (reset = false) => {
    if (reset) {
      page.value = 1;
      users.value = [];
      hasMore.value = true;
    }

    loading.value = true;
    try {
      const params = new URLSearchParams({
        page: page.value.toString(),
        per_page: '15',
      });

      if (search.value) {
        params.append('search', search.value);
      }

      const response = await api.get<PaginatedResponse<User>>(
        `/admin/users?${params}`
      );

      if (reset) {
        users.value = response.data;
      } else {
        users.value = [...users.value, ...response.data];
      }

      hasMore.value = response.current_page < response.last_page;
    } catch (error) {
      console.error('Error loading users:', error);
    } finally {
      loading.value = false;
    }
  };

  const handleSearch = () => {
    loadUsers(true);
  };

  const loadMore = (event: any) => {
    page.value++;
    loadUsers().then(() => {
      event.target.complete();
    });
  };

  const getInitials = (name: string) => {
    return name
      .split(' ')
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);
  };

  const toggleAdmin = async (user: User) => {
    try {
      await api.post(`/admin/users/${user.id}/toggle`);
      user.is_admin = !user.is_admin;
    } catch (error) {
      console.error('Error toggling admin:', error);
    }
  };

  const confirmDelete = (user: User) => {
    userToDelete.value = user;
    showDeleteAlert.value = true;
  };

  const deleteUser = async () => {
    if (!userToDelete.value) return;

    try {
      await api.delete(`/admin/users/${userToDelete.value.id}`);
      users.value = users.value.filter((u) => u.id !== userToDelete.value!.id);
    } catch (error) {
      console.error('Error deleting user:', error);
    } finally {
      showDeleteAlert.value = false;
      userToDelete.value = null;
    }
  };

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

  onMounted(() => {
    loadUsers();
  });
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
  box-shadow: var(--card-shadow-light) 0px 1px 3px 0px, var(--card-shadow-heavy) 0px 0px 0px 1px;
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
</style>