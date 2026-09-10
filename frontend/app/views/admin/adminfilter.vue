<!--
  This view renders the protected filter hierarchy as a drawn tree.
  Reads follow frontend -> GET /api/admin/filters -> AdminController role check -> Filter::findAll() SQL.
  Creates, drag/drop and confirmed deletion follow POST/PATCH/DELETE -> Filter SQL -> refreshed hierarchy.
  Each theme owns one dashed tree frame; connectors are horizontal on desktop and vertical on mobile.
-->
<script setup lang="ts">
import AdminFilterCard from "~/components/administration/AdminFilterCard.vue"
import AdminDeleteFilterModal from "~/components/administration/AdminDeleteFilterModal.vue"
import AdminInlineFilter from "~/components/administration/AdminInlineFilter.vue"
import type { AdminFilter, AdminFilterType } from "~/types/admin/admin-filter"

interface FilterResponse {
  filters: AdminFilter[]
}

interface FilterMutationResponse {
  message: string
  filter: AdminFilter
}

const config = useRuntimeConfig()
const apiFetch = useApi()
const filters = ref<AdminFilter[]>([])
const loading = ref(true)
const creating = ref(false)
const movingFilterId = ref<number | null>(null)
const deletingFilterId = ref<number | null>(null)
const filterToDelete = ref<AdminFilter | null>(null)
const draggedFilterId = ref<number | null>(null)
const activeDropTarget = ref("")
const errorMessage = ref("")
const successMessage = ref("")

const themes = computed(() => filters.value.filter((filter) => filter.filter_type === "theme"))
const categories = computed(() => filters.value.filter((filter) => filter.filter_type === "category"))
const subcategories = computed(() => filters.value.filter((filter) => filter.filter_type === "subcategory"))
const themeIds = computed(() => new Set(themes.value.map((filter) => filter.id)))
const categoryIds = computed(() => new Set(categories.value.map((filter) => filter.id)))
const filtersWithChildren = computed(() => new Set(
  filters.value
    .filter((filter) => filter.belong_to !== null)
    .map((filter) => Number(filter.belong_to)),
))

const orphanCategories = computed(() => categories.value.filter((filter) => (
  filter.belong_to === null || !themeIds.value.has(Number(filter.belong_to))
)))

const orphanSubcategories = computed(() => subcategories.value.filter((filter) => (
  filter.belong_to === null || !categoryIds.value.has(Number(filter.belong_to))
)))

const errorText = (error: unknown, fallback: string): string => {
  if (typeof error !== "object" || error === null) return fallback

  return (error as { data?: { error?: string } }).data?.error || fallback
}

const categoriesForTheme = (themeId: number): AdminFilter[] => (
  categories.value.filter((filter) => Number(filter.belong_to) === themeId)
)

const subcategoriesForCategory = (categoryId: number): AdminFilter[] => (
  subcategories.value.filter((filter) => Number(filter.belong_to) === categoryId)
)

const hasChildren = (filterId: number): boolean => filtersWithChildren.value.has(filterId)

const dropKey = (type: AdminFilterType, belongTo: number | null): string => (
  `${type}:${belongTo ?? ""}`
)

const dropClass = (type: AdminFilterType, belongTo: number | null): string => (
  activeDropTarget.value === dropKey(type, belongTo) ? "ring-2 ring-(--focus-color)" : ""
)

const fetchFilters = async (): Promise<void> => {
  const response = await $fetch<FilterResponse>(`${config.public.apiBase}/admin/filters`, {
    credentials: "include",
  })

  filters.value = response.filters || []
}

const loadFilters = async (): Promise<void> => {
  loading.value = true
  errorMessage.value = ""

  try {
    await fetchFilters()
  } catch (error) {
    errorMessage.value = errorText(error, "Chargement des filtres impossible")
  } finally {
    loading.value = false
  }
}

const createFilter = async (
  name: string,
  type: AdminFilterType,
  belongTo: number | null,
): Promise<void> => {
  if (creating.value) return

  creating.value = true
  errorMessage.value = ""
  successMessage.value = ""

  try {
    await apiFetch<FilterMutationResponse>(`${config.public.apiBase}/admin/filters`, {
      method: "POST",
      credentials: "include",
      body: {
        filter_name: name,
        filter_type: type,
        belong_to: belongTo,
        without_parent: belongTo === null,
      },
    })

    successMessage.value = "Filtre créé"
    await fetchFilters()
  } catch (error) {
    errorMessage.value = errorText(error, "Création du filtre impossible")
  } finally {
    creating.value = false
  }
}

