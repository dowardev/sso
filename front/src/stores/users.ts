import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { api } from '@/services/api';
import type {
  User,
  UserDetail,
  PaginatedResponse,
  UserRole,
  RoleAssignment,
} from '@/types';

export const useUsersStore = defineStore('users', () => {
  const users = ref<User[]>([]);
  const selectedUser = ref<UserDetail | null>(null);
  const userRoles = ref<UserRole[]>([]);
  const roleAssignments = ref<RoleAssignment[]>([]);
  const loading = ref(false);
  const search = ref('');

  const pagination = ref({
    currentPage: 1,
    lastPage: 1,
    perPage: 15,
    total: 0,
  });

  const hasMore = computed(() => pagination.value.currentPage < pagination.value.lastPage);

  async function fetchUsers(params?: { page?: number; per_page?: number; search?: string }) {
    loading.value = true;
    try {
      const queryParams = new URLSearchParams();
      if (params?.page) queryParams.set('page', String(params.page));
      if (params?.per_page) queryParams.set('per_page', String(params.per_page));
      if (params?.search) queryParams.set('search', params.search);

      const response = await api.get<PaginatedResponse<User>>(
        `/admin/users?${queryParams.toString()}`
      );

      if (params?.page && params.page > 1) {
        users.value = [...users.value, ...response.data];
      } else {
        users.value = response.data;
      }

      pagination.value = {
        currentPage: response.current_page,
        lastPage: response.last_page,
        perPage: response.per_page,
        total: response.total,
      };
    } finally {
      loading.value = false;
    }
  }

  async function fetchUser(id: number) {
    loading.value = true;
    try {
      const response = await api.get<UserDetail>(`/admin/users/${id}`);
      selectedUser.value = response;
      return response;
    } finally {
      loading.value = false;
    }
  }

  async function updateUser(id: number, data: { name: string; user: string; email: string; password?: string }) {
    loading.value = true;
    try {
      const response = await api.put<UserDetail>(`/admin/users/${id}`, data);
      selectedUser.value = response;
      const index = users.value.findIndex((u) => u.id === id);
      if (index !== -1) {
        users.value[index] = response;
      }
      return response;
    } finally {
      loading.value = false;
    }
  }

  async function toggleAdmin(id: number) {
    loading.value = true;
    try {
      const response = await api.post<{ is_admin: boolean }>(`/admin/users/${id}/toggle`);
      const index = users.value.findIndex((u) => u.id === id);
      if (index !== -1) {
        users.value[index].is_admin = response.is_admin;
      }
      if (selectedUser.value?.id === id) {
        selectedUser.value.is_admin = response.is_admin;
      }
      return response;
    } finally {
      loading.value = false;
    }
  }

  async function deleteUser(id: number) {
    loading.value = true;
    try {
      await api.delete(`/admin/users/${id}`);
      users.value = users.value.filter((u) => u.id !== id);
      if (selectedUser.value?.id === id) {
        selectedUser.value = null;
      }
    } finally {
      loading.value = false;
    }
  }

  async function fetchUserRoles(id: number) {
    loading.value = true;
    try {
      const response = await api.get<UserRole[]>(`/admin/users/${id}/roles`);
      userRoles.value = response;
      roleAssignments.value = response.map((r) => ({
        role_id: r.id,
        sso_client_id: r.sso_client_id,
        name: r.name,
        slug: r.slug,
      }));
      return response;
    } finally {
      loading.value = false;
    }
  }

  async function saveUserRoles(id: number, assignments: { role_id: number; sso_client_id: number | null }[]) {
    loading.value = true;
    try {
      const response = await api.put<UserRole[]>(`/admin/users/${id}/roles`, { roles: assignments });
      userRoles.value = response;
      roleAssignments.value = response.map((r) => ({
        role_id: r.id,
        sso_client_id: r.sso_client_id,
        name: r.name,
        slug: r.slug,
      }));
      return response;
    } finally {
      loading.value = false;
    }
  }

  async function revokeUserSessions(id: number) {
    loading.value = true;
    try {
      await api.delete(`/admin/users/${id}/sessions`);
      if (selectedUser.value) {
        selectedUser.value.sessions = [];
        selectedUser.value.active_tokens_count = 0;
      }
    } finally {
      loading.value = false;
    }
  }

  function setSearch(value: string) {
    search.value = value;
  }

  function reset() {
    users.value = [];
    selectedUser.value = null;
    userRoles.value = [];
    roleAssignments.value = [];
    pagination.value = {
      currentPage: 1,
      lastPage: 1,
      perPage: 15,
      total: 0,
    };
    search.value = '';
  }

  return {
    users,
    selectedUser,
    userRoles,
    roleAssignments,
    loading,
    search,
    pagination,
    hasMore,
    fetchUsers,
    fetchUser,
    updateUser,
    toggleAdmin,
    deleteUser,
    fetchUserRoles,
    saveUserRoles,
    revokeUserSessions,
    setSearch,
    reset,
  };
});