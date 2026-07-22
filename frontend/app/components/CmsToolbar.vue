<!--
  This component is the upper CMS tool layer mounted by pagecms.vue below Header.
  It owns the visual editing controls, temporary preset/layout values and the preview drawer state.
  Its mode flows through v-model to pagecms.vue; editor tool actions remain intentionally visual for now.
-->
<script setup lang="ts">
import {
  QuestionMarkCircleIcon,
  RectangleGroupIcon,
  Squares2X2Icon,
  ViewColumnsIcon,
} from "@heroicons/vue/24/outline"

const props = defineProps<{
  isEditing: boolean
}>()

const emit = defineEmits<{
  "update:isEditing": [value: boolean]
}>()

const selectedPreset = ref("")
const selectedLayout = ref("")
const helpText = "Choissez un Preset suivi d'un Layout afin de commencer a créer plus rapidement, vous pourez toujours modifier ceux-ci vous même, Sinon, Vous pouvez aussi Créer a partir d'une page blanche"
const presetOptions = ["Article", "Encyclopédie", "Fiche de personnage"]
const layoutOptions = ["Colonne unique", "Deux colonnes", "Grille"]

const setMode = (editing: boolean) => emit("update:isEditing", editing)
</script>

<template>
  <section class="primary-border relative z-20 border-b" aria-label="Outils du CMS">
    <div
      class="grid overflow-hidden transition-[grid-template-rows] duration-300 ease-in-out"
      :class="props.isEditing ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
    >
      <div class="min-h-0">
        <div class="secondary-background flex min-h-20 flex-wrap items-center justify-between gap-4 px-4 py-3 md:px-8">
          <div class="flex items-center gap-2" aria-label="Outils de disposition à venir">
            <button type="button" class="form-control rounded-md border p-2" aria-label="Disposition latérale">
              <ViewColumnsIcon class="size-6" />
            </button>
            <button type="button" class="form-control rounded-md border p-2" aria-label="Disposition centrale">
              <RectangleGroupIcon class="size-6" />
            </button>
            <button type="button" class="form-control rounded-md border p-2" aria-label="Disposition en grille">
              <Squares2X2Icon class="size-6" />
            </button>
          </div>

          <div class="flex items-center gap-2 rounded-full border primary-border p-1" aria-label="Mode du CMS">
            <button
              type="button"
              class="rounded-full px-3 py-1.5 text-sm transition"
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
      </div>
    </div>

    <button
      v-if="!props.isEditing"
      type="button"
      class="button-primary absolute left-1/2 top-0 -translate-x-1/2 rounded-b-lg px-6 py-2 text-sm shadow-lg focus:outline-none focus:ring-2"
      aria-label="Afficher les outils d'édition"
      @click="setMode(true)"
    >
      Édition
    </button>
  </section>
</template>
