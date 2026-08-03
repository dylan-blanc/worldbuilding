<!--
  This administration preview renders current or reported page JSON with the same output as pageresult.
  Every reported block ID receives a yellow border and emits its ID for grouped report details.
  Reporting controls stay disabled because this component is used in the protected moderation workspace.
-->
<script setup lang="ts">
import type { CSSProperties } from "vue"
import type { CmsBlock, CmsLayoutItem } from "~/types/cms"
import { normalizeCmsDocument } from "~/utils/cmsDocument"

const props = defineProps<{
  content: unknown
  pageId: number
  reportedBlockIds: string[]
}>()

const emit = defineEmits<{
  selectReportedBlock: [blockId: string]
}>()

const document = computed(() => normalizeCmsDocument(props.content))
const rootLayout = computed(() => document.value.layouts.lg.filter(item => !item.parentId))
const missingReportedBlockIds = computed(() => (
  props.reportedBlockIds.filter(blockId => !blockById(blockId))
))
const childLayout = (sectionId: string): CmsLayoutItem[] => (
  document.value.layouts.lg.filter(item => item.parentId === sectionId)
)
const blockById = (blockId: string): CmsBlock | null => document.value.blocks[blockId] || null
const itemStyle = (item: CmsLayoutItem): CSSProperties => ({
  gridColumn: `${item.x + 1} / span ${item.w}`,
  gridRow: `${item.y + 1} / span ${item.h}`,
})
const isReported = (blockId: string): boolean => props.reportedBlockIds.includes(blockId)

function selectBlock(blockId: string): void {
  isReported(blockId) && emit("selectReportedBlock", blockId)
}
</script>

<template>
  <div>
    <p
      v-if="missingReportedBlockIds.length"
      class="warning-color mb-4 rounded-lg border border-(--warning-color) p-3 text-sm"
    >
      Certains contenus signalés n’existent plus dans cette version.
      Leurs instantanés restent disponibles dans les détails.
    </p>

    <div class="moderation-result-grid w-full">
      <div
        v-for="item in rootLayout"
        :key="item.i"
        :style="itemStyle(item)"
        class="moderation-result-item relative min-h-24 min-w-0"
        :class="isReported(item.i)
          ? 'cursor-pointer overflow-visible border-4 border-(--warning-color)'
          : 'overflow-hidden border-4 border-transparent'"
        :role="isReported(item.i) ? 'button' : undefined"
        :tabindex="isReported(item.i) ? 0 : undefined"
        @click="selectBlock(item.i)"
        @keydown.enter.prevent="selectBlock(item.i)"
        @keydown.space.prevent="selectBlock(item.i)"
      >
        <CmsResultBlock
          v-if="blockById(item.i)"
          :block="blockById(item.i)!"
          :page-id="pageId"
          :reportable="false"
        >
          <template #section>
            <div class="moderation-result-grid moderation-result-child-grid size-full">
              <div
                v-for="child in childLayout(item.i)"
                :key="child.i"
                :style="itemStyle(child)"
                class="moderation-result-item relative min-h-24 min-w-0"
                :class="isReported(child.i)
                  ? 'cursor-pointer overflow-visible border-4 border-(--warning-color)'
                  : 'overflow-hidden border-4 border-transparent'"
                :role="isReported(child.i) ? 'button' : undefined"
                :tabindex="isReported(child.i) ? 0 : undefined"
                @click.stop="selectBlock(child.i)"
                @keydown.enter.prevent.stop="selectBlock(child.i)"
                @keydown.space.prevent.stop="selectBlock(child.i)"
              >
                <CmsResultBlock
                  v-if="blockById(child.i)"
                  :block="blockById(child.i)!"
                  :page-id="pageId"
                  :reportable="false"
                />
              </div>
            </div>
          </template>
        </CmsResultBlock>
      </div>
    </div>

    <p v-if="rootLayout.length === 0" class="secondary-color py-8 text-center">
      Cette page ne contient aucun bloc publié.
    </p>
  </div>
</template>

<style scoped>
.moderation-result-grid {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  grid-auto-rows: 40px;
  gap: 10px;
  padding: 10px;
}

.moderation-result-child-grid {
  gap: 8px;
  padding: 8px;
}

@media (max-width: 767px) {
  .moderation-result-grid {
    display: flex;
    flex-direction: column;
    padding: 0;
  }

  .moderation-result-item {
    width: 100%;
    min-height: 8rem;
    overflow: visible;
  }
}
</style>
