// SPDX-License-Identifier: MIT
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
  const pending = ref(0);
  const loading = computed(() => pending.value > 0);
  const search = ref('');

  const pagination = ref({
    currentPage: 1,
    lastPage: 1,
    perPage: 15,
    total: 0,
  });

  const hasMore = computed(
    () => pagination.value.currentPage < pagination.value.lastPage
  );

  async function fetchUsers(params?: {
    page?: number;
    per_page?: number;
    search?: string;
    sso_client_id?: string | number;
  }) {
    pending.value++;
    try {
      const queryParams = new URLSearchParams();
      if (params?.page) queryParams.set('page', String(params.page));
      if (params?.per_page)
        queryParams.set('per_page', String(params.per_page));
      if (params?.search) queryParams.set('search', params.search);
      if (params?.sso_client_id) queryParams.set('sso_client_id', String(params.sso_client_id));

      const response = await api.get<PaginatedResponse<User>>({
        endpoint: `/admin/users?${queryParams.toString()}`,
      });

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
    } catch (error) {
      console.error('[fetchUsers] Error:', error);
      users.value = [];
      throw error;
    } finally {
      pending.value--;
    }
  }

  async function fetchUser(id: number) {
    pending.value++;
    try {
      const response = await api.get<UserDetail>({
        endpoint: `/admin/users/${id}`,
      });
      selectedUser.value = response;
      return response;
    } finally {
      pending.value--;
    }
  }

  async function updateUser(params: {
    id: number;
    data: { name: string; user: string; email: string; password?: string };
  }) {
    const { id, data } = params;
    pending.value++;
    try {
      const response = await api.put<UserDetail>({
        endpoint: `/admin/users/${id}`,
        data,
      });
      selectedUser.value = response;
      const index = users.value.findIndex((u) => u.id === id);
      if (index !== -1) {
        users.value[index] = response;
      }
      return response;
    } finally {
      pending.value--;
    }
  }

  async function toggleAdmin(params: { id: number; action: string }) {
    const { id, action } = params;
    pending.value++;
    try {
      const response = await api.post<{ is_admin: boolean }>({
        endpoint: `/admin/users/${id}/toggle`,
        data: { action },
      });
      const index = users.value.findIndex((u) => u.id === id);
      if (index !== -1) {
        users.value[index].is_admin = response.is_admin;
      }
      if (selectedUser.value?.id === id) {
        selectedUser.value.is_admin = response.is_admin;
      }
      return response;
    } finally {
      pending.value--;
    }
  }

  async function deleteUser(id: number) {
    pending.value++;
    try {
      await api.delete({ endpoint: `/admin/users/${id}` });
      users.value = users.value.filter((u) => u.id !== id);
      if (selectedUser.value?.id === id) {
        selectedUser.value = null;
      }
    } finally {
      pending.value--;
    }
  }

  async function fetchUserRoles(id: number) {
    pending.value++;
    try {
      const response = await api.get<UserRole[]>({
        endpoint: `/admin/users/${id}/roles`,
      });
      userRoles.value = response;
      roleAssignments.value = response.map((r) => ({
        role_id: r.id,
        name: r.name,
        slug: r.slug,
      }));
      return response;
    } finally {
      pending.value--;
    }
  }

  async function saveUserRoles(params: {
    id: number;
    assignments: { role_id: number }[];
  }) {
    const { id, assignments } = params;
    pending.value++;
    try {
      const response = await api.put<UserRole[]>({
        endpoint: `/admin/users/${id}/roles`,
        data: { roles: assignments },
      });
      userRoles.value = response;
      roleAssignments.value = response.map((r) => ({
        role_id: r.id,
        name: r.name,
        slug: r.slug,
      }));
      return response;
    } finally {
      pending.value--;
    }
  }

  async function revokeUserSessions(id: number) {
    pending.value++;
    try {
      await api.delete({ endpoint: `/admin/users/${id}/sessions` });
      if (selectedUser.value) {
        selectedUser.value.sessions = [];
        selectedUser.value.active_tokens_count = 0;
      }
    } finally {
      pending.value--;
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
