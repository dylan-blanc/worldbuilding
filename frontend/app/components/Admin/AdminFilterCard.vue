<!--
  This reusable administration card displays one SQL filter in the drawn hierarchy.
  Leaf filters can start a native drag operation and request deletion through the shared modal.
  Parent filters remain immovable and undeletable while they have descendants.
-->
<script setup lang="ts">
import { ArrowsPointingOutIcon, LockClosedIcon, TrashIcon } from "@heroicons/vue/24/outline"
import type { AdminFilter } from "~/types/admin-filter"

withDefaults(defineProps<{
  filter: AdminFilter
  hasChildren: boolean
  deleting?: boolean
}>(), {
  deleting: false,
})

const emit = defineEmits<{
  dragStart: [event: DragEvent, filter: AdminFilter]
  deleteFilter: [filter: AdminFilter]
}>()
</script>

<template>
  <article
    class="primary-background primary-border min-h-20 rounded-sm border-2 border-dashed p-3"
    :class="hasChildren ? 'cursor-not-allowed' : 'cursor-grab active:cursor-grabbing'"
    :draggable="!hasChildren"
    @dragstart="emit('dragStart', $event, filter)"
  >
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <h4 class="font-semibold [overflow-wrap:anywhere]">{{ filter.filter_name }}</h4>
        <p class="secondary-color mt-1 text-xs">{{ filter.parent_name ? `Parent : ${filter.parent_name}` : "Sans parent" }}</p>
      </div>
      <div class="flex shrink-0 items-center gap-2">
        <LockClosedIcon v-if="hasChildren" class="size-5" title="Déplacement bloqué : ce filtre possède des enfants" />
        <ArrowsPointingOutIcon v-else class="size-5" title="Déplaçable" />
        <button
          type="button"
          class="error-color rounded-sm p-1 disabled:cursor-not-allowed disabled:opacity-40"
          :disabled="hasChildren || deleting"
          :title="hasChildren ? 'Suppression bloquée : supprimez d’abord les filtres enfants' : 'Supprimer ce filtre'"
          :aria-label="`Supprimer le filtre ${filter.filter_name}`"
          @mousedown.stop
          @dragstart.stop.prevent
          @click.stop="emit('deleteFilter', filter)"
        >
          <TrashIcon class="size-5" />
        </button>
      </div>
    </div>
  </article>
</template>
