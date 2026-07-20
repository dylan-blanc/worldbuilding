<script setup lang="ts">
import type {
  OwnedPage,
  OwnedPageMessage,
  OwnedPageStatus,
} from "~/types/owned-page"

defineProps<{
  pages: OwnedPage[]
  savingPageId: number | null
  pageMessages: Record<number, OwnedPageMessage>
}>()

const emit = defineEmits<{
  save: [id: number, pageStatus: Exclude<OwnedPageStatus, "banned">, isAnonymous: boolean]
}>()
</script>

<template>
  <div class="primary-background primary-color flex min-h-screen flex-col">
    <Header />

    <main class="mx-auto flex w-full max-w-6xl flex-1 px-4 py-12">
      <section class="flex w-full flex-col gap-6">
        <div>
          <h1 class="text-3xl font-semibold">Mes pages</h1>
          <p class="secondary-color mt-2">Gérez la visibilité et les informations de vos univers.</p>
        </div>

        <OwnedPageDisplay
          :pages="pages"
          :saving-page-id="savingPageId"
          :page-messages="pageMessages"
          @save="emit('save', $event.id, $event.pageStatus, $event.isAnonymous)"
        />
      </section>
    </main>

    <Footer />
  </div>
</template>
