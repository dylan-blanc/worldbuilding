<script setup lang="ts">
import {
  CalendarDaysIcon,
  EyeIcon,
  HeartIcon,
  UserGroupIcon,
} from "@heroicons/vue/24/outline"
import type {
  OwnedPage,
  OwnedPageMessage,
  OwnedPageStatus,
} from "~/types/owned-page"

type PageSettings = {
  page_status: Exclude<OwnedPageStatus, "banned">
  is_anonymous: boolean
}

const props = defineProps<{
  pages: OwnedPage[]
  savingPageId: number | null
  pageMessages: Record<number, OwnedPageMessage>
}>()

const emit = defineEmits<{
  save: [{ id: number, pageStatus: Exclude<OwnedPageStatus, "banned">, isAnonymous: boolean }]
}>()

const settings = reactive<Record<number, PageSettings>>({})

watch(() => props.pages, currentPages => {
  for (const page of currentPages) {
    settings[page.id] = {
      page_status: page.page_status === "public" ? "public" : "private",
      is_anonymous: page.is_anonymous,
    }
  }
}, { immediate: true, deep: true })

function statusLabel(status: OwnedPageStatus): string {
  return {
    public: "Publique",
    private: "Privée",
    banned: "Bannie",
  }[status]
}

function statusClass(status: OwnedPageStatus): string {
  return {
    public: "success-color",
    private: "secondary-color",
    banned: "error-color",
  }[status]
}

function formatDate(value: string): string {
  return new Intl.DateTimeFormat("fr-FR", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value))
}

function saveSettings(page: OwnedPage): void {
  if (page.page_status === "banned" || !settings[page.id]) return

  emit("save", {
    id: page.id,
    pageStatus: settings[page.id].page_status,
    isAnonymous: settings[page.id].is_anonymous,
  })
}
</script>

<template>
  <section class="grid gap-6 lg:grid-cols-2">
    <article
      v-for="page in pages"
      :key="page.id"
      class="primary-border overflow-hidden rounded-lg border"
    >
      <div class="relative aspect-[16/9] w-full overflow-hidden third-background">
        <img
          v-if="page.page_picture"
          :src="page.page_picture"
          :alt="page.page_title"
          class="h-full w-full object-cover"
        >
        <div v-else class="secondary-color flex h-full items-center justify-center text-lg">
          Aucune image
        </div>

        <span
          class="primary-background absolute left-3 top-3 rounded-md border px-3 py-1 text-sm font-semibold primary-border"
          :class="statusClass(page.page_status)"
        >
          {{ statusLabel(page.page_status) }}
        </span>
      </div>

      <div class="flex flex-col gap-5 p-5">
        <div>
          <div class="flex flex-wrap items-start justify-between gap-2">
            <h2 class="text-xl font-semibold [overflow-wrap:anywhere]">{{ page.page_title }}</h2>
            <span class="secondary-color text-sm">ID {{ page.id }}</span>
          </div>
          <p class="secondary-color mt-2 min-h-12 text-sm">
            {{ page.page_description || "Aucune description renseignée." }}
          </p>
        </div>

        <dl class="grid grid-cols-3 gap-3 text-sm">
          <div class="flex items-center gap-2" title="Vues">
            <EyeIcon class="h-5 w-5 secondary-color" aria-hidden="true" />
            <div><dt class="sr-only">Vues</dt><dd>{{ page.number_of_view }}</dd></div>
          </div>
          <div class="flex items-center gap-2" title="Likes">
            <HeartIcon class="h-5 w-5 secondary-color" aria-hidden="true" />
            <div><dt class="sr-only">Likes</dt><dd>{{ page.number_of_likes }}</dd></div>
          </div>
          <div class="flex items-center gap-2" title="Abonnés">
            <UserGroupIcon class="h-5 w-5 secondary-color" aria-hidden="true" />
            <div><dt class="sr-only">Abonnés</dt><dd>{{ page.number_of_followers }}</dd></div>
          </div>
        </dl>

        <dl class="secondary-color grid gap-2 text-sm sm:grid-cols-2">
          <div class="flex items-start gap-2">
            <CalendarDaysIcon class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <div><dt>Créée</dt><dd class="primary-color">{{ formatDate(page.created_at) }}</dd></div>
          </div>
          <div class="flex items-start gap-2">
            <CalendarDaysIcon class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <div><dt>Modifiée</dt><dd class="primary-color">{{ formatDate(page.updated_at) }}</dd></div>
          </div>
        </dl>

        <form
          class="secondary-background primary-border flex flex-col gap-4 border-t p-4"
          @submit.prevent="saveSettings(page)"
        >
          <fieldset
            class="grid gap-3 sm:grid-cols-2"
            :disabled="page.page_status === 'banned' || savingPageId === page.id"
          >
            <label class="flex cursor-pointer items-center gap-3 text-sm">
              <input
                v-model="settings[page.id].page_status"
                type="checkbox"
                true-value="public"
                false-value="private"
                class="h-5 w-5 accent-(--accent-color)"
              >
              Page publique
            </label>

            <label class="flex cursor-pointer items-center gap-3 text-sm">
              <input
                v-model="settings[page.id].is_anonymous"
                type="checkbox"
                class="h-5 w-5 accent-(--accent-color)"
              >
              Publication anonyme
            </label>
          </fieldset>

          <p v-if="page.page_status === 'banned'" class="error-color text-sm">
            Les paramètres d'une page bannie ne peuvent pas être modifiés.
          </p>

          <p
            v-else-if="pageMessages[page.id]"
            class="text-sm"
            :class="pageMessages[page.id].type === 'success' ? 'success-color' : 'error-color'"
          >
            {{ pageMessages[page.id].text }}
          </p>

          <button
            type="submit"
            class="button-primary self-start rounded-md px-4 py-2 text-sm font-medium disabled:cursor-not-allowed"
            :disabled="page.page_status === 'banned' || savingPageId === page.id"
          >
            {{ savingPageId === page.id ? "Enregistrement..." : "Enregistrer" }}
          </button>
        </form>
      </div>
    </article>
  </section>
</template>
