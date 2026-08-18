<!--
  This component renders one published CMS block in pageresult and moderation previews.
  Pageresult supplies pages.pagecontent; text goes to read-only Tiptap and media uses GET /pages/{id}/media.
  User-authored text/image/banner/gallery/video blocks expose ModerationReportAction on hover.
  Under /adminpanel, moderation previews disable media navigation and video controls while preserving rendering.
-->
<script setup lang="ts">
import { PhotoIcon, PlayCircleIcon } from "@heroicons/vue/24/outline"
import type { CmsBlock, CmsJsonValue } from "~/types/cms"

const props = withDefaults(defineProps<{
  block: CmsBlock
  pageId: number
  reportable?: boolean
}>(), {
  reportable: true,
})

const config = useRuntimeConfig()
const route = useRoute()
const reportableTypes = ["text", "image", "banner", "gallery", "video"]
const label = computed(() => String(props.block.props.label || "Contenu"))
const canReport = computed(() => props.reportable && reportableTypes.includes(props.block.type))
const isAdminPanel = computed(() => route.path === "/adminpanel" || route.path.startsWith("/adminpanel/"))
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
  <div class="group/report relative size-full min-h-0">
    <section v-if="block.type === 'section'" class="size-full min-h-0">
      <slot name="section" />
    </section>

    <div
      v-else-if="block.props.moderationRemoved === true"
      class="hidden"
      aria-hidden="true"
    />

    <CmsResultText
      v-else-if="block.type === 'text'"
      :content="block.props.content ?? ''"
    />

    <component
      v-else-if="mediaUrl && block.type !== 'video'"
      :is="isAdminPanel ? 'div' : 'a'"
      :href="isAdminPanel ? undefined : mediaUrl"
      :target="isAdminPanel ? undefined : '_blank'"
      :rel="isAdminPanel ? undefined : 'noopener noreferrer'"
      :role="isAdminPanel ? 'img' : undefined"
      class="flex size-full min-h-0 items-center justify-center overflow-hidden rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--focus-color)"
      :aria-label="isAdminPanel ? 'Voir le report' : `Ouvrir l’image ${label} dans un nouvel onglet`"
    >
      <img
        :src="mediaUrl"
        :alt="isAdminPanel ? '' : label"
        :width="naturalWidth"
        :height="naturalHeight"
        class="size-full object-fill"
      >
    </component>

    <video
      v-else-if="mediaUrl && block.type === 'video'"
      :src="mediaUrl"
      class="size-full min-h-0 object-contain"
      :aria-label="isAdminPanel ? 'Voir le report' : label"
      :controls="!isAdminPanel"
      :disablepictureinpicture="isAdminPanel"
      :controlslist="isAdminPanel ? 'nodownload nofullscreen noremoteplayback' : undefined"
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

    <ModerationReportAction
      v-if="canReport"
      class="absolute right-2 top-2 z-20 opacity-0 transition-opacity group-hover/report:opacity-100 group-focus-within/report:opacity-100"
      :page-id="pageId"
      content-type="page_content"
      :block-id="block.id"
      :target-label="`${label} · ${block.type}`"
      :menu-label="block.type === 'text' ? 'Report this text' : `Report this ${block.type}`"
    />
  </div>
</template>
