<script setup lang="ts">
type PublicPage = {
  id: number
  owner_user_id: number
  owner_username: string
  page_title: string
  page_status: "public" | "private" | "anonymous" | "banned"
  number_of_likes: number
  number_of_followers: number
  page_description: string | null
  page_picture: string | null
  created_at: string
}

type PagesResponse = {
  pages: PublicPage[]
}

const config = useRuntimeConfig()
const pages = ref<PublicPage[]>([])
const pending = ref(true)
const errorMessage = ref("")

function pagePicture(page: PublicPage): string | null {
  return page.page_picture || null
}

onMounted(async () => {
  try {
    const response = await $fetch<PagesResponse>(`${config.public.apiBase}/pages`)
    pages.value = response.pages || []
  } catch {
    errorMessage.value = "Impossible de charger les pages"
  } finally {
    pending.value = false
  }
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
            <svg
              class="h-7 w-7 fill-current"
              viewBox="0 0 24 24"
              aria-hidden="true"
            >
              <path d="M12 2l2.9 6.4 7 .8-5.2 4.8 1.4 6.9-6.1-3.5-6.1 3.5 1.4-6.9-5.2-4.8 7-.8L12 2z" />
            </svg>
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
              <span title="Followers" class="inline-flex items-center gap-1">
                <svg
                  class="h-4 w-4 fill-none stroke-current stroke-2"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z" />
                  <circle cx="12" cy="12" r="3" />
                </svg>
                {{ page.number_of_followers }}
              </span>
              <span title="Likes" class="inline-flex items-center gap-1">
                <svg
                  class="h-4 w-4 fill-none stroke-current stroke-2"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z" />
                </svg>
                {{ page.number_of_likes }}
              </span>
            </div>
          </div>
        </div>
      </article>
    </div>
  </section>
</template>
