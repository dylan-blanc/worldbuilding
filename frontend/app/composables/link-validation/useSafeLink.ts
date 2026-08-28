/*
  This composable provides immediate URL checks to the Tiptap link form.
  External inspection flows through POST /links/inspect -> LinkController -> SafeLinkValidator -> remote HTTPS response.
  PHP remains authoritative during publication and rechecks every external CMS link before SQL promotion.
*/
export type SafeLinkInspection = {
  safe: boolean
  url: string
  final_url: string
  declared_mime: string | null
  detected_signature: string
  signature_hex: string | null
  redirects: string[]
  message: string
}

type InspectionResponse = {
  inspection: SafeLinkInspection
}

export const useSafeLink = () => {
  const config = useRuntimeConfig()
  const apiFetch = useApi()

  const inspect = async (url: string) => {
    const response = await apiFetch<InspectionResponse>(`${config.public.apiBase}/links/inspect`, {
      method: "POST",
      credentials: "include",
      body: { url },
    })

    return response.inspection
  }

  return { inspect }
}
