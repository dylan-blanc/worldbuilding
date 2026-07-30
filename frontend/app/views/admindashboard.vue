<!--
  This view provides the administration dashboard shell used by app/pages/adminpanel.vue.
  Access follows /adminpanel -> Nginx/PHP role authorization -> admin-auth middleware.
  AdminDashboard updates the section query, then this shell renders AdminFilter or AdminModeration.
-->
<script setup lang="ts">
import AdminDashboard from "~/components/Admin/AdminDashboard.vue"
import AdminFilter from "~/views/adminfilter.vue"
import AdminModeration from "~/views/adminmoderation.vue"

const route = useRoute()
const currentSection = computed<"filters" | "moderation">(() => (
  route.query.section === "moderation" ? "moderation" : "filters"
))
</script>

<template>
  <div class="primary-background primary-color flex min-h-screen flex-col">
    <Header />

    <main class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-12">
      <h1 class="text-3xl font-semibold">Page Admin</h1>
      <AdminDashboard :current-section="currentSection" />
      <AdminFilter v-if="currentSection === 'filters'" />
      <AdminModeration v-else />
    </main>

    <Footer />
  </div>
</template>
