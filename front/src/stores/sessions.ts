import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { api } from '@/services/api';
import type { UserSession, PaginatedResponse } from '@/types';

export const useSessionsStore = defineStore('sessions', () => {
  const sessions = ref<UserSession[]>([]);
  const loading = ref(false);
  const onlyActive = ref(false);

  const pagination = ref({
    currentPage: 1,
    lastPage: 1,
    perPage: 15,
    total: 0,
  });

  const hasMore = computed(() => pagination.value.currentPage < pagination.value.lastPage);

  const activeCount = computed(() => {
    if (pagination.value.total > 0) {
      return pagination.value.total;
    }
    return sessions.value.filter((s) => s.token_active).length;
  });

  async function fetchSessions(params?: { page?: number; per_page?: number; active?: boolean }) {
    loading.value = true;
    try {
      const queryParams = new URLSearchParams();
      if (params?.page) queryParams.set('page', String(params.page));
      if (params?.per_page) queryParams.set('per_page', String(params.per_page));
      if (params?.active !== undefined) queryParams.set('active', params.active ? '1' : '0');

      const response = await api.get<PaginatedResponse<UserSession>>(
        `/admin/sessions?${queryParams.toString()}`
      );

      if (params?.page && params.page > 1) {
        sessions.value = [...sessions.value, ...response.data];
      } else {
        sessions.value = response.data;
      }

      pagination.value = {
        currentPage: response.current_page,
        lastPage: response.last_page,
        perPage: response.per_page,
        total: response.total,
      };
    } finally {
      loading.value = false;
    }
  }

  async function revokeSession(id: number) {
    loading.value = true;
    try {
      await api.delete(`/admin/sessions/${id}`);
      sessions.value = sessions.value.filter((s) => s.id !== id);
    } finally {
      loading.value = false;
    }
  }

  function setOnlyActive(value: boolean) {
    onlyActive.value = value;
  }

  function reset() {
    sessions.value = [];
    pagination.value = {
      currentPage: 1,
      lastPage: 1,
      perPage: 15,
      total: 0,
    };
    onlyActive.value = false;
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
  };
});