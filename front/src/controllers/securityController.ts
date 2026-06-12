import { computed } from 'vue';
import { useSecurityStore } from '@/stores/security';

export function useSecurityController() {
  const securityStore = useSecurityStore();

  const settings = computed(() => securityStore.settings);
  const loading = computed(() => securityStore.loading);
  const requireSsl = computed(() => securityStore.requireSsl);
  const sslExclusions = computed(() => securityStore.sslExclusions);

  async function fetchSettings() {
    return securityStore.fetchSettings();
  }

  async function toggleRequireSsl(): Promise<void> {
    const newValue = securityStore.requireSsl ? 'false' : 'true';
    await securityStore.updateSetting({ key: 'require_ssl', value: newValue });
  }

  async function updateSslExclusions(params: {
    exclusions: string[];
  }): Promise<void> {
    const { exclusions } = params;
    await securityStore.updateSetting({
      key: 'ssl_exclusions',
      value: JSON.stringify(exclusions),
    });
  }

  async function addExclusion(params: { host: string }): Promise<void> {
    const { host } = params;
    const current = [...securityStore.sslExclusions];
    if (!current.includes(host)) {
      current.push(host);
      await updateSslExclusions({ exclusions: current });
    }
  }

  async function removeExclusion(params: {
    host: string;
  }): Promise<void> {
    const { host } = params;
    const defaultExclusions = ['localhost', '127.0.0.1', '::1'];
    if (defaultExclusions.includes(host)) {
      return;
    }
    const current = securityStore.sslExclusions.filter(
      (item: string) => item !== host
    );
    await updateSslExclusions({ exclusions: current });
  }

  function reset(): void {
    securityStore.reset();
  }

  return {
    settings,
    loading,
    requireSsl,
    sslExclusions,
    fetchSettings,
    toggleRequireSsl,
    addExclusion,
    removeExclusion,
    reset,
  };
}
