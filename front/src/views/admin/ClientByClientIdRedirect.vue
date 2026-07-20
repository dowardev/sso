<template>
  <ion-page>
    <ion-content class="ion-padding">
      <ion-spinner name="lines"></ion-spinner>
      <p>Redirigiendo...</p>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
// 1. Imports (ionic/vue/ionicons)
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { IonPage, IonContent, IonSpinner } from '@ionic/vue'

// 2. Controllers / Composables
import { useClientsController } from '@/controllers/clientsController'
const clientsController = useClientsController()

// 3. Components

// 4. Interface and types imports

// 5. Main (props, emits, etc.)

// 6. Vars and const
const route = useRoute()
const router = useRouter()

// 7. Watch

// 8. Computed

// 9. OnMounted
onMounted(async () => {
  const clientId = route.params.clientId as string
  try {
    await clientsController.fetchClients()
    const client = clientsController.clients.value.find(c => c.client_id === clientId)
    if (client) {
      router.push({ name: 'AdminClientDetail', params: { id: client.id } })
    } else {
      router.push({ name: 'AdminClients' })
    }
  } catch {
    router.push({ name: 'AdminClients' })
  }
})

// 10. Functions (named parameters pattern)
</script>

<style scoped>
</style>
