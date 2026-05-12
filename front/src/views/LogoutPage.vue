<template>
    <ion-page>
        <ion-header>
            <ion-toolbar>
                <ion-title>Cerrando sesión</ion-title>
            </ion-toolbar>
        </ion-header>
        <ion-content :fullscreen="true" class="ion-text-center">
            <div class="logout-container">
                <ion-spinner name="crescent"></ion-spinner>
                <p>Cerrando sesión...</p>
            </div>
        </ion-content>
    </ion-page>
</template>

<script lang="ts" setup>
import { onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { IonPage, IonHeader, IonToolbar, IonTitle, IonContent, IonSpinner } from '@ionic/vue';
import { useAuthController } from '@/controllers/authController';

const route = useRoute();
const authController = useAuthController();

onMounted(async () => {
    try {
        await authController.logout();
    } catch {
        // Continue even if server logout fails — local state is already cleared
    }

    const redirectUri = (route.query.redirect_uri as string) || '';

    if (redirectUri) {
        window.location.href = redirectUri;
    } else {
        window.location.href = '/auth/login';
    }
});
</script>

<style scoped>
.logout-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    gap: 16px;
}
</style>