import { computed } from 'vue';
import { useRolesStore } from '@/stores/roles';
import type { Role } from '@/types';

export function useRolesController() {
  const rolesStore = useRolesStore();

  const roles = computed(() => rolesStore.roles);
  const loading = computed(() => rolesStore.loading);

  async function fetchRoles(): Promise<Role[]> {
    return rolesStore.fetchRoles();
  }

  async function createRole(params: { data: { name: string; slug: string; description?: string } }): Promise<Role> {
    return rolesStore.createRole(params.data);
  }

  async function updateRole(params: { id: number; data: { name: string; description?: string } }): Promise<Role> {
    return rolesStore.updateRole(params);
  }

  async function deleteRole(id: number): Promise<void> {
    return rolesStore.deleteRole(id);
  }

  function reset(): void {
    rolesStore.reset();
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
}