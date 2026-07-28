<!--
  This page provides the published CMS reader shell.
  Nuxt exposes the technical /pagecmsresult/:id route under the canonical /pageresult/:id alias;
  Pageresult performs the authenticated client-side JSON fetch while Header and Footer remain static.
-->
<script setup lang="ts">
import Pageresult from "~/views/pageresult.vue"

definePageMeta({
  alias: ["/pageresult/:id"],
})

const route = useRoute()
const pageId = computed(() => {
  const value = Number(route.params.id)

  return Number.isInteger(value) && value > 0 ? value : null
})

useHead({
  title: "Lecture d’une page",
})
</script>

<template>
  <div class="primary-background primary-color flex min-h-screen flex-col">
    <Header />

    <main class="flex w-full flex-1">
      <Pageresult v-if="pageId" :page-id="pageId" />
      <p v-else class="error-color m-auto p-8 text-center">Identifiant de page invalide.</p>
    </main>

    <Footer />
  </div>
</template>
