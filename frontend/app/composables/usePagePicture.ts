/**
 * Resolves presentation-image sources for public cards, owned pages and moderation previews.
 * New MinIO keys flow through GET /api/pages/{id}/picture, while temporary legacy HTTP/public paths remain
 * directly readable until their owner replaces them with a validated MinIO upload.
 */
export function usePagePicture() {
  const config = useRuntimeConfig()

  const resolveUrl = (pageId: number, picture: string | null | undefined): string => {
    if (!picture) return ""

    return new RegExp(`^\\d+/pages/${pageId}/images/[a-f0-9]{32}\\.(jpg|png|webp|avif|gif)$`).test(picture)
      ? `${config.public.apiBase}/pages/${pageId}/picture`
      : picture
  }

  return { resolveUrl }
}
