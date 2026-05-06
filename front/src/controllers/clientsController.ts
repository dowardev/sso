import { computed } from 'vue';
import { useClientsStore } from '@/stores/clients';
import type { SsoClient } from '@/types';

export function useClientsController() {
  const clientsStore = useClientsStore();

  const clients = computed(() => clientsStore.clients);
  const loading = computed(() => clientsStore.loading);
  const count = computed(() => clientsStore.count);
  const activeClients = computed(() => clientsStore.activeClients);

  async function fetchClients(): Promise<SsoClient[]> {
    return clientsStore.fetchClients();
  }

  async function createClient(params: { data: { name: string; client_id: string; client_secret: string; redirect_url?: string; active?: boolean } }): Promise<SsoClient> {
    return clientsStore.createClient(params.data);
  }

  async function updateClient(params: { id: number; data: { name: string; client_id: string; client_secret?: string; redirect_url?: string; active?: boolean } }): Promise<SsoClient> {
    return clientsStore.updateClient(params);
  }

  async function deleteClient(id: number): Promise<void> {
    return clientsStore.deleteClient(id);
  }

  function reset(): void {
    clientsStore.reset();
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
}