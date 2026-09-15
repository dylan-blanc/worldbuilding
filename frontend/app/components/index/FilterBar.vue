<!--
  FilterBar renders the discovery controls used on the index page.
  It loads filter choices through frontend -> GET /filters -> FilterController -> Filter -> SQL.
  Select changes update dependent choices locally, Apply writes page filters to the route,
  and sorting or favorites update the route immediately for PageDisplay -> GET /pages.
-->
<script setup lang="ts">
import {
  BarsArrowDownIcon,
  ClockIcon,
  EyeIcon,
  FunnelIcon,
  HeartIcon,
  StarIcon,
} from "@heroicons/vue/24/outline"

type Filter = {
  id: number
  filter_name: string
  filter_type: "theme" | "category" | "subcategory"
  belong_to: number | null
  parent_name?: string | null
}

type FilterResponse = {
  filters: Filter[]
}

type SortBy = "date" | "like" | "view"
type SortOrder = "asc" | "desc" | ""

const config = useRuntimeConfig()
const route = useRoute()
const router = useRouter()

const filterFields = [
  {
    id: "theme",
    label: "Theme",
    placeholder: "Tous les themes",
    type: "theme",
  },
  {
    id: "category",
    label: "Categorie",
    placeholder: "Toutes les categories",
    type: "category",
  },
  {
    id: "subcategory",
    label: "Sous categorie",
    placeholder: "Toutes les sous categories",
    type: "subcategory",
  },
] as const

const sortOptions = [
  {
    id: "date",
    label: "Classer par date d'ajout ou de mise a jour",
    icon: ClockIcon,
  },
  {
    id: "like",
    label: "Classer par likes",
    icon: HeartIcon,
  },
  {
    id: "view",
    label: "Classer par vues",
    icon: EyeIcon,
  },
] as const

const selectedFilters = reactive({
  theme: queryValue("theme_id"),
  category: queryValue("category_id"),
  subcategory: queryValue("subcategory_id"),
})
const selectedSort = reactive<{ by: SortBy | "", order: SortOrder }>({ by: "", order: "" })
const favoritesOnly = ref(queryValue("is_favorite") === "1")
const areFiltersVisible = ref(true)

const themes = ref<Filter[]>([])
const categories = ref<Filter[]>([])
const subcategories = ref<Filter[]>([])
const errorMessage = ref("")

const availableCategories = computed(() => selectedFilters.theme === ""
  ? categories.value
  : categories.value.filter((filter) => String(filter.belong_to) === selectedFilters.theme))

const availableSubcategories = computed(() => {
  if (selectedFilters.category !== "") {
    return subcategories.value.filter((filter) => String(filter.belong_to) === selectedFilters.category)
  }

  if (selectedFilters.theme === "") {
    return subcategories.value
  }

  const categoryIds = new Set(availableCategories.value.map((filter) => filter.id))

  return subcategories.value.filter((filter) => filter.belong_to !== null && categoryIds.has(Number(filter.belong_to)))
})

const filterOptions = computed(() => ({
  theme: themes.value,
  category: availableCategories.value,
  subcategory: availableSubcategories.value,
}))

function queryValue(key: string): string {
  const value = route.query[key]

  return typeof value === "string" ? value : ""
}

function validSortBy(value: string): SortBy | "" {
  return value === "date" || value === "like" || value === "view" ? value : ""
}

function validSortOrder(value: string): SortOrder {
  return value === "asc" || value === "desc" ? value : ""
}

function syncFiltersWithUrl(): void {
  selectedFilters.theme = queryValue("theme_id")
  selectedFilters.category = queryValue("category_id")
  selectedFilters.subcategory = queryValue("subcategory_id")
}

function syncAutomaticControlsWithUrl(): void {
  const sortBy = validSortBy(queryValue("sort_by"))
  const sortOrder = validSortOrder(queryValue("sort_order"))

  selectedSort.by = sortBy !== "" && sortOrder !== "" ? sortBy : ""
  selectedSort.order = sortBy !== "" && sortOrder !== "" ? sortOrder : ""
  favoritesOnly.value = queryValue("is_favorite") === "1"
}

