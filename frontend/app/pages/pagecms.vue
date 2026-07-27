<!--
  This page owns the CMS workspace shell used at /pagecms.
  It keeps Header, CmsToolbar and Footer mounted while the central creation view changes.
  The current flow is: Createpage start action -> private SQL page -> Freepage editor and its history API.
-->
<script setup lang="ts">
import CmsToolbar from "~/components/CmsToolbar.vue"
import Createpage from "~/views/createpage.vue"
import Freepage from "~/views/freepage.vue"

type CmsView = "create" | "free"

definePageMeta({
  middleware: "pagecms-auth",
})

type CreatedPageResponse = {
  page: {
    id: number
  }
}

const route = useRoute()
const router = useRouter()
const config = useRuntimeConfig()
const requestedPageId = Number(route.query.page)
const pageId = ref<number | null>(Number.isInteger(requestedPageId) && requestedPageId > 0 ? requestedPageId : null)
const currentView = ref<CmsView>(pageId.value ? "free" : "create")
const isEditing = ref(true)
const isStarting = ref(false)
const startError = ref("")
const freepage = ref<InstanceType<typeof Freepage> | null>(null)
const canUndo = ref(false)
const canRedo = ref(false)

const errorText = (error: unknown) => {
  if (typeof error !== "object" || error === null) return "Creation de la page impossible"

  return (error as { data?: { error?: string } }).data?.error || "Creation de la page impossible"
}

const updateHistoryState = (state: { canUndo: boolean, canRedo: boolean }) => {
  canUndo.value = state.canUndo
  canRedo.value = state.canRedo
}

// Create the private SQL page before opening Freepage so every autosave has a stable page ID.
// The request flows through POST /pages -> PageController::create() -> Page::create() -> pages + page_revision.
const startFreeEdition = async () => {
  isStarting.value = true
  startError.value = ""

  try {
    const response = await $fetch<CreatedPageResponse>(`${config.public.apiBase}/pages`, {
      method: "POST",
      credentials: "include",
      body: {
        page_title: "Page sans titre",
      },
    })

    pageId.value = response.page.id
    currentView.value = "free"
    isEditing.value = true
    await router.replace({ query: { ...route.query, page: response.page.id } })
  } catch (error) {
    startError.value = errorText(error)
  } finally {
    isStarting.value = false
  }
}
</script>

<template>
  <div class="primary-background primary-color flex min-h-screen flex-col">
    <Header />
    <CmsToolbar
      v-model:is-editing="isEditing"
      :block-palette-enabled="currentView === 'free'"
      :can-undo="currentView === 'free' && canUndo"
      :can-redo="currentView === 'free' && canRedo"
      @undo="freepage?.undo()"
      @redo="freepage?.redo()"
    />

    <main class="flex w-full flex-1">
      <Createpage
        v-if="currentView === 'create'"
        :is-starting="isStarting"
        :error-message="startError"
        @start-free-edition="startFreeEdition"
      />
      <Freepage
        v-else-if="pageId"
        ref="freepage"
        :page-id="pageId"
        @history-state="updateHistoryState"
      />
    </main>

    <Footer />
  </div>
</template>
