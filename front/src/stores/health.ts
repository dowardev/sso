// SPDX-License-Identifier: MIT
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '@/services/api';

export interface HealthData {
  cron_configured: boolean;
  last_schedule_run: string | null;
  login_attempts_pending: number;
  dpop_nonces_pending: number;
  needs_cleanup: {
    login_attempts: number;
    dpop_nonces: number;
    refresh_tokens: number;
  };
  active_sessions: number;
  csp_reports: {
    total: number;
    today: number;
  };
}

export interface CspReportItem {
  id: number;
  blocked_uri: string | null;
  document_uri: string | null;
  violated_directive: string | null;
  script_sample: string | null;
  resolved_at: string | null;
  created_at: string;
}

export const useHealthStore = defineStore('health', () => {
  const healthData = ref<HealthData | null>(null);
  const cspReports = ref<CspReportItem[]>([]);
  const cspPagination = ref({ currentPage: 1, lastPage: 1, total: 0 });
  const loading = ref(false);

  async function fetchHealth() {
    loading.value = true;
    try {
      const response = await api.get<HealthData>({
        endpoint: '/admin/health',
      });
      healthData.value = response;
    } finally {
      loading.value = false;
    }
  }

  async function fetchCspReports(params: { page?: number; status?: string }) {
    const { page = 1, status } = params;
    loading.value = true;
    try {
      const query = `/admin/health/csp-reports?page=${page}` +
        (status ? `&status=${status}` : '');
      const response = await api.get<{
        data: CspReportItem[];
        current_page: number;
        last_page: number;
        total: number;
      }>({ endpoint: query });
      cspReports.value = response.data;
      cspPagination.value = {
        currentPage: response.current_page,
        lastPage: response.last_page,
        total: response.total,
      };
    } finally {
      loading.value = false;
    }
  }

  async function allowScript(params: { hash: string; report_id?: number }) {
    const { hash, report_id } = params;
    return api.post<{ message: string; hash: string }>({
      endpoint: '/admin/health/csp-allow',
      data: { hash, report_id },
    });
  }

  function reset() {
    healthData.value = null;
    cspReports.value = [];
    cspPagination.value = { currentPage: 1, lastPage: 1, total: 0 };
  }

  return {
    healthData,
    cspReports,
    cspPagination,
    loading,
    fetchHealth,
    fetchCspReports,
    allowScript,
    reset,
  };
});
