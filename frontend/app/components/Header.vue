<script setup lang="ts">
import {
  Bars3Icon,
  BookOpenIcon,
  Cog6ToothIcon,
  MoonIcon,
  SunIcon,
  UserCircleIcon,
  XMarkIcon,
} from "@heroicons/vue/24/solid"

type ThemePreference = "dark" | "light" | null

const isMenuOpen = ref(false)
const isDark = ref(false)
let removeSystemThemeListener: (() => void) | undefined
const themePreference = useCookie<ThemePreference>("theme-preference", {
  default: () => null,
  maxAge: 60 * 60 * 24 * 90,
})

const favoriteFilters = ["Univers fantasy", "Personnages", "Lieux à explorer"]

const applyTheme = (dark: boolean) => {
  isDark.value = dark
  document.documentElement.classList.toggle("theme-dark", dark)
}

const toggleTheme = () => {
  themePreference.value = isDark.value ? "light" : "dark"
  applyTheme(themePreference.value === "dark")
}

const toggleMenu = () => {
  isMenuOpen.value = !isMenuOpen.value
}

onMounted(() => {
  const systemTheme = window.matchMedia("(prefers-color-scheme: dark)")
  const updateSystemTheme = (event: MediaQueryListEvent) => {
    !themePreference.value && applyTheme(event.matches)
  }

  applyTheme(themePreference.value ? themePreference.value === "dark" : systemTheme.matches)
  systemTheme.addEventListener("change", updateSystemTheme)
  removeSystemThemeListener = () => systemTheme.removeEventListener("change", updateSystemTheme)
})

onBeforeUnmount(() => removeSystemThemeListener?.())
</script>

<template>
  <header class="header-shell border-b">
    <div class="mx-auto flex h-24 max-w-7xl items-center gap-6 px-4 md:h-28 md:px-8">
      <button
        type="button"
        class="inline-flex shrink-0 items-center justify-center md:hidden"
        aria-label="Ouvrir le menu"
        :aria-expanded="isMenuOpen"
        @click="toggleMenu"
      >
        <Bars3Icon class="size-12" />
      </button>

      <form class="flex min-w-0 flex-1" @submit.prevent>
        <label for="site-search" class="sr-only">Rechercher</label>
        <input
          id="site-search"
          type="search"
          name="search"
          placeholder="Recherche partiel, mot, titre, pays, theme etc etc etc...."
          class="header-search h-12 w-full border px-4 text-lg outline-none focus:ring-2 md:h-11"
        />
      </form>

      <nav class="hidden items-center gap-8 md:flex" aria-label="Navigation principale">
        <button type="button" aria-label="Mes pages" title="Mes pages">
          <BookOpenIcon class="size-9" />
        </button>
        <button type="button" :aria-label="isDark ? 'Activer le mode clair' : 'Activer le mode sombre'" @click="toggleTheme">
          <SunIcon v-if="isDark" class="size-9" />
          <MoonIcon v-else class="size-9" />
        </button>
        <button type="button" aria-label="Paramètres" title="Paramètres">
          <Cog6ToothIcon class="size-9" />
        </button>
      </nav>

      <NuxtLink to="/login" class="shrink-0" aria-label="Profil">
        <UserCircleIcon class="size-14 md:size-16" />
      </NuxtLink>
    </div>

    <Teleport to="body">
      <div v-if="isMenuOpen" class="menu-overlay fixed inset-0 z-40 backdrop-blur-sm" @click="toggleMenu" />
      <aside
        class="menu-panel fixed inset-y-0 left-0 z-50 w-4/5 max-w-sm p-3 shadow-2xl md:hidden"
        :class="isMenuOpen ? 'translate-x-0' : '-translate-x-full'"
        :aria-hidden="!isMenuOpen"
      >
        <div class="menu-title -m-3 mb-5 flex items-center justify-between px-5 py-4 text-3xl">
          <span>MENU</span>
          <button type="button" aria-label="Fermer le menu" @click="toggleMenu">
            <XMarkIcon class="size-12" />
          </button>
        </div>

        <nav class="flex flex-col gap-4" aria-label="Menu mobile">
          <button type="button" class="menu-action flex h-16 items-center justify-between border-2 px-3 text-left text-2xl">
            <span>Paramètres</span>
            <Cog6ToothIcon class="size-10" />
          </button>
          <button type="button" class="menu-action h-16 border-2 px-3 text-left text-2xl">Mes Pages</button>
          <div class="menu-action border-2 p-3">
            <p class="text-2xl">Mes favoris</p>
            <ul class="secondary-color mt-2 space-y-1 text-sm">
              <li v-for="filter in favoriteFilters" :key="filter">{{ filter }}</li>
            </ul>
          </div>
        </nav>
      </aside>
    </Teleport>
  </header>
</template>

<style scoped>
.header-shell { background: var(--primary-background); border-color: var(--primary-border); color: var(--primary-color); }
.header-search { background: var(--primary-background); border-color: var(--primary-color); color: var(--primary-color); }
.header-search::placeholder { color: var(--secondary-color); }
.header-search:focus { --tw-ring-color: var(--focus-color); }
.menu-overlay { background: rgb(7 16 31 / 25%); }
.menu-panel { background: var(--secondary-background); color: var(--primary-color); }
.menu-title { background: var(--accent-color); color: var(--primary-background); }
.menu-action { border-color: var(--primary-color); }
</style>
