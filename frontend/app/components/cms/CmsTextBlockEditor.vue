<!--
  This component edits one CMS text block as Tiptap JSON inside Freepage.
  Only EditorContent occupies the block; its formatting toolbar floats above the active paragraph while text has focus.
  It emits JSON to Freepage, where the document history and autosave send it through PUT /pages/{id}/draft.
  Tiptap keeps fine-grained typing undo locally, while external links flow through POST /links/inspect before insertion.
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
import { BubbleMenu } from "@tiptap/vue-3/menus"
import type { Editor, JSONContent } from "@tiptap/core"
import type { SafeLinkInspection } from "~/composables/link-validation/useSafeLink"
import type { CmsJsonValue } from "~/types/cms/cms"

const props = defineProps<{
  modelValue: CmsJsonValue
}>()

const emit = defineEmits<{
  "update:modelValue": [value: CmsJsonValue]
  historyBoundary: []
}>()

const toolbar = ref<HTMLElement>()
const fontSizeTrigger = ref<HTMLElement>()
const fontSizeMenu = ref<HTMLElement>()
const isFontSizeMenuOpen = ref(false)
const fontSizeMenuId = useId()
const fontSizeMenuStyle = ref({ left: "0px", top: "0px", width: "4rem" })
const linkDialog = ref<HTMLElement>()
const linkInput = ref<HTMLInputElement>()
const isLinkDialogOpen = ref(false)
const isInspectingLink = ref(false)
const linkValue = ref("")
const linkError = ref("")
const linkInspection = ref<SafeLinkInspection | null>(null)
const linkSelection = ref<{ from: number, to: number } | null>(null)
const { inspect: inspectLink } = useSafeLink()
const fontFamilies = [
  { label: "Sans serif", value: "sans-serif" },
  { label: "Serif", value: "serif" },
  { label: "Monospace", value: "monospace" },
]
const fontSizes = [
  12,
  14,
  16,
  18,
  24,
  32,
  36,
  40,
  48,
  56,
  64,
  72,
  80,
  96,
  112,
  128,
]
type HeadingLevel = 1 | 2 | 3 | 4 | 5 | 6
const paragraphFontSize = 16
const headingFontSizes: Record<HeadingLevel, number> = {
  1: 32,
  2: 24,
  3: 20,
  4: 18,
  5: 16,
  6: 14,
}
const lineHeights = ["1", "1.25", "1.5", "1.75", "2"]
let historyGroupOpen = false
let historyTimer: ReturnType<typeof setTimeout> | undefined
let syncingFromParent = false

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
  onUpdate: ({ editor: currentEditor }) => {
    emit("update:modelValue", currentEditor.getJSON() as CmsJsonValue)
  },
})

const appendToolbarToBody = () => document.body
const shouldShowToolbar = ({ editor: currentEditor }: { editor: Editor }) => (
  currentEditor.isFocused || Boolean(toolbar.value?.contains(document.activeElement))
)

// Anchor the menu to the complete paragraph or heading instead of the narrower caret rectangle.
const getParagraphReference = () => {
  const currentEditor = editor.value

  if (!currentEditor) return null

  const position = currentEditor.state.selection.from
  const domPosition = currentEditor.view.domAtPos(position)
  const origin = domPosition.node instanceof HTMLElement
    ? domPosition.node
    : domPosition.node.parentElement

  return origin?.closest<HTMLElement>("p, h1, h2, h3, h4, h5, h6") || currentEditor.view.dom
}

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

// Structural format buttons replace custom sizing and persist the visible default in the Tiptap JSON.
const applyBlockFormat = (level?: HeadingLevel) => {
  const currentEditor = editor.value

  if (!currentEditor) return

  const selection = currentEditor.state.selection
  const formattingRange = selection.empty
    ? { from: selection.$from.start(), to: selection.$from.end() }
    : { from: selection.from, to: selection.to }
  const chain = currentEditor.chain().focus().setTextSelection(formattingRange)

  level ? chain.setHeading({ level }) : chain.setParagraph()
  chain
    .setFontSize(`${level ? headingFontSizes[level] : paragraphFontSize}px`)
    .setTextSelection({ from: selection.from, to: selection.to })
    .run()
}

