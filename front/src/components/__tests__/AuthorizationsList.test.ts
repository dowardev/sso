// SPDX-License-Identifier: MIT
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { IonicVue } from '@ionic/vue';

const getUserAuthorizations = vi.fn();

vi.mock('@/controllers/authController', () => ({
  useAuthController: () => ({ getUserAuthorizations }),
}));

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ showError: vi.fn(), showSuccess: vi.fn() }),
}));

import UserAuthorizationsList from '@/components/UserAuthorizationsList.vue';
import UserSideAuthorizationsList from '@/components/user/AuthorizationsList.vue';

const authorization = {
  id: 1,
  status: 'authorized',
  updated_at: '2026-01-01T10:00:00Z',
  client: { id: 7, name: 'Cliente Test', client_id: 'client-1', redirect_url: null },
};

describe('authorization rows render the status badge once', () => {
  beforeEach(() => {
    getUserAuthorizations.mockReset();
    getUserAuthorizations.mockResolvedValue({ data: [authorization] });
  });

  it('admin list shows a single status badge per row', async () => {
    const wrapper = mount(UserAuthorizationsList, {
      props: { userId: 1 },
      global: { plugins: [IonicVue] },
    });

    await flushPromises();

    const items = wrapper.findAll('ion-item');
    expect(items).toHaveLength(1);
    expect(items[0].findAll('ion-badge')).toHaveLength(1);
    expect(items[0].find('ion-badge').text()).toBe('Autorizado');
  });

  it('user list shows a single status badge per row', async () => {
    const wrapper = mount(UserSideAuthorizationsList, {
      props: { authorizations: [authorization] as never },
      global: { plugins: [IonicVue] },
    });

    await flushPromises();

    const items = wrapper.findAll('ion-item');
    expect(items).toHaveLength(1);
    expect(items[0].findAll('ion-badge')).toHaveLength(1);
    expect(items[0].find('ion-badge').text()).toBe('Autorizado');
  });
});

describe('the admin authorizations section uses native Ionic structure', () => {
  beforeEach(() => {
    getUserAuthorizations.mockReset();
    getUserAuthorizations.mockResolvedValue({ data: [authorization] });
  });

  it('wraps the section in an ion-card with an ion-list-header title', async () => {
    const wrapper = mount(UserAuthorizationsList, {
      props: { userId: 1 },
      global: { plugins: [IonicVue] },
    });

    await flushPromises();

    expect(wrapper.find('ion-card').exists()).toBe(true);
    const header = wrapper.find('ion-list-header');
    expect(header.exists()).toBe(true);
    expect(header.text()).toContain('Aplicaciones Autorizadas');
    expect(wrapper.find('div.section-header').exists()).toBe(false);
  });

  it('keeps a single element in the end slot of every row', async () => {
    const wrapper = mount(UserAuthorizationsList, {
      props: { userId: 1 },
      global: { plugins: [IonicVue] },
    });

    await flushPromises();

    const items = wrapper.findAll('ion-item');
    expect(items).toHaveLength(1);
    expect(items[0].findAll('[slot="end"]')).toHaveLength(1);
  });
});
