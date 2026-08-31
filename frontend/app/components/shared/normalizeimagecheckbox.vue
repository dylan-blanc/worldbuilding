<!--
  This shared checkbox controls the default-enabled normalization of an uploaded image to its detected safe format.
  Profile, owned-page and CMS upload forms send its boolean value as normalize_image_type to their PHP media endpoints.
-->
<script setup lang="ts">
defineProps<{
  compact?: boolean
  disabled?: boolean
}>()

const modelValue = defineModel<boolean>({ default: true })
</script>

<template>
  <label
    class="flex items-start"
    :class="[
      compact ? 'gap-2 text-xs' : 'gap-3 text-sm',
      disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
    ]"
  >
    <input
      v-model="modelValue"
      type="checkbox"
      class="mt-0.5 shrink-0 accent-(--accent-color)"
      :class="compact ? 'size-4' : 'size-5'"
      :disabled="disabled"
    >
    <span>Normaliser l’extension selon le format réel détecté</span>
  </label>
</template>
