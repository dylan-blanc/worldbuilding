/**
 * Normalizes current and legacy published page JSON for pageresult and moderation previews.
 * Both consumers receive the same CmsPageDocument block IDs and layouts before CmsResultBlock rendering.
 */
import type { CmsPageDocument } from "~/types/cms"

export function emptyCmsDocument(): CmsPageDocument {
  return {
    schemaVersion: 1,
    settings: {
      desktopColumns: 12,
      responsiveStrategy: "auto-stack",
    },
    blocks: {},
    layouts: {
      lg: [],
      md: [],
      sm: [],
      xs: [],
    },
  }
}

export function normalizeCmsDocument(content: unknown): CmsPageDocument {
  if (typeof content === "object" && content !== null && (content as CmsPageDocument).schemaVersion === 1) {
    const normalized = JSON.parse(JSON.stringify(content)) as CmsPageDocument
    normalized.blocks = Object.fromEntries(Object.entries(normalized.blocks || {}))
    normalized.layouts = {
      lg: normalized.layouts?.lg || [],
      md: normalized.layouts?.md || [],
      sm: normalized.layouts?.sm || [],
      xs: normalized.layouts?.xs || [],
    }

    return normalized
  }

  const normalized = emptyCmsDocument()
  const legacyBlocks = typeof content === "object" && content !== null && Array.isArray((content as { blocks?: unknown }).blocks)
    ? (content as { blocks: Array<{ type?: string, content?: string }> }).blocks
    : []

  legacyBlocks.forEach((legacyBlock, index) => {
    const id = `legacy-${index}`
    const text = typeof legacyBlock.content === "string" ? legacyBlock.content : ""

    normalized.blocks[id] = {
      id,
      type: "text",
      props: {
        label: legacyBlock.type === "heading" ? "Titre" : "Texte",
        content: {
          type: "doc",
          content: [{
            type: legacyBlock.type === "heading" ? "heading" : "paragraph",
            attrs: legacyBlock.type === "heading" ? { level: 1 } : {},
            content: text ? [{ type: "text", text }] : [],
          }],
        },
      },
    }
    normalized.layouts.lg.push({
      i: id,
      parentId: null,
      x: 0,
      y: index * 4,
      w: 12,
      h: 4,
    })
  })

  return normalized
}
