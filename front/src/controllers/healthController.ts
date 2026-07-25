// SPDX-License-Identifier: MIT
import { computed } from 'vue'
import { useHealthStore } from '@/stores/health'

export function useHealthController() {
  const store = useHealthStore()

  const healthStatus = computed(() => store.healthData)
  const cspReports = computed(() => store.cspReports)
  const cspPagination = computed(() => store.cspPagination)
  const loading = computed(() => store.loading)

  async function loadHealth() {
    return store.fetchHealth()
  }

  async function loadCspReports(params: { page?: number; status?: string }) {
    return store.fetchCspReports(params)
  }

  async function allowScript(params: { hash: string; report_id?: number }) {
    return store.allowScript(params)
  }

  return {
    healthStatus,
    cspReports,
    cspPagination,
    loading,
    loadHealth,
    loadCspReports,
    allowScript,
  }
}
