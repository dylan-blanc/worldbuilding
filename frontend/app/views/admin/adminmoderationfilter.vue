<!--
  This view manages a two-level moderation reason tree from the administration sidebar.
  GET/POST/PATCH/DELETE requests follow AdminController -> Filter -> filters SQL with moderation-only payloads.
  Root and child creation, drag/drop and confirmed deletion refresh the two-level hierarchy.
-->
<script setup lang="ts">
import AdminFilterCard from "~/components/administration/AdminFilterCard.vue"
import AdminDeleteFilterModal from "~/components/administration/AdminDeleteFilterModal.vue"
import AdminInlineFilter from "~/components/administration/AdminInlineFilter.vue"
import type { AdminFilter } from "~/types/admin/admin-filter"

interface FilterResponse {
  filters: AdminFilter[]
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

const moderationFilters = computed(() => (
  filters.value.filter(filter => filter.filter_type === "moderation")
))
const rootFilters = computed(() => (
  moderationFilters.value.filter(filter => filter.belong_to === null)
))
const filtersWithChildren = computed(() => new Set(
  moderationFilters.value
    .filter(filter => filter.belong_to !== null)
    .map(filter => Number(filter.belong_to)),
))

function errorText(error: unknown, fallback: string): string {
  if (typeof error !== "object" || error === null) return fallback

  return (error as { data?: { error?: string } }).data?.error || fallback
}

function childrenFor(parentId: number): AdminFilter[] {
  return moderationFilters.value.filter(filter => Number(filter.belong_to) === parentId)
}

function hasChildren(filterId: number): boolean {
  return filtersWithChildren.value.has(filterId)
}

function dropKey(parentId: number | null): string {
  return `moderation:${parentId ?? "root"}`
}

function dropClass(parentId: number | null): string {
  return activeDropTarget.value === dropKey(parentId) ? "ring-2 ring-(--focus-color)" : ""
}

async function fetchFilters(): Promise<void> {
  const response = await $fetch<FilterResponse>(`${config.public.apiBase}/admin/filters`, {
    credentials: "include",
  })

  filters.value = response.filters || []
}

async function loadFilters(): Promise<void> {
  loading.value = true
  errorMessage.value = ""

  try {
    await fetchFilters()
  } catch (error) {
    errorMessage.value = errorText(error, "Chargement des motifs de modération impossible")
  } finally {
    loading.value = false
  }
}

async function createModerationFilter(name: string, belongTo: number | null): Promise<void> {
  if (creating.value) return

  creating.value = true
  errorMessage.value = ""
  successMessage.value = ""

  try {
    await apiFetch(`${config.public.apiBase}/admin/filters`, {
      method: "POST",
      credentials: "include",
      body: {
        filter_name: name,
        filter_type: "moderation",
        belong_to: belongTo,
        without_parent: belongTo === null,
      },
    })
    successMessage.value = belongTo === null
      ? "Motif racine créé"
      : "Sous-motif créé"
    await fetchFilters()
  } catch (error) {
    errorMessage.value = errorText(error, "Création du motif de modération impossible")
  } finally {
    creating.value = false
  }
}

async function moveFilter(filter: AdminFilter, belongTo: number | null): Promise<void> {
  if (movingFilterId.value !== null || hasChildren(filter.id)) return
  if ((filter.belong_to === null ? null : Number(filter.belong_to)) === belongTo) return

  movingFilterId.value = filter.id
  errorMessage.value = ""
  successMessage.value = ""

  try {
    await apiFetch(`${config.public.apiBase}/admin/filters/${filter.id}`, {
      method: "PATCH",
      credentials: "include",
      body: {
        filter_type: "moderation",
        belong_to: belongTo,
      },
    })
    successMessage.value = belongTo === null
      ? "Motif détaché de son parent"
      : "Motif rattaché à son nouveau parent"
    await fetchFilters()
  } catch (error) {
    errorMessage.value = errorText(error, "Déplacement du motif impossible")
  } finally {
    movingFilterId.value = null
  }
}

function requestDelete(filter: AdminFilter): void {
  if (hasChildren(filter.id)) return

  filterToDelete.value = filter
  errorMessage.value = ""
  successMessage.value = ""
}

function closeDeleteModal(): void {
  deletingFilterId.value === null && (filterToDelete.value = null)
}

async function deleteFilter(): Promise<void> {
  if (!filterToDelete.value || deletingFilterId.value !== null) return

  deletingFilterId.value = filterToDelete.value.id
  errorMessage.value = ""
  successMessage.value = ""

  try {
    await apiFetch(`${config.public.apiBase}/admin/filters/${filterToDelete.value.id}`, {
      method: "DELETE",
      credentials: "include",
    })
    successMessage.value = "Motif de modération supprimé"
    filterToDelete.value = null
    await fetchFilters()
  } catch (error) {
    errorMessage.value = errorText(error, "Suppression du motif de modération impossible")
  } finally {
    deletingFilterId.value = null
  }
}

function startDrag(event: DragEvent, filter: AdminFilter): void {
  if (hasChildren(filter.id)) {
    event.preventDefault()
    return
  }

  draggedFilterId.value = filter.id
  event.dataTransfer && (event.dataTransfer.effectAllowed = "move")
  event.dataTransfer?.setData("text/plain", String(filter.id))
}

function enterDropTarget(parentId: number | null): void {
  draggedFilterId.value !== null && (activeDropTarget.value = dropKey(parentId))
}

async function dropFilter(event: DragEvent, belongTo: number | null): Promise<void> {
  event.preventDefault()
  const transferredId = Number(event.dataTransfer?.getData("text/plain") || draggedFilterId.value)
  const filter = moderationFilters.value.find(item => item.id === transferredId)

  draggedFilterId.value = null
  activeDropTarget.value = ""
  if (!filter || filter.id === belongTo) return

  await moveFilter(filter, belongTo)
}

function endDrag(): void {
  draggedFilterId.value = null
  activeDropTarget.value = ""
}

onMounted(loadFilters)
</script>

<template>
  <section class="flex flex-col gap-6">
    <header>
      <h2 class="text-2xl font-semibold">Filtres de modération</h2>
      <p class="secondary-color mt-2 text-sm">
        Créez des motifs racines et leurs sous-motifs, puis déplacez les éléments sans enfant par drag & drop.
      </p>
      <p v-if="errorMessage" class="error-color mt-3 text-sm font-medium" role="alert">{{ errorMessage }}</p>
      <p v-if="successMessage" class="success-color mt-3 text-sm font-medium" aria-live="polite">{{ successMessage }}</p>
    </header>

