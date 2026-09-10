<!--
  This statically generated page warns users before they leave Worldbuilding for an external HTTPS site.
  The global external-links plugin opens it with destination, return URL and scroll position parameters.
  Continuing replaces this warning tab; returning closes it or restores the original internal route as a fallback.
-->
<script setup lang="ts">
import { ArrowLeftIcon, ArrowTopRightOnSquareIcon, ExclamationTriangleIcon } from "@heroicons/vue/24/outline"

const route = useRoute()
useHead({
  title: "Vous quittez Worldbuilding",
  meta: [{ name: "referrer", content: "no-referrer" }],
})

/*
 * Lit destination, return et scroll depuis route.query à l'exécution dans le navigateur.
 * L'absence de données pendant nuxt generate conserve /external-link compatible avec le SSG.
 */
const destination = computed(() => typeof route.query.destination === "string" ? route.query.destination : "")
const returnUrl = computed(() => typeof route.query.return === "string" ? route.query.return : "")
const returnScroll = computed(() => {
  const value = typeof route.query.scroll === "string" ? Number.parseInt(route.query.scroll, 10) : 0

  return Number.isFinite(value) && value >= 0 ? value : 0
})
/*
 * Analyse destination avec URL et retourne null pour tout protocole différent de HTTP(S).
 * HTTP reste affichable dans l'avertissement ; isSecureDestination réserve la poursuite à HTTPS.
 */
const parsedDestination = computed(() => {
  try {
    const url = new URL(destination.value)

    return ["http:", "https:"].includes(url.protocol) ? url : null
  } catch {
    return null
  }
})
const destinationHost = computed(() => parsedDestination.value?.hostname || "Destination invalide")
const isSecureDestination = computed(() => parsedDestination.value?.protocol === "https:")
/*
 * Retourne true uniquement pour une destination HTTPS dont l'origine diffère de window.location.origin.
 * Résultat utilisé par l'état disabled du bouton Poursuivre.
 */
const canContinue = computed(() => (
  isSecureDestination.value
  && (!import.meta.client || parsedDestination.value?.origin !== window.location.origin)
))

/*
 * Normalise return par rapport à window.location.origin.
 * Sortie : chemin interne avec query/hash lorsque l'origine correspond, sinon /.
 */
const internalReturnUrl = () => {
  try {
    const url = new URL(returnUrl.value, window.location.origin)

    return url.origin === window.location.origin ? `${url.pathname}${url.search}${url.hash}` : "/"
  } catch {
    return "/"
  }
}

/*
 * Vérifie une seconde fois protocole HTTPS et origine externe au clic sur Poursuivre.
 * location.replace charge ensuite la destination sans conserver l'avertissement dans l'historique de cet onglet.
 */
const continueToDestination = () => {
  const target = parsedDestination.value

  if (!target || target.protocol !== "https:" || target.origin === window.location.origin) return

  window.location.replace(target.href)
}

/*
 * Ferme l'onglet d'avertissement lorsqu'il a été créé par le plugin.
 * Si window.close échoue, charge internalReturnUrl() puis restaure la position scroll enregistrée.
 */
const returnToSite = () => {
  window.close()

  window.setTimeout(async () => {
    if (window.closed) return

    await navigateTo(internalReturnUrl())
    await nextTick()
    window.requestAnimationFrame(() => window.scrollTo({ top: returnScroll.value, behavior: "instant" }))
  }, 100)
}
</script>

<template>
  <main class="external-link-background primary-color flex min-h-dvh items-center justify-center p-4 sm:p-8">
    <section class="primary-background primary-border relative isolate w-full max-w-2xl overflow-hidden rounded-2xl border p-6 shadow-2xl sm:p-10" aria-labelledby="external-link-title">
      <ExclamationTriangleIcon class="warning-color pointer-events-none absolute right-5 top-5 -z-10 size-12 opacity-10 sm:right-8 sm:top-8" aria-hidden="true" />

      <div class="text-center">
        <h1 id="external-link-title" class="text-2xl font-bold sm:text-3xl">
          Attention, vous quittez Worldbuilding
        </h1>
        <p class="secondary-color mt-4">
          Le site externe suivant ne dépend pas de Worldbuilding. Vérifiez sa destination avant de poursuivre.
        </p>
        <p class="primary-background primary-border mt-5 break-all rounded-lg border px-4 py-3 font-medium">
          <span class="block text-lg">{{ destinationHost }}</span>
          <span v-if="parsedDestination" class="secondary-color mt-1 block text-xs font-normal sm:text-sm">
            {{ parsedDestination.href }}
          </span>
        </p>
      </div>

      <p v-if="!parsedDestination" class="error-color mt-5 text-center" role="alert">
        Cette destination est invalide et ne peut pas être ouverte.
      </p>
      <p v-else-if="!isSecureDestination" class="error-color mt-5 text-center" role="alert">
        Les destinations HTTP non chiffrées sont interdites.
      </p>

      <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <button
          type="button"
          class="form-control inline-flex min-h-12 items-center justify-center gap-2 rounded-lg border px-5 py-3 font-semibold focus:outline-none focus:ring-2"
          @click="returnToSite"
        >
          <ArrowLeftIcon class="size-5" aria-hidden="true" />
          Retourner sur le site
        </button>
        <button
          type="button"
          class="button-primary inline-flex min-h-12 items-center justify-center gap-2 rounded-lg px-5 py-3 font-semibold focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:opacity-40"
          :disabled="!canContinue"
          @click="continueToDestination"
        >
          Poursuivre
          <ArrowTopRightOnSquareIcon class="size-5" aria-hidden="true" />
        </button>
      </div>
    </section>
  </main>
</template>

<style scoped>
.external-link-background {
  background: color-mix(in srgb, var(--primary-background) 22%, #07101f);
}
</style>
