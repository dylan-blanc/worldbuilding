<!--
  This component edits one CMS text block as Tiptap JSON inside Freepage.
  Only EditorContent occupies the block; its formatting toolbar is teleported into CmsToolbar while text has focus.
  It emits JSON to Freepage, where the document history and autosave send it through PUT /pages/{id}/draft.
  Tiptap keeps fine-grained typing undo locally while historyBoundary creates grouped whole-document undo points.
-->
<script setup lang="ts">
import Highlight from "@tiptap/extension-highlight"
import Placeholder from "@tiptap/extension-placeholder"
import Subscript from "@tiptap/extension-subscript"
import Superscript from "@tiptap/extension-superscript"
import TextAlign from "@tiptap/extension-text-align"
import { TextStyleKit } from "@tiptap/extension-text-style"
import Typography from "@tiptap/extension-typography"
import StarterKit from "@tiptap/starter-kit"
import { EditorContent, useEditor } from "@tiptap/vue-3"
import type { JSONContent } from "@tiptap/core"
import type { CmsJsonValue } from "~/types/cms"

const props = defineProps<{
  modelValue: CmsJsonValue
}>()

const emit = defineEmits<{
  "update:modelValue": [value: CmsJsonValue]
  historyBoundary: []
}>()

const toolbar = ref<HTMLElement>()
const isToolbarVisible = ref(false)
const fontFamilies = [
  { label: "Sans serif", value: "sans-serif" },
  { label: "Serif", value: "serif" },
  { label: "Monospace", value: "monospace" },
]
const fontSizes = ["12px", "14px", "16px", "18px", "24px", "32px"]
const lineHeights = ["1", "1.25", "1.5", "1.75", "2"]
let historyGroupOpen = false
let historyTimer: ReturnType<typeof setTimeout> | undefined
let blurTimer: ReturnType<typeof setTimeout> | undefined
let syncingFromParent = false

const setToolbarVisibility = (visible: boolean) => {
  if (isToolbarVisible.value === visible) return

  isToolbarVisible.value = visible
}

const showToolbar = () => {
  clearTimeout(blurTimer)
  setToolbarVisibility(true)
}

// Keep the toolbar open when focus moves from ProseMirror to one of its formatting controls.
const scheduleToolbarClose = () => {
  clearTimeout(blurTimer)
  blurTimer = setTimeout(() => {
    toolbar.value?.contains(document.activeElement) || setToolbarVisibility(false)
  })
}

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

const openHistoryGroup = () => {
  if (!historyGroupOpen) {
    historyGroupOpen = true
    emit("historyBoundary")
  }

  clearTimeout(historyTimer)
  historyTimer = setTimeout(() => {
    historyGroupOpen = false
  }, 750)
}

