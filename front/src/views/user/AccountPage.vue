<template>
  <UserProfileWidget
    :mode="profileMode"
    :embed-mode="false"
    :external-redirect="true"
    :hide-back-button="true"
    :title="'Mi Cuenta'"
  />
</template>

<script setup lang="ts">
import UserProfileWidget from '@/components/user/UserProfileWidget.vue';
import { computed } from 'vue';
import { useAuthController } from '@/controllers/authController';

const authController = useAuthController();

const profileMode = computed(() =>
  authController.user.value?.is_admin ? 'full' : 'limited'
);
</script>
