/**
 * Protects /adminpanel during client navigation and hydration in the SSG application.
 * The role follows frontend -> GET /api/me -> UserProfileController::show()
 * -> User::findById() -> users.roles SQL value -> redirect or protected page.
 */
export default defineNuxtRouteMiddleware(async () => {
  if (!import.meta.client) return

  const config = useRuntimeConfig()
  const userRole = useState<"user" | "admin" | null>("auth-role", () => null)
  const profilePicture = useState<string | null>("profile-picture", () => null)

  try {
    const response = await $fetch<{
      user: {
        roles: "user" | "admin"
        profil_picture: string | null
      }
    }>(`${config.public.apiBase}/me`, {
      credentials: "include",
    })

    userRole.value = response.user.roles
    profilePicture.value = response.user.profil_picture

    if (response.user.roles === "admin") return
  } catch {
    userRole.value = null
    profilePicture.value = null
  }

  return navigateTo("/")
})