    <LoadingSpinner v-if="loading" label="Chargement des motifs de modération" />

    <template v-else>
      <section
        class="secondary-background primary-border rounded-xl border p-5"
        :class="dropClass(null)"
        @dragenter.prevent="enterDropTarget(null)"
        @dragover.prevent
        @drop="dropFilter($event, null)"
      >
        <h3 class="text-lg font-semibold">Créer ou détacher un motif racine</h3>
        <p class="secondary-color mt-1 text-sm">
          Déposez un sous-motif dans cette zone pour le détacher de son parent.
        </p>
        <div class="mt-4 max-w-80">
          <AdminInlineFilter
            label="Créer un motif racine"
            :disabled="creating"
            @create="createModerationFilter($event, null)"
          />
        </div>
      </section>

      <section class="grid gap-6" aria-label="Arbres des motifs de modération">
        <article
          v-for="rootFilter in rootFilters"
          :key="rootFilter.id"
          class="secondary-background primary-border overflow-x-auto rounded-sm border-2 border-dashed p-4 sm:p-6"
        >
          <div class="moderation-tree-row">
            <div
              class="moderation-tree-node"
              :class="dropClass(rootFilter.id)"
              @dragenter.prevent="enterDropTarget(rootFilter.id)"
              @dragover.prevent
              @drop="dropFilter($event, rootFilter.id)"
            >
              <AdminFilterCard
                :filter="rootFilter"
                :has-children="hasChildren(rootFilter.id)"
                :deleting="deletingFilterId === rootFilter.id"
                @drag-start="startDrag"
                @delete-filter="requestDelete"
                @dragend="endDrag"
              />
            </div>

            <div class="moderation-tree-children">
              <div
                v-for="childFilter in childrenFor(rootFilter.id)"
                :key="childFilter.id"
                class="moderation-tree-child"
              >
                <AdminFilterCard
                  :filter="childFilter"
                  :has-children="false"
                  :deleting="deletingFilterId === childFilter.id"
                  @drag-start="startDrag"
                  @delete-filter="requestDelete"
                  @dragend="endDrag"
                />
              </div>

              <div class="moderation-tree-child">
                <AdminInlineFilter
                  label="Créer un sous-motif"
                  :disabled="creating"
                  @create="createModerationFilter($event, rootFilter.id)"
                />
              </div>
            </div>
          </div>
        </article>

        <p v-if="rootFilters.length === 0" class="secondary-color text-center">
          Aucun motif racine enregistré.
        </p>
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
.moderation-tree-row {
  display: grid;
  grid-template-columns: 15rem max-content;
  align-items: flex-start;
}

.moderation-tree-node {
  width: 15rem;
  border-radius: 0.25rem;
}

.moderation-tree-children {
  position: relative;
  display: grid;
  width: max-content;
  min-width: 15rem;
  gap: 1.5rem;
  margin-left: 4rem;
  padding-left: 4rem;
}

.moderation-tree-children::before {
  position: absolute;
  top: 2.5rem;
  bottom: 2.5rem;
  left: 0;
  border-left: 2px solid var(--primary-color);
  content: "";
}

.moderation-tree-children::after,
.moderation-tree-child::before {
  position: absolute;
  top: 2.5rem;
  width: 4rem;
  border-top: 2px solid var(--primary-color);
  content: "";
}

.moderation-tree-children::after {
  left: -4rem;
}

.moderation-tree-child {
  position: relative;
  width: 15rem;
}

.moderation-tree-child::before {
  left: -4rem;
}

@media (max-width: 767px) {
  .moderation-tree-row {
    display: block;
  }

  .moderation-tree-node,
  .moderation-tree-child {
    width: 100%;
  }

  .moderation-tree-children {
    width: auto;
    min-width: 0;
    gap: 1rem;
    margin-top: 1rem;
    margin-left: 1.25rem;
    padding-left: 1.5rem;
  }

  .moderation-tree-children::before {
    top: 0;
    bottom: 2.5rem;
  }

  .moderation-tree-children::after {
    display: none;
  }

  .moderation-tree-child::before {
    left: -1.5rem;
    width: 1.5rem;
  }
}
</style>
