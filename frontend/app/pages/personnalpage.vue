<script setup lang="ts">
import Firstcreation from "~/views/firstcreation.vue"
import Ownedpage from "~/views/ownedpage.vue"
import type {
  OwnedPage,
  OwnedPageMessage,
  OwnedPageStatus,
} from "~/types/owned-page"

type OwnedPagesResponse = {
  pages: OwnedPage[]
}

type OwnedPageResponse = {
  page: OwnedPage
}

const config = useRuntimeConfig()
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

// Send an asynchronous request with the session cookie to the /me/owned-pages route.
// The route calls OwnedPageController::index(), which gets the authenticated user's ID
// from the session and passes it to OwnedPage::findByOwnerId(). The model selects that
// user's pages from the database, including their identity, status, visibility, metrics,
// description, picture, and timestamps, then the result and request state are exposed to the UI.
async function loadOwnedPages(): Promise<void> {
  if (!import.meta.client) return

  pending.value = true
  errorMessage.value = ""

  try {
    const response = await $fetch<OwnedPagesResponse>(`${config.public.apiBase}/me/owned-pages`, {
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
): Promise<void> {
  savingPageId.value = id
  delete pageMessages[id]

  try {
    const response = await $fetch<OwnedPageResponse>(
      `${config.public.apiBase}/me/owned-pages/${id}/settings`,
      {
        method: "POST",
        credentials: "include",
        body: {
          page_status: pageStatus,
          is_anonymous: isAnonymous,
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
  />
  <Firstcreation v-else />
</template>
