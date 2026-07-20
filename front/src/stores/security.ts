import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { api } from '@/services/api';
import type { Setting } from '@/types';

export const useSecurityStore = defineStore('security', () => {
  const settings = ref<Setting[]>([]);
  const loading = ref(false);

  const requireSsl = computed(() => {
    const setting = settings.value.find((s) => s.key === 'require_ssl');
    return setting?.value === 'true';
  });

  const sslExclusions = computed<string[]>(() => {
    const setting = settings.value.find((s) => s.key === 'ssl_exclusions');
    if (!setting) return ['localhost', '127.0.0.1', '::1'];
    try {
      return JSON.parse(setting.value);
    } catch {
      return ['localhost', '127.0.0.1', '::1'];
    }
  });

  async function fetchSettings(): Promise<Setting[]> {
    loading.value = true;
    try {
      const response = await api.get<Setting[]>({
        endpoint: '/admin/settings',
      });
      settings.value = response;
      return response;
    } finally {
      loading.value = false;
    }
  }

  async function updateSetting(params: {
    key: string;
    value: string;
  }): Promise<Setting> {
    const { key, value } = params;
    loading.value = true;
    try {
      const response = await api.put<{
        message: string;
        setting: Setting;
      }>({
        endpoint: `/admin/settings/${key}`,
        data: { value },
      });
      const index = settings.value.findIndex((s) => s.key === key);
      if (index !== -1) {
        settings.value[index] = response.setting;
      }
      return response.setting;
    } finally {
      loading.value = false;
    }
  }

  function reset(): void {
    settings.value = [];
  }

  return {
    settings,
    loading,
    requireSsl,
    sslExclusions,
    fetchSettings,
    updateSetting,
    reset,
  };
});
