<!--
  This component controls the administration section shown by views/admindashboard.vue.
  Selection follows button -> /adminpanel?section=filters|moderation -> route-driven view rendering.
-->
<script setup lang="ts">
type AdminSection = "filters" | "moderation"

defineProps<{
  currentSection: AdminSection
}>()

const route = useRoute()
const router = useRouter()

const selectSection = async (section: AdminSection) => {
  await router.push({
    path: "/adminpanel",
    query: {
      ...route.query,
      section,
    },
  })
}
</script>

<template>
  <nav class="secondary-background primary-border grid gap-3 rounded-xl border p-3 sm:grid-cols-2" aria-label="Sections administratives">
    <button
      type="button"
      class="min-h-12 rounded-md px-5 py-3 text-sm font-semibold uppercase transition focus:outline-none focus:ring-2"
      :class="currentSection === 'filters' ? 'button-primary' : 'form-control border'"
      :aria-pressed="currentSection === 'filters'"
      @click="selectSection('filters')"
    >
      Filtres
    </button>
    <button
      type="button"
      class="min-h-12 rounded-md px-5 py-3 text-sm font-semibold uppercase transition focus:outline-none focus:ring-2"
      :class="currentSection === 'moderation' ? 'button-primary' : 'form-control border'"
      :aria-pressed="currentSection === 'moderation'"
      @click="selectSection('moderation')"
    >
      Modération
    </button>
  </nav>
</template>
