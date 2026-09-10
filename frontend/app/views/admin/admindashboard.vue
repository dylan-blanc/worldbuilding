<!--
  This view provides the administration shell used by app/pages/adminpanel.vue.
  The route section selects a filter or moderation view while AdminDashboard stays in the left margin.
  All child views load protected API data after mounting so the Nuxt page remains compatible with SSG.
-->
<script setup lang="ts">
import AdminDashboard from "~/components/administration/AdminDashboard.vue"
import AdminFilter from "~/views/admin/adminfilter.vue"
import AdminModeration from "~/views/admin/adminmoderation.vue"
import AdminModerationFilter from "~/views/admin/adminmoderationfilter.vue"
import AdminModerationUsers from "~/views/admin/adminmoderationusers.vue"

type AdminSection = "filters" | "moderationfilters" | "moderation" | "moderationusers"

const route = useRoute()
const sections: AdminSection[] = ["filters", "moderationfilters", "moderation", "moderationusers"]
const currentSection = computed<AdminSection>(() => {
  const section = typeof route.query.section === "string" ? route.query.section : ""

  return sections.includes(section as AdminSection) ? section as AdminSection : "filters"
})
</script>

<template>
  <div class="primary-background primary-color flex min-h-screen flex-col">
    <Header />

    <main class="mx-auto grid w-full max-w-[96rem] flex-1 gap-6 px-4 py-8 lg:grid-cols-[16rem_minmax(0,1fr)] lg:items-start lg:py-12">
      <AdminDashboard :current-section="currentSection" />

      <div class="min-w-0">
        <h1 class="mb-6 text-3xl font-semibold">Page Admin</h1>
        <AdminFilter v-if="currentSection === 'filters'" />
        <AdminModerationFilter v-else-if="currentSection === 'moderationfilters'" />
        <AdminModeration v-else-if="currentSection === 'moderation'" />
        <AdminModerationUsers v-else />
      </div>
    </main>

    <Footer />
  </div>
</template>