const moveFilter = async (
  filter: AdminFilter,
  type: AdminFilterType,
  belongTo: number | null,
): Promise<void> => {
  if (hasChildren(filter.id) || movingFilterId.value !== null) return

  movingFilterId.value = filter.id
  errorMessage.value = ""
  successMessage.value = ""

  try {
    await apiFetch<FilterMutationResponse>(`${config.public.apiBase}/admin/filters/${filter.id}`, {
      method: "PATCH",
      credentials: "include",
      body: {
        filter_type: type,
        belong_to: belongTo,
      },
    })

    successMessage.value = "Filtre déplacé"
    await fetchFilters()
  } catch (error) {
    errorMessage.value = errorText(error, "Déplacement du filtre impossible")
  } finally {
    movingFilterId.value = null
  }
}

const requestDelete = (filter: AdminFilter): void => {
  if (hasChildren(filter.id)) return

  filterToDelete.value = filter
  errorMessage.value = ""
  successMessage.value = ""
}

const closeDeleteModal = (): void => {
  deletingFilterId.value === null && (filterToDelete.value = null)
}

const deleteFilter = async (): Promise<void> => {
  if (!filterToDelete.value || deletingFilterId.value !== null) return

  deletingFilterId.value = filterToDelete.value.id
  errorMessage.value = ""
  successMessage.value = ""

  try {
    await apiFetch(`${config.public.apiBase}/admin/filters/${filterToDelete.value.id}`, {
      method: "DELETE",
      credentials: "include",
    })
    successMessage.value = "Filtre supprimé"
    filterToDelete.value = null
    await fetchFilters()
  } catch (error) {
    errorMessage.value = errorText(error, "Suppression du filtre impossible")
  } finally {
    deletingFilterId.value = null
  }
}

const startDrag = (event: DragEvent, filter: AdminFilter): void => {
  if (hasChildren(filter.id)) {
    event.preventDefault()
    return
  }

  draggedFilterId.value = filter.id
  event.dataTransfer && (event.dataTransfer.effectAllowed = "move")
  event.dataTransfer?.setData("text/plain", String(filter.id))
}

const enterDropTarget = (type: AdminFilterType, belongTo: number | null): void => {
  draggedFilterId.value !== null && (activeDropTarget.value = dropKey(type, belongTo))
}

const dropFilter = async (
  event: DragEvent,
  type: AdminFilterType,
  belongTo: number | null,
): Promise<void> => {
  event.preventDefault()
  const transferredId = Number(event.dataTransfer?.getData("text/plain") || draggedFilterId.value)
  const filter = filters.value.find((item) => item.id === transferredId)

  draggedFilterId.value = null
  activeDropTarget.value = ""

  if (!filter || filter.id === belongTo) return

  await moveFilter(filter, type, belongTo)
}

const endDrag = (): void => {
  draggedFilterId.value = null
  activeDropTarget.value = ""
}

onMounted(loadFilters)
</script>

