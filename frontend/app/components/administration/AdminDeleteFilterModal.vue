<!--
  This shared confirmation dialog protects filter deletion in both administration filter views.
  Confirmation returns the selection to the view, which calls DELETE /api/admin/filters/{id};
  AdminController then validates children and active moderation usage before changing SQL data.
-->
<script setup lang="ts">
import { TrashIcon, XMarkIcon } from "@heroicons/vue/24/outline"
import type { AdminFilter } from "~/types/admin/admin-filter"

defineProps<{
  filter: AdminFilter | null
  pending: boolean
  errorMessage?: string
}>()

const emit = defineEmits<{
  close: []
  confirm: []
}>()
</script>

<template>
  <Teleport to="body">
    <div
      v-if="filter"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
      role="presentation"
      @click.self="!pending && emit('close')"
    >
      <section
        class="primary-background primary-border w-full max-w-md rounded-xl border p-5 shadow-xl"
        role="dialog"
        aria-modal="true"
        aria-labelledby="delete-filter-title"
      >
        <header class="flex items-start justify-between gap-4">
          <div>
            <h2 id="delete-filter-title" class="text-xl font-semibold">Supprimer le filtre</h2>
            <p class="secondary-color mt-2 text-sm">Confirmez la suppression de « {{ filter.filter_name }} ».</p>
          </div>
          <button
            type="button"
            class="secondary-color rounded-sm p-1 disabled:opacity-40"
            :disabled="pending"
            aria-label="Fermer"
            @click="emit('close')"
          >
            <XMarkIcon class="size-6" />
          </button>
        </header>

        <p class="error-color mt-5 text-sm">
          Les associations de ce filtre aux pages seront supprimées. Les pages utilisateur ne seront pas supprimées.
        </p>
        <p v-if="errorMessage" class="error-color mt-3 text-sm font-medium" role="alert">
          {{ errorMessage }}
        </p>

        <footer class="mt-6 flex justify-end gap-3">
          <button
            type="button"
            class="primary-border rounded-lg border px-4 py-2 disabled:opacity-40"
            :disabled="pending"
            @click="emit('close')"
          >
            Annuler
          </button>
          <button
            type="button"
            class="error-color primary-border flex items-center gap-2 rounded-lg border px-4 py-2 disabled:opacity-40"
            :disabled="pending"
            @click="emit('confirm')"
          >
            <TrashIcon class="size-5" />
            {{ pending ? "Suppression…" : "Supprimer" }}
          </button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>
