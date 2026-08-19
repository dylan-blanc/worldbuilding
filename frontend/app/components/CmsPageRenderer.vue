<!--
  This component renders one CMS JSON document for both pageresult and the private Freepage preview.
  Its document and responsive breakpoint flow from the parent view into resolveCmsLayout, then CmsResultBlock
  renders the shared content while media continues through GET /pages/{id}/media.
  Preview mode removes moderation actions and navigation without changing the published visual layout.
-->
<script setup lang="ts">
import type { CSSProperties } from "vue"
import type {
  CmsBlock,
  CmsBreakpoint,
  CmsLayoutItem,
  CmsPageDocument,
} from "~/types/cms"
import { resolveCmsLayout, sortCmsLayout } from "~/utils/cmsLayout"

const props = withDefaults(defineProps<{
  document: CmsPageDocument
  pageId: number
  breakpoint: CmsBreakpoint
  preview?: boolean
}>(), {
  preview: false,
})

const activeLayout = computed(() => resolveCmsLayout(props.document, props.breakpoint))
const rootLayout = computed(() => sortCmsLayout(
  activeLayout.value.filter(item => !item.parentId),
))
const childLayout = (sectionId: string) => sortCmsLayout(
  activeLayout.value.filter(item => item.parentId === sectionId),
)
const blockById = (blockId: string): CmsBlock | null => props.document.blocks[blockId] || null
const itemStyle = (item: CmsLayoutItem): CSSProperties => ({
  gridColumn: `${item.x + 1} / span ${item.w}`,
  gridRow: `${item.y + 1} / span ${item.h}`,
})

// Rich-text links remain visible in previews but cannot navigate away from the unsaved page.
const preventPreviewNavigation = (event: MouseEvent) => {
  const target = event.target instanceof Element ? event.target : null
  props.preview && target?.closest("a") && event.preventDefault()
}
</script>

<template>
  <div
    class="w-full rounded-xl border-2 border-transparent p-2"
    @click.capture="preventPreviewNavigation"
  >
    <div class="cms-page-grid w-full">
      <div
        v-for="item in rootLayout"
        :key="item.i"
        :style="itemStyle(item)"
        class="min-h-0 min-w-0 overflow-hidden"
      >
        <CmsResultBlock
          v-if="blockById(item.i)"
          :block="blockById(item.i)!"
          :page-id="pageId"
          :reportable="!preview"
          :media-navigation-enabled="!preview"
        >
          <template #section>
            <div class="cms-page-grid cms-page-child-grid size-full">
              <div
                v-for="child in childLayout(item.i)"
                :key="child.i"
                :style="itemStyle(child)"
                class="min-h-0 min-w-0 overflow-hidden"
              >
                <CmsResultBlock
                  v-if="blockById(child.i)"
                  :block="blockById(child.i)!"
                  :page-id="pageId"
                  :reportable="!preview"
                  :media-navigation-enabled="!preview"
                />
              </div>
            </div>
          </template>
        </CmsResultBlock>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cms-page-grid {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  grid-auto-rows: 40px;
  gap: 10px;
  padding: 10px;
}

.cms-page-child-grid {
  gap: 8px;
  padding: 8px;
}
</style>
