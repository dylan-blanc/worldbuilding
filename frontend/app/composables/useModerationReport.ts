/**
 * Shares moderation filter loading and authenticated report submission between PageDisplay and CmsResultBlock.
 * UI components open a target, then POST page/block identifiers to ModerationController for server-side snapshots.
 */
import type { ReportedContentType } from "~/types/moderation"

export interface ModerationFilter {
  id: number
  filter_name: string
  filter_type: "moderation"
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
  const sharedFilters = useState<ModerationFilter[]>("moderation-report-filters", () => [])
  const sharedFiltersPending = useState<boolean>("moderation-report-filters-pending", () => false)
  const target = ref<ModerationReportTarget | null>(null)
  const selectedFilterId = ref<number | null>(null)
  const message = ref("")
  const pending = ref(false)
  const error = ref("")
  const success = ref("")

  const orderedFilters = computed(() => [...sharedFilters.value].sort((left, right) => (
    filterOrder.indexOf(left.filter_name) - filterOrder.indexOf(right.filter_name)
  )))

  async function fetchFilters(): Promise<void> {
    if (sharedFilters.value.length > 0 || sharedFiltersPending.value) return

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
    selectedFilterId.value = null
    message.value = ""
    error.value = ""
    success.value = ""
    await fetchFilters()
  }

  function close(): void {
    if (pending.value) return

    target.value = null
    selectedFilterId.value = null
    message.value = ""
    error.value = ""
    success.value = ""
  }

  async function submit(): Promise<void> {
    if (!target.value || pending.value || success.value !== "") return

    if (selectedFilterId.value === null) {
      error.value = "Veuillez choisir un motif de signalement"
      return
    }

    pending.value = true
    error.value = ""

    try {
      const response = await $fetch<{ message: string }>(
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
    selectedFilterId,
    message,
    pending,
    filtersPending: sharedFiltersPending,
    filters: sharedFilters,
    orderedFilters,
    error,
    success,
    open,
    close,
    submit,
  }
}
