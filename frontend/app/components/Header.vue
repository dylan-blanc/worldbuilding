<template>
  <header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-6xl items-center gap-6 px-4 py-4">
      <NuxtLink to="/" class="text-lg font-semibold text-slate-950">
        Worldbuilding
      </NuxtLink>

      <form class="flex flex-1 justify-center">
        <label for="site-search" class="sr-only">Rechercher</label>
        <input
          id="site-search"
          type="search"
          name="search"
          placeholder="Rechercher"
          class="w-full max-w-md rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-700"
        />
      </form>

      <nav class="flex items-center gap-4 text-sm font-medium text-slate-700">
        <NuxtLink to="/login" class="transition hover:text-slate-950">
          Connexion
        </NuxtLink>
        <NuxtLink to="/register" class="transition hover:text-slate-950">
          Inscription
        </NuxtLink>
      </nav>
    </div>
  </header>
</template>
