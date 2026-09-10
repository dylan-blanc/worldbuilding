<!--
  This component explains an administrator-removed CMS block only inside the /pagecms editor.
  CmsEditorBlock supplies the marked block and replacement editor/upload slot; text snapshots remain selectable
  with reduced opacity, while CmsResultBlock hides the same moderationRemoved block from published page output.
-->
<script setup lang="ts">
import type { CmsBlock } from "~/types/cms/cms"

const props = defineProps<{
  block: CmsBlock
}>()

const isText = computed(() => props.block.type === "text")
</script>

<template>
  <section class="cms-no-drag flex min-h-0 flex-1 flex-col overflow-auto border-2 border-(--warning-color) p-3 text-left">
    <p class="font-semibold text-(--warning-color)">
      Ce contenu a été supprimé par l’administration.
    </p>
    <p class="secondary-color mt-1 text-sm">
      Remplacez-le ou supprimez ce bloc avant de republier la page.
    </p>

    <div
      v-if="isText"
      class="primary-border mt-3 select-text rounded-md border p-3 opacity-45"
      aria-label="Ancien texte modéré, disponible pour copie"
      @click.capture.prevent
    >
      <CmsResultText :content="block.props.content ?? ''" />
    </div>

    <div class="primary-border mt-3 min-h-24 flex-1 border-t pt-3">
      <slot />
    </div>
  </section>
</template>
