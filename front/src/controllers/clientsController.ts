// SPDX-License-Identifier: MIT
import { computed } from 'vue';
import { useClientsStore } from '@/stores/clients';
import type { SsoClient } from '@/types';

export function useClientsController() {
  const clientsStore = useClientsStore();

  const clients = computed(() => clientsStore.clients);
  const selectedClient = computed(() => clientsStore.selectedClient);
  const loading = computed(() => clientsStore.loading);
  const count = computed(() => clientsStore.count);
  const activeClients = computed(() => clientsStore.activeClients);

  async function fetchClients(): Promise<SsoClient[]> {
    return clientsStore.fetchClients();
  }

  async function fetchClient(id: number): Promise<SsoClient> {
    return clientsStore.fetchClient(id);
  }

  async function createClient(params: {
    data: {
      name: string;
      client_id: string;
      client_secret?: string;
      redirect_url?: string;
      allowed_origins?: string[];
      active?: boolean;
    };
  }): Promise<SsoClient> {
    return clientsStore.createClient(params.data);
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
  }): Promise<SsoClient> {
    return clientsStore.updateClient(params);
  }

  async function deleteClient(params: { id: number }): Promise<void> {
    return clientsStore.deleteClient(params);
  }

  function reset(): void {
    clientsStore.reset();
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
}
