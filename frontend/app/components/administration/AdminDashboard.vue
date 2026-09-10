<!--
  This sidebar navigates between the four protected administration views rendered by AdminDashboard.
  NuxtLink updates /adminpanel query parameters without server rendering dependencies, preserving the SSG shell.
  Filter and moderation groups can be collapsed independently on desktop or mobile.
-->
<script setup lang="ts">
import { ChevronDoubleDownIcon } from "@heroicons/vue/24/outline"

type AdminSection = "filters" | "moderationfilters" | "moderation" | "moderationusers"

const props = defineProps<{
  currentSection: AdminSection
}>()

const filterSections = ["filters", "moderationfilters"]
const moderationSections = ["moderation", "moderationusers"]
const filtersOpened = ref(filterSections.includes(props.currentSection))
const moderationOpened = ref(moderationSections.includes(props.currentSection))

watch(() => props.currentSection, (section) => {
  filterSections.includes(section) && (filtersOpened.value = true)
  moderationSections.includes(section) && (moderationOpened.value = true)
})
</script>

<template>
  <aside class="secondary-background primary-border rounded-xl border p-3 lg:sticky lg:top-6" aria-label="Navigation administrative">
    <nav class="grid gap-3">
      <section>
        <button
          type="button"
          class="form-control flex min-h-11 w-full items-center justify-between rounded-md border px-4 py-2 text-left font-semibold focus:outline-none focus:ring-2"
          :aria-expanded="filtersOpened"
          aria-controls="admin-filter-links"
          @click="filtersOpened = !filtersOpened"
        >
          <span>Filtres</span>
          <ChevronDoubleDownIcon
            class="size-5 transition-transform"
            :class="filtersOpened ? 'rotate-180' : ''"
            aria-hidden="true"
          />
        </button>

        <div v-show="filtersOpened" id="admin-filter-links" class="mt-2 grid gap-1 pl-3">
          <NuxtLink
            to="/adminpanel?section=filters"
            class="rounded-md px-3 py-2 text-sm transition focus:outline-none focus:ring-2"
            :class="currentSection === 'filters' ? 'button-primary' : 'secondary-color hover:primary-color'"
          >
            Filtres des pages
          </NuxtLink>
          <NuxtLink
            to="/adminpanel?section=moderationfilters"
            class="rounded-md px-3 py-2 text-sm transition focus:outline-none focus:ring-2"
            :class="currentSection === 'moderationfilters' ? 'button-primary' : 'secondary-color hover:primary-color'"
          >
            Filtres de modération
          </NuxtLink>
        </div>
      </section>

      <section>
        <button
          type="button"
          class="form-control flex min-h-11 w-full items-center justify-between rounded-md border px-4 py-2 text-left font-semibold focus:outline-none focus:ring-2"
          :aria-expanded="moderationOpened"
          aria-controls="admin-moderation-links"
          @click="moderationOpened = !moderationOpened"
        >
          <span>Modération</span>
          <ChevronDoubleDownIcon
            class="size-5 transition-transform"
            :class="moderationOpened ? 'rotate-180' : ''"
            aria-hidden="true"
          />
        </button>

        <div v-show="moderationOpened" id="admin-moderation-links" class="mt-2 grid gap-1 pl-3">
          <NuxtLink
            to="/adminpanel?section=moderation"
            class="rounded-md px-3 py-2 text-sm transition focus:outline-none focus:ring-2"
            :class="currentSection === 'moderation' ? 'button-primary' : 'secondary-color hover:primary-color'"
          >
            Par pages
          </NuxtLink>
          <NuxtLink
            to="/adminpanel?section=moderationusers"
            class="rounded-md px-3 py-2 text-sm transition focus:outline-none focus:ring-2"
            :class="currentSection === 'moderationusers' ? 'button-primary' : 'secondary-color hover:primary-color'"
          >
            Par utilisateurs
          </NuxtLink>
        </div>
      </section>
    </nav>
  </aside>
</template>
