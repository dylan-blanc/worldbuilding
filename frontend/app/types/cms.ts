/**
 * This file defines the serializable CMS document shared by the editor, block palette and future page renderer.
 * Freepage updates blocks and layouts independently before the frontend sends the document to a PHP draft endpoint.
 * The backend will validate this schema, store drafts in page_revision and copy published JSON to pages.pagecontent.
 */

export const CMS_BLOCK_MIME = "application/x-worldbuilding-cms-block"

export type CmsBlockType = "section" | "text" | "image" | "banner" | "gallery" | "video" | "separator"
export type CmsBreakpoint = "lg" | "md" | "sm" | "xs"
export type CmsViewportMode = "desktop" | "tablet" | "mobile"
export type CmsJsonPrimitive = string | number | boolean | null
export type CmsJsonValue = CmsJsonPrimitive | CmsJsonValue[] | { [key: string]: CmsJsonValue }

export interface CmsBlockDefinition {
  type: CmsBlockType
  label: string
  defaultWidth: number
  defaultHeight: number
}

export interface CmsBlock {
  id: string
  type: CmsBlockType
  props: Record<string, CmsJsonValue>
}

export interface CmsLayoutItem {
  i: string
  parentId?: string | null
  x: number
  y: number
  w: number
  h: number
  minW?: number
  minH?: number
  maxW?: number
  maxH?: number
}

export interface CmsPageDocument {
  schemaVersion: 1
  settings: {
    desktopColumns: 12
    responsiveStrategy: "auto-stack"
  }
  blocks: Record<string, CmsBlock>
  layouts: Record<CmsBreakpoint, CmsLayoutItem[]>
}
