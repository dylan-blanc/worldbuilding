<!--
  This component loads and displays the public page cards used by app/views/accueil.vue.
  Data follows frontend -> GET /api/pages -> PageController::index() -> Page::findPublicCards()
  -> users/pages SQL join -> card response. UserAvatar resolves non-anonymous owner pictures.
  ModerationReportAction delegates reports to the shared composable and POST /api/pages/{id}/reports.
-->
<script setup lang="ts">
import { EyeIcon, HeartIcon, StarIcon as StarOutlineIcon } from "@heroicons/vue/24/outline"
import { StarIcon as StarSolidIcon } from "@heroicons/vue/24/solid"

type PublicPage = {
  id: number
  owner_user_id: number | null
  owner_username: string | null
  owner_picture: string | null
  page_title: string
  page_status: "public" | "private" | "banned"
  is_anonymous: boolean | number
  number_of_likes: number
  number_of_view: number
  number_of_followers: number
  number_of_favorites: number
  is_favorite: boolean | number
  page_description: string | null
  page_picture: string | null
  created_at: string
  updated_at: string
}

type PagesResponse = {
  pages: PublicPage[]
}

type FavoriteResponse = {
  is_favorite: boolean
  number_of_favorites: number
}

const config = useRuntimeConfig()
const route = useRoute()
const pages = ref<PublicPage[]>([])
const pending = ref(true)
const errorMessage = ref("")
const favoriteErrorMessage = ref("")
const favoritePending = reactive<Record<number, boolean>>({})
const apiFetch = useApi()
const { resolveUrl: resolvePagePicture } = usePagePicture()
const { renewSession } = useSessionActivity()

function pagePicture(page: PublicPage): string | null {
  return resolvePagePicture(page.id, page.page_picture) || null
}

const apiQuery = computed(() => {
  const query: Record<string, string> = {}
  const allowedParameters = [
    "theme_id",
    "category_id",
    "subcategory_id",
    "sort_by",
    "sort_order",
    "is_favorite",
    "ranking",
    "period",
    "feed",
  ]

  for (const parameter of allowedParameters) {
    const value = route.query[parameter]

    if (typeof value === "string" && value !== "") query[parameter] = value
  }

  return query
})

const apiQueryKey = computed(() => JSON.stringify(apiQuery.value))
let latestRequest = 0

async function fetchPages(): Promise<void> {
  const requestId = ++latestRequest
  pending.value = true
  errorMessage.value = ""

  try {
    const response = await $fetch<PagesResponse>(`${config.public.apiBase}/pages`, {
      query: apiQuery.value,
      credentials: "include",
    })

    if (requestId !== latestRequest) return

    pages.value = response.pages || []
  } catch {
    if (requestId !== latestRequest) return

    errorMessage.value = "Impossible de charger les pages"
  } finally {
    if (requestId === latestRequest) pending.value = false
  }
}

async function togglePageFavorite(page: PublicPage): Promise<void> {
  if (favoritePending[page.id]) return

  favoritePending[page.id] = true
  favoriteErrorMessage.value = ""

  try {
    const response = await apiFetch<FavoriteResponse>(`${config.public.apiBase}/pages/${page.id}/favorite`, {
      method: Boolean(page.is_favorite) ? "DELETE" : "POST",
    })

    page.is_favorite = response.is_favorite
    page.number_of_favorites = response.number_of_favorites

    if (!response.is_favorite && route.query.is_favorite === "1") {
      pages.value = pages.value.filter((listedPage) => listedPage.id !== page.id)
    }
  } catch (error: unknown) {
    const failure = error as { status?: number, statusCode?: number, response?: { status?: number } }
    const status = failure.status || failure.statusCode || failure.response?.status || 0

    favoriteErrorMessage.value = status === 401
      ? "Connectez-vous pour ajouter cette page aux favoris."
      : "Impossible de modifier ce favori."
  } finally {
    favoritePending[page.id] = false
  }
}

onMounted(() => {
  watch(apiQueryKey, fetchPages, { immediate: true })
})
</script>

