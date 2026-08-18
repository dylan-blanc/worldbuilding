<!--
  This component is the draggable CMS block palette opened by CmsToolbar in the free-page editor.
  It writes a typed block definition to DataTransfer; Freepage reads it on drop and updates content and layout separately.
  The later draft flow will send the resulting document through a frontend request to PHP and page_revision.
-->
<script setup lang="ts">
import {
  ArrowsPointingOutIcon,
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

interface PalettePosition {
  x: number
  y: number
}

const PALETTE_MARGIN = 8
const DEFAULT_TOP = 128
const POSITION_COOKIE_MAX_AGE = 60 * 60 * 24 * 365
const palette = ref<HTMLElement | null>(null)
const position = reactive<PalettePosition>({ x: PALETTE_MARGIN, y: DEFAULT_TOP })
const dragOffset = reactive<PalettePosition>({ x: 0, y: 0 })
const isPositionReady = ref(false)
const isMovingPalette = ref(false)
const savedPosition = useCookie<PalettePosition | null>("cms-block-palette-position", {
  default: () => null,
  maxAge: POSITION_COOKIE_MAX_AGE,
  sameSite: "lax",
})
let activePointerId: number | null = null

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

// Keep the complete palette reachable, including after a viewport resize.
const constrainPosition = (x: number, y: number): PalettePosition => {
  const width = palette.value?.offsetWidth || 0
  const height = palette.value?.offsetHeight || 0
  const maximumX = Math.max(PALETTE_MARGIN, window.innerWidth - width - PALETTE_MARGIN)
  const maximumY = Math.max(PALETTE_MARGIN, window.innerHeight - height - PALETTE_MARGIN)

  return {
    x: Math.min(Math.max(x, PALETTE_MARGIN), maximumX),
    y: Math.min(Math.max(y, PALETTE_MARGIN), maximumY),
  }
}

const setPosition = (x: number, y: number) => {
  const constrainedPosition = constrainPosition(x, y)
  position.x = constrainedPosition.x
  position.y = constrainedPosition.y
}

const storePosition = () => {
  savedPosition.value = { x: position.x, y: position.y }
}

// Pointer capture provides the same live movement for mouse, pen and touch input.
const startMovingPalette = (event: PointerEvent) => {
  if (event.pointerType === "mouse" && event.button !== 0) return

  activePointerId = event.pointerId
  dragOffset.x = event.clientX - position.x
  dragOffset.y = event.clientY - position.y
  isMovingPalette.value = true
  const handle = event.currentTarget as HTMLElement
  handle.setPointerCapture(event.pointerId)
}

const movePalette = (event: PointerEvent) => {
  if (event.pointerId !== activePointerId) return

  event.preventDefault()
  setPosition(event.clientX - dragOffset.x, event.clientY - dragOffset.y)
}

const stopMovingPalette = (event: PointerEvent) => {
  if (event.pointerId !== activePointerId) return

  const handle = event.currentTarget as HTMLElement
  handle.hasPointerCapture(event.pointerId) && handle.releasePointerCapture(event.pointerId)
  activePointerId = null
  isMovingPalette.value = false
  storePosition()
}

const movePaletteWithKeyboard = (event: KeyboardEvent) => {
  const distance = event.shiftKey ? 25 : 10
  const movements: Partial<Record<string, PalettePosition>> = {
    ArrowDown: { x: 0, y: distance },
    ArrowLeft: { x: -distance, y: 0 },
    ArrowRight: { x: distance, y: 0 },
    ArrowUp: { x: 0, y: -distance },
  }
  const movement = movements[event.key]

  if (!movement) return

  event.preventDefault()
  setPosition(position.x + movement.x, position.y + movement.y)
  storePosition()
}

const keepPaletteInViewport = () => setPosition(position.x, position.y)

onMounted(async () => {
  await nextTick()
  const cookiePosition = savedPosition.value
  const hasSavedPosition = Number.isFinite(cookiePosition?.x) && Number.isFinite(cookiePosition?.y)

  setPosition(
    hasSavedPosition ? cookiePosition!.x : window.innerWidth - (palette.value?.offsetWidth || 0) - 16,
    hasSavedPosition ? cookiePosition!.y : DEFAULT_TOP,
  )
  isPositionReady.value = true
  window.addEventListener("resize", keepPaletteInViewport)
})

onBeforeUnmount(() => window.removeEventListener("resize", keepPaletteInViewport))
</script>

<template>
  <Teleport to="body">
    <aside
      ref="palette"
      class="primary-background primary-border fixed z-50 flex max-h-[calc(100dvh-1rem)] w-18 flex-col items-center gap-2 overflow-y-auto rounded-xl border p-2 shadow-xl"
      :style="{
        left: `${position.x}px`,
        top: `${position.y}px`,
        visibility: isPositionReady ? 'visible' : 'hidden',
      }"
      aria-label="Blocs à glisser dans la page"
    >
      <button
        type="button"
        class="primary-background primary-border sticky top-0 z-10 flex h-7 w-full shrink-0 touch-none items-center justify-center border-b pb-2"
        :class="isMovingPalette ? 'cursor-grabbing' : 'cursor-move'"
        aria-label="Déplacer la palette. Utilisez aussi les flèches du clavier."
        title="Déplacer la palette"
        @pointerdown="startMovingPalette"
        @pointermove="movePalette"
        @pointerup="stopMovingPalette"
        @pointercancel="stopMovingPalette"
        @keydown="movePaletteWithKeyboard"
      >
        <ArrowsPointingOutIcon class="size-5" aria-hidden="true" />
      </button>

      <button
        v-for="block in blocks"
        :key="block.type"
        type="button"
        draggable="true"
        class="form-control flex size-13 shrink-0 cursor-grab items-center justify-center rounded-lg border transition hover:bg-(--secondary-background) active:cursor-grabbing"
        :aria-label="`Ajouter un bloc ${block.label}`"
        :title="block.label"
        @dragstart="startDragging($event, block)"
      >
        <component :is="block.icon" class="size-8" aria-hidden="true" />
      </button>
    </aside>
  </Teleport>
</template>
