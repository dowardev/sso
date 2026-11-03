// SPDX-License-Identifier: MIT
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { ref } from 'vue';
import { mount } from '@vue/test-utils';
import { IonicVue } from '@ionic/vue';
import AuthCard from '@/components/AuthCard.vue';

const loadBranding = vi.fn();
const logoUrl = ref<string | null>(null);

vi.mock('@/composables/useBranding', () => ({
  useBranding: () => ({
    logoUrl,
    loadBranding,
    uploadLogo: vi.fn(),
    removeLogo: vi.fn(),
  }),
}));

describe('AuthCard', () => {
  beforeEach(() => {
    loadBranding.mockClear();
    logoUrl.value = null;
  });

  const mountCard = (options: Record<string, unknown> = {}) =>
    mount(AuthCard, {
      props: { title: 'Un título', subtitle: 'Un subtítulo' },
      global: { plugins: [IonicVue] },
      ...options,
    });

  it('muestra el logo de branding configurado en el header por defecto', () => {
    logoUrl.value = 'http://localhost:8001/storage/branding/auth-logo.png';

    const wrapper = mountCard();

    expect(wrapper.find('img.auth-logo').attributes('src')).toBe(
      'http://localhost:8001/storage/branding/auth-logo.png'
    );
  });

  it('usa el slot #subtitle en lugar del prop subtitle', () => {
    const wrapper = mountCard({
      slots: { subtitle: '<span class="slot-subtitle">Subtítulo del slot</span>' },
    });

    expect(wrapper.find('.slot-subtitle').exists()).toBe(true);
    expect(wrapper.find('.auth-card-header p').text()).toContain('Subtítulo del slot');
    expect(wrapper.find('.auth-card-header p').text()).not.toContain('Un subtítulo');
  });

  it('mantiene el prop subtitle cuando no hay slot #subtitle', () => {
    const wrapper = mountCard();

    expect(wrapper.find('.auth-card-header p').text()).toBe('Un subtítulo');
  });
});
