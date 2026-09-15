<!--
  This component loads and displays the public page cards used by app/views/accueil.vue.
  Data follows frontend -> GET /api/pages -> PageController::index() -> Page::findPublicCards()
  -> users/pages/engagement SQL queries -> card response. Like and favorite clicks follow frontend ->
  POST or DELETE /api/pages/{id}/{engagement} -> PageEngagementController -> PageEngagement -> SQL.
  UserAvatar resolves non-anonymous owner pictures.
  ModerationReportAction delegates reports to the shared composable and POST /api/pages/{id}/reports.
-->
<script setup lang="ts">
import { EyeIcon, HeartIcon as HeartOutlineIcon, StarIcon as StarOutlineIcon } from "@heroicons/vue/24/outline"
import { HeartIcon as HeartSolidIcon } from "@heroicons/vue/24/solid"

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
  is_liked: boolean | number
  is_following: boolean | number
  is_favorite: boolean | number
  page_description: string | null
  page_picture: string | null
  created_at: string
  updated_at: string
}

type PagesResponse = {
  pages: PublicPage[]
}

type EngagementResponse = {
  is_liked: boolean
  is_following: boolean
  is_favorite: boolean
  number_of_likes: number
  number_of_followers: number
  number_of_favorites: number
}

const config = useRuntimeConfig()
const route = useRoute()
const pages = ref<PublicPage[]>([])
const pending = ref(true)
const errorMessage = ref("")
const engagementErrorMessage = ref("")
const engagementPending = reactive<Record<number, boolean>>({})
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
  if (engagementPending[page.id]) return

  engagementPending[page.id] = true
  engagementErrorMessage.value = ""

  try {
    const response = await apiFetch<EngagementResponse>(`${config.public.apiBase}/pages/${page.id}/favorite`, {
      method: Boolean(page.is_favorite) ? "DELETE" : "POST",
    })

    applyEngagementResponse(page, response)

    if (!response.is_favorite && route.query.is_favorite === "1") {
      pages.value = pages.value.filter((listedPage) => listedPage.id !== page.id)
    }
  } catch (error: unknown) {
    const failure = error as { status?: number, statusCode?: number, response?: { status?: number } }
    const status = failure.status || failure.statusCode || failure.response?.status || 0

    engagementErrorMessage.value = status === 401
      ? "Connectez-vous pour ajouter cette page aux favoris."
      : "Impossible de modifier ce favori."
  } finally {
    engagementPending[page.id] = false
  }
}

async function togglePageLike(page: PublicPage): Promise<void> {
  if (engagementPending[page.id]) return

  engagementPending[page.id] = true
  engagementErrorMessage.value = ""

  try {
    const response = await apiFetch<EngagementResponse>(`${config.public.apiBase}/pages/${page.id}/like`, {
      method: Boolean(page.is_liked) ? "DELETE" : "POST",
    })

    applyEngagementResponse(page, response)
  } catch (error: unknown) {
    const failure = error as { status?: number, statusCode?: number, response?: { status?: number } }
    const status = failure.status || failure.statusCode || failure.response?.status || 0

    engagementErrorMessage.value = status === 401
      ? "Connectez-vous pour aimer cette page."
      : status === 409
        ? "Retirez cette page des favoris avant de retirer son like."
        : "Impossible de modifier ce like."
  } finally {
    engagementPending[page.id] = false
  }
}

function applyEngagementResponse(page: PublicPage, response: EngagementResponse): void {
  page.is_liked = response.is_liked
  page.is_following = response.is_following
  page.is_favorite = response.is_favorite
  page.number_of_likes = response.number_of_likes
  page.number_of_followers = response.number_of_followers
  page.number_of_favorites = response.number_of_favorites
}

onMounted(() => {
  watch(apiQueryKey, fetchPages, { immediate: true })
})
</script>

<template>
  <section class="flex flex-col gap-4">
    <p v-if="engagementErrorMessage" class="error-color text-center text-sm" role="alert">
      {{ engagementErrorMessage }}
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
            :disabled="engagementPending[page.id]"
            :aria-label="page.is_favorite ? `Retirer ${page.page_title} des favoris` : `Ajouter ${page.page_title} aux favoris`"
            :aria-pressed="Boolean(page.is_favorite)"
            @click.stop="togglePageFavorite(page)"
          >
            <svg
              v-if="page.is_favorite"
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 24 24"
              class="h-7 w-7"
              aria-hidden="true"
            >
              <defs>
                <linearGradient :id="`favorite-gradient-${page.id}`" x1="0" y1="0" x2="1" y2="1">
                  <stop offset="0%" class="[stop-color:#fde047]" />
                  <stop offset="100%" class="[stop-color:#f59e0b]" />
                </linearGradient>
              </defs>
              <path
                fill-rule="evenodd"
                :fill="`url(#favorite-gradient-${page.id})`"
                d="M10.788 3.21c.448-1.077 1.976-1.077 2.424 0l2.082 5.006 5.404.434c1.164.093 1.636 1.545.749 2.305l-4.117 3.527 1.257 5.273c.271 1.136-.964 2.033-1.96 1.425L12 18.354 7.373 21.18c-.996.608-2.231-.29-1.96-1.425l1.257-5.273-4.117-3.527c-.887-.76-.415-2.212.749-2.305l5.404-.434 2.082-5.005Z"
                clip-rule="evenodd"
              />
            </svg>
            <StarOutlineIcon v-else class="h-7 w-7" aria-hidden="true" />
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
              <button
                type="button"
                class="pointer-events-auto inline-flex items-center gap-1 disabled:cursor-not-allowed disabled:opacity-70"
                :disabled="engagementPending[page.id] || Boolean(page.is_favorite && page.is_liked)"
                :title="page.is_favorite && page.is_liked ? 'Retirez d’abord cette page des favoris' : 'Likes'"
                :aria-label="page.is_favorite && page.is_liked
                  ? `Retirez d’abord ${page.page_title} des favoris pour enlever son like`
                  : page.is_liked
                    ? `Retirer le like de ${page.page_title}`
                    : `Aimer ${page.page_title}`"
                :aria-pressed="Boolean(page.is_liked)"
                @click.stop="togglePageLike(page)"
              >
                <HeartSolidIcon v-if="page.is_liked" class="h-4 w-4" aria-hidden="true" />
                <HeartOutlineIcon v-else class="h-4 w-4" aria-hidden="true" />
                {{ page.number_of_likes }}
              </button>
            </div>
          </div>
        </div>
      </article>
    </div>
  </section>
</template>
