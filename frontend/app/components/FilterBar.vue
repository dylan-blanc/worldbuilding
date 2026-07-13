<script setup lang="ts">
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

const config = useRuntimeConfig()

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

const selectedFilters = reactive({
  theme: "",
  category: "",
  subcategory: "",
})

const themes = ref<Filter[]>([])
const categories = ref<Filter[]>([])
const subcategories = ref<Filter[]>([])
const errorMessage = ref("")

const filterOptions = computed(() => ({
  theme: themes.value,
  category: categories.value,
  subcategory: subcategories.value,
}))

async function fetchFilters(type: Filter["filter_type"]): Promise<Filter[]> {
  const response = await $fetch<FilterResponse>(`${config.public.apiBase}/filters`, {
    query: {
      type,
    },
  })

  return response.filters
}

onMounted(async () => {
  try {
    const [themeFilters, categoryFilters, subcategoryFilters] = await Promise.all([
      fetchFilters("theme"),
      fetchFilters("category"),
      fetchFilters("subcategory"),
    ])

    themes.value = themeFilters
    categories.value = categoryFilters
    subcategories.value = subcategoryFilters
  } catch {
    errorMessage.value = "Impossible de charger les filtres"
  }
})
</script>

<template>
  <form class="filter-bar grid w-full gap-4 rounded-md border p-4 shadow-sm md:grid-cols-3">
    <label
      v-for="field in filterFields"
      :key="field.id"
      :for="`filter-${field.id}`"
      class="flex flex-col gap-2"
    >
      <span class="secondary-color text-sm font-medium">{{ field.label }}</span>
      <select
        :id="`filter-${field.id}`"
        v-model="selectedFilters[field.id]"
        class="form-control h-11 rounded-md border px-3 text-sm outline-none transition"
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

    <p
      v-if="errorMessage"
      class="error-color text-sm md:col-span-3"
    >
      {{ errorMessage }}
    </p>
  </form>
</template>

<style scoped>
.filter-bar { background: var(--primary-background); border-color: var(--primary-border); }
</style>
