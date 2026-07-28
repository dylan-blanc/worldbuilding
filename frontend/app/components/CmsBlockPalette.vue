<!--
  This component is the draggable CMS block palette opened by CmsToolbar in the free-page editor.
  It writes a typed block definition to DataTransfer; Freepage reads it on drop and updates content and layout separately.
  The later draft flow will send the resulting document through a frontend request to PHP and page_revision.
-->
<script setup lang="ts">
import {
  Bars3BottomLeftIcon,
  MinusIcon,
  PhotoIcon,
  PlayCircleIcon,
  QueueListIcon,
  RectangleGroupIcon,
  Squares2X2Icon,
} from "@heroicons/vue/24/outline"
import type { Component } from "vue"
import { CMS_BLOCK_MIME, type CmsBlockDefinition } from "~/types/cms"

interface PaletteBlock extends CmsBlockDefinition {
  icon: Component
}

const blocks: PaletteBlock[] = [
  { type: "section", label: "Zone", defaultWidth: 12, defaultHeight: 4, icon: Squares2X2Icon },
  { type: "text", label: "Texte", defaultWidth: 6, defaultHeight: 4, icon: Bars3BottomLeftIcon },
  { type: "image", label: "Image", defaultWidth: 4, defaultHeight: 5, icon: PhotoIcon },
  { type: "banner", label: "Bannière", defaultWidth: 12, defaultHeight: 3, icon: RectangleGroupIcon },
  { type: "gallery", label: "Galerie", defaultWidth: 8, defaultHeight: 5, icon: QueueListIcon },
  { type: "video", label: "Vidéo", defaultWidth: 8, defaultHeight: 6, icon: PlayCircleIcon },
  { type: "separator", label: "Séparateur", defaultWidth: 12, defaultHeight: 1, icon: MinusIcon },
]

const startDragging = (event: DragEvent, block: PaletteBlock) => {
  const data = JSON.stringify({
    type: block.type,
    label: block.label,
    defaultWidth: block.defaultWidth,
    defaultHeight: block.defaultHeight,
  } satisfies CmsBlockDefinition)

  event.dataTransfer?.setData(CMS_BLOCK_MIME, data)
  event.dataTransfer?.setData("text/plain", block.type)
  event.dataTransfer && (event.dataTransfer.effectAllowed = "copy")
}
</script>

<template>
  <aside
    class="primary-background primary-border absolute right-4 top-full z-40 mt-4 flex w-18 flex-col items-center gap-2 rounded-xl border p-2 shadow-xl"
    aria-label="Blocs à glisser dans la page"
  >
    <button
      v-for="block in blocks"
      :key="block.type"
      type="button"
      draggable="true"
      class="form-control flex size-13 cursor-grab items-center justify-center rounded-lg border transition hover:bg-(--secondary-background) active:cursor-grabbing"
      :aria-label="`Ajouter un bloc ${block.label}`"
      :title="block.label"
      @dragstart="startDragging($event, block)"
    >
      <component :is="block.icon" class="size-8" aria-hidden="true" />
    </button>
  </aside>
</template>
