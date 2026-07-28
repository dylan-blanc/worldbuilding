<!--
  This component renders the editable chrome and content of one Freepage CMS block.
  Freepage supplies media URLs and handles deletion/upload; new image uploads also lock their grid item to the natural media ratio.
  Image controls overlay the media so the image occupies the same complete block area as the published CmsResultBlock output.
  Text changes flow through CmsTextBlockEditor into the draft JSON.
  Section blocks expose a slot where Freepage mounts their nested Grid Layout Plus container.
-->
<script setup lang="ts">
import {
  Bars3Icon,
  PhotoIcon,
  PlayCircleIcon,
  TrashIcon,
} from "@heroicons/vue/24/outline"
import type { CmsBlock, CmsJsonValue } from "~/types/cms"

const props = defineProps<{
  block: CmsBlock
  mediaUrl: string | null
  uploading: boolean
  dragHandleClass: "cms-root-drag-handle" | "cms-child-drag-handle"
}>()

const emit = defineEmits<{
  remove: [blockId: string]
  uploadMedia: [blockId: string, event: Event]
  updateContent: [blockId: string, value: CmsJsonValue]
  historyBoundary: []
}>()

const label = computed(() => String(props.block.props.label || "Bloc"))
const objectKey = computed(() => String(props.block.props.objectKey || ""))
const isImageMedia = computed(() => ["image", "banner", "gallery"].includes(props.block.type))
const numericProperty = (value: CmsJsonValue | undefined) => (
  typeof value === "number" || typeof value === "string"
    ? Number(value) || undefined
    : undefined
)
const naturalWidth = computed(() => numericProperty(props.block.props.width))
const naturalHeight = computed(() => numericProperty(props.block.props.height))
</script>

<template>
  <article
    class="secondary-background primary-border relative flex size-full min-h-0 flex-col overflow-hidden rounded-lg border text-center shadow-sm"
    :data-cms-block-id="block.id"
  >
    <header
      class="primary-border flex h-9 shrink-0 cursor-move items-center justify-between gap-2 border-b px-2"
      :class="[
        props.dragHandleClass,
        objectKey && isImageMedia ? 'secondary-background absolute inset-x-0 top-0 z-10' : '',
      ]"
    >
      <span class="flex min-w-0 items-center gap-2 text-xs font-medium">
        <Bars3Icon class="size-4 shrink-0" aria-hidden="true" />
        <span class="truncate">{{ label }}</span>
      </span>
      <button
        type="button"
        class="cms-no-drag error-color rounded p-1 transition hover:bg-(--third-background)"
        :aria-label="`Supprimer ${label}`"
        :title="block.type === 'section' ? 'Supprimer la zone et tous ses blocs' : 'Supprimer le bloc'"
        @click.stop="emit('remove', block.id)"
      >
        <TrashIcon class="size-5" />
      </button>
    </header>

    <div v-if="block.type === 'section'" class="min-h-0 flex-1">
      <slot name="section" />
    </div>

    <CmsTextBlockEditor
      v-else-if="block.type === 'text'"
      :model-value="block.props.content ?? ''"
      @history-boundary="emit('historyBoundary')"
      @update:model-value="emit('updateContent', block.id, $event)"
    />

    <div v-else-if="objectKey && block.type !== 'video'" class="flex size-full min-h-0 flex-1 overflow-hidden">
      <img
        :src="mediaUrl || undefined"
        :alt="label"
        :width="naturalWidth"
        :height="naturalHeight"
        class="size-full object-fill"
      >
    </div>

    <video
      v-else-if="objectKey && block.type === 'video'"
      :src="mediaUrl || undefined"
      class="min-h-0 w-full flex-1 object-contain"
      controls
      preload="metadata"
    />

    <label
      v-else-if="block.type === 'image' || block.type === 'banner' || block.type === 'gallery' || block.type === 'video'"
      class="cms-no-drag flex min-h-0 flex-1 cursor-pointer flex-col items-center justify-center gap-2 p-3"
    >
      <PlayCircleIcon v-if="block.type === 'video'" class="size-12" />
      <PhotoIcon v-else class="size-12" />
      <span class="text-sm">{{ uploading ? "Validation…" : "Importer un média" }}</span>
      <input
        type="file"
        class="sr-only"
        :accept="block.type === 'video' ? 'video/mp4,video/webm' : 'image/jpeg,image/png,image/webp,image/avif,image/gif'"
        :disabled="uploading"
        @change="emit('uploadMedia', block.id, $event)"
      >
    </label>

    <div v-else-if="block.type === 'separator'" class="flex min-h-0 flex-1 items-center px-3">
      <span class="primary-border w-full border-t" />
    </div>

    <div v-else class="flex min-h-0 flex-1 items-center justify-center p-3">
      <span class="font-medium">{{ label }}</span>
    </div>
  </article>
</template>
