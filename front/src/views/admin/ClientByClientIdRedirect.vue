<template>
  <ion-page>
    <ion-content class="ion-padding">
      <ion-spinner name="lines"></ion-spinner>
      <p>Redirigiendo...</p>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useClientsController } from '@/controllers/clientsController';

const route = useRoute();
const router = useRouter();
const clientsController = useClientsController();

onMounted(async () => {
  const clientId = route.params.clientId as string;
  try {
    // Cargar todos los clientes (o endpoint específico si existe)
    await clientsController.fetchClients();
    const client = clientsController.clients.value.find(c => c.client_id === clientId);
    if (client) {
      router.push({ name: 'AdminClientDetail', params: { id: client.id } });
    } else {
      router.push({ name: 'AdminClients' });
    }
  } catch {
    router.push({ name: 'AdminClients' });
  }
});
</script>