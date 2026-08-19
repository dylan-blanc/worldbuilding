<!--
  This component is the upper CMS tool layer mounted by pagecms.vue below Header.
  It owns the visual editing controls, temporary preset/layout values, preview drawer state and the full-width
  teleport host used by the focused CmsTextBlockEditor without placing formatting controls inside the canvas.
  Its mode, responsive viewport and history actions flow to pagecms.vue, which delegates them to Freepage.
  When the editing drawer closes, a compact preview bar keeps viewport selection and the return action accessible.
-->
<script setup lang="ts">
import {
  ArrowUturnLeftIcon,
  ArrowUturnRightIcon,
  ComputerDesktopIcon,
  DevicePhoneMobileIcon,
  DeviceTabletIcon,
  QuestionMarkCircleIcon,
  Squares2X2Icon,
} from "@heroicons/vue/24/outline"
import type { Component } from "vue"
import CmsBlockPalette from "~/components/CmsBlockPalette.vue"
import type { CmsBlockDefinition, CmsWorkspaceViewportMode } from "~/types/cms"

const props = defineProps<{
  isEditing: boolean
  blockPaletteEnabled: boolean
  canUndo: boolean
  canRedo: boolean
  viewportMode: CmsWorkspaceViewportMode
  combinedResponsiveEnabled: boolean
}>()

const emit = defineEmits<{
  "update:isEditing": [value: boolean]
  "update:viewportMode": [value: CmsWorkspaceViewportMode]
  addBlock: [definition: CmsBlockDefinition]
  undo: []
  redo: []
}>()

const selectedPreset = ref("")
const selectedLayout = ref("")
const isBlockPaletteOpen = ref(false)
const helpText = "Choissez un Preset suivi d'un Layout afin de commencer a créer plus rapidement, vous pourez toujours modifier ceux-ci vous même, Sinon, Vous pouvez aussi Créer a partir d'une page blanche"
const presetOptions = ["Article", "Encyclopédie", "Fiche de personnage"]
const layoutOptions = ["Colonne unique", "Deux colonnes", "Grille"]
const viewportOptions = computed<Array<{
  value: CmsWorkspaceViewportMode
  label: string
  icons: Component[]
}>>(() => props.combinedResponsiveEnabled
  ? [
      { value: "desktop", label: "Vue bureau", icons: [ComputerDesktopIcon] },
      {
        value: "responsive",
        label: "Vues tablette et mobile côte à côte",
        icons: [DeviceTabletIcon, DevicePhoneMobileIcon],
      },
    ]
  : [
      { value: "desktop", label: "Vue bureau", icons: [ComputerDesktopIcon] },
      { value: "tablet", label: "Vue tablette", icons: [DeviceTabletIcon] },
      { value: "mobile", label: "Vue mobile", icons: [DevicePhoneMobileIcon] },
    ])

const setMode = (editing: boolean) => emit("update:isEditing", editing)
const toggleBlockPalette = () => {
  props.blockPaletteEnabled && (isBlockPaletteOpen.value = !isBlockPaletteOpen.value)
}

watch(() => props.isEditing, editing => editing || (isBlockPaletteOpen.value = false))
</script>

