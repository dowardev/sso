<!-- SPDX-License-Identifier: MIT -->
<template>
  <ProfilePage
    :mode="profileMode"
    :embed-mode="false"
    :external-redirect="true"
    :hide-back-button="true"
    :title="'Mi Cuenta'"
  />
</template>

<script setup lang="ts">
  // 1. Imports (ionic/vue/ionicons)
  import { computed } from 'vue';

  // 2. Controllers
  import { useAuthController } from '@/composables/authController';

  const authController = useAuthController();

  // 3. Components
  import ProfilePage from '@/views/user/ProfilePage.vue';

  // 4. Interface and types imports
  // (none)

  // 5. Main (props, emits, etc.)
  // (none)

  // 6. Vars and const
  // (none)

  // 7. Watch
  // (none)

  // 8. Computed
  const profileMode = computed(() =>
    authController.user.value?.is_admin ? 'full' : 'limited'
  );

  // 9. OnMounted
  // (none)

  // 10. Functions
  // (none)
</script>
