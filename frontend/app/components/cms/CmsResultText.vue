<!--
  This component renders one published CMS text block as read-only Tiptap JSON.
  Pageresult passes pages.pagecontent through CmsResultBlock; validated internal and HTTPS links
  remain interactive, while no editor toolbar or document mutation is exposed.
-->
<script setup lang="ts">
import Highlight from "@tiptap/extension-highlight"
import Subscript from "@tiptap/extension-subscript"
import Superscript from "@tiptap/extension-superscript"
import TextAlign from "@tiptap/extension-text-align"
import { TextStyleKit } from "@tiptap/extension-text-style"
import Typography from "@tiptap/extension-typography"
import StarterKit from "@tiptap/starter-kit"
import { EditorContent, useEditor } from "@tiptap/vue-3"
import type { JSONContent } from "@tiptap/core"
import type { CmsJsonValue } from "~/types/cms/cms"

const props = defineProps<{
  content: CmsJsonValue
}>()

const normalizeContent = (value: CmsJsonValue): JSONContent => {
  if (typeof value === "object" && value !== null && !Array.isArray(value) && value.type === "doc") {
    return value as JSONContent
  }

  const text = typeof value === "string" ? value : ""

  return {
    type: "doc",
    content: [{
      type: "paragraph",
      content: text ? [{ type: "text", text }] : [],
    }],
  }
}

const editor = useEditor({
  content: normalizeContent(props.content),
  editable: false,
  extensions: [
    StarterKit.configure({
      heading: { levels: [1, 2, 3, 4, 5, 6] },
      link: {
        openOnClick: true,
        autolink: false,
        linkOnPaste: false,
        defaultProtocol: "https",
        HTMLAttributes: {
          rel: "noopener noreferrer nofollow",
        },
      },
    }),
    TextAlign.configure({
      types: ["heading", "paragraph"],
      alignments: ["left", "center", "right", "justify"],
    }),
    Highlight.configure({ multicolor: true }),
    Subscript,
    Superscript,
    TextStyleKit,
    Typography,
  ],
  editorProps: {
    attributes: {
      class: "cms-result-rich-text focus:outline-none",
    },
  },
})

watch(() => props.content, value => {
  if (!editor.value) return

  const normalized = normalizeContent(value)
  JSON.stringify(editor.value.getJSON()) !== JSON.stringify(normalized)
    && editor.value.commands.setContent(normalized, { emitUpdate: false })
}, { deep: true })
</script>

<template>
  <EditorContent
    v-if="editor"
    :editor="editor"
    class="size-full overflow-auto p-3 text-left"
  />
</template>

<style scoped>
:deep(.cms-result-rich-text h1) { font-size: 2rem; font-weight: 700; }
:deep(.cms-result-rich-text h2) { font-size: 1.5rem; font-weight: 700; }
:deep(.cms-result-rich-text h3) { font-size: 1.25rem; font-weight: 600; }
:deep(.cms-result-rich-text h4) { font-size: 1.125rem; font-weight: 600; }
:deep(.cms-result-rich-text h5) { font-size: 1rem; font-weight: 600; }
:deep(.cms-result-rich-text h6) { font-size: 0.875rem; font-weight: 600; }
:deep(.cms-result-rich-text ul) { list-style: disc; padding-left: 1.5rem; }
:deep(.cms-result-rich-text ol) { list-style: decimal; padding-left: 1.5rem; }
:deep(.cms-result-rich-text blockquote) { border-left: 3px solid var(--primary-border); padding-left: 0.75rem; }
:deep(.cms-result-rich-text pre) { overflow-x: auto; border-radius: 0.375rem; background: var(--third-background); padding: 0.75rem; }
:deep(.cms-result-rich-text code) { font-family: monospace; }
:deep(.cms-result-rich-text a) { color: var(--focus-color); text-decoration: underline; }
</style>
