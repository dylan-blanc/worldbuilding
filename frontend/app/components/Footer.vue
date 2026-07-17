<script setup lang="ts">
const currentYear = new Date().getFullYear()
</script>

<template>
  <footer class="footer-shell border-t">
    <div class="footer-content mx-auto flex max-w-6xl items-left justify-left px-4 py-4 text-sm">
      <span>{{ currentYear }}</span>
      <span class="ml-1" aria-label="Copyright">&copy;</span>
    </div>
  </footer>
</template>

<style scoped>
.footer-shell { background: var(--primary-background); border-color: var(--primary-border); }
.footer-content { color: var(--secondary-color); }
</style>
