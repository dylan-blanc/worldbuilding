/**
 * Resolves profile picture URLs for UserAvatar and profile picture history.
 * Current-user keys follow frontend -> GET /api/me/picture -> UserProfileController::picture() -> MinIO.
 * Page-owner keys follow frontend -> GET /api/pages/{id}/owner-picture
 * -> PageMediaController::ownerPicture() -> User/Page SQL checks -> MinIO.
 */
import type { MaybeRefOrGetter } from "vue"

export type ProfilePictureSource = "current-user" | "page-owner"

interface ProfilePictureOptions {
  picture?: MaybeRefOrGetter<string | null | undefined>
  source?: MaybeRefOrGetter<ProfilePictureSource>
  pageId?: MaybeRefOrGetter<number | null | undefined>
  anonymous?: MaybeRefOrGetter<boolean>
}

export const useProfilePicture = (options: ProfilePictureOptions = {}) => {
  const config = useRuntimeConfig()
  const failed = ref(false)

  const resolveUrl = (
    picture: string | null | undefined,
    source: ProfilePictureSource = "current-user",
    pageId?: number | null,
  ): string => {
    if (!picture) return ""
    if (picture.startsWith("/") || /^(?:https?:|blob:|data:)/.test(picture)) return picture
    if (source === "page-owner") return pageId ? `${config.public.apiBase}/pages/${pageId}/owner-picture` : ""

    return `${config.public.apiBase}/me/picture?key=${encodeURIComponent(picture)}`
  }

  const stateKey = computed(() => JSON.stringify([
    toValue(options.picture),
    toValue(options.source),
    toValue(options.pageId),
    toValue(options.anonymous),
  ]))

  const pictureUrl = computed(() => {
    if (failed.value || toValue(options.anonymous)) return ""

    return resolveUrl(
      toValue(options.picture),
      toValue(options.source) || "current-user",
      toValue(options.pageId),
    )
  })

  const useFallback = () => {
    failed.value = true
  }

  const reset = () => {
    failed.value = false
  }

  watch(stateKey, reset)

  return {
    failed: readonly(failed),
    pictureUrl,
    reset,
    resolveUrl,
    useFallback,
  }
}
