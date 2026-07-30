<!--
  This reusable administration card displays one SQL filter in the drawn hierarchy.
  Leaf filters can start a native drag operation, while parent filters display a lock
  and remain immovable to protect their existing descendants.
-->
<script setup lang="ts">
import { ArrowsPointingOutIcon, LockClosedIcon } from "@heroicons/vue/24/outline"
import type { AdminFilter } from "~/types/admin-filter"

defineProps<{
  filter: AdminFilter
  hasChildren: boolean
}>()

const emit = defineEmits<{
  dragStart: [event: DragEvent, filter: AdminFilter]
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
      <LockClosedIcon v-if="hasChildren" class="size-5 shrink-0" title="Déplacement bloqué : ce filtre possède des enfants" />
      <ArrowsPointingOutIcon v-else class="size-5 shrink-0" title="Déplaçable" />
    </div>
  </article>
</template>
