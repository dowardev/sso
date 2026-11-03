// SPDX-License-Identifier: MIT
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { IonicVue } from '@ionic/vue';
import VerifyEmailPage from '@/views/VerifyEmailPage.vue';
import VerifyEmailPendingPage from '@/views/VerifyEmailPendingPage.vue';

const replace = vi.fn();
const route = { params: { token: 'token-de-prueba' }, query: {} as Record<string, string> };

vi.mock('vue-router', () => ({
  useRouter: () => ({ replace, push: vi.fn() }),
  useRoute: () => route,
}));

vi.mock('@/services/api', () => ({
  api: { get: vi.fn().mockResolvedValue({}), post: vi.fn().mockResolvedValue({}) },
}));

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ showSuccess: vi.fn(), showError: vi.fn() }),
}));

vi.mock('@/services/emailVerifyOrigin', () => ({
  loadEmailVerifyOrigin: () => 'admin',
  clearEmailVerifyOrigin: vi.fn(),
}));

describe('Páginas de verificación de email', () => {
  beforeEach(() => {
    replace.mockClear();
  });

  const mountPage = (component: unknown) =>
    mount(component as never, { global: { plugins: [IonicVue] } });

  it('monta la AuthCard directamente dentro del ion-content (sin ion-grid) en VerifyEmailPage', () => {
    const wrapper = mountPage(VerifyEmailPage);

    expect(wrapper.find('ion-card.auth-screen').exists()).toBe(true);
    expect(wrapper.find('ion-grid').exists()).toBe(false);
    expect(wrapper.find('ion-col').exists()).toBe(false);
  });

  it('monta la AuthCard directamente dentro del ion-content (sin ion-grid) en VerifyEmailPendingPage', () => {
    const wrapper = mountPage(VerifyEmailPendingPage);

    expect(wrapper.find('ion-card.auth-screen').exists()).toBe(true);
    expect(wrapper.find('ion-grid').exists()).toBe(false);
    expect(wrapper.find('ion-col').exists()).toBe(false);
  });
});
