export default defineNuxtRouteMiddleware(async to => {
  if (!import.meta.client) return

  const config = useRuntimeConfig()

  try {
    await $fetch(`${config.public.apiBase}/me`, {
      credentials: "include",
    })
  } catch {
    return navigateTo({
      path: "/login",
      query: {
        redirect: to.fullPath,
      },
    })
  }
})