const editor = useEditor({
  content: normalizeContent(props.modelValue),
  extensions: [
    StarterKit.configure({
      heading: { levels: [1, 2, 3, 4, 5, 6] },
      link: {
        openOnClick: false,
        autolink: false,
        linkOnPaste: false,
        defaultProtocol: "https",
        HTMLAttributes: {
          rel: "noopener noreferrer nofollow",
        },
      },
      undoRedo: {
        depth: 100,
        newGroupDelay: 500,
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
    Placeholder.configure({
      placeholder: "Commencez à écrire…",
    }),
  ],
  editorProps: {
    attributes: {
      class: "cms-tiptap-content min-h-24 focus:outline-none",
    },
  },
  onTransaction: ({ transaction }) => {
    transaction.docChanged && !syncingFromParent && openHistoryGroup()
  },
  onFocus: showToolbar,
  onBlur: scheduleToolbarClose,
  onUpdate: ({ editor: currentEditor }) => {
    emit("update:modelValue", currentEditor.getJSON() as CmsJsonValue)
  },
})

watch(() => props.modelValue, value => {
  if (!editor.value) return

  const normalized = normalizeContent(value)

  if (JSON.stringify(editor.value.getJSON()) !== JSON.stringify(normalized)) {
    clearTimeout(historyTimer)
    historyGroupOpen = false
    syncingFromParent = true
    editor.value.commands.setContent(normalized, { emitUpdate: false })
    syncingFromParent = false
  }
}, { deep: true })

const activeClass = (name: string, attributes?: Record<string, unknown>) => (
  editor.value?.isActive(name, attributes) ? "button-primary" : "form-control"
)

const applyLink = () => {
  if (!editor.value) return

  const currentHref = String(editor.value.getAttributes("link").href || "")
  const requestedHref = globalThis.prompt("Lien interne ou HTTPS", currentHref)

  if (requestedHref === null) return

  const href = requestedHref.trim()

  if (href === "") {
    editor.value.chain().focus().extendMarkRange("link").unsetLink().run()
    return
  }

  if ((!href.startsWith("/") || href.startsWith("//")) && !href.startsWith("https://")) {
    globalThis.alert("Seuls les liens internes et HTTPS sont autorisés.")
    return
  }

  editor.value.chain().focus().extendMarkRange("link").setLink({
    href,
    target: href.startsWith("/") ? "_self" : "_blank",
    rel: "noopener noreferrer nofollow",
  }).run()
}

const setTextColor = (event: Event) => {
  const color = (event.target as HTMLInputElement).value
  editor.value?.chain().focus().setColor(color).run()
}

const setHighlightColor = (event: Event) => {
  const color = (event.target as HTMLInputElement).value
  editor.value?.chain().focus().setHighlight({ color }).run()
}

const selectValue = (event: Event) => (event.target as HTMLSelectElement).value
const setFontFamily = (event: Event) => editor.value?.chain().focus().setFontFamily(selectValue(event)).run()
const setFontSize = (event: Event) => editor.value?.chain().focus().setFontSize(selectValue(event)).run()
const setLineHeight = (event: Event) => editor.value?.chain().focus().setLineHeight(selectValue(event)).run()

onBeforeUnmount(() => {
  clearTimeout(historyTimer)
  clearTimeout(blurTimer)
})
</script>

<template>
  <div v-if="editor" class="cms-no-drag relative flex h-full min-h-0 flex-col">
    <Teleport v-if="isToolbarVisible" to="#cms-text-toolbar-host">
      <div
        ref="toolbar"
        class="cms-tiptap-toolbar primary-background primary-border flex w-full flex-wrap items-center gap-1 rounded-lg border p-2 shadow-sm"
        role="toolbar"
        aria-label="Mise en forme du texte"
        @focusin="showToolbar"
        @focusout="scheduleToolbarClose"
      >
      <button type="button" class="cms-format-button" :class="activeClass('paragraph')" title="Paragraphe" @click="editor.chain().focus().setParagraph().run()">P</button>
      <button v-for="level in ([1, 2, 3, 4, 5, 6] as const)" :key="level" type="button" class="cms-format-button" :class="activeClass('heading', { level })" :title="`Titre ${level}`" @click="editor.chain().focus().toggleHeading({ level }).run()">H{{ level }}</button>
      <button type="button" class="cms-format-button font-bold" :class="activeClass('bold')" title="Gras" @click="editor.chain().focus().toggleBold().run()">B</button>
      <button type="button" class="cms-format-button italic" :class="activeClass('italic')" title="Italique" @click="editor.chain().focus().toggleItalic().run()">I</button>
      <button type="button" class="cms-format-button underline" :class="activeClass('underline')" title="Souligné" @click="editor.chain().focus().toggleUnderline().run()">U</button>
      <button type="button" class="cms-format-button line-through" :class="activeClass('strike')" title="Barré" @click="editor.chain().focus().toggleStrike().run()">S</button>
      <button type="button" class="cms-format-button font-mono" :class="activeClass('code')" title="Code en ligne" @click="editor.chain().focus().toggleCode().run()">&lt;/&gt;</button>
      <button type="button" class="cms-format-button" :class="activeClass('subscript')" title="Indice" @click="editor.chain().focus().toggleSubscript().run()">X₂</button>
      <button type="button" class="cms-format-button" :class="activeClass('superscript')" title="Exposant" @click="editor.chain().focus().toggleSuperscript().run()">X²</button>

      <button type="button" class="cms-format-button" :class="activeClass('bulletList')" title="Liste à puces" @click="editor.chain().focus().toggleBulletList().run()">• Liste</button>
      <button type="button" class="cms-format-button" :class="activeClass('orderedList')" title="Liste numérotée" @click="editor.chain().focus().toggleOrderedList().run()">1. Liste</button>
      <button type="button" class="cms-format-button" :class="activeClass('blockquote')" title="Citation" @click="editor.chain().focus().toggleBlockquote().run()">❝</button>
      <button type="button" class="cms-format-button" :class="activeClass('codeBlock')" title="Bloc de code" @click="editor.chain().focus().toggleCodeBlock().run()">Code</button>
      <button type="button" class="form-control cms-format-button" title="Séparateur horizontal" @click="editor.chain().focus().setHorizontalRule().run()">―</button>

      <button v-for="alignment in ['left', 'center', 'right', 'justify']" :key="alignment" type="button" class="cms-format-button" :class="activeClass({ left: 'paragraph', center: 'paragraph', right: 'paragraph', justify: 'paragraph' }[alignment] || 'paragraph', { textAlign: alignment })" :title="`Aligner ${alignment}`" @click="editor.chain().focus().setTextAlign(alignment).run()">
        {{ { left: "⇤", center: "↔", right: "⇥", justify: "☰" }[alignment] }}
      </button>

      <button type="button" class="cms-format-button" :class="activeClass('link')" title="Ajouter ou modifier un lien" @click="applyLink">Lien</button>
      <button type="button" class="form-control cms-format-button" title="Retirer le lien" @click="editor.chain().focus().unsetLink().run()">Sans lien</button>

      <label class="form-control flex h-8 items-center gap-1 rounded border px-1 text-xs" title="Couleur du texte">
        Texte
        <input type="color" class="size-5 cursor-pointer" :value="editor.getAttributes('textStyle').color || '#071a33'" @input="setTextColor">
      </label>
      <label class="form-control flex h-8 items-center gap-1 rounded border px-1 text-xs" title="Surlignage">
        Fond
        <input type="color" class="size-5 cursor-pointer" :value="editor.getAttributes('highlight').color || '#fff59d'" @input="setHighlightColor">
      </label>

      <select class="form-control h-8 rounded border px-1 text-xs" title="Police" :value="editor.getAttributes('textStyle').fontFamily || ''" @change="setFontFamily">
        <option value="" disabled>Police</option>
        <option v-for="font in fontFamilies" :key="font.value" :value="font.value">{{ font.label }}</option>
      </select>
      <select class="form-control h-8 rounded border px-1 text-xs" title="Taille" :value="editor.getAttributes('textStyle').fontSize || ''" @change="setFontSize">
        <option value="" disabled>Taille</option>
        <option v-for="size in fontSizes" :key="size" :value="size">{{ size }}</option>
      </select>
      <select class="form-control h-8 rounded border px-1 text-xs" title="Interligne" :value="editor.getAttributes('textStyle').lineHeight || ''" @change="setLineHeight">
        <option value="" disabled>Interligne</option>
        <option v-for="height in lineHeights" :key="height" :value="height">{{ height }}</option>
      </select>

      <button type="button" class="form-control cms-format-button" title="Effacer la mise en forme" @click="editor.chain().focus().unsetAllMarks().clearNodes().run()">Effacer</button>
      <button type="button" class="form-control cms-format-button" :disabled="!editor.can().undo()" title="Annuler la saisie" @click="editor.chain().focus().undo().run()">↶</button>
        <button type="button" class="form-control cms-format-button" :disabled="!editor.can().redo()" title="Rétablir la saisie" @click="editor.chain().focus().redo().run()">↷</button>
      </div>
    </Teleport>

    <EditorContent :editor="editor" class="min-h-0 flex-1 overflow-auto p-3 text-left" />
  </div>
</template>

<style scoped>
.cms-tiptap-toolbar {
  max-height: 16rem;
  overflow-y: auto;
}

@media (max-width: 767px) {
  .cms-tiptap-toolbar {
    flex-wrap: nowrap;
    overflow-x: auto;
    overflow-y: hidden;
    scroll-snap-type: x proximity;
    -webkit-overflow-scrolling: touch;
  }

  .cms-tiptap-toolbar > * {
    flex: 0 0 auto;
    min-height: 2.75rem;
    scroll-snap-align: start;
  }

  .cms-format-button {
    height: 2.75rem;
    min-width: 2.75rem;
  }
}

.cms-format-button {
  height: 2rem;
  min-width: 2rem;
  border: 1px solid var(--primary-border);
  border-radius: 0.25rem;
  padding-inline: 0.375rem;
  font-size: 0.75rem;
}

:deep(.cms-tiptap-content p.is-editor-empty:first-child::before) {
  float: left;
  height: 0;
  color: var(--secondary-color);
  pointer-events: none;
  content: attr(data-placeholder);
}

:deep(.cms-tiptap-content h1) { font-size: 2rem; font-weight: 700; }
:deep(.cms-tiptap-content h2) { font-size: 1.5rem; font-weight: 700; }
:deep(.cms-tiptap-content h3) { font-size: 1.25rem; font-weight: 600; }
:deep(.cms-tiptap-content h4) { font-size: 1.125rem; font-weight: 600; }
:deep(.cms-tiptap-content h5) { font-size: 1rem; font-weight: 600; }
:deep(.cms-tiptap-content h6) { font-size: 0.875rem; font-weight: 600; }
:deep(.cms-tiptap-content ul) { list-style: disc; padding-left: 1.5rem; }
:deep(.cms-tiptap-content ol) { list-style: decimal; padding-left: 1.5rem; }
:deep(.cms-tiptap-content blockquote) { border-left: 3px solid var(--primary-border); padding-left: 0.75rem; }
:deep(.cms-tiptap-content pre) { overflow-x: auto; border-radius: 0.375rem; background: var(--third-background); padding: 0.75rem; }
:deep(.cms-tiptap-content code) { font-family: monospace; }
:deep(.cms-tiptap-content a) { color: var(--focus-color); text-decoration: underline; }
</style>
