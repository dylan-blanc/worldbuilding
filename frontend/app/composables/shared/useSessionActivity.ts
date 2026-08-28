/**
 * Renews an authenticated PHP session from the selected Header and PageDisplay NuxtLink clicks.
 * The request follows click -> POST /api/session/activity -> CSRF/session validation -> cookie renewal.
 */
export function useSessionActivity() {
  const config = useRuntimeConfig()
  const apiFetch = useApi()
  const authenticated = useState<boolean | null>("auth-status", () => null)
  const userRole = useState<"user" | "admin" | null>("auth-role", () => null)
  const profilePicture = useState<string | null>("profile-picture", () => null)

  const renewSession = async (): Promise<void> => {
    if (authenticated.value !== true) return

    try {
      await apiFetch(`${config.public.apiBase}/session/activity`, {
        method: "POST",
      })
    } catch (error) {
      const status = (error as { status?: number, response?: { status?: number } }).status
        || (error as { response?: { status?: number } }).response?.status

      if (status !== 401) return

      authenticated.value = false
      userRole.value = null
      profilePicture.value = null
    }
  }

  return {
    authenticated,
    renewSession,
  }
}
