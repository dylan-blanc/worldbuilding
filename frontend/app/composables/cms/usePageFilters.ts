/*
  This composable shares the public navigation filter catalog between publication and personal-page forms.
  Client requests flow through GET /filters -> FilterController -> Filter::findNavigational() -> filters SQL.
*/
import type { PageFilterOption } from "~/types/cms/page-filter"

type FilterResponse = {
  filters: PageFilterOption[]
}

export const usePageFilters = () => {
  const config = useRuntimeConfig()
  const filters = useState<PageFilterOption[]>("page-filter-options", () => [])
  const pending = useState("page-filter-options-pending", () => false)
  const loaded = useState("page-filter-options-loaded", () => false)
  const error = useState("page-filter-options-error", () => "")

  const load = async () => {
    if (!import.meta.client || loaded.value || pending.value) return

    pending.value = true
    error.value = ""

    try {
      const response = await $fetch<FilterResponse>(`${config.public.apiBase}/filters`, {
        credentials: "include",
      })
      filters.value = response.filters || []
      loaded.value = true
    } catch {
      error.value = "Impossible de charger les filtres"
    } finally {
      pending.value = false
    }
  }

  return { filters, pending, loaded, error, load }
}
