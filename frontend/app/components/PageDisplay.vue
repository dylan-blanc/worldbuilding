<script setup lang="ts">
import { EyeIcon, HeartIcon } from "@heroicons/vue/24/outline"
import { StarIcon } from "@heroicons/vue/24/solid"

type PublicPage = {
  id: number
  owner_user_id: number
  owner_username: string
  page_title: string
  page_status: "public" | "private" | "anonymous" | "banned"
  number_of_likes: number
  number_of_view: number
  number_of_followers: number
  page_description: string | null
  page_picture: string | null
  created_at: string
  updated_at: string
}

type PagesResponse = {
  pages: PublicPage[]
}

const config = useRuntimeConfig()
const route = useRoute()
const pages = ref<PublicPage[]>([])
const pending = ref(true)
const errorMessage = ref("")

function pagePicture(page: PublicPage): string | null {
  return page.page_picture || null
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

onMounted(() => {
  watch(apiQueryKey, fetchPages, { immediate: true })
})
</script>

<template>
  <section class="flex flex-col gap-4">
    <div
      v-if="pending"
      class="secondary-color flex min-h-48 items-center justify-center"
      aria-label="Chargement des pages"
    >
      <span
        class="h-10 w-10 animate-spin rounded-full border-4 border-current border-r-transparent"
        aria-hidden="true"
      ></span>
    </div>

    <div
      v-else-if="errorMessage"
      class="error-color flex min-h-48 items-center justify-center"
      aria-label="Erreur de chargement des pages"
    >
      <span
        class="h-10 w-10 animate-spin rounded-full border-4 border-current border-r-transparent"
        aria-hidden="true"
      ></span>
    </div>

    <p
      v-else-if="pages.length === 0"
      class="secondary-color flex min-h-48 items-center justify-center text-center"
    >
      Aucune page ne correspond aux filtres selectionnes.
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
        <img
          v-if="pagePicture(page)"
          :src="pagePicture(page) || undefined"
          :alt="page.page_title"
          class="block h-full w-full object-cover"
        >

        <div
          v-else
          class="flex h-full w-full items-center justify-center bg-(--third-background)"
          aria-hidden="true"
        >
          <span class="rotate-24 text-3xl font-medium text-(--primary-color)">IMAGE</span>
        </div>

        <div class="pointer-events-none absolute inset-0 p-4 opacity-0 transition-opacity duration-150 group-hover:opacity-100 group-focus-within:opacity-100">
          <div
            class="absolute right-3 top-2 flex flex-col items-center text-sm leading-none text-(--primary-color)"
            aria-label="Vote en attente"
          >
            <StarIcon class="h-7 w-7" aria-hidden="true" />
            <span>--</span>
          </div>

          <div class="absolute bottom-4 left-4 flex flex-col gap-2">
            <div :title="page.owner_username" class="flex items-center">
              <span class="flex h-10 w-10 items-center justify-center rounded-full bg-(--primary-color) text-base font-bold text-(--primary-background)">
                {{ page.owner_username.slice(0, 1).toUpperCase() }}
              </span>
              <span class="sr-only">{{ page.owner_username }}</span>
            </div>

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
