<script setup lang="ts">
import {
  ClockIcon,
  EyeIcon,
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

function syncFormWithUrl(): void {
  const sortBy = validSortBy(queryValue("sort_by"))
  const sortOrder = validSortOrder(queryValue("sort_order"))

  selectedFilters.theme = queryValue("theme_id")
  selectedFilters.category = queryValue("category_id")
  selectedFilters.subcategory = queryValue("subcategory_id")
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

function cycleSort(sortBy: SortBy): void {
  if (selectedSort.by !== sortBy) {
    selectedSort.by = sortBy
    selectedSort.order = "asc"
    return
  }

  selectedSort.order = selectedSort.order === "asc" ? "desc" : ""
  selectedSort.by = selectedSort.order === "" ? "" : sortBy
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

watch(() => route.query, syncFormWithUrl, { deep: true })
watch(() => selectedFilters.theme, clearInvalidSelections)
watch(() => selectedFilters.category, clearInvalidSelections)

onMounted(async () => {
  syncFormWithUrl()

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
  <form
    class="primary-background primary-border grid w-full gap-5 rounded-md border p-4 shadow-sm"
    @submit.prevent="applyFilters"
  >
    <div class="grid gap-4 md:grid-cols-3">
      <label
        v-for="field in filterFields"
        :key="field.id"
        :for="`filter-${field.id}`"
        class="flex min-w-0 flex-col gap-2"
      >
        <span class="primary-color text-base font-medium">{{ field.label }}</span>
        <select
          :id="`filter-${field.id}`"
          v-model="selectedFilters[field.id]"
          class="form-control h-11 w-full rounded-sm border px-3 text-sm outline-none transition focus:ring-2"
        >
          <option value="">{{ field.placeholder }}</option>
          <option
            v-for="filter in filterOptions[field.id]"
            :key="filter.id"
            :value="filter.id"
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
          class="form-control min-h-11 rounded-md border px-4 text-sm font-medium uppercase transition focus:outline-none focus:ring-2"
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
          class="relative flex size-12 items-center justify-center rounded-md border transition focus:outline-none focus:ring-2"
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
          class="flex size-12 items-center justify-center rounded-md border transition focus:outline-none focus:ring-2"
          :class="favoritesOnly ? 'button-primary' : 'form-control'"
          :title="favoritesOnly ? 'Afficher toutes les pages' : 'Afficher mes favoris'"
          :aria-label="favoritesOnly ? 'Afficher toutes les pages' : 'Afficher mes favoris'"
          :aria-pressed="favoritesOnly"
          @click="favoritesOnly = !favoritesOnly"
        >
          <StarIcon class="size-7" aria-hidden="true" />
        </button>
      </div>
    </div>
  </form>
</template>
