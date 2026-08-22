<!--
  This shared form section edits a page title and its 1-to-15 navigation filters.
  Freepage displays filters inline, while OwnedPageDisplay opens the same catalog in a modal;
  their parents submit values to PageController, which writes pages and page_filters through PDO.
-->
<script setup lang="ts">
import type { PageFilterType } from "~/types/page-filter"
import { containsPageTitleUrl } from "~/utils/pageMetadata"

const props = withDefaults(defineProps<{
  pageTitle: string
  filterIds: number[]
  disabled?: boolean
  filterDisplay?: "inline" | "modal"
}>(), {
  disabled: false,
  filterDisplay: "inline",
})

const emit = defineEmits<{
  "update:pageTitle": [value: string]
  "update:filterIds": [value: number[]]
}>()

const { filters, pending, error, load } = usePageFilters()
const isFilterModalOpen = ref(false)
const temporaryFilterIds = ref<number[]>([])
const groups: Array<{ type: PageFilterType, label: string }> = [
  { type: "theme", label: "Thèmes" },
  { type: "category", label: "Catégories" },
  { type: "subcategory", label: "Sous-catégories" },
]
const titleError = computed(() => {
  const title = props.pageTitle.trim()

  if (!title) return "Le titre est obligatoire"
  if (title.length > 255) return "Le titre ne peut pas dépasser 255 caractères"
  if (containsPageTitleUrl(title)) return "Les URL sont interdites dans le titre de la page"
  return ""
})
const filterError = computed(() => (
  props.filterIds.length < 1
    ? "Sélectionnez au moins un filtre"
    : props.filterIds.length > 15 ? "Vous pouvez sélectionner 15 filtres maximum" : ""
))
const temporaryFilterError = computed(() => (
  temporaryFilterIds.value.length < 1
    ? "Sélectionnez au moins un filtre"
    : temporaryFilterIds.value.length > 15 ? "Vous pouvez sélectionner 15 filtres maximum" : ""
))
const selectedFilters = computed(() => filters.value.filter(filter => props.filterIds.includes(filter.id)))
const visibleSelectedFilters = computed(() => selectedFilters.value.slice(0, 5))
const hiddenSelectedFilterCount = computed(() => Math.max(0, selectedFilters.value.length - 5))

const toggleFilter = (filterId: number) => {
  if (props.disabled) return

  const selected = props.filterIds.includes(filterId)

  if (!selected && props.filterIds.length >= 15) return

  emit(
    "update:filterIds",
    selected ? props.filterIds.filter(id => id !== filterId) : [...props.filterIds, filterId],
  )
}

const toggleTemporaryFilter = (filterId: number) => {
  if (props.disabled) return

  const selected = temporaryFilterIds.value.includes(filterId)

  if (!selected && temporaryFilterIds.value.length >= 15) return

  temporaryFilterIds.value = selected
    ? temporaryFilterIds.value.filter(id => id !== filterId)
    : [...temporaryFilterIds.value, filterId]
}

const openFilterModal = async () => {
  if (props.disabled) return

  await load()
  temporaryFilterIds.value = [...props.filterIds]
  isFilterModalOpen.value = true
}

const closeFilterModal = () => {
  isFilterModalOpen.value = false
  temporaryFilterIds.value = []
}

const confirmFilterSelection = () => {
  if (temporaryFilterError.value) return

  emit("update:filterIds", [...temporaryFilterIds.value])
  closeFilterModal()
}

onMounted(load)
</script>

