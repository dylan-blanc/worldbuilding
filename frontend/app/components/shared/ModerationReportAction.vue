<!--
  This shared action renders the report menu and two-level reason modal used by PageDisplay and CmsResultBlock.
  Submission follows useModerationReport -> POST /api/pages/{id}/reports -> ModerationController -> SQL.
-->
<script setup lang="ts">
import {
  FlagIcon,
  XMarkIcon,
} from "@heroicons/vue/24/outline";
import type { ReportedContentType } from "~/types/shared/moderation";

const props = withDefaults(
  defineProps<{
    pageId: number;
    contentType: ReportedContentType;
    blockId?: string;
    targetLabel: string;
    menuLabel?: string;
  }>(),
  {
    blockId: "",
    menuLabel: "Report this content",
  },
);

const menuOpen = ref(false);
const {
  target,
  selectedParentId,
  selectedFilterId,
  message,
  pending,
  filtersPending,
  filters,
  rootFilters,
  selectedParentChildren,
  error,
  success,
  open,
  close,
  submit,
} = useModerationReport();

function selectParent(filterId: number): void {
  selectedParentId.value = filterId;
  selectedFilterId.value = filters.value.some(
    (filter) => Number(filter.belong_to) === filterId,
  )
    ? null
    : filterId;
  error.value = "";
}

function toggleMenu(): void {
  menuOpen.value = !menuOpen.value;
}

async function openModal(): Promise<void> {
  menuOpen.value = false;
  await open({
    pageId: props.pageId,
    contentType: props.contentType,
    blockId: props.blockId,
    label: props.targetLabel,
  });
}

function closeOnEscape(event: KeyboardEvent): void {
  if (event.key !== "Escape") return;

  menuOpen.value = false;
  close();
}

watch(target, (value) => {
  if (!import.meta.client) return;

  value
    ? document.addEventListener("keydown", closeOnEscape)
    : document.removeEventListener("keydown", closeOnEscape);
});

onBeforeUnmount(() => {
  import.meta.client && document.removeEventListener("keydown", closeOnEscape);
});
</script>

