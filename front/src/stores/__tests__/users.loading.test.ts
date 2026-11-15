// SPDX-License-Identifier: MIT
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import type { Mock } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { flushPromises } from '@vue/test-utils';

// Mock the API before importing the store
vi.mock('@/services/api', () => {
  const api = {
    get: vi.fn(),
    put: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
  };
  return { api };
});

import { api } from '@/services/api';
import { useUsersStore } from '@/stores/users';

describe('users store loading flag (counter-based)', () => {
  let store: ReturnType<typeof useUsersStore>;

  beforeEach(() => {
    setActivePinia(createPinia());
    store = useUsersStore();
    vi.clearAllMocks();
  });

  afterEach(() => {
    vi.resetAllMocks();
  });

  it('stays loading while overlapping requests are in flight', async () => {
    // Create two deferred promises we can resolve independently
    let resolveUser: (value: unknown) => void;
    let resolveRoles: (value: unknown) => void;

    const userPromise = new Promise((resolve) => {
      resolveUser = resolve;
    });
    const rolesPromise = new Promise((resolve) => {
      resolveRoles = resolve;
    });

    // Mock API to return our deferred promises
    (api.get as Mock)
      .mockImplementationOnce(() => userPromise)   // fetchUser
      .mockImplementationOnce(() => rolesPromise); // fetchUserRoles

    // Start both requests concurrently (fire-and-forget, like Promise.all in the view)
    const userP = store.fetchUser(1);
    const rolesP = store.fetchUserRoles(1);

    // Microtask: both requests have started, pending should be 2
    await flushPromises();
    expect(store.loading).toBe(true);

    // Resolve only the first request
    resolveUser!({ id: 1, name: 'Test', user: 'test', email: 'test@test.com' });
    await flushPromises();

    // Loading should still be true because the second request is in flight
    expect(store.loading).toBe(true);

    // Resolve the second request
    resolveRoles!([{ id: 1, name: 'Admin', slug: 'admin' }]);
    await flushPromises();

    // Now both are done, loading should be false
    expect(store.loading).toBe(false);

    // Ensure the promises themselves resolve
    await expect(userP).resolves.toBeDefined();
    await expect(rolesP).resolves.toBeDefined();
  });

  it('sets loading to false after a rejected request', async () => {
    let rejectUser: (reason?: Error) => void;
    const userPromise = new Promise((_resolve, reject) => {
      rejectUser = reject;
    });

    (api.get as Mock).mockImplementationOnce(() => userPromise);

    const userP = store.fetchUser(1);

    await flushPromises();
    expect(store.loading).toBe(true);

    // Reject the request
    rejectUser!(new Error('Network error'));
    try {
      await userP;
    } catch {
      // Expected to reject
    }
    await flushPromises();

    // Loading should be false after rejection
    expect(store.loading).toBe(false);
  });

  it('sets loading to false after multiple requests where one rejects', async () => {
    let resolveRoles: (value: unknown) => void;
    let rejectUser: (reason?: Error) => void;
    const rolesPromise = new Promise((resolve) => {
      resolveRoles = resolve;
    });
    const userPromise = new Promise((_resolve, reject) => {
      rejectUser = reject;
    });

    // First request rejects on demand, second resolves later
    (api.get as Mock)
      .mockImplementationOnce(() => userPromise)
      .mockImplementationOnce(() => rolesPromise);

    const userP = store.fetchUser(1);
    const rolesP = store.fetchUserRoles(1);

    await flushPromises();
    expect(store.loading).toBe(true);

    // Reject the first request
    rejectUser!(new Error('User not found'));
    await userP.catch(() => {});
    await flushPromises();

    // Loading should still be true because roles request is in flight
    expect(store.loading).toBe(true);

    // Resolve the second
    resolveRoles!([{ id: 2, name: 'User', slug: 'user' }]);
    await flushPromises();

    // Now both done, loading false
    expect(store.loading).toBe(false);

    await expect(rolesP).resolves.toBeDefined();
  });

  it('returns to zero exactly once per action even when action throws', async () => {
    // This test verifies the counter doesn't go negative or stay positive
    // after an error, by running several actions sequentially.

    (api.get as Mock)
      .mockImplementationOnce(() => Promise.reject(new Error('Error 1')))
      .mockImplementationOnce(() => Promise.resolve({ id: 1, name: 'Test', user: 'test', email: 'test@test.com' }))
      .mockImplementationOnce(() => Promise.reject(new Error('Error 2')))
      .mockImplementationOnce(() => Promise.resolve([{ id: 1, name: 'Role', slug: 'role' }]));

    // First action: fetchUser rejects
    await store.fetchUser(1).catch(() => {});
    await flushPromises();
    expect(store.loading).toBe(false);

    // Second action: fetchUser succeeds
    await store.fetchUser(1);
    await flushPromises();
    expect(store.loading).toBe(false);

    // Third action: fetchUserRoles rejects
    await store.fetchUserRoles(1).catch(() => {});
    await flushPromises();
    expect(store.loading).toBe(false);

    // Fourth action: fetchUserRoles succeeds
    await store.fetchUserRoles(1);
    await flushPromises();
    expect(store.loading).toBe(false);
  });
});