function clearInvalidSelections(): void {
  if (selectedFilters.category !== "" && !availableCategories.value.some((filter) => String(filter.id) === selectedFilters.category)) {
    selectedFilters.category = ""
  }

  if (selectedFilters.subcategory !== "" && !availableSubcategories.value.some((filter) => String(filter.id) === selectedFilters.subcategory)) {
    selectedFilters.subcategory = ""
  }
}

async function cycleSort(sortBy: SortBy): Promise<void> {
  if (selectedSort.by !== sortBy) {
    selectedSort.by = sortBy
    selectedSort.order = "asc"
  } else {
    selectedSort.order = selectedSort.order === "asc" ? "desc" : ""
    selectedSort.by = selectedSort.order === "" ? "" : sortBy
  }

  await applyAutomaticControls()
}

function updateFilterChildren(field: keyof typeof selectedFilters): void {
  if (field === "theme") {
    selectedFilters.category = ""
    selectedFilters.subcategory = ""
  }

  if (field === "category") selectedFilters.subcategory = ""
}

function sortLabel(sortBy: SortBy, label: string): string {
  const state = selectedSort.by === sortBy && selectedSort.order !== ""
    ? `, ordre ${selectedSort.order.toUpperCase()}`
    : ", inactif"

  return label + state
}

function cleanFilterQuery() {
  const query = { ...route.query }

  delete query.theme_id
  delete query.category_id
  delete query.subcategory_id
  delete query.sort_by
  delete query.sort_order
  delete query.is_favorite

  return query
}

async function applyFilters(): Promise<void> {
  const query = cleanFilterQuery()

  if (selectedFilters.theme !== "") query.theme_id = selectedFilters.theme
  if (selectedFilters.category !== "") query.category_id = selectedFilters.category
  if (selectedFilters.subcategory !== "") query.subcategory_id = selectedFilters.subcategory

  if (selectedSort.by !== "" && selectedSort.order !== "") {
    query.sort_by = selectedSort.by
    query.sort_order = selectedSort.order
  }

  if (favoritesOnly.value) query.is_favorite = "1"

  await router.push({ query })
}

async function applyAutomaticControls(): Promise<void> {
  const query = { ...route.query }

  delete query.sort_by
  delete query.sort_order
  delete query.is_favorite

  if (selectedSort.by !== "" && selectedSort.order !== "") {
    query.sort_by = selectedSort.by
    query.sort_order = selectedSort.order
  }

  if (favoritesOnly.value) query.is_favorite = "1"

  await router.push({ query })
}

async function toggleFavorites(): Promise<void> {
  favoritesOnly.value = !favoritesOnly.value
  await applyAutomaticControls()
}

async function resetFilters(): Promise<void> {
  selectedFilters.theme = ""
  selectedFilters.category = ""
  selectedFilters.subcategory = ""
  selectedSort.by = ""
  selectedSort.order = ""
  favoritesOnly.value = false

  await router.push({ query: cleanFilterQuery() })
}

async function fetchFilters(type: Filter["filter_type"]): Promise<Filter[]> {
  const response = await $fetch<FilterResponse>(`${config.public.apiBase}/filters`, {
    query: {
      type,
    },
  })

  return response.filters
}

watch(
  () => [route.query.theme_id, route.query.category_id, route.query.subcategory_id],
  syncFiltersWithUrl,
)
watch(
  () => [route.query.sort_by, route.query.sort_order, route.query.is_favorite],
  syncAutomaticControlsWithUrl,
)

onMounted(async () => {
  syncFiltersWithUrl()
  syncAutomaticControlsWithUrl()

  try {
    const [themeFilters, categoryFilters, subcategoryFilters] = await Promise.all([
      fetchFilters("theme"),
      fetchFilters("category"),
      fetchFilters("subcategory"),
    ])

    themes.value = themeFilters
    categories.value = categoryFilters
    subcategories.value = subcategoryFilters
    clearInvalidSelections()
  } catch {
    errorMessage.value = "Impossible de charger les filtres"
  }
})
</script>

