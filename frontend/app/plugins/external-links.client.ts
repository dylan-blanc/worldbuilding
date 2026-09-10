/**
 * Routes every external HTTP(S) link through the warning page, where HTTP downgrades cannot be continued.
 * Client-side event delegation also covers links created later by Tiptap and CMS rendering.
 * The warning opens in a new tab so the Worldbuilding tab keeps its route, state and scroll position.
 */
export default defineNuxtPlugin(() => {
  const warningPath = "/external-link"

  /*
   * Entrée : cible d'un événement de clic.
   * Recherche du parent a[href], résolution de son URL et comparaison avec window.location.origin.
   * Sortie : URL externe HTTP(S), ou null pour une route interne, un autre protocole ou une adresse invalide.
   */
  const externalDestination = (target: EventTarget | null) => {
    const element = target instanceof Element ? target : null
    const anchor = element?.closest<HTMLAnchorElement>("a[href]")

    if (!anchor) return null

    try {
      const destination = new URL(anchor.href, window.location.href)
      const externalHttpLink = ["http:", "https:"].includes(destination.protocol)
        && destination.origin !== window.location.origin

      return externalHttpLink ? destination.href : null
    } catch {
      return null
    }
  }

  /*
   * Intercepte les clics gauche et milieu dont externalDestination() retourne une URL.
   * Annule la navigation initiale puis ouvre /external-link dans un nouvel onglet avec destination, route de retour
   * et position de défilement. noopener,noreferrer supprime la relation entre les deux onglets.
   */
  const openWarning = (event: MouseEvent) => {
    if ((event.type === "click" && event.button !== 0) || (event.type === "auxclick" && event.button !== 1)) return

    const destination = externalDestination(event.target)

    if (!destination) return

    event.preventDefault()
    event.stopImmediatePropagation()

    const warningUrl = new URL(warningPath, window.location.origin)
    warningUrl.searchParams.set("destination", destination)
    warningUrl.searchParams.set("return", window.location.href)
    warningUrl.searchParams.set("scroll", String(window.scrollY))
    window.open(warningUrl.href, "_blank", "noopener,noreferrer")
  }

  document.addEventListener("click", openWarning, true)
  document.addEventListener("auxclick", openWarning, true)

  return {
    provide: {
      /*
       * Supprime les écouteurs click et auxclick enregistrés sur document par ce plugin.
       */
      removeExternalLinkInterceptor: () => {
        document.removeEventListener("click", openWarning, true)
        document.removeEventListener("auxclick", openWarning, true)
      },
    },
  }
})
