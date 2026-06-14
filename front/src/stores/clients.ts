import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { api } from '@/services/api';
import type { SsoClient } from '@/types';

export const useClientsStore = defineStore('clients', () => {
  const clients = ref<SsoClient[]>([]);
  const selectedClient = ref<SsoClient | null>(null);
  const loading = ref(false);

  const count = computed(() => clients.value.length);

  const activeClients = computed(() => clients.value.filter((c) => c.active));

  async function fetchClients() {
    loading.value = true;
    try {
      const response = await api.get<SsoClient[]>({
        endpoint: '/admin/clients',
      });
      clients.value = response;
      return response;
    } finally {
      loading.value = false;
    }
  }

  async function fetchClient(id: number) {
    loading.value = true;
    try {
      const response = await api.get<SsoClient>({
        endpoint: `/admin/clients/${id}`,
      });
      selectedClient.value = response;
      return response;
    } finally {
      loading.value = false;
    }
  }

  async function createClient(data: {
    name: string;
    client_id: string;
    client_secret?: string;
    redirect_url?: string;
    allowed_origins?: string[];
    active?: boolean;
  }) {
    loading.value = true;
    try {
      const response = await api.post<{ message: string; client: SsoClient }>({
        endpoint: '/admin/clients',
        data,
      });
      clients.value = [...clients.value, response.client];
      return response.client;
    } finally {
      loading.value = false;
    }
  }

  async function updateClient(params: {
    id: number;
    data: {
      name: string;
      client_id: string;
      client_secret?: string;
      redirect_url?: string;
      allowed_origins?: string[];
      active?: boolean;
    };
  }) {
    const { id, data } = params;
    loading.value = true;
    try {
      const response = await api.put<{ message: string; client: SsoClient }>({
        endpoint: `/admin/clients/${id}`,
        data,
      });
      const index = clients.value.findIndex((c) => c.id === id);
      if (index !== -1) {
        clients.value[index] = response.client;
      }
      return response.client;
    } finally {
      loading.value = false;
    }
  }

  async function deleteClient(params: { id: number }) {
    const { id } = params;
    loading.value = true;
    try {
      await api.delete({ endpoint: `/admin/clients/${id}` });
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
    selectedClient,
    loading,
    count,
    activeClients,
    fetchClients,
    fetchClient,
    createClient,
    updateClient,
    deleteClient,
    reset,
  };
});
