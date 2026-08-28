/**
 * Routes every external HTTP link through the statically generated warning page.
 * Client-side event delegation also covers links created later by Tiptap and CMS rendering.
 * The warning opens in a new tab so the Worldbuilding tab keeps its route, state and scroll position.
 */
export default defineNuxtPlugin(() => {
  const warningPath = "/external-link"

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
      removeExternalLinkInterceptor: () => {
        document.removeEventListener("click", openWarning, true)
        document.removeEventListener("auxclick", openWarning, true)
      },
    },
  }
})
