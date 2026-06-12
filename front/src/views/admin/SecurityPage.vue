<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-back-button
            default-href="/admin/config"
          ></ion-back-button>
        </ion-buttons>
        <ion-title>Seguridad</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <!-- content -->
      <div class="security-container">
        <ion-list class="security-list">
          <ion-list-header class="section-header"
            >Seguridad de Clientes</ion-list-header
          >

          <ion-item>
            <ion-icon
              slot="start"
              :icon="lockClosed"
            ></ion-icon>
            <ion-label>
              <h2>Requerir SSL en redirect_url</h2>
              <p>
                Cuando está activo, los clientes OAuth2 con redirect_url
                HTTP serán rechazados
              </p>
            </ion-label>
            <ion-toggle
              slot="end"
              :checked="requireSsl"
              :disabled="loading"
              @ion-change="onToggleSsl()"
            ></ion-toggle>
          </ion-item>

          <template v-if="requireSsl">
            <ion-item>
              <ion-icon
                slot="start"
                :icon="listOutline"
              ></ion-icon>
              <ion-label>
                <h2>Exclusiones SSL</h2>
                <p>
                  Hosts permitidos con HTTP aunque SSL sea requerido
                </p>
              </ion-label>
            </ion-item>

            <div class="exclusions-container">
              <ion-chip
                v-for="exclusion in defaultExclusions"
                :key="exclusion"
                :disabled="true"
                class="exclusion-chip default-chip"
              >
                <ion-icon
                  :icon="lockClosedOutline"
                ></ion-icon>
                <ion-label>{{ exclusion }}</ion-label>
              </ion-chip>

              <ion-chip
                v-for="exclusion in customExclusions"
                :key="exclusion"
                class="exclusion-chip custom-chip"
                @click="onRemoveExclusion({ host: exclusion })"
              >
                <ion-label>{{ exclusion }}</ion-label>
                <ion-icon :icon="closeCircle"></ion-icon>
              </ion-chip>

              <ion-chip
                class="exclusion-chip add-chip"
                @click="showAddExclusionAlert()"
              >
                <ion-icon :icon="addCircleOutline"></ion-icon>
                <ion-label>Agregar</ion-label>
              </ion-chip>
            </div>
          </template>
        </ion-list>
      </div>
      <!-- end content -->

      <!-- modals -->
      <!-- end modals -->

      <!-- popovers -->
      <!-- end popovers -->

      <!-- alerts -->
      <!-- end alerts -->
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
// imports
import { onMounted, computed } from 'vue';
import {
  IonPage,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonButtons,
  IonBackButton,
  IonContent,
  IonList,
  IonListHeader,
  IonItem,
  IonIcon,
  IonLabel,
  IonToggle,
  IonChip,
  alertController,
} from '@ionic/vue';
import {
  lockClosed,
  lockClosedOutline,
  listOutline,
  closeCircle,
  addCircleOutline,
} from 'ionicons/icons';

// controllers
import { useSecurityController } from '@/controllers/securityController';
const securityController = useSecurityController();

// components
// end components

// interface and types imports
// end interface and types imports

// main
// end main

// vars and const
const requireSsl = securityController.requireSsl;
const sslExclusions = securityController.sslExclusions;
const loading = securityController.loading;

const defaultExclusions = ['localhost', '127.0.0.1', '::1'];

const customExclusions = computed(() => {
  return sslExclusions.value.filter(
    (item: string) => !defaultExclusions.includes(item)
  );
});

// watch
// end

// computed
// end computed

// onmounted
onMounted(async () => {
  await securityController.fetchSettings();
});
// end onmounted

// functions
const onToggleSsl = async (): Promise<void> => {
  await securityController.toggleRequireSsl();
};

const onRemoveExclusion = async (params: {
  host: string;
}): Promise<void> => {
  const { host } = params;
  const alert = await alertController.create({
    header: 'Eliminar exclusión',
    message: `¿Eliminar "${host}" de las exclusiones SSL?`,
    buttons: [
      { text: 'Cancelar', role: 'cancel' },
      {
        text: 'Eliminar',
        role: 'destructive',
        handler: async () => {
          await securityController.removeExclusion({ host });
        },
      },
    ],
  });
  await alert.present();
};

const showAddExclusionAlert = async (): Promise<void> => {
  const alert = await alertController.create({
    header: 'Agregar exclusión SSL',
    inputs: [
      {
        name: 'host',
        type: 'text',
        placeholder: 'ej: mi-servidor.local, 192.168.1.50',
      },
    ],
    buttons: [
      { text: 'Cancelar', role: 'cancel' },
      {
        text: 'Agregar',
        handler: async (data: { host: string }) => {
          const host = data.host?.trim();
          if (host) {
            await securityController.addExclusion({ host });
          }
        },
      },
    ],
  });
  await alert.present();
};
// end functions
</script>

<style scoped>
.security-container {
  padding: 16px;
  max-width: 900px;
  margin: 0 auto;
}

.security-list {
  background: transparent;
}

.section-header {
  font-size: 13px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--text-tertiary);
  padding-left: 0;
}

.security-list ion-item {
  --border-radius: 12px;
  --background: var(--background-contrast);
  --padding-start: 12px;
  --padding-end: 12px;
  margin-bottom: 8px;
}

.security-list ion-item:active {
  --background: var(--ion-color-light);
}

.security-list ion-icon {
  color: var(--text-secondary);
}

.security-list ion-label h2 {
  font-size: 14px;
  font-weight: 600;
  color: var(--text-primary);
}

.security-list ion-label p {
  font-size: 12px;
  color: var(--text-tertiary);
  margin-top: 2px;
}

.exclusions-container {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 12px 12px 16px;
}

.exclusion-chip {
  --background: var(--background-contrast);
  --color: var(--text-primary);
  font-size: 13px;
}

.default-chip {
  opacity: 0.6;
}

.custom-chip:hover {
  --background: var(--ion-color-danger-light);
}

.add-chip {
  --border-style: dashed;
  --border-color: var(--text-tertiary);
}

@media (min-width: 768px) {
  .security-container {
    padding: 24px;
  }
}
</style>
