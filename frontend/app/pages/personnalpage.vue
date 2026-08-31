<!--
  This page loads and updates every page owned by the authenticated user at /personnalpage.
  Its form data flows through /me/pages routes to PageController, then pages and page_filters SQL.
  Presentation images flow through POST /pages/{id}/picture with optional detected-type normalization before MinIO storage.
-->
<script setup lang="ts">
import Firstcreation from "~/views/cms/firstcreation.vue"
import Ownedpage from "~/views/cms/ownedpage.vue"
import type {
  OwnedPage,
  OwnedPageMessage,
  OwnedPageStatus,
} from "~/types/cms/owned-page"

type OwnedPagesResponse = {
  pages: OwnedPage[]
}

type OwnedPageResponse = {
  page: OwnedPage
}

const config = useRuntimeConfig()
const apiFetch = useApi()
const pages = ref<OwnedPage[]>([])
const pending = ref(true)
const loaded = ref(false)
const errorMessage = ref("")
const savingPageId = ref<number | null>(null)
const pageMessages = reactive<Record<number, OwnedPageMessage>>({})

function responseStatus(error: unknown): number | null {
  if (typeof error !== "object" || error === null) return null

  const candidate = error as { status?: number, statusCode?: number, response?: { status?: number } }

  return candidate.statusCode || candidate.status || candidate.response?.status || null
}

function errorText(error: unknown): string {
  if (typeof error !== "object" || error === null) return "Enregistrement impossible"

  const candidate = error as { data?: { error?: string } }

  return candidate.data?.error || "Enregistrement impossible"
}

// Send an asynchronous request with the session cookie to the /me/pages route.
// The route calls PageController::mine(), which gets the authenticated user's ID
// from the session and passes it to Page::findCardsByOwnerId(). The model selects that
// user's pages from the database, including their identity, status, visibility, metrics,
// description, picture, and timestamps, then the result and request state are exposed to the UI.
async function loadOwnedPages(): Promise<void> {
  if (!import.meta.client) return

  pending.value = true
  errorMessage.value = ""

  try {
    const response = await $fetch<OwnedPagesResponse>(`${config.public.apiBase}/me/pages`, {
      credentials: "include",
    })

    pages.value = response.pages || []
  } catch (error) {
    pages.value = []

    if (responseStatus(error) !== 401) {
      errorMessage.value = "Impossible de charger vos pages"
    }
  } finally {
    loaded.value = true
    pending.value = false
  }
}

// Save a page's visibility settings through the API, synchronize the returned page with
// the local list, and expose the request result to OwnedPageDisplay through explicit props.
async function saveOwnedPageSettings(
  id: number,
  pageStatus: Exclude<OwnedPageStatus, "banned">,
  isAnonymous: boolean,
  pageTitle: string,
  filterIds: number[],
): Promise<void> {
  savingPageId.value = id
  delete pageMessages[id]

  try {
    const response = await apiFetch<OwnedPageResponse>(
      `${config.public.apiBase}/me/pages/${id}/settings`,
      {
        method: "POST",
        credentials: "include",
        body: {
          page_status: pageStatus,
          is_anonymous: isAnonymous,
          page_title: pageTitle.trim(),
          filter_ids: filterIds,
        },
      },
    )
    const index = pages.value.findIndex(page => page.id === id)

    if (index !== -1) pages.value[index] = response.page

    pageMessages[id] = {
      type: "success",
      text: "Paramètres enregistrés",
    }
  } catch (error) {
    pageMessages[id] = {
      type: "error",
      text: errorText(error),
    }
  } finally {
    savingPageId.value = null
  }
}

async function uploadPagePicture(payload: { pageId: number, event: Event, normalizeImageType: boolean }): Promise<void> {
  const { pageId, event, normalizeImageType } = payload
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]

  if (!file || savingPageId.value !== null) return

  const formData = new FormData()
  formData.append("file", file)
  formData.append("normalize_image_type", normalizeImageType ? "1" : "0")
  savingPageId.value = pageId
  delete pageMessages[pageId]

  try {
    const response = await apiFetch<OwnedPageResponse>(`${config.public.apiBase}/pages/${pageId}/picture`, {
      method: "POST",
      credentials: "include",
      body: formData,
    })
    const index = pages.value.findIndex(page => page.id === pageId)
    index !== -1 && (pages.value[index] = response.page)
    pageMessages[pageId] = { type: "success", text: "Image de présentation enregistrée" }
  } catch (error) {
    pageMessages[pageId] = { type: "error", text: errorText(error) }
  } finally {
    savingPageId.value = null
    input.value = ""
  }
}

onMounted(loadOwnedPages)
</script>

<template>
  <div
    v-if="pending || !loaded"
    class="primary-background primary-color flex min-h-screen flex-col"
  >
    <Header />
    <main class="mx-auto flex w-full max-w-6xl flex-1 items-center justify-center px-4 py-12">
      <LoadingSpinner label="Vérification de vos pages" />
    </main>
    <Footer />
  </div>

  <div
    v-else-if="errorMessage"
    class="primary-background primary-color flex min-h-screen flex-col"
  >
    <Header />
    <main class="mx-auto flex w-full max-w-6xl flex-1 items-center justify-center px-4 py-12">
      <section class="flex flex-col items-center gap-4 text-center">
        <p class="error-color">{{ errorMessage }}</p>
        <button type="button" class="button-primary rounded-md px-4 py-2" @click="loadOwnedPages">
          Réessayer
        </button>
      </section>
    </main>
    <Footer />
  </div>

  <Ownedpage
    v-else-if="pages.length > 0"
    :pages="pages"
    :saving-page-id="savingPageId"
    :page-messages="pageMessages"
    @save="saveOwnedPageSettings"
    @upload-picture="uploadPagePicture"
  />
  <Firstcreation v-else />
</template>
