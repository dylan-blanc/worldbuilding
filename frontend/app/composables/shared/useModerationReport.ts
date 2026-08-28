/**
 * Shares two-level moderation filter loading and authenticated submission between report actions.
 * UI components open a target, then POST page/block identifiers to ModerationController for server-side snapshots.
 */
import type { ReportedContentType } from "~/types/shared/moderation"

export interface ModerationFilter {
  id: number
  filter_name: string
  filter_type: "moderation"
  belong_to: number | null
  parent_name: string | null
}

export interface ModerationReportTarget {
  pageId: number
  contentType: ReportedContentType
  blockId?: string
  label: string
}

interface ModerationFiltersResponse {
  filters: ModerationFilter[]
}

const filterOrder = [
  "Mature content",
  "Spam",
  "Violent or shocking content",
  "Other",
]

function errorDetails(error: unknown): { message: string, status: number } {
  if (typeof error !== "object" || error === null) return { message: "", status: 0 }

  const fetchError = error as {
    status?: number
    statusCode?: number
    data?: { error?: string }
  }

  return {
    message: fetchError.data?.error || "",
    status: fetchError.status || fetchError.statusCode || 0,
  }
}

export function useModerationReport() {
  const config = useRuntimeConfig()
  const apiFetch = useApi()
  const sharedFilters = useState<ModerationFilter[]>("moderation-report-filters", () => [])
  const sharedFiltersPending = useState<boolean>("moderation-report-filters-pending", () => false)
  const target = ref<ModerationReportTarget | null>(null)
  const selectedParentId = ref<number | null>(null)
  const selectedFilterId = ref<number | null>(null)
  const message = ref("")
  const pending = ref(false)
  const error = ref("")
  const success = ref("")

  const orderedFilters = computed(() => [...sharedFilters.value].sort((left, right) => {
    const leftIndex = filterOrder.indexOf(left.filter_name)
    const rightIndex = filterOrder.indexOf(right.filter_name)
    const leftRank = leftIndex === -1 ? filterOrder.length : leftIndex
    const rightRank = rightIndex === -1 ? filterOrder.length : rightIndex

    return leftRank - rightRank || left.filter_name.localeCompare(right.filter_name, "fr")
  }))
  const rootFilters = computed(() => (
    orderedFilters.value.filter(filter => filter.belong_to === null)
  ))
  const selectedParentChildren = computed(() => (
    selectedParentId.value === null
      ? []
      : orderedFilters.value.filter(filter => Number(filter.belong_to) === selectedParentId.value)
  ))

  async function fetchFilters(): Promise<void> {
    if (sharedFiltersPending.value) return

    sharedFiltersPending.value = true
    error.value = ""

    try {
      const response = await $fetch<ModerationFiltersResponse>(`${config.public.apiBase}/filters`, {
        query: { type: "moderation" },
      })

      sharedFilters.value = response.filters || []
      sharedFilters.value.length === 0 && (error.value = "Aucun motif de signalement disponible")
    } catch {
      error.value = "Chargement des motifs de signalement impossible"
    } finally {
      sharedFiltersPending.value = false
    }
  }

  async function open(reportTarget: ModerationReportTarget): Promise<void> {
    target.value = reportTarget
    selectedParentId.value = null
    selectedFilterId.value = null
    message.value = ""
    error.value = ""
    success.value = ""
    await fetchFilters()
  }

  function close(): void {
    if (pending.value) return

    target.value = null
    selectedParentId.value = null
    selectedFilterId.value = null
    message.value = ""
    error.value = ""
    success.value = ""
  }

  async function submit(): Promise<void> {
    if (!target.value || pending.value || success.value !== "") return

    if (selectedParentId.value === null) {
      error.value = "Veuillez choisir un motif de signalement"
      return
    }

    if (selectedParentChildren.value.length > 0 && selectedFilterId.value === null) {
      error.value = "Veuillez choisir un sous-motif de signalement"
      return
    }

    selectedFilterId.value ??= selectedParentId.value

    pending.value = true
    error.value = ""

    try {
      const response = await apiFetch<{ message: string }>(
        `${config.public.apiBase}/pages/${target.value.pageId}/reports`,
        {
          method: "POST",
          credentials: "include",
          body: {
            reported_filter_content: selectedFilterId.value,
            reported_user_message: message.value,
            reported_content_type: target.value.contentType,
            reported_block_id: target.value.blockId || "",
          },
        },
      )

      success.value = response.message || "Signalement envoyé"
    } catch (fetchError) {
      const details = errorDetails(fetchError)
      error.value = details.status === 401
        ? "Veuillez vous connecter pour envoyer ce signalement."
        : details.message || "Envoi du signalement impossible"
    } finally {
      pending.value = false
    }
  }

  return {
    target,
    selectedParentId,
    selectedFilterId,
    message,
    pending,
    filtersPending: sharedFiltersPending,
    filters: sharedFilters,
    rootFilters,
    selectedParentChildren,
    error,
    success,
    open,
    close,
    submit,
  }
}
