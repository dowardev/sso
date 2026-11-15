// SPDX-License-Identifier: MIT
/**
 * Regression guard for the roles card of the admin user detail view.
 *
 * The card renders `userRoles`, which used to be populated only when the
 * "Asignar Roles" modal was opened. The card therefore always showed the
 * empty state ("Sin roles asignados") even for users that had roles.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { IonicVue } from '@ionic/vue';
import { createPinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import { ref } from 'vue';

const fetchUser = vi.fn();
const fetchUserRoles = vi.fn();

// Mirrors the store semantics: `userRoles` starts empty and is only filled by
// `fetchUserRoles`. A mock that always returns roles would hide the bug.
const userRoles = ref<unknown[]>([]);
const selectedUser = ref<unknown>(null);

vi.mock('@/controllers/usersController', () => ({
  useUsersController: () => ({
    fetchUser,
    fetchUserRoles,
    userRoles,
    roleAssignments: { value: [] },
    setRoleAssignments: vi.fn(),
    selectedUser,
    loading: { value: false },
    user: { value: null },
  }),
}));

vi.mock('@/controllers/rolesController', () => ({
  useRolesController: () => ({ roles: { value: [] }, fetchRoles: vi.fn() }),
}));

vi.mock('@/controllers/clientsController', () => ({
  useClientsController: () => ({ clients: { value: [] }, fetchClients: vi.fn() }),
}));

vi.mock('@/controllers/sessionsController', () => ({
  useSessionsController: () => ({
    sessions: { value: [] },
    loading: { value: false },
    fetchSessions: vi.fn(),
  }),
}));

vi.mock('@/controllers/authController', () => ({
  useAuthController: () => ({ user: { value: { id: 1, is_admin: true } } }),
}));

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ showError: vi.fn(), showSuccess: vi.fn() }),
}));

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { value: { id: 1, is_admin: true } }, profile: null }),
}));

import UserDetailPage from '@/views/admin/UserDetailPage.vue';

describe('UserDetailPage roles card', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    userRoles.value = [];
    selectedUser.value = null;
    fetchUser.mockImplementation(async () => {
      const user = {
        id: 1,
        name: 'Gonzalo',
        user: 'dowar',
        email: 'dowar@protonmail.com',
        created_at: '2026-01-01T00:00:00Z',
        active_tokens_count: 0,
        encryption_level: 'standard',
      };
      selectedUser.value = user;

      return user;
    });
    fetchUserRoles.mockImplementation(async () => {
      const roles = [{ id: 2, name: 'Administrador', slug: 'admin' }];
      userRoles.value = roles;

      return roles;
    });
  });

  const mountPage = async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/admin/users/:id', component: UserDetailPage }],
    });

    await router.push('/admin/users/1');
    await router.isReady();

    return mount(UserDetailPage, {
      global: {
        plugins: [IonicVue, createPinia(), router],
        stubs: {
          IonBackButton: true,
          IonModal: { template: '<div><slot /></div>' },
          UserMenu: true,
        },
      },
    });
  };

  it('loads the assigned roles together with the user', async () => {
    await mountPage();
    await flushPromises();

    expect(fetchUser).toHaveBeenCalledWith(1);
    expect(fetchUserRoles).toHaveBeenCalledWith(1);
  });

  it('does not render the empty state when the user has roles', async () => {
    const wrapper = await mountPage();
    await flushPromises();

    expect(wrapper.text()).not.toContain('Sin roles asignados');
  });
});
