<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-menu-button></ion-menu-button>
        </ion-buttons>
        <ion-title>Salud del Sistema</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <!-- 1. Métricas generales -->
      <div class="health-container">
        <ion-list class="health-list">
          <ion-list-header class="section-header">
            Estado General
          </ion-list-header>

          <ion-item>
            <ion-icon slot="start" :icon="pulseOutline"></ion-icon>
            <ion-label>
              <h2>Cron Jobs</h2>
              <p>{{ healthStatus?.cron_configured ? 'Configurado' : 'No detectado' }}</p>
            </ion-label>
            <ion-icon
              slot="end"
              :icon="healthStatus?.cron_configured ? checkmarkCircle : alertCircle"
              :color="healthStatus?.cron_configured ? 'success' : 'warning'"
            ></ion-icon>
          </ion-item>

          <ion-item>
            <ion-icon slot="start" :icon="peopleOutline"></ion-icon>
            <ion-label>
              <h2>Sesiones Activas</h2>
              <p>{{ healthStatus?.active_sessions ?? '...' }}</p>
            </ion-label>
          </ion-item>

          <ion-item>
            <ion-icon slot="start" :icon="shieldOutline"></ion-icon>
            <ion-label>
              <h2>Reportes CSP</h2>
              <p>{{ healthStatus?.csp_reports?.today ?? 0 }} hoy / {{ healthStatus?.csp_reports?.total ?? 0 }} total</p>
            </ion-label>
            <ion-button
              slot="end"
              fill="clear"
              size="small"
              @click="activeTab = 'csp'"
            >
              Ver
            </ion-button>
          </ion-item>
        </ion-list>

        <!-- 2. Cleanup pendiente -->
        <ion-list class="health-list">
          <ion-list-header class="section-header">
            Limpiezas Pendientes
          </ion-list-header>

          <ion-item>
            <ion-icon slot="start" :icon="timerOutline"></ion-icon>
            <ion-label>
              <h2>Login Attempts</h2>
              <p>{{ healthStatus?.needs_cleanup?.login_attempts ?? 0 }} registros por limpiar</p>
            </ion-label>
            <ion-badge
              slot="end"
              :color="(healthStatus?.needs_cleanup?.login_attempts ?? 0) > 100 ? 'warning' : 'medium'"
            >
              {{ healthStatus?.needs_cleanup?.login_attempts ?? 0 }}
            </ion-badge>
          </ion-item>

          <ion-item>
            <ion-icon slot="start" :icon="timerOutline"></ion-icon>
            <ion-label>
              <h2>DPoP Nonces</h2>
              <p>{{ healthStatus?.needs_cleanup?.dpop_nonces ?? 0 }} registros por limpiar</p>
            </ion-label>
            <ion-badge
              slot="end"
              :color="(healthStatus?.needs_cleanup?.dpop_nonces ?? 0) > 1000 ? 'warning' : 'medium'"
            >
              {{ healthStatus?.needs_cleanup?.dpop_nonces ?? 0 }}
            </ion-badge>
          </ion-item>

          <ion-item lines="none">
            <ion-icon slot="start" :icon="timerOutline"></ion-icon>
            <ion-label>
              <h2>Refresh Tokens</h2>
              <p>{{ healthStatus?.needs_cleanup?.refresh_tokens ?? 0 }} tokens por limpiar</p>
            </ion-label>
            <ion-badge
              slot="end"
              :color="(healthStatus?.needs_cleanup?.refresh_tokens ?? 0) > 100 ? 'warning' : 'medium'"
            >
              {{ healthStatus?.needs_cleanup?.refresh_tokens ?? 0 }}
            </ion-badge>
          </ion-item>
        </ion-list>

        <!-- 3. CSP Reports -->
        <ion-list class="health-list">
          <ion-list-header class="section-header">
            Reportes CSP
            <ion-chip slot="end" size="small" @click="activeTab = activeTab === 'csp' ? '' : 'csp'">
              <ion-label>{{ activeTab === 'csp' ? 'Ocultar' : 'Ver todo' }}</ion-label>
            </ion-chip>
          </ion-list-header>

          <template v-if="activeTab === 'csp'">
            <ion-item v-if="cspReports.length === 0">
              <ion-label class="ion-text-center">
                <p>No hay reportes CSP</p>
              </ion-label>
            </ion-item>

            <ion-item
              v-for="report in cspReports"
              :key="report.id"
              class="csp-report-item"
            >
              <ion-label>
                <h2>{{ report.blocked_uri || '(directiva violada)' }}</h2>
                <p class="report-meta">
                  {{ report.violated_directive }}
                  <span v-if="report.document_uri"> - {{ report.document_uri }}</span>
                </p>
                <p class="report-date">{{ formatDate(report.created_at) }}</p>
                <div v-if="report.script_sample" class="script-sample">
                  <code>{{ report.script_sample.substring(0, 200) }}{{ report.script_sample.length > 200 ? '...' : '' }}</code>
                </div>
                <div v-if="!report.resolved_at" class="hash-suggestion">
                  <ion-chip
                    size="small"
                    color="primary"
                    :disabled="allowingHash === report.id"
                    @click="allowScriptFromReport(report)"
                  >
                    <ion-icon :icon="lockOpenOutline"></ion-icon>
                    <ion-label>Permitir script</ion-label>
                  </ion-chip>
                </div>
                <div v-else>
                  <ion-chip size="small" color="success" disabled>
                    <ion-icon :icon="checkmarkCircle"></ion-icon>
                    <ion-label>Permitido</ion-label>
                  </ion-chip>
                </div>
              </ion-label>
            </ion-item>

            <ion-item v-if="cspPagination.lastPage > 1">
              <ion-buttons slot="end">
                <ion-button
                  size="small"
                  :disabled="cspPagination.currentPage <= 1"
                  @click="changeCspPage(cspPagination.currentPage - 1)"
                >
                  <ion-icon :icon="chevronBackOutline"></ion-icon>
                </ion-button>
                <ion-label class="page-label">
                  {{ cspPagination.currentPage }} / {{ cspPagination.lastPage }}
                </ion-label>
                <ion-button
                  size="small"
                  :disabled="cspPagination.currentPage >= cspPagination.lastPage"
                  @click="changeCspPage(cspPagination.currentPage + 1)"
                >
                  <ion-icon :icon="chevronForwardOutline"></ion-icon>
                </ion-button>
              </ion-buttons>
            </ion-item>
          </template>
        </ion-list>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
