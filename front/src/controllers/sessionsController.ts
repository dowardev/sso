import { computed } from 'vue';
import { useSessionsStore } from '@/stores/sessions';
import type { UserSession } from '@/types';

export function useSessionsController() {
  const sessionsStore = useSessionsStore();

  const sessions = computed(() => sessionsStore.sessions);
  const loading = computed(() => sessionsStore.loading);
  const onlyActive = computed(() => sessionsStore.onlyActive);
  const pagination = computed(() => sessionsStore.pagination);
  const hasMore = computed(() => sessionsStore.hasMore);
  const activeCount = computed(() => sessionsStore.activeCount);

  async function fetchSessions(params?: { page?: number; per_page?: number; active?: boolean }): Promise<void> {
    return sessionsStore.fetchSessions(params);
  }

  async function revokeSession(id: number): Promise<void> {
    return sessionsStore.revokeSession(id);
  }

  function setOnlyActive(value: boolean): void {
    sessionsStore.setOnlyActive(value);
  }

  function reset(): void {
    sessionsStore.reset();
  }

  function formatDate(params: { dateStr: string }): string {
    const { dateStr } = params;
    const date = new Date(dateStr);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Ahora mismo';
    if (diffMins < 60) return `Hace ${diffMins} min`;
    if (diffHours < 24) return `Hace ${diffHours} h`;
    if (diffDays < 7) return `Hace ${diffDays} d`;

    return date.toLocaleDateString('es-ES', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
    });
  }

  return {
    sessions,
    loading,
    onlyActive,
    pagination,
    hasMore,
    activeCount,
    fetchSessions,
    revokeSession,
    setOnlyActive,
    reset,
    formatDate,
  };
}