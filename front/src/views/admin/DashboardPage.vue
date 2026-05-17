<template>
  <ion-page>
    <ion-header class="ion-no-border dashboard-header" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-menu-button></ion-menu-button>
        </ion-buttons>
        <ion-title>Dashboard SSO</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <!-- content -->

      <div class="dashboard-container">
        <section>
          <ion-grid>
            <ion-row>
              <ion-col>
                <ion-card color="primary">
                  <ion-card-header>
                    <div style="display: flex; justify-content: space-between">
                      <div>
                        <ion-card-title
                          style="font-size: 24px; font-weight: 700"
                          >Bienvenido de nuevo</ion-card-title
                        >
                        <ion-card-subtitle>{{
                          formattedDate
                        }}</ion-card-subtitle>
                      </div>

                      <div>
                        <div class="welcome-icon">
                          <ion-icon :icon="shield"></ion-icon>
                        </div>
                      </div>
                    </div>
                  </ion-card-header>
                </ion-card>
              </ion-col>
            </ion-row>
          </ion-grid>
        </section>

        <section>
          <ion-list>
            <ion-list-header>
              <ion-label> Resumen del Sistema </ion-label>
            </ion-list-header>

            <ion-grid>
              <ion-row>
                <ion-col size="12" size-sm="6" size-lg="4">
                  <ion-card class="shadow1">
                    <div class="stat-card-gradient ion-padding">
                      <div>
                        <ion-icon
                          class="stat-card-icon"
                          color="light"
                          :icon="people"
                        ></ion-icon>
                      </div>
                    </div>

                    <ion-item
                      button
                      detail="true"
                      lines="none"
                      class="transparent"
                      @click="router.push({ name: 'AdminUsers' })"
                    >
                      <ion-label>
                        <span class="stat-value">{{
                          isLoading ? '...' : stats.totalUsers
                        }}</span>

                        <p>Usuarios</p>
                      </ion-label>
                    </ion-item>
                  </ion-card>
                </ion-col>

                <ion-col size="12" size-sm="6" size-lg="4">
                  <ion-card class="shadow1">
                    <div class="stat-card-gradient ion-padding">
                      <div>
                        <ion-icon
                          class="stat-card-icon"
                          color="light"
                          :icon="key"
                        ></ion-icon>
                      </div>
                    </div>

                    <ion-item
                      button
                      detail="true"
                      lines="none"
                      class="transparent"
                      @click="router.push({ name: 'AdminSessions' })"
                    >
                      <ion-label>
                        <span class="stat-value">{{
                          isLoading ? '...' : stats.activeSessions
                        }}</span>

                        <p>Sesiones Activas</p>
                      </ion-label>
                    </ion-item>
                  </ion-card>
                </ion-col>

                <ion-col size="12" size-sm="6" size-lg="4">
                  <ion-card class="shadow1">
                    <div class="stat-card-gradient ion-padding">
                      <div>
                        <ion-icon
                          class="stat-card-icon stat-card-icon--small"
                          color="light"
                          :icon="desktop"
                        ></ion-icon>
                      </div>
                    </div>

                    <ion-item
                      button
                      detail="true"
                      lines="none"
                      class="transparent"
                      @click="router.push({ name: 'AdminClients' })"
                    >
                      <ion-label>
                        <span class="stat-value">{{
                          isLoading ? '...' : stats.totalClients
                        }}</span>

                        <p>Clientes SSO</p>
                      </ion-label>
                    </ion-item>
                  </ion-card>
                </ion-col>
              </ion-row>
            </ion-grid>
          </ion-list>
        </section>

        <section>
          <ion-list>
            <ion-list-header>
              <ion-label>Estado del Sistema</ion-label>
            </ion-list-header>

            <ion-grid>
              <ion-row>
                <ion-col size="12" size-md="4">
                  <ion-card class="shadow1">
                    <ion-item class="transparent" lines="none">
                      <div slot="start" class="status-indicator online"></div>

                      <ion-label>
                        Servidor API
                        <p>Operativo</p>
                      </ion-label>
                    </ion-item>
                  </ion-card>
                </ion-col>

                <ion-col size="12" size-md="4">
                  <ion-card class="shadow1">
                    <ion-item class="transparent" lines="none">
                      <div slot="start" class="status-indicator online"></div>

                      <ion-label>
                        Base de Datos
                        <p>Conectada</p>
                      </ion-label>
                    </ion-item>
                  </ion-card>
                </ion-col>

                <ion-col size="12" size-md="4">
                  <ion-card class="shadow1">
                    <ion-item class="transparent" lines="none">
                      <ion-label>
                        SSO Activo
                        <p>{{ stats.activeSessions }} sesiones</p>
                      </ion-label>
                    </ion-item>
                  </ion-card>
                </ion-col>
              </ion-row>
            </ion-grid>
          </ion-list>
        </section>
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
  import { ref, computed, onMounted } from 'vue';
  import { useRouter } from 'vue-router';
  import {
    IonPage,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonButtons,
    IonMenuButton,
    IonContent,
    IonIcon,
    IonGrid,
    IonRow,
    IonCol,
  } from '@ionic/vue';
  import { people, key, desktop, shield } from 'ionicons/icons';

  // controllers
  import { useUsersController } from '@/controllers/usersController';
  import { useSessionsController } from '@/controllers/sessionsController';
  import { useClientsController } from '@/controllers/clientsController';
  const usersController = useUsersController();
  const sessionsController = useSessionsController();
  const clientsController = useClientsController();

  // components
  // end components

  // interface and types imports
  // end interface and types imports

  // main
  const router = useRouter();
  // end main

  // vars and const
  const loading = ref(true);
  // end vars and const

  // watch
  // end

  // computed
  const formattedDate = computed(() => {
    const now = new Date();
    const options: Intl.DateTimeFormatOptions = {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    };
    return now.toLocaleDateString('es-ES', options);
  });

  const stats = computed(() => ({
    totalUsers: usersController.pagination.value.total,
    activeSessions: sessionsController.activeCount.value,
    totalClients: clientsController.count.value,
  }));

  const isLoading = computed(() => {
    return (
      usersController.loading.value ||
      sessionsController.loading.value ||
      clientsController.loading.value
    );
  });
  // end computed

  // onmounted
  onMounted(async () => {
    loading.value = true;
    try {
      await Promise.all([
        usersController.fetchUsers({ per_page: 1 }),
        sessionsController.fetchSessions({ per_page: 1, active: true }),
        clientsController.fetchClients(),
      ]);
    } catch (error) {
      // Silent fail - UI will show default values
    } finally {
      loading.value = false;
    }
  });
  // end onmounted

  // functions
  // end functions
