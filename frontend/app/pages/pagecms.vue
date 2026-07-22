<!--
  This page owns the CMS workspace shell used at /pagecms.
  It keeps Header, CmsToolbar and Footer mounted while the central creation view changes.
  The current flow is: Createpage start action -> local view state -> Freepage editor placeholder.
-->
<script setup lang="ts">
import CmsToolbar from "~/components/CmsToolbar.vue"
import Createpage from "~/views/createpage.vue"
import Freepage from "~/views/freepage.vue"

type CmsView = "create" | "free"

definePageMeta({
  middleware: "pagecms-auth",
})

const currentView = ref<CmsView>("create")
const isEditing = ref(true)

const activeView = computed(() => currentView.value === "free" ? Freepage : Createpage)

const startFreeEdition = () => {
  currentView.value = "free"
  isEditing.value = true
}
</script>

<template>
  <div class="primary-background primary-color flex min-h-screen flex-col">
    <Header />
    <CmsToolbar v-model:is-editing="isEditing" />

    <main class="flex w-full flex-1">
      <component :is="activeView" @start-free-edition="startFreeEdition" />
    </main>

    <Footer />
  </div>
</template>