<template>
  <div>
    <button
      type="button"
      class="button-primary inline-flex min-h-12 w-fit items-center gap-2 px-4 py-3 text-sm font-medium uppercase focus:outline-none focus:ring-2"
      aria-controls="home-filter-controls"
      :aria-expanded="areFiltersVisible"
      @click="areFiltersVisible = !areFiltersVisible"
    >
      <FunnelIcon class="size-6" aria-hidden="true" />
      Filtres
    </button>

    <div
      id="home-filter-controls"
      class="grid transition-[grid-template-rows,opacity] duration-300 ease-in-out"
      :class="areFiltersVisible ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'"
      :aria-hidden="!areFiltersVisible"
      :inert="!areFiltersVisible"
    >
      <div class="min-h-0 overflow-hidden">
        <form
          class="primary-background primary-border mt-6 grid w-full gap-5 rounded-md p-4 shadow-sm"
          @submit.prevent="applyFilters"
        >
          <div class="flex flex-wrap gap-3 sm:justify-end" aria-label="Classements décoratifs">
            <button
              type="button"
              class="button-primary inline-flex min-h-11 items-center gap-2 rounded-full px-5 py-2 text-sm font-medium focus:outline-none focus:ring-2"
            >
              Populaire
              <BarsArrowDownIcon class="size-6" aria-hidden="true" />
            </button>
            <button
              type="button"
              class="form-control min-h-11 rounded-full border-2 px-5 py-2 text-sm font-medium focus:outline-none focus:ring-2"
            >
              Nouveaux
            </button>
            <button
              type="button"
              class="form-control min-h-11 rounded-full border-2 px-5 py-2 text-sm font-medium focus:outline-none focus:ring-2"
            >
              Tendance
            </button>
          </div>

          <div class="grid gap-4 md:grid-cols-3">
            <label
              v-for="field in filterFields"
              :key="field.id"
              :for="`filter-${field.id}`"
              class="flex min-w-0 flex-col gap-2"
            >
              <span class="primary-color text-base font-bold">{{ field.label }}</span>
              <select
                :id="`filter-${field.id}`"
                v-model="selectedFilters[field.id]"
                class="form-control h-11 w-full rounded-sm border-2 px-3 text-sm font-semibold outline-none transition focus:ring-2"
                @change="updateFilterChildren(field.id)"
              >
                <option value="">{{ field.placeholder }}</option>
                <option
                  v-for="filter in filterOptions[field.id]"
                  :key="filter.id"
                  :value="String(filter.id)"
                >
                  {{ filter.filter_name }}
                </option>
              </select>
            </label>
          </div>

          <p
            v-if="errorMessage"
            class="error-color text-sm"
          >
            {{ errorMessage }}
          </p>

          <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex flex-wrap gap-3">
              <button
                type="submit"
                class="button-primary min-h-11 rounded-md px-5 text-sm font-semibold uppercase transition focus:outline-none focus:ring-2"
              >
                Appliquer
              </button>
              <button
                type="button"
                class="form-control min-h-11 rounded-md border-2 px-4 text-sm font-medium uppercase transition focus:outline-none focus:ring-2"
                @click="resetFilters"
              >
                Reinitialiser
              </button>
            </div>

            <div class="flex flex-wrap justify-end gap-3" aria-label="Options de classement">
              <button
                v-for="sortOption in sortOptions"
                :key="sortOption.id"
                type="button"
                class="relative flex size-12 items-center justify-center rounded-md border-2 transition focus:outline-none focus:ring-2"
                :class="selectedSort.by === sortOption.id ? 'button-primary' : 'form-control'"
                :title="sortLabel(sortOption.id, sortOption.label)"
                :aria-label="sortLabel(sortOption.id, sortOption.label)"
                :aria-pressed="selectedSort.by === sortOption.id"
                @click="cycleSort(sortOption.id)"
              >
                <component :is="sortOption.icon" class="size-7" aria-hidden="true" />
                <span
                  v-if="selectedSort.by === sortOption.id"
                  class="absolute bottom-0.5 right-1 text-[9px] font-bold uppercase leading-none"
                >
                  {{ selectedSort.order }}
                </span>
              </button>

              <button
                type="button"
                class="flex size-12 items-center justify-center rounded-md border-2 transition focus:outline-none focus:ring-2"
                :class="favoritesOnly ? 'button-primary' : 'form-control'"
                :title="favoritesOnly ? 'Afficher toutes les pages' : 'Afficher mes favoris'"
                :aria-label="favoritesOnly ? 'Afficher toutes les pages' : 'Afficher mes favoris'"
                :aria-pressed="favoritesOnly"
                @click="toggleFavorites"
              >
                <StarIcon class="size-7" aria-hidden="true" />
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
