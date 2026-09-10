<script setup lang="ts">
withDefaults(defineProps<{
  label?: string
}>(), {
  label: "Chargement",
})
</script>

<template>
  <div
    class="secondary-color flex min-h-48 items-center justify-center"
    role="status"
    :aria-label="label"
  >
    <span
      class="h-10 w-10 animate-spin rounded-full border-4 border-current border-r-transparent"
      aria-hidden="true"
    ></span>
  </div>
</template>
