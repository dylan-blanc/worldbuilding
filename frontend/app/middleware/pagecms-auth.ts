/**
 * Protects authenticated CMS routes during client navigation in the generated Nuxt application.
 * Access follows frontend -> GET /api/me -> PHP session validation -> redirect to /login when absent.
 */
export default defineNuxtRouteMiddleware(async to => {
  if (!import.meta.client) return

  const config = useRuntimeConfig()
  const authenticated = useState<boolean | null>("auth-status", () => null)

  try {
    await $fetch(`${config.public.apiBase}/me`, {
      credentials: "include",
    })
    authenticated.value = true
  } catch {
    authenticated.value = false
    return navigateTo({
      path: "/login",
      query: {
        redirect: to.fullPath,
      },
    })
  }
})