<template>
  <section class="flex flex-col gap-6">
    <div>
      <h2 class="text-2xl font-semibold">Organisation des filtres</h2>
      <p class="secondary-color mt-2 text-sm">
        Créez les filtres avec les croix et déplacez uniquement les éléments sans enfant par drag & drop.
      </p>
      <p v-if="errorMessage" class="error-color mt-3 text-sm font-medium" role="alert">{{ errorMessage }}</p>
      <p v-if="successMessage" class="success-color mt-3 text-sm font-medium" aria-live="polite">{{ successMessage }}</p>
    </div>

    <LoadingSpinner v-if="loading" label="Chargement des filtres" />

    <template v-else>
      <section class="secondary-background primary-border rounded-xl border p-5">
        <h3 class="text-xl font-semibold">Zones racines et filtres sans parent</h3>

        <div class="mt-4 grid gap-4 lg:grid-cols-3">
          <div
            class="primary-border rounded-lg border-2 border-dashed p-3"
            :class="dropClass('theme', null)"
            @dragenter.prevent="enterDropTarget('theme', null)"
            @dragover.prevent
            @drop="dropFilter($event, 'theme', null)"
          >
            <h4 class="mb-3 text-center text-sm font-semibold uppercase">Thème racine</h4>
            <AdminInlineFilter
              label="Nom du nouveau thème"
              :disabled="creating"
              @create="createFilter($event, 'theme', null)"
            />
          </div>

          <div
            class="primary-border rounded-lg border-2 border-dashed p-3"
            :class="dropClass('category', null)"
            @dragenter.prevent="enterDropTarget('category', null)"
            @dragover.prevent
            @drop="dropFilter($event, 'category', null)"
          >
            <h4 class="mb-3 text-center text-sm font-semibold uppercase">Catégorie sans parent</h4>
            <AdminInlineFilter
              label="Nom de la catégorie sans parent"
              :disabled="creating"
              @create="createFilter($event, 'category', null)"
            />
            <div class="mt-3 grid gap-4">
              <div
                v-for="category in orphanCategories"
                :key="category.id"
                class="orphan-tree"
              >
                <div
                  :class="dropClass('subcategory', category.id)"
                  @dragenter.prevent.stop="enterDropTarget('subcategory', category.id)"
                  @dragover.prevent.stop
                  @drop.stop="dropFilter($event, 'subcategory', category.id)"
                >
                  <AdminFilterCard
                    :filter="category"
                    :has-children="hasChildren(category.id)"
                    :deleting="deletingFilterId === category.id"
                    @drag-start="startDrag"
                    @delete-filter="requestDelete"
                    @dragend="endDrag"
                  />
                </div>

                <div class="orphan-tree-children">
                  <AdminInlineFilter
                    label="Créer une sous-catégorie"
                    :disabled="creating"
                    @create="createFilter($event, 'subcategory', category.id)"
                  />
                  <AdminFilterCard
                    v-for="subcategory in subcategoriesForCategory(category.id)"
                    :key="subcategory.id"
                    :filter="subcategory"
                    :has-children="hasChildren(subcategory.id)"
                    :deleting="deletingFilterId === subcategory.id"
                    @drag-start="startDrag"
                    @delete-filter="requestDelete"
                    @dragend="endDrag"
                  />
                </div>
              </div>
            </div>
          </div>

          <div
            class="primary-border rounded-lg border-2 border-dashed p-3"
            :class="dropClass('subcategory', null)"
            @dragenter.prevent="enterDropTarget('subcategory', null)"
            @dragover.prevent
            @drop="dropFilter($event, 'subcategory', null)"
          >
            <h4 class="mb-3 text-center text-sm font-semibold uppercase">Sous-catégorie sans parent</h4>
            <AdminInlineFilter
              label="Nom de la sous-catégorie sans parent"
              :disabled="creating"
              @create="createFilter($event, 'subcategory', null)"
            />
            <div class="mt-3 grid gap-2">
              <AdminFilterCard
                v-for="subcategory in orphanSubcategories"
                :key="subcategory.id"
                :filter="subcategory"
                :has-children="hasChildren(subcategory.id)"
                :deleting="deletingFilterId === subcategory.id"
                @drag-start="startDrag"
                @delete-filter="requestDelete"
                @dragend="endDrag"
              />
            </div>
          </div>
        </div>
      </section>

      <section class="space-y-6" aria-label="Arbres des thèmes">
        <article
          v-for="theme in themes"
          :key="theme.id"
          class="secondary-background primary-border overflow-x-auto rounded-sm border-2 border-dashed p-4 sm:p-6"
        >
          <div class="filter-tree-row">
            <div
              class="filter-tree-node"
              :class="dropClass('category', theme.id)"
              @dragenter.prevent="enterDropTarget('category', theme.id)"
              @dragover.prevent
              @drop="dropFilter($event, 'category', theme.id)"
            >
              <AdminFilterCard
                :filter="theme"
                :has-children="hasChildren(theme.id)"
                :deleting="deletingFilterId === theme.id"
                @drag-start="startDrag"
                @delete-filter="requestDelete"
                @dragend="endDrag"
              />
            </div>

            <div class="filter-tree-children">
              <div
                v-for="category in categoriesForTheme(theme.id)"
                :key="category.id"
                class="filter-tree-child"
              >
                <div class="filter-tree-row">
                  <div
                    class="filter-tree-node"
                    :class="dropClass('subcategory', category.id)"
                    @dragenter.prevent="enterDropTarget('subcategory', category.id)"
                    @dragover.prevent
                    @drop="dropFilter($event, 'subcategory', category.id)"
                  >
                    <AdminFilterCard
                      :filter="category"
                      :has-children="hasChildren(category.id)"
                      :deleting="deletingFilterId === category.id"
                      @drag-start="startDrag"
                      @delete-filter="requestDelete"
                      @dragend="endDrag"
                    />
                  </div>

                  <div class="filter-tree-children">
                    <div
                      v-for="subcategory in subcategoriesForCategory(category.id)"
                      :key="subcategory.id"
                      class="filter-tree-child filter-tree-leaf"
                    >
                      <AdminFilterCard
                        :filter="subcategory"
                        :has-children="hasChildren(subcategory.id)"
                        :deleting="deletingFilterId === subcategory.id"
                        @drag-start="startDrag"
                        @delete-filter="requestDelete"
                        @dragend="endDrag"
                      />
                    </div>

                    <div class="filter-tree-child filter-tree-creator">
                      <AdminInlineFilter
                        label="Créer une sous-catégorie"
                        :disabled="creating"
                        @create="createFilter($event, 'subcategory', category.id)"
                      />
                    </div>
                  </div>
                </div>
              </div>

              <div class="filter-tree-child filter-tree-creator">
                <AdminInlineFilter
                  label="Créer une catégorie"
                  :disabled="creating"
                  @create="createFilter($event, 'category', theme.id)"
                />
              </div>
            </div>
          </div>
        </article>

        <p v-if="themes.length === 0" class="secondary-color text-center">Aucun thème enregistré.</p>
      </section>
    </template>

    <AdminDeleteFilterModal
      :filter="filterToDelete"
      :pending="deletingFilterId !== null"
      :error-message="errorMessage"
      @close="closeDeleteModal"
      @confirm="deleteFilter"
    />
  </section>
