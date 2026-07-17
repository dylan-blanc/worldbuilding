<script setup lang="ts">
const bannerImage = {
  alt: "Paysage de science-fiction avec structures futuristes dans une zone enneigée",
  src: "https://unsplash.com/photos/yyKvhywBupY/download?force=true",
}

const tutorialVideos = [
  {
    title: "Tutoriel Wysiwyg",
    detail: "Prise en main de l'édition visuelle",
  },
  {
    title: "Vidéo démonstration",
    detail: "Edition de texte, écriture au stylet, création de galerie...",
  },
  {
    title: "Tutoriel CMS",
    detail: "Organisation des pages et clic droit",
  },
]
</script>

<template>
  <div class="primary-background primary-color flex min-h-screen flex-col">
    <Header />

    <main class="mx-auto flex w-full max-w-7xl flex-1 px-4 py-8 md:px-8">
      <section class="flex w-full flex-col gap-10">
        <img
          :src="bannerImage.src"
          :alt="bannerImage.alt"
          class="h-44 w-full border-2 object-cover md:h-64"
        />

        <div class="flex justify-center">
          <NuxtLink
            to="/pagecms"
            class="button-primary rounded-full px-5 py-2 text-base font-medium transition focus:outline-none focus:ring-2"
          >
            Commencer à Créer
          </NuxtLink>
        </div>

        <div class="grid gap-3 md:grid-cols-[1fr_2.5fr_1fr]">
          <div
            v-for="video in tutorialVideos"
            :key="video.title"
            class="third-background primary-color relative flex min-h-64 flex-col items-center justify-center overflow-hidden border-2 primary-border p-4 text-center"
          >
            <div class="mb-5 flex h-14 w-20 items-center justify-center rounded-xl bg-red-600">
              <div class="ml-1 h-0 w-0 border-y-[12px] border-l-[20px] border-y-transparent border-l-white" />
            </div>
            <p class="text-2xl font-medium md:text-3xl">{{ video.title }}</p>
            <p class="mt-4 text-xl md:text-3xl">{{ video.detail }}</p>
            <div class="pointer-events-none absolute bottom-3 right-3 text-xs uppercase tracking-wide secondary-color">YouTube</div>
          </div>
        </div>
      </section>
    </main>

    <Footer />
  </div>
</template>
