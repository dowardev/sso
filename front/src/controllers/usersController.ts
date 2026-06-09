import { computed } from 'vue';
import { useUsersStore } from '@/stores/users';
import type { RoleAssignment, UserDetail, UserRole } from '@/types';

export function useUsersController() {
  const usersStore = useUsersStore();

  const users = computed(() => usersStore.users);
  const selectedUser = computed(() => usersStore.selectedUser);
  const userRoles = computed(() => usersStore.userRoles);
  const roleAssignments = computed({
    get: () => usersStore.roleAssignments,
    set: (value: RoleAssignment[]) => {
      usersStore.roleAssignments = value;
    },
  });
  const loading = computed(() => usersStore.loading);
  const search = computed(() => usersStore.search);
  const pagination = computed(() => usersStore.pagination);
  const hasMore = computed(() => usersStore.hasMore);

  async function fetchUsers(params?: {
    page?: number;
    per_page?: number;
    search?: string;
  }): Promise<void> {
    return usersStore.fetchUsers(params);
  }

  async function fetchUser(id: number): Promise<UserDetail> {
    return usersStore.fetchUser(id);
  }

  async function updateUser(params: {
    id: number;
    data: { name: string; user: string; email: string; password?: string };
  }): Promise<UserDetail> {
    return usersStore.updateUser(params);
  }

  async function toggleAdmin(id: number): Promise<{ is_admin: boolean }> {
    return usersStore.toggleAdmin(id);
  }

  async function deleteUser(id: number): Promise<void> {
    return usersStore.deleteUser(id);
  }

  async function fetchUserRoles(id: number): Promise<UserRole[]> {
    return usersStore.fetchUserRoles(id);
  }

  async function saveUserRoles(params: {
    id: number;
    assignments: { role_id: number }[];
  }): Promise<UserRole[]> {
    return usersStore.saveUserRoles(params);
  }

  async function revokeUserSessions(id: number): Promise<void> {
    return usersStore.revokeUserSessions(id);
  }

  function setSearch(value: string): void {
    usersStore.setSearch(value);
  }

  function reset(): void {
    usersStore.reset();
  }

  function getInitials(params: { name: string }): string {
    const { name } = params;
    return name
      .split(' ')
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);
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
    getInitials,
  };
}