</template>

<style scoped>
.filter-tree-row {
  display: grid;
  grid-template-columns: 15rem max-content;
  align-items: flex-start;
}

.filter-tree-node {
  width: 15rem;
  flex: none;
  border-radius: 0.25rem;
}

.filter-tree-children {
  position: relative;
  display: grid;
  width: max-content;
  min-width: 15rem;
  gap: 1.5rem;
  margin-left: 4rem;
  padding-left: 4rem;
}

.filter-tree-children::before {
  position: absolute;
  top: 2.5rem;
  bottom: 2.5rem;
  left: 0;
  border-left: 2px solid var(--primary-color);
  content: "";
}

.filter-tree-children::after {
  position: absolute;
  top: 2.5rem;
  left: -4rem;
  width: 4rem;
  border-top: 2px solid var(--primary-color);
  content: "";
}

.filter-tree-child {
  position: relative;
  width: max-content;
}

.filter-tree-child::before {
  position: absolute;
  top: 2.5rem;
  left: -4rem;
  width: 4rem;
  border-top: 2px solid var(--primary-color);
  content: "";
}

.filter-tree-creator,
.filter-tree-leaf {
  width: 15rem;
}

.orphan-tree-children {
  position: relative;
  display: grid;
  gap: 0.75rem;
  margin-top: 0.75rem;
  margin-left: 1.25rem;
  padding-left: 1.25rem;
  border-left: 2px solid var(--primary-color);
}

.orphan-tree-children > * {
  position: relative;
}

.orphan-tree-children > *::before {
  position: absolute;
  top: 2.5rem;
  left: -1.25rem;
  width: 1.25rem;
  border-top: 2px solid var(--primary-color);
  content: "";
}

@media (max-width: 767px) {
  .filter-tree-row {
    display: block;
  }

  .filter-tree-node {
    width: 100%;
  }

  .filter-tree-children {
    width: auto;
    min-width: 0;
    gap: 1rem;
    margin-top: 1rem;
    margin-left: 1.25rem;
    padding-left: 1.5rem;
  }

  .filter-tree-children::before {
    top: 0;
    bottom: 2.5rem;
  }

  .filter-tree-children::after {
    display: none;
  }

  .filter-tree-child::before {
    left: -1.5rem;
    width: 1.5rem;
  }

  .filter-tree-child,
  .filter-tree-creator,
  .filter-tree-leaf {
    width: 100%;
  }
}
</style>
