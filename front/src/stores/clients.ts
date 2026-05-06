import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { api } from '@/services/api';
import type { SsoClient } from '@/types';

export const useClientsStore = defineStore('clients', () => {
  const clients = ref<SsoClient[]>([]);
  const loading = ref(false);

  const count = computed(() => clients.value.length);

  const activeClients = computed(() => clients.value.filter((c) => c.active));

  async function fetchClients() {
    loading.value = true;
    try {
      const response = await api.get<SsoClient[]>('/admin/clients');
      clients.value = response;
      return response;
    } finally {
      loading.value = false;
    }
  }

  async function createClient(data: {
    name: string;
    client_id: string;
    client_secret: string;
    redirect_url?: string;
    active?: boolean;
  }) {
    loading.value = true;
    try {
      const response = await api.post<SsoClient>('/admin/clients', data);
      clients.value = [...clients.value, response];
      return response;
    } finally {
      loading.value = false;
    }
  }

  async function updateClient(
    id: number,
    data: {
      name: string;
      client_id: string;
      client_secret?: string;
      redirect_url?: string;
      active?: boolean;
    }
  ) {
    loading.value = true;
    try {
      const response = await api.put<SsoClient>(`/admin/clients/${id}`, data);
      const index = clients.value.findIndex((c) => c.id === id);
      if (index !== -1) {
        clients.value[index] = response;
      }
      return response;
    } finally {
      loading.value = false;
    }
  }

  async function deleteClient(id: number) {
    loading.value = true;
    try {
      await api.delete(`/admin/clients/${id}`);
      clients.value = clients.value.filter((c) => c.id !== id);
    } finally {
      loading.value = false;
    }
  }

  function reset() {
    clients.value = [];
  }

  return {
    clients,
    loading,
    count,
    activeClients,
    fetchClients,
    createClient,
    updateClient,
    deleteClient,
    reset,
  };
});