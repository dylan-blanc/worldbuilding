<!--
  This view renders the published JSON of one page for readers and owners.
  It fetches GET /pages/{id} in the browser so Nuxt remains SSG-compatible and the session cookie
  can authorize private or admin-only banned content, then maps blocks and XYWH layouts to CSS grids.
-->
<script setup lang="ts">
import type { CSSProperties } from "vue"
import type {
  CmsBlock,
  CmsLayoutItem,
  CmsPageDocument,
} from "~/types/cms"

type ResultPage = {
  id: number
  owner_user_id: number | null
  page_title: string
  page_status: "public" | "private" | "banned"
  is_anonymous: boolean
  pagecontent: unknown
}

type PageResponse = {
  page: ResultPage
}

const props = defineProps<{
  pageId: number
}>()

const config = useRuntimeConfig()
const page = ref<ResultPage | null>(null)
const document = ref<CmsPageDocument | null>(null)
const pending = ref(true)
const errorMessage = ref("")

const emptyDocument = (): CmsPageDocument => ({
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
})

// Legacy fixtures are converted only for display; current publications already use schemaVersion 1.
const normalizeDocument = (content: unknown): CmsPageDocument => {
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

  const normalized = emptyDocument()
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

const errorText = (error: unknown) => {
  if (typeof error !== "object" || error === null) return "Impossible de charger cette page"

  const status = (error as { statusCode?: number, response?: { status?: number } }).statusCode
    || (error as { response?: { status?: number } }).response?.status

  return status === 404
    ? "Cette page est introuvable ou vous n’êtes pas autorisé à la consulter."
    : "Impossible de charger cette page."
}

const loadPage = async () => {
  pending.value = true
  errorMessage.value = ""

  try {
    const response = await $fetch<PageResponse>(`${config.public.apiBase}/pages/${props.pageId}`, {
      credentials: "include",
    })

    page.value = response.page
    document.value = normalizeDocument(response.page.pagecontent)
  } catch (error) {
    page.value = null
    document.value = null
    errorMessage.value = errorText(error)
  } finally {
    pending.value = false
  }
}

const rootLayout = computed(() => document.value?.layouts.lg.filter(item => !item.parentId) || [])
const childLayout = (sectionId: string) => (
  document.value?.layouts.lg.filter(item => item.parentId === sectionId) || []
)
const blockById = (blockId: string): CmsBlock | null => document.value?.blocks[blockId] || null
const itemStyle = (item: CmsLayoutItem): CSSProperties => ({
  gridColumn: `${item.x + 1} / span ${item.w}`,
  gridRow: `${item.y + 1} / span ${item.h}`,
})

onMounted(() => {
  watch(() => props.pageId, loadPage, { immediate: true })
})
</script>

<template>
  <section class="flex w-full flex-col p-[5px]">
    <LoadingSpinner
      v-if="pending"
      label="Chargement de la page"
    />

    <div
      v-else-if="errorMessage"
      class="flex min-h-96 flex-col items-center justify-center gap-4 text-center"
    >
      <p class="error-color">{{ errorMessage }}</p>
      <button type="button" class="button-primary rounded-md px-4 py-2" @click="loadPage">
        Réessayer
      </button>
    </div>

    <template v-else-if="page && document">
      <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <span class="secondary-background primary-border rounded-full border px-3 py-1 text-sm">
          {{ page.page_status === "public" ? "Page publique" : page.page_status === "private" ? "Page privée" : "Page bannie" }}
        </span>
      </header>

      <p
        v-if="rootLayout.length === 0"
        class="secondary-color flex min-h-96 items-center justify-center text-center"
      >
        Cette page publiée ne contient encore aucun bloc.
      </p>

      <div v-else class="w-full rounded-xl border-2 border-transparent p-2">
        <div class="cms-result-grid w-full">
          <div
            v-for="item in rootLayout"
            :key="item.i"
            :style="itemStyle(item)"
            class="cms-result-item min-h-24 min-w-0 overflow-hidden"
          >
            <CmsResultBlock
              v-if="blockById(item.i)"
              :block="blockById(item.i)!"
              :page-id="page.id"
            >
              <template #section>
                <div class="cms-result-grid cms-result-child-grid size-full">
                  <div
                    v-for="child in childLayout(item.i)"
                    :key="child.i"
                    :style="itemStyle(child)"
                    class="cms-result-item min-h-24 min-w-0 overflow-hidden"
                  >
                    <CmsResultBlock
                      v-if="blockById(child.i)"
                      :block="blockById(child.i)!"
                      :page-id="page.id"
                    />
                  </div>
                </div>
              </template>
            </CmsResultBlock>
          </div>
        </div>
      </div>
    </template>
  </section>
</template>

<style scoped>
.cms-result-grid {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  grid-auto-rows: 40px;
  gap: 10px;
  padding: 10px;
}

.cms-result-child-grid {
  gap: 8px;
  padding: 8px;
}

@media (max-width: 767px) {
  .cms-result-grid {
    display: flex;
    flex-direction: column;
    padding: 0;
  }

  .cms-result-item {
    width: 100%;
    min-height: 8rem;
    overflow: visible;
  }
}
</style>
