<!--
  This view presents the two entry points of the CMS creation flow inside /pagecms.
  The free-edition button emits to pagecms.vue, which replaces this view with Freepage.
  Preset and layout choices stay in CmsToolbar and will feed the editor in a later iteration.
-->
<script setup lang="ts">
import { ArrowUpIcon } from "@heroicons/vue/24/solid"

const emit = defineEmits<{
  startFreeEdition: []
}>()
</script>

<template>
  <section class="mx-auto grid w-full max-w-6xl flex-1 items-center gap-10 px-6 py-14 md:grid-cols-[1fr_auto_1fr] md:px-10">
    <div class="flex justify-center">
      <button
        type="button"
        class="form-control rounded-xl border px-8 py-3 text-xl font-medium shadow-sm transition hover:bg-(--secondary-background) focus:outline-none focus:ring-2"
        @click="emit('startFreeEdition')"
      >
        Édition libre
      </button>
    </div>

    <p class="text-center text-3xl font-semibold underline underline-offset-8">ou</p>

    <div class="flex flex-col items-center gap-3 text-center">
      <ArrowUpIcon class="size-20" aria-hidden="true" />
      <p class="max-w-56 text-3xl leading-snug">Choisissez un Preset</p>
    </div>
  </section>
</template>
