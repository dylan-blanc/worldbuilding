<script setup lang="ts">
const currentYear = new Date().getFullYear()
</script>

<template>
  <footer class="border-t border-slate-200 bg-white">
    <div class="mx-auto flex max-w-6xl items-center justify-center px-4 py-4 text-sm text-slate-600">
      <span>{{ currentYear }}</span>
      <span class="ml-1" aria-label="Copyright">&copy;</span>
    </div>
  </footer>
</template>
