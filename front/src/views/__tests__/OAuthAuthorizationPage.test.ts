// SPDX-License-Identifier: MIT
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { ref, nextTick } from 'vue';
import { mount } from '@vue/test-utils';
import { IonicVue } from '@ionic/vue';
import OAuthAuthorizationPage from '@/views/OAuthAuthorizationPage.vue';

const replace = vi.fn();
const route = {
  query: {
    client_id: 'cliente-1',
    redirect_uri: 'http://localhost:9999/callback',
    client_name: 'Mi Aplicación',
  } as Record<string, string>,
};

const loadBranding = vi.fn();
const logoUrl = ref<string | null>(null);

vi.mock('vue-router', () => ({
  useRouter: () => ({ replace, push: vi.fn() }),
  useRoute: () => route,
}));

vi.mock('@/controllers/authController', () => ({
  useAuthController: () => ({
    approveOAuthAuthorization: vi.fn(),
    denyOAuthAuthorization: vi.fn(),
  }),
}));

vi.mock('@/composables/useBranding', () => ({
  useBranding: () => ({
    logoUrl,
    loadBranding,
    uploadLogo: vi.fn(),
    removeLogo: vi.fn(),
  }),
}));

describe('OAuthAuthorizationPage', () => {
  beforeEach(() => {
    replace.mockClear();
    loadBranding.mockClear();
    logoUrl.value = null;
  });

  const mountPage = () =>
    mount(OAuthAuthorizationPage, { global: { plugins: [IonicVue] } });

  it('muestra el nombre de la aplicación en el subtítulo de la AuthCard', async () => {
    const wrapper = mountPage();
    await nextTick();

    expect(wrapper.find('.auth-card-header p').text()).toContain('Mi Aplicación');
  });

  it('muestra el logo de branding configurado en el header de la AuthCard', () => {
    logoUrl.value = 'http://localhost:8001/storage/branding/auth-logo.png';

    const wrapper = mountPage();

    expect(wrapper.find('img.auth-logo').attributes('src')).toBe(
      'http://localhost:8001/storage/branding/auth-logo.png'
    );
  });

  it('carga el branding una sola vez, desde la AuthCard', () => {
    mountPage();

    expect(loadBranding).toHaveBeenCalledTimes(1);
  });
});
