/**
 * This utility selects and initializes the responsive CMS layouts shared by Freepage and Pageresult.
 * Desktop content uses lg, tablet content uses md and mobile content uses xs; missing responsive data is derived
 * from the visual desktop order before the editor saves it through PUT /pages/{id}/draft -> PageRevision -> MySQL.
 */
import type {
  CmsBreakpoint,
  CmsLayoutItem,
  CmsPageDocument,
  CmsViewportMode,
} from "~/types/cms"

export const cmsViewportBreakpoints: Record<CmsViewportMode, CmsBreakpoint> = {
  desktop: "lg",
  tablet: "md",
  mobile: "xs",
}

const cloneLayout = (layout: CmsLayoutItem[]): CmsLayoutItem[] => (
  layout.map(item => ({ ...item, parentId: item.parentId ?? null }))
)

const MOBILE_ROOT_ITEM_WIDTH = 354
const MOBILE_CHILD_ITEM_WIDTH = 328
const GRID_ROW_HEIGHT = 40

// Estimate media rows from the same 390 px mobile preview and grid spacing used by Freepage.
const mobileItemHeight = (
  document: CmsPageDocument,
  item: CmsLayoutItem,
): number => {
  const block = document.blocks[item.i]

  if (!block || !["image", "banner", "gallery", "video"].includes(block.type)) {
    return Math.max(item.h, 1)
  }

  const naturalWidth = Number(block.props.width)
  const naturalHeight = Number(block.props.height)

  if (!Number.isFinite(naturalWidth) || !Number.isFinite(naturalHeight)
    || naturalWidth <= 0 || naturalHeight <= 0
  ) {
    return Math.max(item.h, 1)
  }

  const margin = item.parentId ? 8 : 10
  const itemWidth = item.parentId ? MOBILE_CHILD_ITEM_WIDTH : MOBILE_ROOT_ITEM_WIDTH
  const proportionalHeight = itemWidth * naturalHeight / naturalWidth

  return Math.max(
    item.minH || 1,
    Math.round((proportionalHeight + margin) / (GRID_ROW_HEIGHT + margin)),
  )
}

export const sortCmsLayout = (layout: CmsLayoutItem[]): CmsLayoutItem[] => (
  layout
    .map((item, index) => ({ item, index }))
    .sort((left, right) => (
      left.item.y - right.item.y
      || left.item.x - right.item.x
      || left.index - right.index
    ))
    .map(entry => entry.item)
)

const hasCompleteLayout = (document: CmsPageDocument, layout: CmsLayoutItem[]): boolean => {
  const ids = new Set(layout.map(item => item.i))
  return ids.size === Object.keys(document.blocks).length
    && Object.keys(document.blocks).every(id => ids.has(id))
}

// Mobile keeps every section as one root group while stacking its children in desktop visual order.
export const createMobileLayout = (document: CmsPageDocument): CmsLayoutItem[] => {
  const desktopLayout = document.layouts.lg
  const roots: CmsLayoutItem[] = []
  const children: CmsLayoutItem[] = []
  let rootY = 0

  sortCmsLayout(desktopLayout.filter(item => !item.parentId)).forEach(sourceRoot => {
    const sourceChildren = sortCmsLayout(
      desktopLayout.filter(item => item.parentId === sourceRoot.i),
    )
    let childY = 0

    sourceChildren.forEach(sourceChild => {
      const height = mobileItemHeight(document, sourceChild)
      children.push({
        ...sourceChild,
        parentId: sourceRoot.i,
        x: 0,
        y: childY,
        w: 12,
        h: height,
        minW: 12,
        maxW: 12,
      })
      childY += height
    })

    const isSection = document.blocks[sourceRoot.i]?.type === "section"
    const height = isSection
      ? Math.max(4, childY + (sourceChildren.length ? 2 : 0))
      : mobileItemHeight(document, sourceRoot)

    roots.push({
      ...sourceRoot,
      parentId: null,
      x: 0,
      y: rootY,
      w: 12,
      h: height,
      minW: 12,
      maxW: 12,
    })
    rootY += height
  })

  return [...roots, ...children]
}

export const resolveCmsLayout = (
  document: CmsPageDocument,
  breakpoint: CmsBreakpoint,
): CmsLayoutItem[] => {
  const layout = document.layouts[breakpoint] || []

  if (breakpoint === "lg" || hasCompleteLayout(document, layout)) return layout
  if (breakpoint === "xs") return createMobileLayout(document)
  return cloneLayout(document.layouts.lg)
}

export const initializeCmsResponsiveLayouts = (document: CmsPageDocument): CmsPageDocument => {
  document.layouts.md = cloneLayout(resolveCmsLayout(document, "md"))
  document.layouts.xs = cloneLayout(resolveCmsLayout(document, "xs"))
  return document
}

export const cmsViewportModeFromWidth = (width: number): CmsViewportMode => (
  width >= 1024 ? "desktop" : width >= 768 ? "tablet" : "mobile"
)