<template>
  <section class="flex flex-col gap-4">
    <label class="flex flex-col gap-2 text-sm font-medium">
      Titre de la page
      <input
        :value="pageTitle"
        type="text"
        maxlength="255"
        class="form-control rounded-md border px-3 py-2"
        :disabled="disabled"
        autocomplete="off"
        @input="emit('update:pageTitle', ($event.target as HTMLInputElement).value)"
      >
      <span v-if="titleError" class="error-color text-xs">{{ titleError }}</span>
    </label>

    <fieldset v-if="filterDisplay === 'inline'" :disabled="disabled" class="flex flex-col gap-3">
      <legend class="text-sm font-medium">
        Filtres <span class="secondary-color">({{ filterIds.length }}/15)</span>
      </legend>

      <p v-if="pending" class="secondary-color text-sm">Chargement des filtres…</p>
      <p v-else-if="error" class="error-color text-sm">{{ error }}</p>

      <div v-else class="flex flex-col gap-4">
        <section v-for="group in groups" :key="group.type">
          <h3 class="secondary-color mb-2 text-xs font-semibold uppercase tracking-wide">
            {{ group.label }}
          </h3>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="filter in filters.filter(item => item.filter_type === group.type)"
              :key="filter.id"
              type="button"
              class="primary-border rounded-full border px-3 py-1.5 text-sm transition disabled:opacity-40"
              :class="filterIds.includes(filter.id) ? 'button-primary' : 'form-control'"
              :disabled="disabled || (!filterIds.includes(filter.id) && filterIds.length >= 15)"
              :aria-pressed="filterIds.includes(filter.id)"
              @click="toggleFilter(filter.id)"
            >
              {{ filter.filter_name }}
            </button>
          </div>
        </section>
      </div>

      <p v-if="filterError" class="error-color text-xs">{{ filterError }}</p>
    </fieldset>

    <section v-else class="flex flex-col gap-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <span class="text-sm font-medium">
          Filtres <span class="secondary-color">({{ filterIds.length }}/15)</span>
        </span>
        <button
          type="button"
          class="form-control rounded-md border px-4 py-2 text-sm font-medium disabled:opacity-40"
          :disabled="disabled"
          @click="openFilterModal"
        >
          Choisir les filtres
        </button>
      </div>

      <div v-if="selectedFilters.length" class="flex flex-wrap gap-2" aria-label="Filtres sélectionnés">
        <span
          v-for="filter in visibleSelectedFilters"
          :key="filter.id"
          class="secondary-background primary-border rounded-full border px-2.5 py-1 text-xs"
        >
          {{ filter.filter_name }}
        </span>
        <span
          v-if="hiddenSelectedFilterCount"
          class="secondary-color primary-border rounded-full border px-2.5 py-1 text-xs"
        >
          +{{ hiddenSelectedFilterCount }}
        </span>
      </div>
      <p v-else-if="pending" class="secondary-color text-xs">Chargement des filtres…</p>
      <p v-else class="secondary-color text-xs">Aucun filtre sélectionné</p>

      <p v-if="filterError" class="error-color text-xs">{{ filterError }}</p>
    </section>

    <Teleport to="body">
      <div
        v-if="filterDisplay === 'modal' && isFilterModalOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
        role="presentation"
        @click.self="closeFilterModal"
        @keydown.esc="closeFilterModal"
      >
        <section
          role="dialog"
          aria-modal="true"
          aria-labelledby="page-filter-modal-title"
          class="primary-background primary-border flex max-h-[90dvh] w-full max-w-2xl flex-col rounded-xl border p-5 shadow-2xl"
        >
          <header class="flex items-start justify-between gap-4">
            <div>
              <h2 id="page-filter-modal-title" class="text-xl font-semibold">Choisir les filtres</h2>
              <p class="secondary-color mt-1 text-sm">
                Sélectionnez entre 1 et 15 filtres, toutes catégories confondues.
              </p>
            </div>
            <button
              type="button"
              class="form-control shrink-0 rounded-md border px-3 py-1.5 text-sm"
              aria-label="Fermer"
              @click="closeFilterModal"
            >
              Fermer
            </button>
          </header>

          <div class="mt-5 flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto pr-1">
            <p v-if="pending" class="secondary-color text-sm">Chargement des filtres…</p>
            <p v-else-if="error" class="error-color text-sm">{{ error }}</p>

            <section v-for="group in groups" v-else :key="group.type">
              <h3 class="secondary-color mb-2 text-xs font-semibold uppercase tracking-wide">
                {{ group.label }}
              </h3>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="filter in filters.filter(item => item.filter_type === group.type)"
                  :key="filter.id"
                  type="button"
                  class="primary-border rounded-full border px-3 py-1.5 text-sm transition disabled:opacity-40"
                  :class="temporaryFilterIds.includes(filter.id) ? 'button-primary' : 'form-control'"
                  :disabled="!temporaryFilterIds.includes(filter.id) && temporaryFilterIds.length >= 15"
                  :aria-pressed="temporaryFilterIds.includes(filter.id)"
                  @click="toggleTemporaryFilter(filter.id)"
                >
                  {{ filter.filter_name }}
                </button>
              </div>
            </section>
          </div>

          <p v-if="temporaryFilterError" class="error-color mt-4 text-sm">{{ temporaryFilterError }}</p>

          <footer class="mt-5 flex flex-wrap items-center justify-between gap-3">
            <span class="secondary-color text-sm">{{ temporaryFilterIds.length }}/15 sélectionnés</span>
            <div class="flex gap-3">
              <button
                type="button"
                class="primary-border rounded-md border px-4 py-2"
                @click="closeFilterModal"
              >
                Annuler
              </button>
              <button
                type="button"
                class="button-primary rounded-md px-4 py-2 disabled:opacity-40"
                :disabled="Boolean(temporaryFilterError)"
                @click="confirmFilterSelection"
              >
                Valider la sélection
              </button>
            </div>
          </footer>
        </section>
      </div>
    </Teleport>
  </section>
</template>