// 1. Imports (ionic/vue/ionicons)
import { ref, onMounted } from 'vue'
import {
  IonPage,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonButtons,
  IonMenuButton,
  IonButton,
  IonContent,
  IonList,
  IonListHeader,
  IonItem,
  IonIcon,
  IonLabel,
  IonBadge,
  IonChip,
} from '@ionic/vue'
import {
  pulseOutline,
  peopleOutline,
  shieldOutline,
  timerOutline,
  checkmarkCircle,
  alertCircle,
  lockOpenOutline,
  chevronBackOutline,
  chevronForwardOutline,
} from 'ionicons/icons'

// 2. Controllers / Composables
import { useHealthController } from '@/controllers/healthController'
import { alertController } from '@ionic/vue'
const healthCtrl = useHealthController()

// 3. Components

// 4. Interface and types imports
import type { CspReportItem } from '@/stores/health'

// 5. Main (props, emits, etc.)

// 6. Vars and const
const activeTab = ref('')
const allowingHash = ref<number | null>(null)

const healthStatus = healthCtrl.healthStatus
const cspReports = healthCtrl.cspReports
const cspPagination = healthCtrl.cspPagination

// 7. Watch

// 8. Computed

// 9. OnMounted
onMounted(async () => {
  await healthCtrl.loadHealth()
  await healthCtrl.loadCspReports({})
})

// 10. Functions (named parameters pattern)
function formatDate(dateStr: string): string {
  const d = new Date(dateStr)
  return d.toLocaleString('es-MX', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

async function changeCspPage(page: number) {
  await healthCtrl.loadCspReports({ page })
}

async function computeHashFromScript(scriptSample: string): Promise<string> {
  const encoder = new TextEncoder()
  const data = encoder.encode(scriptSample)
  const hashBuffer = await crypto.subtle.digest('SHA-256', data)
  return 'sha256-' + btoa(String.fromCharCode(...new Uint8Array(hashBuffer)))
    .replace(/=+$/, '')
}

async function allowScriptFromReport(report: CspReportItem) {
  const scriptSample = report.script_sample
  if (!scriptSample) {
    const alert = await alertController.create({
      header: 'Sin muestra',
      message: 'Este reporte no contiene muestra del script. No se puede calcular el hash automáticamente.',
      buttons: ['OK'],
    })
    await alert.present()
    return
  }

  allowingHash.value = report.id
  try {
    const hash = await computeHashFromScript(scriptSample)
    const result = await healthCtrl.allowScript({ hash, report_id: report.id })
    const alert = await alertController.create({
      header: 'Script permitido',
      message: `Hash ${result.hash} agregado al CSP.\n\nRequiere deploy para producción.`,
      buttons: ['OK'],
    })
    await alert.present()
    await healthCtrl.loadCspReports({})
    await healthCtrl.loadHealth()
  } catch (err: unknown) {
    const message = err instanceof Error ? err.message : 'Error desconocido'
    const alert = await alertController.create({
      header: 'Error',
      message,
      buttons: ['OK'],
    })
    await alert.present()
  } finally {
    allowingHash.value = null
  }
}
</script>

<style scoped>
.health-container {
  padding: clamp(12px, 3vw, 16px);
  max-width: 900px;
  margin: 0 auto;
}

.health-list {
  background: transparent;
  margin-bottom: var(--space-4, 24px);
}

.section-header {
  font-size: clamp(12px, 2.5vw, 13px);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--text-tertiary);
  padding-left: 0;
}

.health-list ion-item {
  --border-radius: 4px;
  --background: var(--background-contrast);
  --padding-start: clamp(10px, 2.5vw, 12px);
  --padding-end: clamp(10px, 2.5vw, 12px);
  margin-bottom: var(--space-2, 8px);
}

.health-list ion-icon {
  color: var(--text-secondary);
}

.health-list ion-label h2 {
  font-size: clamp(13px, 3vw, 14px);
  font-weight: 600;
  color: var(--text-primary);
}

.health-list ion-label p {
  font-size: clamp(11px, 2.5vw, 12px);
  color: var(--text-tertiary);
  margin-top: 2px;
}

.csp-report-item {
  --min-height: 80px;
}

.report-meta {
  font-size: 11px !important;
  color: var(--text-secondary) !important;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.report-date {
  font-size: 10px !important;
  color: var(--text-tertiary) !important;
  margin-top: 0 !important;
}

.script-sample {
  background: var(--background-rgb, #f5f5f5);
  border-radius: 4px;
  padding: 6px 8px;
  margin-top: 4px;
  max-height: 60px;
  overflow: hidden;
}

.script-sample code {
  font-size: 10px;
  word-break: break-all;
  color: var(--text-primary);
}

.hash-suggestion {
  margin-top: 4px;
}

.page-label {
  font-size: 12px;
  text-align: center;
  padding: 0 8px;
}

@media (min-width: 768px) {
  .health-container {
    padding: clamp(16px, 3vw, 24px);
  }
}
</style>
