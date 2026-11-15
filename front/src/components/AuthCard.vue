<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-card class="auth-screen">
    <div class="blob blob-1" aria-hidden="true"></div>
    <div class="blob blob-2" aria-hidden="true"></div>
    <div class="blob blob-3" aria-hidden="true"></div>

    <div class="auth-screen-inner">
      <div
        class="auth-card-content"
        :class="{ 'auth-card-content--with-below': hasBelow }"
        :style="{ maxWidth: props.maxWidth }"
      >
        <slot name="header">
          <div class="auth-card-header">
            <div class="auth-logo-circle" :class="{ 'auth-logo-circle--plain': logoUrl }">
              <img
                :src="logoUrl || lambdaLogo"
                :class="['auth-logo', logoUrl ? 'auth-logo--custom' : 'auth-logo--filtered']"
                alt="Logo SSO Server"
              />
            </div>
            <h2>{{ title }}</h2>
            <p v-if="hasSubtitleSlot">
              <slot name="subtitle" />
            </p>
            <p v-else>{{ subtitle }}</p>
          </div>
        </slot>

        <slot />
      </div>

      <!-- Rendered right BELOW the card (outside the glass box), still
           inside the centered block so it reads as attached to it. -->
      <div v-if="hasBelow" class="auth-card-below">
        <slot name="below" />
      </div>
    </div>
  </ion-card>
</template>

<script setup lang="ts">
// 1. Imports (ionic/vue/ionicons)
import { computed, onMounted, useSlots } from 'vue';
import { IonCard } from '@ionic/vue';
import lambdaLogo from '@/assets/images/lambda_v1.svg';

// 2. Controllers / Composables
import { useBranding } from '@/composables/useBranding';
const { logoUrl, loadBranding } = useBranding();

// 3. Components

// 4. Interface and types imports

// 5. Main (props, emits, etc.)
const props = withDefaults(
  defineProps<{
    title: string;
    subtitle: string;
    maxWidth?: string;
    /** Force the "below" slot on/off. When omitted it is auto-detected
     *  from the presence of the slot. */
    below?: boolean;
  }>(),
  {
    maxWidth: 'min(440px, 100%)',
  },
);

// True when the host renders content below the card: the card's bottom
// padding shrinks so no dead space is left between the card content and
// whatever follows it. `props.below` lets a host that always provides
// the slot opt out explicitly (e.g. a public route that renders nothing).
const slots = useSlots();
const hasBelow = computed(() => props.below ?? Boolean(slots.below));

// A host can replace the static subtitle with rich content (e.g. the OAuth
// consent screen naming the client app) without overriding the whole header,
// so the branding (logo) keeps being rendered by this component.
const hasSubtitleSlot = computed(() => Boolean(slots.subtitle));

// 6. Vars and const

// 7. Watch

// 8. Computed

// 9. OnMounted
onMounted(() => {
  void loadBranding();
});

// 10. Functions (named parameters pattern)
</script>

<style scoped>
.auth-screen {
  min-height: 100dvh;
  height: auto;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
  background: var(--ion-background-color);
  padding: 24px;
}

/* Wraps the card so it is centered as one block. `flex: 0 0 auto` is
   mandatory: ion-card paints with `contain: content` (paint containment
   clips descendants to its border box), so if this block were ever
   allowed to shrink, the card's content would be clipped mid-text and
   a dead gap would appear under the card. */
.auth-screen-inner {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 100%;
  flex: 0 0 auto;
  min-height: min-content;
}

.blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
  opacity: 0.5;
  pointer-events: none;
  /* LBDS-202: removed will-change (no animations left to promote) */
}

.blob-1 {
  width: 420px;
  height: 420px;
  background: var(--ion-color-primary);
  top: -10%;
  left: -5%;
  /* LBDS-202: animation removed — static blob paints once */
}

.blob-2 {
  width: 350px;
  height: 350px;
  background: var(--ion-color-secondary);
  bottom: -10%;
  right: -5%;
  /* LBDS-202: animation removed — static blob paints once */
}

.blob-3 {
  width: 280px;
  height: 280px;
  background: var(--ion-color-tertiary);
  top: 50%;
  left: 60%;
  /* LBDS-202: animation removed — static blob paints once */
}

/* LBDS-202: blob float keyframes removed - blobs are static now. */

.auth-card-content {
  position: relative;
  z-index: 1;
  width: 100%;
  max-width: min(440px, 100%);
  /* Never let the flex parent squeeze the card: its box must always be
     at least as tall as its content, otherwise the paint containment of
     ion-card cuts the last line of the form in half. */
  flex: 0 0 auto;
  min-height: min-content;
  padding: clamp(28px, 6vw, 44px) clamp(20px, 5vw, 36px);
  background: var(--glass-bg);
  border: 1px solid var(--glass-border);
  border-radius: var(--radius-lg);
  backdrop-filter: var(--glass-blur, blur(24px));
  -webkit-backdrop-filter: var(--glass-blur, blur(24px));
  animation: card-appear 0.6s ease both;
}

/* When something is rendered right below the card, the card's bottom
   padding would leave a dead band of empty glass between the content
   and that link. Shrink it so the link reads as attached to the card. */
.auth-card-content--with-below {
  padding-bottom: var(--space-2);
}

.auth-card-below {
  position: relative;
  z-index: 1;
  display: flex;
  /* Desktop: both links on one row, right under the card. Mobile: they
     stack (the two labels do not fit side by side below 768px). */
  flex-direction: row;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: var(--space-1) var(--space-3);
  margin-top: var(--space-2);
  flex: 0 0 auto;
}

@media (max-width: 767px) {
  .auth-card-below {
    flex-direction: column;
  }
}

@keyframes card-appear {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.auth-card-header {
  text-align: center;
  margin-bottom: clamp(24px, 4vw, 32px);
}

.auth-logo-circle {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: var(--gradient-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto var(--space-4);
  box-shadow: 0 4px 20px rgba(var(--gradient-primary-rgb), 0.3);
}

.auth-logo-circle--plain {
  width: auto;
  height: auto;
  background: transparent;
  box-shadow: none;
}

.auth-logo {
  max-width: 32px;
  height: auto;
}

.auth-logo--custom {
  max-width: 96px;
  max-height: 96px;
}

.auth-logo--filtered {
  filter: brightness(0) invert(1);
}

.auth-card-header h2 {
  font-family: var(--font-heading);
  font-size: clamp(22px, 5vw, 26px);
  font-weight: 700;
  letter-spacing: -0.01em;
  color: var(--text-primary);
  margin: 0 0 var(--space-2) 0;
}

.auth-card-header p {
  font-size: clamp(13px, 3vw, 14px);
  line-height: 1.5;
  color: var(--text-tertiary);
  margin: 0;
}

@media (prefers-reduced-motion: reduce) {
  /* LBDS-202: .blob rule removed - blobs have no animation to disable. */
  .auth-card-content {
    animation: none;
  }
}
</style>