<template>
  <section class="primary-border relative z-20 border-b" aria-label="Outils du CMS">
    <div
      class="grid overflow-hidden transition-[grid-template-rows] duration-300 ease-in-out"
      :class="props.isEditing ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
    >
      <div class="min-h-0">
        <div class="secondary-background flex min-h-20 flex-wrap items-center justify-between gap-4 px-4 py-3 md:px-8">
          <div class="flex items-center gap-2" aria-label="Outils de disposition et d’historique">
            <button
              type="button"
              class="form-control rounded-md border p-2"
              :disabled="!props.canUndo"
              aria-label="Revenir en arrière"
              title="Annuler la dernière modification (Ctrl+Z)"
              @click="emit('undo')"
            >
              <ArrowUturnLeftIcon class="size-6" />
            </button>
            <button
              type="button"
              class="form-control rounded-md border p-2"
              :disabled="!props.canRedo"
              aria-label="Rétablir la modification"
              title="Rétablir la modification (Ctrl+Maj+Z)"
              @click="emit('redo')"
            >
              <ArrowUturnRightIcon class="size-6" />
            </button>
            <button
              type="button"
              class="rounded-md border p-2"
              :class="isBlockPaletteOpen ? 'button-primary' : 'form-control'"
              :disabled="!props.blockPaletteEnabled"
              :aria-expanded="isBlockPaletteOpen"
              aria-label="Afficher les blocs à glisser"
              title="Blocs de contenu"
              @click="toggleBlockPalette"
            >
              <Squares2X2Icon class="size-6" />
            </button>
          </div>

          <div class="flex items-center gap-2">
            <div class="primary-border flex items-center gap-2 rounded-full border p-1" aria-label="Mode du CMS">
              <button
                type="button"
                class="rounded-full px-3 py-1.5 text-sm transition disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="!props.blockPaletteEnabled"
                aria-pressed="false"
                @click="setMode(false)"
              >
                Visualisation
              </button>
              <button
                type="button"
                class="button-primary rounded-full px-3 py-1.5 text-sm"
                aria-pressed="true"
              >
                Édition
              </button>
            </div>

            <div class="primary-border flex items-center rounded-md border p-1" aria-label="Aperçu responsive">
              <button
                v-for="option in viewportOptions"
                :key="option.value"
                type="button"
                class="rounded-sm p-1.5 transition disabled:opacity-40"
                :class="props.viewportMode === option.value ? 'button-primary' : 'form-control'"
                :disabled="!props.blockPaletteEnabled"
                :aria-label="option.label"
                :title="option.label"
                :aria-pressed="props.viewportMode === option.value"
                @click="emit('update:viewportMode', option.value)"
              >
                <span class="flex items-center gap-0.5" aria-hidden="true">
                  <component
                    :is="icon"
                    v-for="(icon, iconIndex) in option.icons"
                    :key="`${option.value}-${iconIndex}`"
                    class="size-5"
                  />
                </span>
              </button>
            </div>
          </div>

          <div class="flex flex-wrap items-center justify-end gap-3">
            <label class="sr-only" for="cms-preset">Preset</label>
            <select id="cms-preset" v-model="selectedPreset" class="form-control min-w-36 rounded-md border px-3 py-2">
              <option value="" disabled>Preset</option>
              <option v-for="preset in presetOptions" :key="preset" :value="preset">{{ preset }}</option>
            </select>

            <label class="sr-only" for="cms-layout">Layout</label>
            <select id="cms-layout" v-model="selectedLayout" class="form-control min-w-36 rounded-md border px-3 py-2">
              <option value="" disabled>Layout</option>
              <option v-for="layout in layoutOptions" :key="layout" :value="layout">{{ layout }}</option>
            </select>

            <div class="group relative">
              <button type="button" class="rounded-full" aria-label="Aide sur les presets et layouts" :aria-describedby="'cms-help'">
                <QuestionMarkCircleIcon class="size-9" />
              </button>
              <div
                id="cms-help"
                role="tooltip"
                class="primary-background primary-border pointer-events-none absolute right-0 top-full z-30 mt-2 w-72 rounded-lg border p-3 text-sm opacity-0 shadow-xl transition group-hover:opacity-100 group-focus-within:opacity-100"
              >
                {{ helpText }}
              </div>
            </div>
          </div>
        </div>

        <div
          id="cms-text-toolbar-host"
          class="secondary-background primary-border w-full empty:hidden border-t px-[5px] py-2"
          aria-label="Outils du bloc texte sélectionné"
        />
      </div>
    </div>

    <div
      v-if="!props.isEditing"
      class="secondary-background flex min-h-14 flex-wrap items-center justify-center gap-4 px-4 py-2"
      aria-label="Outils de prévisualisation du CMS"
    >
      <div
        class="primary-border flex items-center rounded-md border p-1"
        aria-label="Aperçu responsive de la visualisation"
      >
        <button
          v-for="option in viewportOptions"
          :key="`preview-${option.value}`"
          type="button"
          class="rounded-sm p-1.5 transition disabled:opacity-40"
          :class="props.viewportMode === option.value ? 'button-primary' : 'form-control'"
          :disabled="!props.blockPaletteEnabled"
          :aria-label="option.label"
          :title="option.label"
          :aria-pressed="props.viewportMode === option.value"
          @click="emit('update:viewportMode', option.value)"
        >
          <span class="flex items-center gap-0.5" aria-hidden="true">
            <component
              :is="icon"
              v-for="(icon, iconIndex) in option.icons"
              :key="`preview-${option.value}-${iconIndex}`"
              class="size-5"
            />
          </span>
        </button>
      </div>

      <div class="primary-border flex items-center gap-2 rounded-full border p-1" aria-label="Mode du CMS">
        <button
          type="button"
          class="button-primary rounded-full px-3 py-1.5 text-sm"
          aria-pressed="true"
        >
          Visualisation
        </button>
        <button
          type="button"
          class="rounded-full px-3 py-1.5 text-sm transition"
          aria-label="Afficher les outils d'édition"
          aria-pressed="false"
          @click="setMode(true)"
        >
          Édition
        </button>
      </div>
    </div>

    <CmsBlockPalette
      v-if="props.isEditing && props.blockPaletteEnabled && isBlockPaletteOpen"
      @add="emit('addBlock', $event)"
    />
  </section>
</template>
