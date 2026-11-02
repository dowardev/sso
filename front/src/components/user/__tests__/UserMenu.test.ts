// SPDX-License-Identifier: MIT
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { ref } from 'vue';
import { mount } from '@vue/test-utils';
import { IonicVue } from '@ionic/vue';
import UserMenu from '@/components/user/UserMenu.vue';

const push = vi.fn();
const replace = vi.fn();
const logout = vi.fn();

const routePath = ref('/home');
const user = ref<{ name: string } | null>({ name: 'Ada Lovelace' });

vi.mock('vue-router', () => ({
  useRouter: () => ({ push, replace }),
  useRoute: () => ({ path: routePath.value }),
}));

vi.mock('@/controllers/authController', () => ({
  useAuthController: () => ({
    user,
    logout,
  }),
}));

const flush = async (): Promise<void> => {
  await new Promise((resolve) => setTimeout(resolve, 50));
};

describe('UserMenu', () => {
  let wrapper: ReturnType<typeof mount> | null = null;

  beforeEach(() => {
    push.mockClear();
    replace.mockClear();
    logout.mockClear();
    routePath.value = '/home';
    user.value = { name: 'Ada Lovelace' };
  });

  afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
  });

  const mountMenu = () => {
    wrapper = mount(UserMenu, {
      global: { plugins: [IonicVue] },
      attachTo: document.body,
    });
    return wrapper;
  };

  const openMenu = async () => {
    const menu = mountMenu();
    await menu.find('ion-button').trigger('click');
    await flush();
    return menu;
  };

  const menuItems = (): string[] =>
    Array.from(document.body.querySelectorAll('ion-item')).map((item) => item.textContent?.trim() ?? '');

  it('muestra las entradas de cuenta, seguridad y logout', async () => {
    await openMenu();

    expect(menuItems()).toEqual(['Mi Cuenta', 'Seguridad', 'Cerrar Sesión']);
  });

  it('navega a /account al elegir "Mi Cuenta"', async () => {
    await openMenu();

    const accountItem = Array.from(document.body.querySelectorAll('ion-item')).find(
      (item) => item.textContent?.trim() === 'Mi Cuenta'
    );
    accountItem?.dispatchEvent(new CustomEvent('click'));

    await flush();

    expect(push).toHaveBeenCalledWith('/account');
  });

  it('navega a la pestaña de seguridad al elegir "Seguridad"', async () => {
    await openMenu();

    const securityItem = Array.from(document.body.querySelectorAll('ion-item')).find(
      (item) => item.textContent?.trim() === 'Seguridad'
    );
    securityItem?.dispatchEvent(new CustomEvent('click'));

    await flush();

    expect(push).toHaveBeenCalledWith({ path: '/account', query: { tab: 'security' } });
  });

  it('oculta el enlace "Mi Cuenta" cuando ya estás en la página de cuenta', async () => {
    routePath.value = '/account';

    await openMenu();

    const items = menuItems();

    expect(items).not.toContain('Mi Cuenta');
    expect(items).toContain('Seguridad');
  });

  it('pide confirmación antes de cerrar sesión', async () => {
    await openMenu();

    const logoutItem = Array.from(document.body.querySelectorAll('ion-item')).find(
      (item) => item.textContent?.trim() === 'Cerrar Sesión'
    );
    logoutItem?.dispatchEvent(new CustomEvent('click'));

    await flush();

    const alert = document.body.querySelector('ion-alert');

    expect(alert).not.toBeNull();
    expect(alert?.className).not.toContain('overlay-hidden');
    expect(logout).not.toHaveBeenCalled();
  });

  it('navega al login aunque el logout falle', async () => {
    logout.mockRejectedValueOnce(new Error('network'));

    await openMenu();

    const logoutItem = Array.from(document.body.querySelectorAll('ion-item')).find(
      (item) => item.textContent?.trim() === 'Cerrar Sesión'
    );
    logoutItem?.dispatchEvent(new CustomEvent('click'));

    await flush();

    const confirmButton = document.body.querySelector(
      '.alert-button-role-destructive'
    ) as HTMLButtonElement | null;
    confirmButton?.click();

    await flush();

    expect(logout).toHaveBeenCalled();
    expect(replace).toHaveBeenCalledWith('/login');
  });

  it('muestra las iniciales del usuario en el avatar', () => {
    const menu = mountMenu();

    expect(menu.find('.user-menu-avatar-text').text()).toBe('AL');
  });
});
