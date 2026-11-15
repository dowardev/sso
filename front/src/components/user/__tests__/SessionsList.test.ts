// SPDX-License-Identifier: MIT
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { IonicVue } from '@ionic/vue';
import SessionsList from '@/components/user/SessionsList.vue';

const sessions = [
  {
    id: 1,
    user_agent: 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0 Safari/537.36',
    ip_address: '127.0.0.1',
    last_used_at: '2026-01-01T10:00:00Z',
    created_at: '2026-01-01T09:00:00Z',
    token_active: true,
    client: { name: 'Cliente Test' },
  },
];

describe('SessionsList', () => {
  const mountList = () =>
    mount(SessionsList, {
      props: { sessions: sessions as never, loading: false },
      global: { plugins: [IonicVue] },
    });

  it('no anida controles interactivos: el item no se renderiza como button', () => {
    const wrapper = mountList();
    const item = wrapper.find('ion-item');

    expect(item.exists()).toBe(true);
    // Un ion-item clickable renderiza un <button> nativo; si lo hiciera,
    // los botones de revocar quedarían anidados dentro de otro botón.
    expect(item.element.tagName.toLowerCase()).not.toBe('button');
    expect(item.find('button button').exists()).toBe(false);
  });

  it('agrupa badge y acciones en un único elemento con slot="end"', () => {
    const wrapper = mountList();
    const endSlotElements = wrapper.findAll('[slot="end"]');

    expect(endSlotElements).toHaveLength(1);
    expect(endSlotElements[0].classes()).toContain('session-actions');
  });

  it('expone el estado de expansión con aria-expanded y un nombre accesible', async () => {
    const wrapper = mountList();
    const toggle = wrapper.find('[aria-expanded]');

    expect(toggle.exists()).toBe(true);
    expect(toggle.attributes('aria-expanded')).toBe('false');
    expect(toggle.attributes('aria-label')).toBeTruthy();

    await toggle.trigger('click');

    expect(wrapper.find('[aria-expanded]').attributes('aria-expanded')).toBe('true');
  });
});
