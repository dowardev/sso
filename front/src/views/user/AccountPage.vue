<template>
  <UserProfileWidget
    :mode="profileMode"
    :embedMode="false"
    :externalRedirect="true"
    :hideBackButton="true"
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
