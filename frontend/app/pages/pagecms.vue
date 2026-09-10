<!--
  This page owns the CMS workspace shell used at /pagecms.
  It keeps Header, CmsToolbar and Footer mounted while the central creation view and responsive mode change.
  The current flow is: Createpage start action -> private SQL page -> Freepage editor/preview and its history API.
  A client-only 1200 px viewport check enables the combined tablet/mobile workspace without affecting Nuxt SSG.
-->
<script setup lang="ts">
import CmsToolbar from "~/components/cms/CmsToolbar.vue"
import Createpage from "~/views/cms/createpage.vue"
import Freepage from "~/views/cms/freepage.vue"
import type { CmsWorkspaceViewportMode } from "~/types/cms/cms"

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
const apiFetch = useApi()
const requestedPageId = Number(route.query.page)
const pageId = ref<number | null>(Number.isInteger(requestedPageId) && requestedPageId > 0 ? requestedPageId : null)
const currentView = ref<CmsView>(pageId.value ? "free" : "create")
const isEditing = ref(true)
const isStarting = ref(false)
const startError = ref("")
const freepage = ref<InstanceType<typeof Freepage> | null>(null)
const canUndo = ref(false)
const canRedo = ref(false)
const viewportMode = ref<CmsWorkspaceViewportMode>("desktop")
const combinedResponsiveEnabled = ref(false)
const combinedResponsiveMinimumWidth = 1200

// Wide workspaces replace separate tablet/mobile controls with one side-by-side mode.
const updateCombinedResponsiveAvailability = () => {
  const isEnabled = window.innerWidth >= combinedResponsiveMinimumWidth

  if (combinedResponsiveEnabled.value === isEnabled) return

  combinedResponsiveEnabled.value = isEnabled
  viewportMode.value = "desktop"
}

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
    const response = await apiFetch<CreatedPageResponse>(`${config.public.apiBase}/pages`, {
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

onMounted(() => {
  updateCombinedResponsiveAvailability()
  window.addEventListener("resize", updateCombinedResponsiveAvailability)
})

onBeforeUnmount(() => window.removeEventListener("resize", updateCombinedResponsiveAvailability))
</script>

<template>
  <div class="primary-background primary-color flex min-h-screen flex-col">
    <Header />
    <CmsToolbar
      v-model:is-editing="isEditing"
      v-model:viewport-mode="viewportMode"
      :block-palette-enabled="currentView === 'free'"
      :combined-responsive-enabled="combinedResponsiveEnabled"
      :can-undo="currentView === 'free' && canUndo"
      :can-redo="currentView === 'free' && canRedo"
      @undo="freepage?.undo()"
      @redo="freepage?.redo()"
      @add-block="freepage?.addBlockFromPalette($event)"
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
        :is-editing="isEditing"
        :combined-responsive-enabled="combinedResponsiveEnabled"
        :viewport-mode="viewportMode"
        @history-state="updateHistoryState"
      />
    </main>

    <Footer />
  </div>
</template>