</script>

<style scoped>
  .dashboard-container {
    padding: var(--dashboard-padding);
    max-width: 1400px;
    margin: 0 auto;
  }

  @media (min-width: 768px) {
    .dashboard-container {
      padding: var(--dashboard-padding-lg);
    }
  }

  .section-title {
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-tertiary);
    margin: 0 0 16px 0;
  }

  .welcome-section {
    background: var(--ion-color-primary);
    border-radius: var(--card-border-radius);
    padding: 24px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    overflow: hidden;
  }

  @media (min-width: 768px) {
    .welcome-section {
      padding: 32px;
    }
  }

  .welcome-section::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
  }

  .welcome-section::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: 10%;
    width: 100px;
    height: 100px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
  }

  .welcome-content {
    position: relative;
    z-index: 1;
  }

  .welcome-title {
    font-size: 24px;
    font-weight: 700;
    color: var(--ion-text-color);
    margin: 0 0 4px 0;
  }

  @media (min-width: 768px) {
    .welcome-title {
      font-size: 28px;
    }
  }

  .welcome-date {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.85);
    margin: 0;
  }

  .welcome-icon {
    width: 56px;
    height: 56px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    z-index: 1;
  }

  .welcome-icon ion-icon {
    font-size: 28px;
    color: white;
  }

  .stats-section {
    margin-bottom: 24px;
  }

  .stat-card-gradient {
    background: linear-gradient(to right, var(--ion-color-secondary), #ffffff);
    width: 100%;
    height: 73px;
    overflow: hidden;
    position: relative;
  }

  .stat-card-icon {
    font-size: 136px;
    position: absolute;
    margin: 0;
    top: -33px;
    left: -27px;
  }

  .stat-card-icon--small {
    font-size: 122px;
  }

  .stats-grid {
    padding: 0;
    --ion-grid-padding: 0;
  }

  .stats-grid ion-col {
    padding: 0 0 12px 0;
  }

  .stat-card {
    background: var(--background-contrast);
    border-radius: var(--card-border-radius);
    padding: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow:
      var(--card-shadow-light) 0px 1px 3px 0px,
      var(--card-shadow-heavy) 0px 0px 0px 1px;
    cursor: pointer;
    transition:
      transform 0.2s ease,
      box-shadow 0.2s ease;
    position: relative;
    overflow: hidden;
    height: 100%;
  }

  @media (min-width: 768px) {
    .stat-card {
      padding: 20px;
      gap: 16px;
    }
  }

  .stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
  }

  @media (prefers-reduced-motion: reduce) {
    .stat-card:hover {
      transform: none;
    }
  }

  .stat-card-accent {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    border-radius: 4px 0 0 4px;
  }

  .users-accent {
    background: var(--ion-color-primary);
  }

  .sessions-accent {
    background: var(--ion-color-success);
  }

  .clients-accent {
    background: var(--ion-color-secondary);
  }

  .stat-icon-wrapper {
    flex-shrink: 0;
  }

  .stat-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  @media (min-width: 768px) {
    .stat-icon {
      width: 48px;
      height: 48px;
    }
  }

  .stat-icon ion-icon {
    font-size: 22px;
    color: #fff;
  }

  .users-icon {
    background: var(--ion-color-primary);
  }

  .sessions-icon {
    background: var(--ion-color-success);
  }

  .clients-icon {
    background: var(--ion-color-secondary);
  }

  .stat-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
  }

  .stat-value {
    font-size: 26px;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1.2;
  }

  @media (min-width: 768px) {
    .stat-value {
      font-size: 32px;
    }
  }

  .stat-label {
    font-size: 13px;
    font-weight: 500;
    color: var(--text-secondary);
    margin-top: 2px;
  }

  .stat-arrow {
    flex-shrink: 0;
    color: var(--text-tertiary);
    display: flex;
    align-items: center;
  }

  .stat-arrow ion-icon {
    font-size: 20px;
  }

  .system-status-section {
    margin-bottom: 24px;
  }

  .status-grid {
    padding: 0;
    --ion-grid-padding: 0;
  }

  .status-grid ion-col {
    padding: 0 0 12px 0;
  }

  .status-card {
    background: var(--system-status-bg);
    border-radius: 12px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: var(--card-shadow-subtle) 0px 0px 0px 1px;
    height: 100%;
  }

  .status-indicator {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
  }

  .status-indicator.online {
    background: var(--ion-color-success);
    box-shadow: 0 0 8px var(--ion-color-success);
  }

  .status-indicator.offline {
    background: var(--ion-color-danger);
    box-shadow: 0 0 8px var(--ion-color-danger);
  }

  .status-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }

  .status-label {
    font-size: 12px;
    font-weight: 500;
    color: var(--text-tertiary);
  }

  .status-value {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
  }

  .quick-actions-section {
    margin-bottom: 24px;
  }

  .quick-actions-grid {
    padding: 0;
    --ion-grid-padding: 0;
  }

  .quick-actions-grid ion-col {
    padding: 0 0 12px 0;
  }

  .action-btn {
    --border-radius: 12px;
    --padding-start: 16px;
    --padding-end: 16px;
    height: 48px;
    font-size: 13px;
    font-weight: 500;
    text-transform: none;
    --background: var(--ion-color-primary);
    --color: white;
    margin: 0;
    width: 100%;
  }

  .action-btn.secondary {
    --background: var(--ion-color-light);
    --color: var(--ion-color-dark);
  }

  .action-btn ion-icon {
    font-size: 18px;
    margin-right: 8px;
  }

  @media (prefers-reduced-motion: reduce) {
    .stat-card,
    .action-btn {
      transition: none;
    }
  }
</style>