<template>
  <div @click.stop>
    <button
      type="button"
      class="flex size-10 items-center justify-center transition focus:outline-none"
      :aria-expanded="menuOpen"
      :aria-label="`Actions de signalement pour ${targetLabel}`"
      @click="toggleMenu"
    >
      <svg
        class="size-20 drop-shadow-[0_2px_2px_#000]"
        viewBox="0 0 24 24"
        fill="white"
        aria-hidden="true"
      >
        <circle cx="12" cy="5" r="2" />
        <circle cx="12" cy="12" r="2" />
        <circle cx="12" cy="19" r="2" />
      </svg>
    </button>

    <button
      v-if="menuOpen"
      type="button"
      class="fixed inset-0 z-30 cursor-default"
      aria-label="Fermer le menu de signalement"
      @click="menuOpen = false"
    />

    <div
      v-if="menuOpen"
      class="secondary-background primary-border absolute right-0 z-40 mt-2 w-56 rounded-md border p-2 shadow-xl"
      role="menu"
    >
      <button
        type="button"
        class="flex w-full items-center gap-3 rounded-md px-3 py-3 text-left text-sm transition hover:bg-(--third-background) focus:outline-none focus:ring-2 focus:ring-(--focus-color)"
        role="menuitem"
        @click="openModal"
      >
        <FlagIcon class="size-5 shrink-0" aria-hidden="true" />
        <span>{{ menuLabel }}</span>
      </button>
    </div>

    <Teleport to="body">
      <div
        v-if="target"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/65 p-4"
        role="presentation"
        @mousedown.self="close"
      >
        <section
          class="primary-background primary-border max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-xl border p-6 shadow-2xl"
          role="dialog"
          aria-modal="true"
          aria-labelledby="moderation-report-dialog-title"
        >
          <div class="flex items-start justify-between gap-4">
            <div>
              <h2
                id="moderation-report-dialog-title"
                class="text-2xl font-semibold"
              >
                {{
                  target.contentType === "page_display"
                    ? "Report image"
                    : "Report content"
                }}
              </h2>
              <p class="secondary-color mt-1 text-sm">{{ target.label }}</p>
            </div>
            <button
              type="button"
              class="shrink-0 rounded-md p-1 focus:outline-none focus:ring-2 focus:ring-(--focus-color)"
              aria-label="Fermer"
              @click="close"
            >
              <XMarkIcon class="size-7" aria-hidden="true" />
            </button>
          </div>

          <LoadingSpinner
            v-if="filtersPending"
            class="mt-6"
            label="Chargement des motifs"
          />

          <form v-else class="mt-6" @submit.prevent="submit">
            <fieldset :disabled="pending || success !== ''" class="space-y-3">
              <legend class="sr-only">Motif du signalement</legend>

              <div
                v-for="filter in rootFilters"
                :key="filter.id"
                class="grid gap-2"
              >
                <label
                  class="primary-border flex cursor-pointer items-center gap-4 rounded-lg border p-3 transition hover:bg-(--secondary-background)"
                >
                  <input
                    v-model="selectedParentId"
                    type="radio"
                    name="report-filter-parent"
                    :value="filter.id"
                    class="size-5 accent-(--accent-color)"
                    @change="selectParent(filter.id)"
                  />
                  <span>{{ filter.filter_name }}</span>
                </label>

                <label
                  v-if="
                    selectedParentId === filter.id &&
                    selectedParentChildren.length > 0
                  "
                  class="ml-9 block"
                >
                  <span class="text-sm font-medium">Précisez le motif</span>
                  <select
                    v-model="selectedFilterId"
                    required
                    class="form-control mt-2 w-full rounded-md border px-3 py-2 focus:outline-none focus:ring-2"
                  >
                    <option :value="null" disabled>
                      Sélectionnez un sous-motif
                    </option>
                    <option
                      v-for="childFilter in selectedParentChildren"
                      :key="childFilter.id"
                      :value="childFilter.id"
                    >
                      {{ childFilter.filter_name }}
                    </option>
                  </select>
                </label>
              </div>

              <label class="block pt-2">
                <span class="text-sm font-medium">Commentaire optionnel</span>
                <textarea
                  v-model="message"
                  rows="4"
                  maxlength="2000"
                  class="form-control mt-2 w-full resize-y rounded-md border px-3 py-2 focus:outline-none focus:ring-2"
                  placeholder="Ajoutez un contexte utile pour la modération"
                />
                <span class="secondary-color mt-1 block text-right text-xs"
                  >{{ message.length }}/2000</span
                >
              </label>
            </fieldset>

            <p
              v-if="error"
              class="error-color mt-4 text-sm font-medium"
              role="alert"
            >
              {{ error }}
              <NuxtLink
                v-if="error.startsWith('Veuillez vous connecter')"
                to="/login"
                class="ml-1 font-semibold text-(--accent-color) underline"
              >
                Se connecter
              </NuxtLink>
            </p>

            <p
              v-if="success"
              class="success-color mt-4 text-sm font-medium"
              aria-live="polite"
            >
              {{ success }}
            </p>

            <div class="mt-6 flex justify-end gap-3">
              <button
                type="button"
                class="form-control rounded-md border px-4 py-2 focus:outline-none focus:ring-2"
                @click="close"
              >
                {{ success ? "Fermer" : "Annuler" }}
              </button>
              <button
                v-if="!success"
                type="submit"
                :disabled="
                  pending || filtersPending || rootFilters.length === 0
                "
                class="button-primary rounded-md px-4 py-2 font-medium focus:outline-none focus:ring-2 disabled:cursor-not-allowed"
              >
                {{ pending ? "Envoi…" : "Envoyer le signalement" }}
              </button>
            </div>
          </form>
        </section>
      </div>
    </Teleport>
  </div>
</template>
