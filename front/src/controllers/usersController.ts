// SPDX-License-Identifier: MIT
import { computed } from 'vue';
import { useUsersStore } from '@/stores/users';
import type { UserDetail, UserRole, RoleAssignment } from '@/types';

export function useUsersController() {
  const usersStore = useUsersStore();

  const users = computed(() => usersStore.users);
  const selectedUser = computed(() => usersStore.selectedUser);
  const userRoles = computed(() => usersStore.userRoles);
  const roleAssignments = computed(() => usersStore.roleAssignments);
  const loading = computed(() => usersStore.loading);
  const search = computed(() => usersStore.search);
  const pagination = computed(() => usersStore.pagination);
  const hasMore = computed(() => usersStore.hasMore);

  async function fetchUsers(params?: {
    page?: number;
    per_page?: number;
    search?: string;
    sso_client_id?: string | number;
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

  async function toggleAdmin(params: { id: number; action: string }): Promise<{ is_admin: boolean }> {
    return usersStore.toggleAdmin(params);
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

  function setRoleAssignments(value: RoleAssignment[]): void {
    usersStore.roleAssignments = value;
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
    setRoleAssignments,
    setSearch,
    reset,
    getInitials,
  };
}