const openLinkDialog = async () => {
  if (!editor.value) return

  linkSelection.value = {
    from: editor.value.state.selection.from,
    to: editor.value.state.selection.to,
  }
  linkValue.value = String(editor.value.getAttributes("link").href || "")
  linkError.value = ""
  linkInspection.value = null
  isLinkDialogOpen.value = true
  await nextTick()
  linkDialog.value?.focus()
  linkInput.value?.select()
}

const closeLinkDialog = () => {
  if (isInspectingLink.value) return

  isLinkDialogOpen.value = false
  const currentEditor = editor.value
  const selection = linkSelection.value

  if (currentEditor && selection && selection.to <= currentEditor.state.doc.content.size) {
    currentEditor.chain().focus().setTextSelection(selection).run()
  } else {
    currentEditor?.commands.focus()
  }

  linkSelection.value = null
}

const resetLinkFeedback = () => {
  linkError.value = ""
  linkInspection.value = null
}

const applyLink = async () => {
  const currentEditor = editor.value

  if (!currentEditor || isInspectingLink.value) return

  const href = linkValue.value.trim()
  linkError.value = ""
  linkInspection.value = null

  if (linkSelection.value && linkSelection.value.to <= currentEditor.state.doc.content.size) {
    currentEditor.commands.setTextSelection(linkSelection.value)
  }

  if (href === "") {
    currentEditor.chain().focus().extendMarkRange("link").unsetLink().run()
    isLinkDialogOpen.value = false
    linkSelection.value = null
    return
  }

  isInspectingLink.value = true

  try {
    const inspection = await inspectLink(href)
    linkInspection.value = inspection

    if (!inspection.safe) {
      linkError.value = inspection.message
      return
    }

    const isInternalLink = href.startsWith("/") && !href.startsWith("//")
    currentEditor.chain().focus().extendMarkRange("link").setLink({
      href,
      target: isInternalLink ? "_self" : "_blank",
      rel: "noopener noreferrer nofollow",
    }).run()
    isLinkDialogOpen.value = false
    linkSelection.value = null
  } catch (error) {
    const candidate = error as { data?: { error?: string } }
    linkError.value = candidate.data?.error || "La vérification du lien a échoué"
  } finally {
    isInspectingLink.value = false
  }
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
const currentFontSize = () => {
  const value = Number.parseInt(String(editor.value?.getAttributes("textStyle").fontSize || ""), 10)

  return Number.isFinite(value) ? value : ""
}
const applyFontSize = (requestedSize: number) => {
  if (!Number.isFinite(requestedSize)) return null

  const fontSize = Math.min(256, Math.max(8, Math.round(requestedSize)))
  editor.value?.chain().focus().setFontSize(`${fontSize}px`).run()
  return fontSize
}
const setFontSize = (event: Event) => {
  const input = event.target as HTMLInputElement
  const fontSize = applyFontSize(Number(input.value))

  fontSize !== null && (input.value = String(fontSize))
}
const currentPresetFontSize = () => {
  const value = currentFontSize()

  return typeof value === "number" && fontSizes.includes(value) ? value : ""
}
const closeFontSizeMenu = () => {
  isFontSizeMenuOpen.value = false
}
const toggleFontSizeMenu = () => {
  if (isFontSizeMenuOpen.value) {
    closeFontSizeMenu()
    return
  }

  const bounds = fontSizeTrigger.value?.getBoundingClientRect()

  if (!bounds) return

  fontSizeMenuStyle.value = {
    left: `${Math.max(8, Math.min(bounds.left, globalThis.innerWidth - bounds.width - 8))}px`,
    top: `${bounds.bottom + 4}px`,
    width: `${bounds.width}px`,
  }
  isFontSizeMenuOpen.value = true
}
const selectPresetFontSize = (fontSize: number) => {
  applyFontSize(fontSize)
  closeFontSizeMenu()
}
const closeFontSizeMenuFromPointer = (event: PointerEvent) => {
  const target = event.target instanceof Node ? event.target : null

  if (!target
    || fontSizeTrigger.value?.contains(target)
    || fontSizeMenu.value?.contains(target)
  ) return

  closeFontSizeMenu()
}
const setLineHeight = (event: Event) => editor.value?.chain().focus().setLineHeight(selectValue(event)).run()

onMounted(() => {
  globalThis.document.addEventListener("pointerdown", closeFontSizeMenuFromPointer)
})

onBeforeUnmount(() => {
  clearTimeout(historyTimer)
  globalThis.document.removeEventListener("pointerdown", closeFontSizeMenuFromPointer)
})
</script>

<template>
  <div v-if="editor" class="cms-no-drag relative flex h-full min-h-0 flex-col">
    <BubbleMenu
      :editor="editor"
      :append-to="appendToolbarToBody"
      :should-show="shouldShowToolbar"
      :get-referenced-virtual-element="getParagraphReference"
      :options="{
        strategy: 'fixed',
        placement: 'top',
        offset: 8,
        flip: false,
        shift: { padding: 8 },
      }"
      :update-delay="0"
      :resize-delay="0"
    >
      <div
        ref="toolbar"
        class="cms-tiptap-toolbar primary-background primary-border z-50 flex w-max max-w-[calc(100vw-1rem)] flex-col gap-1 rounded-lg border p-2 shadow-sm"
        role="toolbar"
        aria-label="Mise en forme du texte"
      >
      <div class="cms-tiptap-toolbar-row flex w-full items-center gap-1 overflow-x-auto overflow-y-hidden [scrollbar-width:thin]">
      <button type="button" class="cms-format-button" :class="activeClass('paragraph')" title="Paragraphe · 16px" @click="applyBlockFormat()">P</button>
      <button v-for="level in ([1, 2, 3, 4, 5, 6] as const)" :key="level" type="button" class="cms-format-button" :class="activeClass('heading', { level })" :title="`Titre ${level} · ${headingFontSizes[level]}px`" @click="applyBlockFormat(level)">H{{ level }}</button>
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
      </div>

      <div class="cms-tiptap-toolbar-row flex w-full items-center gap-1 overflow-x-auto overflow-y-hidden [scrollbar-width:thin]">
      <button type="button" class="cms-format-button" :class="activeClass('link')" title="Ajouter ou modifier un lien" @click="openLinkDialog">Lien</button>
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
      <div class="form-control flex h-8 items-center gap-1 rounded border px-1 text-xs" title="Taille du texte entre 8 et 256 pixels">
        Taille
        <button
          ref="fontSizeTrigger"
          type="button"
          class="flex h-6 w-16 items-center justify-between rounded px-1 outline-none"
          aria-haspopup="listbox"
          :aria-controls="fontSizeMenuId"
          :aria-expanded="isFontSizeMenuOpen"
          aria-label="Ouvrir les tailles de texte prédéfinies"
          @click="toggleFontSizeMenu"
        >
          <span>{{ currentPresetFontSize() || "Choix" }}</span>
          <span aria-hidden="true">▾</span>
        </button>
        <input
          type="number"
          class="w-14 bg-transparent text-right outline-none"
          :value="currentFontSize()"
          min="8"
          max="256"
          step="1"
          inputmode="numeric"
          aria-label="Taille du texte en pixels"
          @change="setFontSize"
        >
        <span>px</span>
      </div>
      <select class="form-control h-8 rounded border px-1 text-xs" title="Interligne" :value="editor.getAttributes('textStyle').lineHeight || ''" @change="setLineHeight">
        <option value="" disabled>Interligne</option>
        <option v-for="height in lineHeights" :key="height" :value="height">{{ height }}</option>
      </select>

      <button type="button" class="form-control cms-format-button" title="Effacer la mise en forme" @click="editor.chain().focus().unsetAllMarks().clearNodes().run()">Effacer</button>
      <button type="button" class="form-control cms-format-button" :disabled="!editor.can().undo()" title="Annuler la saisie" @click="editor.chain().focus().undo().run()">↶</button>
        <button type="button" class="form-control cms-format-button" :disabled="!editor.can().redo()" title="Rétablir la saisie" @click="editor.chain().focus().redo().run()">↷</button>
      </div>
      </div>
    </BubbleMenu>

    <Teleport to="body">
      <div
        v-if="isFontSizeMenuOpen"
        :id="fontSizeMenuId"
        ref="fontSizeMenu"
        role="listbox"
        aria-label="Tailles de texte prédéfinies"
        class="primary-background primary-border fixed z-[70] h-[194px] touch-pan-y overflow-y-auto overscroll-contain rounded-md border shadow-xl [scrollbar-width:thin]"
        :style="fontSizeMenuStyle"
      >
        <button
          v-for="size in fontSizes"
          :key="size"
          type="button"
          role="option"
          class="flex h-8 w-full shrink-0 items-center justify-center px-2 text-xs"
          :class="currentFontSize() === size ? 'button-primary' : 'form-control'"
          :aria-selected="currentFontSize() === size"
          @mousedown.prevent
          @click="selectPresetFontSize(size)"
        >
          {{ size }}
        </button>
      </div>
    </Teleport>

    <Teleport to="body">
      <div
        v-if="isLinkDialogOpen"
        class="fixed inset-0 z-[80] flex items-center justify-center bg-black/70 p-4"
        role="presentation"
        @click.self="closeLinkDialog"
        @keydown.esc="closeLinkDialog"
      >
        <section
          ref="linkDialog"
          role="dialog"
          aria-modal="true"
          aria-labelledby="cms-link-dialog-title"
          tabindex="-1"
          class="primary-background primary-border w-full max-w-lg rounded-xl border p-5 shadow-2xl outline-none"
        >
          <h2 id="cms-link-dialog-title" class="text-xl font-semibold">Ajouter un lien</h2>
          <p class="secondary-color mt-1 text-sm">
            Les liens externes sont contrôlés avant leur insertion.
          </p>

          <form class="mt-4 flex flex-col gap-4" @submit.prevent="applyLink">
            <label class="flex flex-col gap-2 text-sm font-medium">
              Adresse interne ou HTTPS
              <input
                ref="linkInput"
                v-model="linkValue"
                type="text"
                maxlength="2048"
                class="form-control rounded-md border px-3 py-2"
                :disabled="isInspectingLink"
                autocomplete="off"
                @input="resetLinkFeedback"
              >
            </label>

            <section
              v-if="linkInspection"
              class="secondary-background primary-border rounded-md border p-3 text-sm"
            >
              <dl class="grid gap-2 sm:grid-cols-[auto_1fr]">
                <dt class="secondary-color">MIME déclaré</dt>
                <dd class="break-all">{{ linkInspection.declared_mime || "Non disponible" }}</dd>
                <dt class="secondary-color">Signature détectée</dt>
                <dd>{{ linkInspection.detected_signature }}</dd>
                <dt v-if="linkInspection.signature_hex" class="secondary-color">Premiers octets</dt>
                <dd v-if="linkInspection.signature_hex" class="break-all font-mono text-xs">
                  {{ linkInspection.signature_hex }}
                </dd>
                <dt class="secondary-color">Redirections</dt>
                <dd>{{ linkInspection.redirects.length }}/3</dd>
              </dl>
            </section>

            <p v-if="linkError" class="error-color text-sm font-medium" role="alert">
              {{ linkError }}
            </p>

            <footer class="flex justify-end gap-3">
              <button
                type="button"
                class="primary-border rounded-md border px-4 py-2 disabled:opacity-40"
                :disabled="isInspectingLink"
                @click="closeLinkDialog"
              >
                Annuler
              </button>
              <button
                type="submit"
                class="button-primary rounded-md px-4 py-2 disabled:opacity-40"
                :disabled="isInspectingLink"
              >
                {{ isInspectingLink ? "Vérification…" : "Vérifier et insérer" }}
              </button>
            </footer>
          </form>
        </section>
      </div>
    </Teleport>

    <EditorContent :editor="editor" class="min-h-0 flex-1 overflow-auto p-3 text-left" />
  </div>
</template>

<style scoped>
@media (max-width: 767px) {
  .cms-tiptap-toolbar-row {
    scroll-snap-type: x proximity;
    -webkit-overflow-scrolling: touch;
  }

  .cms-tiptap-toolbar-row > * {
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
