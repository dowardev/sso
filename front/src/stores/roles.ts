import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '@/services/api';
import type { Role } from '@/types';

export const useRolesStore = defineStore('roles', () => {
  const roles = ref<Role[]>([]);
  const loading = ref(false);

  async function fetchRoles() {
    loading.value = true;
    try {
      const response = await api.get<Role[]>({ endpoint: '/admin/roles' });
      roles.value = response;
      return response;
    } finally {
      loading.value = false;
    }
  }

  async function createRole(data: {
    name: string;
    slug: string;
    description?: string;
  }) {
    loading.value = true;
    try {
      const response = await api.post<Role>({ endpoint: '/admin/roles', data });
      roles.value = [...roles.value, response];
      return response;
    } finally {
      loading.value = false;
    }
  }

  async function updateRole(params: {
    id: number;
    data: { name: string; description?: string };
  }) {
    const { id, data } = params;
    loading.value = true;
    try {
      const response = await api.put<Role>({
        endpoint: `/admin/roles/${id}`,
        data,
      });
      const index = roles.value.findIndex((r) => r.id === id);
      if (index !== -1) {
        roles.value[index] = response;
      }
      return response;
    } finally {
      loading.value = false;
    }
  }

  async function deleteRole(id: number) {
    loading.value = true;
    try {
      await api.delete({ endpoint: `/admin/roles/${id}` });
      roles.value = roles.value.filter((r) => r.id !== id);
    } finally {
      loading.value = false;
    }
  }

  function reset() {
    roles.value = [];
  }

  return {
    roles,
    loading,
    fetchRoles,
    createRole,
    updateRole,
    deleteRole,
    reset,
  };
});
