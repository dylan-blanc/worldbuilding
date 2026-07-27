<!--
  This component renders one published CMS block without editor controls.
  Pageresult supplies pages.pagecontent; text goes to read-only Tiptap and media URLs.
  Image, banner and gallery media fill their grid item, whose ratio is locked by Freepage for new uploads.
  use GET /pages/{id}/media, where PHP applies the same page access policy before MinIO.
-->
<script setup lang="ts">
import { PhotoIcon, PlayCircleIcon } from "@heroicons/vue/24/outline"
import type { CmsBlock, CmsJsonValue } from "~/types/cms"

const props = defineProps<{
  block: CmsBlock
  pageId: number
}>()

const config = useRuntimeConfig()
const label = computed(() => String(props.block.props.label || "Contenu"))
const objectKey = computed(() => String(props.block.props.objectKey || ""))
const mediaUrl = computed(() => objectKey.value
  ? `${config.public.apiBase}/pages/${props.pageId}/media?key=${encodeURIComponent(objectKey.value)}`
  : null
)
const numericProperty = (value: CmsJsonValue | undefined) => (
  typeof value === "number" || typeof value === "string"
    ? Number(value) || undefined
    : undefined
)
const naturalWidth = computed(() => numericProperty(props.block.props.width))
const naturalHeight = computed(() => numericProperty(props.block.props.height))
</script>

<template>
  <section v-if="block.type === 'section'" class="size-full min-h-0">
    <slot name="section" />
  </section>

  <CmsResultText
    v-else-if="block.type === 'text'"
    :content="block.props.content ?? ''"
  />

  <a
    v-else-if="mediaUrl && block.type !== 'video'"
    :href="mediaUrl"
    target="_blank"
    rel="noopener noreferrer"
    class="flex size-full min-h-0 items-center justify-center overflow-hidden rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--focus-color)"
    :aria-label="`Ouvrir l’image ${label} dans un nouvel onglet`"
  >
    <img
      :src="mediaUrl"
      :alt="label"
      :width="naturalWidth"
      :height="naturalHeight"
      class="size-full object-fill"
    >
  </a>

  <video
    v-else-if="mediaUrl && block.type === 'video'"
    :src="mediaUrl"
    class="size-full min-h-0 object-contain"
    controls
    preload="metadata"
  />

  <div
    v-else-if="block.type === 'image' || block.type === 'banner' || block.type === 'gallery' || block.type === 'video'"
    class="secondary-background secondary-color flex size-full min-h-24 flex-col items-center justify-center gap-2 rounded-md p-3"
  >
    <PlayCircleIcon v-if="block.type === 'video'" class="size-12" aria-hidden="true" />
    <PhotoIcon v-else class="size-12" aria-hidden="true" />
    <span class="text-sm">{{ label }}</span>
  </div>

  <div v-else-if="block.type === 'separator'" class="flex size-full min-h-8 items-center px-3">
    <span class="primary-border w-full border-t" />
  </div>

  <div v-else class="secondary-background flex size-full min-h-24 items-center justify-center rounded-md p-3">
    <span class="font-medium">{{ label }}</span>
  </div>
</template>