<template>
  <section class="flex flex-col gap-4">
    <p v-if="favoriteErrorMessage" class="error-color text-center text-sm" role="alert">
      {{ favoriteErrorMessage }}
    </p>

    <LoadingSpinner
      v-if="pending"
      label="Chargement des pages"
    />

    <div
      v-else-if="errorMessage"
      class="flex min-h-48 flex-col items-center justify-center gap-4 text-center"
    >
      <p class="error-color">{{ errorMessage }}</p>
      <button type="button" class="button-primary rounded-md px-4 py-2" @click="fetchPages">
        Réessayer
      </button>
    </div>

    <p
      v-else-if="pages.length === 0"
      class="secondary-color flex min-h-48 items-center justify-center text-center"
    >
      Aucune page ne correspond aux filtres sélectionnés.
    </p>

    <div
      v-else
      class="grid w-full grid-cols-[repeat(3,minmax(0,330px))] justify-between gap-8 max-[1120px]:grid-cols-[repeat(2,minmax(0,330px))] max-[1120px]:justify-center max-[740px]:grid-cols-[minmax(0,330px)]"
    >
      <article
        v-for="page in pages"
        :key="page.id"
        class="group relative h-[410px] w-[310px] justify-self-center overflow-hidden bg-(--third-background) text-(--primary-color) transition-[height,width,box-shadow] duration-200 ease-in-out hover:h-[430px] hover:w-[330px] hover:shadow-xl focus-within:h-[430px] focus-within:w-[330px] focus-within:shadow-xl"
      >
        <NuxtLink
          :to="`/pageresult/${page.id}`"
          class="block size-full focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-(--focus-color)"
          :aria-label="`Lire ${page.page_title}`"
          @click="renewSession"
        >
          <img
            v-if="pagePicture(page)"
            :src="pagePicture(page) || undefined"
            :alt="page.page_title"
            class="block size-full object-cover"
          >

          <div
            v-else
            class="flex size-full items-center justify-center bg-(--third-background)"
            aria-hidden="true"
          >
            <span class="rotate-24 text-3xl font-medium text-(--primary-color)">IMAGE</span>
          </div>
        </NuxtLink>

        <ModerationReportAction
          class="pointer-events-none absolute right-3 top-3 z-20 opacity-0 transition-opacity duration-150 group-hover:pointer-events-auto group-hover:opacity-100 group-focus-within:pointer-events-auto group-focus-within:opacity-100"
          :page-id="page.id"
          content-type="page_display"
          :target-label="page.page_title"
          menu-label="Report this image"
        />

        <div class="pointer-events-none absolute inset-0 p-4 opacity-0 transition-opacity duration-150 group-hover:opacity-100 group-focus-within:opacity-100">
          <button
            type="button"
            class="pointer-events-auto absolute left-3 top-2 flex flex-col items-center text-sm leading-none text-(--primary-color) disabled:cursor-wait disabled:opacity-60"
            :disabled="favoritePending[page.id]"
            :aria-label="page.is_favorite ? `Retirer ${page.page_title} des favoris` : `Ajouter ${page.page_title} aux favoris`"
            :aria-pressed="Boolean(page.is_favorite)"
            @click.stop="togglePageFavorite(page)"
          >
            <StarSolidIcon v-if="page.is_favorite" class="h-7 w-7" aria-hidden="true" />
            <StarOutlineIcon v-else class="h-7 w-7" aria-hidden="true" />
            <span>{{ page.number_of_favorites }}</span>
          </button>

          <div class="absolute bottom-4 left-4 flex flex-col gap-2">
            <UserAvatar
              :picture="page.owner_picture"
              :username="page.owner_username"
              :page-id="page.id"
              source="page-owner"
              :anonymous="Boolean(page.is_anonymous)"
              size="md"
            />

            <h2 class="inline-block max-w-60 bg-(--primary-background) px-2 py-0.5 text-sm font-semibold leading-tight text-(--primary-color) [overflow-wrap:anywhere]">
              {{ page.page_title }}
            </h2>

            <div class="flex items-center gap-4 text-sm text-(--primary-color)">
              <span title="Vues" class="inline-flex items-center gap-1">
                <EyeIcon class="h-4 w-4" aria-hidden="true" />
                {{ page.number_of_view }}
              </span>
              <span title="Likes" class="inline-flex items-center gap-1">
                <HeartIcon class="h-4 w-4" aria-hidden="true" />
                {{ page.number_of_likes }}
              </span>
            </div>
          </div>
        </div>
      </article>
    </div>
  </section>
</template>
