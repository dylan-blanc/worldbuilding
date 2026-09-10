/**
 * Sends same-origin browser API requests for every frontend view and composable.
 * Mutations follow Nuxt client -> GET /api/csrf -> PHP session -> protected endpoint, while
 * the CSRF token remains only in Nuxt memory and is refreshed once after an expired-token response.
 */
import type { FetchOptions } from "ofetch"

type ApiOptions = FetchOptions<"json">

let pendingCsrfRequest: Promise<string> | null = null

export function useApi() {
  const config = useRuntimeConfig()
  const csrfToken = useState<string | null>("csrf-token", () => null)
  const mutationMethods = ["POST", "PUT", "PATCH", "DELETE"]

  const loadCsrfToken = async (): Promise<string> => {
    if (csrfToken.value) return csrfToken.value

    pendingCsrfRequest ||= $fetch<{ csrf_token: string }>(`${config.public.apiBase}/csrf`, {
      credentials: "include",
    }).then((response) => {
      csrfToken.value = response.csrf_token

      return response.csrf_token
    }).finally(() => {
      pendingCsrfRequest = null
    })

    return pendingCsrfRequest
  }

  const apiFetch = async <T>(url: string, options: ApiOptions = {}): Promise<T> => {
    const method = String(options.method || "GET").toUpperCase()
    const isMutation = mutationMethods.includes(method)

    const execute = async (allowCsrfRetry: boolean): Promise<T> => {
      const headers = new Headers(options.headers as HeadersInit | undefined)
      isMutation && headers.set("X-CSRF-Token", await loadCsrfToken())

      try {
        return await $fetch<T>(url, {
          ...options,
          credentials: "include",
          headers,
        } as never)
      } catch (error) {
        const status = (error as { status?: number, response?: { status?: number } }).status
          || (error as { response?: { status?: number } }).response?.status
        const code = (error as { data?: { code?: string } }).data?.code

        if (!isMutation || !allowCsrfRetry || status !== 403 || code !== "invalid_csrf_token") throw error

        csrfToken.value = null

        return execute(false)
      }
    }

    const response = await execute(true)

    if (isMutation && ["/login", "/register", "/logout"].some(path => url.endsWith(path))) {
      csrfToken.value = null
    }

    return response
  }

  return apiFetch
}
