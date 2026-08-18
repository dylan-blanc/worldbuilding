<!--
  This view edits one CMS page selected by pagecms.vue through its numeric pageId prop.
  Content, parent-child containment and XYWH layouts flow through GET/PUT /pages/{id}/draft to PageController,
  CmsContentValidator, PageRevision and MySQL; publication sends the selected public identity mode through
  POST /pages/{id}/publish -> PageController -> PageRevision -> the pages and page_revision SQL tables.
  Media flows through POST /pages/{id}/media, PHP signature validation/re-encoding, MinIO and an authorized GET proxy.
  New image uploads store their natural ratio and resize the related desktop grid item; later resizes snap to that ratio.
-->
<script setup lang="ts">
import {
  EyeSlashIcon,
  GlobeAltIcon,
  XMarkIcon,
} from "@heroicons/vue/24/outline";
import { GridItem, GridLayout, type Layout } from "grid-layout-plus";
import {
  CMS_BLOCK_MIME,
  type CmsBlock,
  type CmsBlockDefinition,
  type CmsBlockType,
  type CmsJsonValue,
  type CmsLayoutItem,
  type CmsPageDocument,
} from "~/types/cms";

type DraftResponse = {
  revision: {
    revision_number: number;
    pagecontent: CmsPageDocument;
    updated_at: string;
  };
};

type MediaResponse = {
  media: {
    objectKey: string;
    mediaType: "image" | "video";
    mimeType: string;
    size: number;
    width: number | null;
    height: number | null;
    url: string;
  };
};

type PublicationVisibility = "public" | "anonymous";

const props = defineProps<{
  pageId: number;
}>();

const emit = defineEmits<{
  historyState: [state: { canUndo: boolean; canRedo: boolean }];
}>();

const config = useRuntimeConfig();
const router = useRouter();
const canvas = ref<HTMLElement>();
const publishDialog = ref<HTMLElement>();
const isLoaded = ref(false);
const isSaving = ref(false);
const isPublishing = ref(false);
const isPublishModalOpen = ref(false);
const publicationVisibility = ref<PublicationVisibility>("public");
const dirty = ref(false);
const statusMessage = ref("");
const errorMessage = ref("");
const uploadingBlockId = ref<string | null>(null);
const revisionNumber = ref<number | null>(null);
const historyPast = ref<string[]>([]);
const historyFuture = ref<string[]>([]);
const historyLimit = 50;
const historyCharacterLimit = 10_000_000;
const gridRowHeight = 40;
const rootGridMargin = 10;
const childGridMargin = 8;
let autosaveTimer: ReturnType<typeof setTimeout> | undefined;
let checkpointTimer: ReturnType<typeof setInterval> | undefined;
let pendingGridSnapshot: string | null = null;

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
});

const responsiveBreakpoints = ["lg", "md", "sm", "xs"] as const;

const pageDocument = ref<CmsPageDocument>(emptyDocument());
const allowedBlockTypes: CmsBlockType[] = [
  "section",
  "text",
  "image",
  "banner",
  "gallery",
  "video",
  "separator",
];

// Grid Layout Plus adds transient runtime fields, so only the public CMS contract reaches PHP.
const createSnapshot = (): string =>
  JSON.stringify({
    ...pageDocument.value,
    blocks: Object.fromEntries(Object.entries(pageDocument.value.blocks)),
    layouts: Object.fromEntries(
      responsiveBreakpoints.map((breakpoint) => [
        breakpoint,
        pageDocument.value.layouts[breakpoint].map((item) => ({
          i: item.i,
          parentId: item.parentId ?? null,
          x: item.x,
          y: item.y,
          w: item.w,
          h: item.h,
          ...(item.minW === undefined ? {} : { minW: item.minW }),
          ...(item.minH === undefined ? {} : { minH: item.minH }),
          ...(item.maxW === undefined ? {} : { maxW: item.maxW }),
          ...(item.maxH === undefined ? {} : { maxH: item.maxH }),
        })),
      ]),
    ),
  });

// Root items move as one unit. Child XYWH coordinates remain relative to their section.
const rootLayout = computed({
  get: () => pageDocument.value.layouts.lg.filter((item) => !item.parentId),
  set: (layout: CmsLayoutItem[]) => {
    const uniqueLayout = layout.filter(
      (item, index, items) =>
        items.findIndex((candidate) => candidate.i === item.i) === index,
    );
    const rootIds = new Set(uniqueLayout.map((item) => item.i));
    const children = pageDocument.value.layouts.lg.filter(
      (item) => item.parentId && !rootIds.has(item.i),
    );
    pageDocument.value.layouts.lg = [
      ...uniqueLayout.map((item) => ({ ...item, parentId: null })),
      ...children,
    ];
  },
});

const childLayout = (sectionId: string) =>
  pageDocument.value.layouts.lg.filter((item) => item.parentId === sectionId);

// Repair recoverable block/layout drift before PHP validates a draft; block content always remains authoritative.
const repairDocumentIntegrity = (): number => {
  const blocksWereArray = Array.isArray(pageDocument.value.blocks);
  blocksWereArray &&
    (pageDocument.value.blocks = Object.fromEntries(
      Object.entries(pageDocument.value.blocks),
    ));
  const blockIds = new Set(Object.keys(pageDocument.value.blocks));
  let repairCount = blocksWereArray ? 1 : 0;

  responsiveBreakpoints.forEach((breakpoint) => {
    const seen = new Set<string>();

    pageDocument.value.layouts[breakpoint] = pageDocument.value.layouts[
      breakpoint
    ].filter((item) => {
      if (
        typeof item.i !== "string" ||
        !blockIds.has(item.i) ||
        seen.has(item.i)
      ) {
        repairCount += 1;
        return false;
      }

      seen.add(item.i);
      const parent = item.parentId
        ? pageDocument.value.blocks[item.parentId]
        : null;
      const invalidParent =
        item.parentId &&
        (item.parentId === item.i ||
          !parent ||
          parent.type !== "section" ||
          pageDocument.value.blocks[item.i]?.type === "section");

      if (invalidParent) {
        item.parentId = null;
        repairCount += 1;
      }

      return true;
    });

    const layoutIds = new Set(
      pageDocument.value.layouts[breakpoint].map((item) => item.i),
    );

    pageDocument.value.layouts[breakpoint].forEach((item) => {
      if (item.parentId && !layoutIds.has(item.parentId)) {
        item.parentId = null;
        repairCount += 1;
      }
    });
  });

  const desktopIds = new Set(
    pageDocument.value.layouts.lg.map((item) => item.i),
  );
  let nextRootRow = pageDocument.value.layouts.lg
    .filter((item) => !item.parentId)
    .reduce((row, item) => Math.max(row, item.y + item.h), 0);

  Object.keys(pageDocument.value.blocks).forEach((blockId) => {
    if (desktopIds.has(blockId)) return;

    pageDocument.value.layouts.lg.push({
      i: blockId,
      parentId: null,
      x: 0,
      y: nextRootRow,
      w: 12,
      h: 4,
      minW: 1,
      minH: 1,
    });
    desktopIds.add(blockId);
    nextRootRow += 4;
    repairCount += 1;
  });

  return repairCount;
};

const documentIntegrityError = (): string | null => {
  const blockIds = new Set(Object.keys(pageDocument.value.blocks));

  for (const breakpoint of responsiveBreakpoints) {
    const seen = new Set<string>();

    for (const [index, item] of pageDocument.value.layouts[
      breakpoint
    ].entries()) {
      if (typeof item.i !== "string" || !blockIds.has(item.i)) {
        return `${breakpoint}[${index}] référence un bloc inconnu`;
      }

      if (seen.has(item.i)) {
        return `${breakpoint}[${index}] duplique le bloc ${item.i}`;
      }

      seen.add(item.i);
    }
  }

  const desktopIds = new Set(
    pageDocument.value.layouts.lg.map((item) => item.i),
  );
  const missingBlockId = Object.keys(pageDocument.value.blocks).find(
    (blockId) => !desktopIds.has(blockId),
  );

  return missingBlockId
    ? `le bloc ${missingBlockId} n'a pas de position desktop`
    : null;
};

const emitHistoryState = () =>
  emit("historyState", {
    canUndo: historyPast.value.length > 0,
    canRedo: historyFuture.value.length > 0,
  });

const pushHistorySnapshot = (snapshot: string) => {
  if (historyPast.value.at(-1) !== snapshot) {
    historyPast.value.push(snapshot);

    while (
      historyPast.value.length > 1 &&
      (historyPast.value.length > historyLimit ||
        historyPast.value.reduce((size, item) => size + item.length, 0) >
          historyCharacterLimit)
    ) {
      historyPast.value.shift();
    }
  }

  historyFuture.value = [];
  emitHistoryState();
};

// Whole-document snapshots cover structural changes; Tiptap keeps its own fine-grained typing history.
const recordHistory = () => {
  isLoaded.value && pushHistorySnapshot(createSnapshot());
};

const restoreSnapshot = (snapshot: string) => {
  pageDocument.value = normalizeDocument(JSON.parse(snapshot));
  statusMessage.value = "Modification restaurée";
};

const undo = () => {
  if (!isLoaded.value) return;

  const current = createSnapshot();

  while (historyPast.value.at(-1) === current) historyPast.value.pop();

  const previous = historyPast.value.pop();

  if (!previous) {
    emitHistoryState();
    return;
  }

  historyFuture.value.push(current);
  restoreSnapshot(previous);
  emitHistoryState();
};

const redo = () => {
  if (!isLoaded.value) return;

  const next = historyFuture.value.pop();

  if (!next) {
    emitHistoryState();
    return;
  }

  historyPast.value.push(createSnapshot());
  restoreSnapshot(next);
  emitHistoryState();
};

defineExpose({ undo, redo });

const errorText = (error: unknown, fallback: string) => {
  if (typeof error !== "object" || error === null) return fallback;

  return (error as { data?: { error?: string } }).data?.error || fallback;
};

// Convert fixture-era content to the V1 document without overwriting published SQL content during loading.
const normalizeDocument = (content: unknown): CmsPageDocument => {
  if (
    typeof content === "object" &&
    content !== null &&
    (content as CmsPageDocument).schemaVersion === 1
  ) {
    const document = JSON.parse(JSON.stringify(content)) as CmsPageDocument;
    document.blocks = Object.fromEntries(Object.entries(document.blocks || {}));

    responsiveBreakpoints.forEach((breakpoint) => {
      document.layouts[breakpoint] = (document.layouts[breakpoint] || []).map(
        (item) => ({
          ...item,
          parentId: item.parentId ?? null,
        }),
      );
    });

    return document;
  }

  const document = emptyDocument();
  const legacyBlocks =
    typeof content === "object" &&
    content !== null &&
    Array.isArray((content as { blocks?: unknown }).blocks)
      ? (content as { blocks: Array<{ type?: string; content?: string }> })
          .blocks
      : [];

  legacyBlocks.forEach((legacyBlock, index) => {
    const id = `legacy-${index}`;
    const text =
      typeof legacyBlock.content === "string" ? legacyBlock.content : "";

    document.blocks[id] = {
      id,
      type: "text",
      props: {
        label: legacyBlock.type === "heading" ? "Titre" : "Texte",
        content: {
          type: "doc",
          content: [
            {
              type: "paragraph",
              content: text ? [{ type: "text", text }] : [],
            },
          ],
        },
        objectKey: null,
      },
    };
    document.layouts.lg.push({
      i: id,
      parentId: null,
      x: 0,
      y: index * 3,
      w: 12,
      h: legacyBlock.type === "heading" ? 2 : 3,
    });
  });

  return document;
};

// Load or create the server draft through PageRevision before enabling watchers and autosave.
const loadDraft = async () => {
  errorMessage.value = "";

  try {
    const response = await $fetch<DraftResponse>(
      `${config.public.apiBase}/pages/${props.pageId}/draft`,
      {
        credentials: "include",
      },
    );

    pageDocument.value = normalizeDocument(response.revision.pagecontent);
    repairDocumentIntegrity();
    revisionNumber.value = response.revision.revision_number;
    historyPast.value = [];
    historyFuture.value = [];
    await nextTick();
    isLoaded.value = true;
    dirty.value = false;
    statusMessage.value = "Brouillon chargé";
    emitHistoryState();
  } catch (error) {
    errorMessage.value = errorText(error, "Chargement du brouillon impossible");
  }
};

const scheduleAutosave = () => {
  clearTimeout(autosaveTimer);
  autosaveTimer = setTimeout(() => void saveDraft(), 30_000); // 30 seconds after last change
};

// Save one current draft row; changes made during the request schedule another asynchronous save.
const saveDraft = async (): Promise<boolean> => {
  if (!isLoaded.value || isSaving.value) return false;
  if (!dirty.value) return true;

  const repairCount = repairDocumentIntegrity();
  const integrityError = documentIntegrityError();

  if (integrityError) {
    errorMessage.value = `Brouillon incohérent : ${integrityError}`;
    statusMessage.value = "";
    return false;
  }

  const snapshot = createSnapshot();
  isSaving.value = true;
  dirty.value = false;
  errorMessage.value = "";
  statusMessage.value = "Enregistrement…";

  try {
    const response = await $fetch<DraftResponse>(
      `${config.public.apiBase}/pages/${props.pageId}/draft`,
      {
        method: "PUT",
        credentials: "include",
        body: {
          pagecontent: JSON.parse(snapshot),
        },
      },
    );

    revisionNumber.value = response.revision.revision_number;
    statusMessage.value = repairCount
      ? `Brouillon réparé (${repairCount}) et enregistré`
      : "Brouillon enregistré";
    createSnapshot() !== snapshot && (dirty.value = true);
    dirty.value && scheduleAutosave();
    return true;
  } catch (error) {
    dirty.value = true;
    errorMessage.value = errorText(
      error,
      "Enregistrement du brouillon impossible",
    );
    statusMessage.value = "";
    return false;
  } finally {
    isSaving.value = false;
  }
};

const openPublishModal = async () => {
  publicationVisibility.value = "public";
  errorMessage.value = "";
  isPublishModalOpen.value = true;
  await nextTick();
  publishDialog.value?.focus();
};

const closePublishModal = () => {
  isPublishing.value || (isPublishModalOpen.value = false);
};

// Save pending edits, then publish the same revision and visibility in one backend transaction.
const publish = async () => {
  if (isPublishing.value) return;

  clearTimeout(autosaveTimer);
  isPublishing.value = true;
  errorMessage.value = "";

  try {
    if (dirty.value && !(await saveDraft())) {
      scheduleAutosave();
      return;
    }

    await $fetch(`${config.public.apiBase}/pages/${props.pageId}/publish`, {
      method: "POST",
      credentials: "include",
      body: {
        is_anonymous: publicationVisibility.value === "anonymous",
      },
    });
    isPublishModalOpen.value = false;
    await router.push(`/pagecmsresult/${props.pageId}`);
  } catch (error) {
    errorMessage.value = errorText(error, "Publication impossible");
  } finally {
    isPublishing.value = false;
  }
};

const nextBlockId = () =>
  globalThis.crypto?.randomUUID?.() || `block-${Date.now()}`;
const nextAvailableRow = (parentId: string | null) =>
  pageDocument.value.layouts.lg
    .filter((item) => (item.parentId ?? null) === parentId)
    .reduce((row, item) => Math.max(row, item.y + item.h), 0);

const parseDefinition = (event: DragEvent): CmsBlockDefinition | null => {
  const rawDefinition = event.dataTransfer?.getData(CMS_BLOCK_MIME);

  try {
    const definition = JSON.parse(
      rawDefinition || "null",
    ) as CmsBlockDefinition | null;
    return definition && allowedBlockTypes.includes(definition.type)
      ? definition
      : null;
  } catch {
    return null;
  }
};

const defaultTextContent = (): CmsJsonValue => ({
  type: "doc",
  content: [
    {
      type: "paragraph",
      content: [],
    },
  ],
});

const defaultProps = (type: CmsBlockType): CmsBlock["props"] => ({
  label:
    type === "section" ? "Zone" : type === "text" ? "Texte" : "Nouveau bloc",
  content: type === "text" ? defaultTextContent() : "",
  objectKey:
    type === "image" ||
    type === "banner" ||
    type === "gallery" ||
    type === "video"
      ? ""
      : null,
});

const syncSectionHeight = (sectionId: string) => {
  const section = pageDocument.value.layouts.lg.find(
    (item) => item.i === sectionId && !item.parentId,
  );

  if (!section) return;

  const children = childLayout(sectionId);
  const contentRows = children.reduce(
    (row, item) => Math.max(row, item.y + item.h),
    0,
  );
  const requiredHeight = Math.max(4, contentRows + (children.length ? 2 : 0));

  section.h !== requiredHeight && (section.h = requiredHeight);
};

const updateChildLayout = (sectionId: string, layout: Layout) => {
  const currentById = new Map(
    childLayout(sectionId).map((item) => [item.i, item]),
  );
  const uniqueLayout = layout.filter(
    (item, index, items) =>
      items.findIndex((candidate) => candidate.i === item.i) === index,
  );
  const updatedIds = new Set(uniqueLayout.map((item) => String(item.i)));
  const otherItems = pageDocument.value.layouts.lg.filter(
    (item) => item.parentId !== sectionId && !updatedIds.has(item.i),
  );
  const updatedItems = uniqueLayout.map((item) => ({
    ...currentById.get(String(item.i)),
    ...item,
    i: String(item.i),
    parentId: sectionId,
  })) as CmsLayoutItem[];

  pageDocument.value.layouts.lg = [...otherItems, ...updatedItems];
  syncSectionHeight(sectionId);
};

const addBlock = (event: DragEvent, parentId: string | null = null) => {
  const definition = parseDefinition(event);
  const dropTarget =
    event.currentTarget instanceof HTMLElement
      ? event.currentTarget
      : canvas.value;
  const bounds = dropTarget?.getBoundingClientRect();

  if (!definition || !bounds) return;

  if (parentId && definition.type === "section") {
    errorMessage.value =
      "Une zone ne peut pas être placée dans une autre zone.";
    return;
  }

  recordHistory();
  errorMessage.value = "";
  const width = Math.min(
    Math.max(definition.defaultWidth, 1),
    pageDocument.value.settings.desktopColumns,
  );
  const columnWidth = bounds.width / pageDocument.value.settings.desktopColumns;
  const desiredX = Math.floor((event.clientX - bounds.left) / columnWidth);
  const id = nextBlockId();

  pageDocument.value.blocks[id] = {
    id,
    type: definition.type,
    props: defaultProps(definition.type),
  };
  pageDocument.value.layouts.lg.push({
    i: id,
    parentId,
    x: Math.min(
      Math.max(desiredX, 0),
      pageDocument.value.settings.desktopColumns - width,
    ),
    y: nextAvailableRow(parentId),
    w: width,
    h: Math.max(definition.defaultHeight, 1),
    minW: 1,
    minH: 1,
  });

  parentId && syncSectionHeight(parentId);
  statusMessage.value = parentId ? "Bloc ajouté dans la zone" : "Bloc ajouté";
};

const removeBlock = (blockId: string) => {
  const block = pageDocument.value.blocks[blockId];

  if (!block) return;

  recordHistory();
  const parentId =
    pageDocument.value.layouts.lg.find((item) => item.i === blockId)
      ?.parentId ?? null;
  const removedIds = new Set([
    blockId,
    ...(block.type === "section"
      ? pageDocument.value.layouts.lg
          .filter((item) => item.parentId === blockId)
          .map((item) => item.i)
      : []),
  ]);

  removedIds.forEach((id) => delete pageDocument.value.blocks[id]);
  responsiveBreakpoints.forEach((breakpoint) => {
    pageDocument.value.layouts[breakpoint] = pageDocument.value.layouts[
      breakpoint
    ].filter((item) => !removedIds.has(item.i));
  });

  parentId && syncSectionHeight(parentId);
  statusMessage.value =
    removedIds.size > 1
      ? `Zone et ${removedIds.size - 1} bloc(s) supprimés`
      : "Bloc supprimé";
};

const updateBlockContent = (blockId: string, content: CmsJsonValue) => {
  const block = pageDocument.value.blocks[blockId];

  if (!block) return;

  const contentChanged =
    JSON.stringify(block.props.content) !== JSON.stringify(content);
  block.props.content = content;
  contentChanged && delete block.props.moderationRemoved;
};

const imageDimensions = (block: CmsBlock | undefined) => {
  const width = Number(block?.props.width);
  const height = Number(block?.props.height);

  return width > 0 && height > 0 ? { width, height } : null;
};

// Convert the natural pixel ratio to the closest complete grid row without changing the selected width.
const snapImageLayoutToRatio = async (
  blockId: string,
  fallbackPixelWidth?: number,
) => {
  const block = pageDocument.value.blocks[blockId];
  const dimensions = imageDimensions(block);
  const item = pageDocument.value.layouts.lg.find(
    (candidate) => candidate.i === blockId,
  );

  if (!block?.props.aspectRatioLocked || !dimensions || !item) return;

  await nextTick();
  const blockElement = globalThis.document?.querySelector<HTMLElement>(
    `[data-cms-block-id="${CSS.escape(blockId)}"]`,
  );
  const pixelWidth =
    blockElement?.closest<HTMLElement>(".vgl-item")?.getBoundingClientRect()
      .width || fallbackPixelWidth;

  if (!pixelWidth) return;

  const margin = item.parentId ? childGridMargin : rootGridMargin;
  const proportionalHeight =
    (pixelWidth * dimensions.height) / dimensions.width;
  item.h = Math.max(
    item.minH || 1,
    Math.round((proportionalHeight + margin) / (gridRowHeight + margin)),
  );
  item.parentId && syncSectionHeight(item.parentId);
};

// Send the original multipart file to PHP; only validated and re-encoded media receive a MinIO objectKey.
const uploadMedia = async (blockId: string, event: Event) => {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  const block = pageDocument.value.blocks[blockId];

  if (!file || !block) return;

  const initialPixelWidth = input
    .closest<HTMLElement>(".vgl-item")
    ?.getBoundingClientRect().width;
  const mediaType = block.type === "video" ? "video" : "image";
  const formData = new FormData();
  formData.append("media_type", mediaType);
  formData.append("file", file);
  uploadingBlockId.value = blockId;
  errorMessage.value = "";

  try {
    const response = await $fetch<MediaResponse>(
      `${config.public.apiBase}/pages/${props.pageId}/media`,
      {
        method: "POST",
        credentials: "include",
        body: formData,
      },
    );

    recordHistory();
    block.props.objectKey = response.media.objectKey;
    block.props.mimeType = response.media.mimeType;
    block.props.size = response.media.size;
    block.props.width = response.media.width;
    block.props.height = response.media.height;
    block.props.aspectRatioLocked = mediaType === "image";
    delete block.props.moderationRemoved;
    await snapImageLayoutToRatio(blockId, initialPixelWidth);
    statusMessage.value = "Média validé et enregistré";
  } catch (error) {
    errorMessage.value = errorText(error, "Upload du média impossible");
  } finally {
    uploadingBlockId.value = null;
    input.value = "";
  }
};

const mediaUrl = (blockId: string) => {
  const objectKey = pageDocument.value.blocks[blockId]?.props.objectKey;

  return objectKey
    ? `${config.public.apiBase}/pages/${props.pageId}/media?key=${encodeURIComponent(String(objectKey))}`
    : null;
};

const blockById = (blockId: string) =>
  pageDocument.value.blocks[blockId] as CmsBlock;

// A pointer snapshot captures the state before Grid Layout Plus mutates XYWH during drag or resize.
const captureGridInteraction = (event: PointerEvent) => {
  const target = event.target instanceof Element ? event.target : null;

  target?.closest(
    ".cms-root-drag-handle, .cms-child-drag-handle, .vgl-item__resizer",
  ) && (pendingGridSnapshot = createSnapshot());
};

const finishGridInteraction = (sectionId: string | null = null) => {
  sectionId && syncSectionHeight(sectionId);
  const before = pendingGridSnapshot;
  pendingGridSnapshot = null;

  before && before !== createSnapshot() && pushHistorySnapshot(before);
};

const finishGridResize = async (
  blockId: string,
  sectionId: string | null = null,
) => {
  await snapImageLayoutToRatio(blockId);
  finishGridInteraction(sectionId);
};

const handleKeyboardHistory = (event: KeyboardEvent) => {
  if ((!event.ctrlKey && !event.metaKey) || event.altKey) return;

  const target = event.target instanceof Element ? event.target : null;

  if (
    target?.closest(
      ".ProseMirror, input, textarea, select, [contenteditable='true']",
    )
  )
    return;

  const key = event.key.toLowerCase();
  const wantsUndo = key === "z" && !event.shiftKey;
  const wantsRedo = (key === "z" && event.shiftKey) || key === "y";

  if (!wantsUndo && !wantsRedo) return;

  event.preventDefault();
  wantsUndo ? undo() : redo();
};

watch(
  pageDocument,
  () => {
    if (!isLoaded.value) return;

    dirty.value = true;
    statusMessage.value = "Modifications non enregistrées";
    scheduleAutosave();
  },
  { deep: true },
);

onMounted(async () => {
  await loadDraft();
  globalThis.addEventListener("keydown", handleKeyboardHistory);
  checkpointTimer = setInterval(() => dirty.value && void saveDraft(), 90_000);
});

onBeforeUnmount(() => {
  clearTimeout(autosaveTimer);
  clearInterval(checkpointTimer);
  globalThis.removeEventListener("keydown", handleKeyboardHistory);
  dirty.value && void saveDraft();
});
</script>

<template>
  <section class="flex min-h-0 w-full flex-1 overflow-x-auto p-[5px]">
    <div
      v-if="!isLoaded && !errorMessage"
      class="flex w-full flex-1 items-center justify-center"
    >
      <LoadingSpinner label="Chargement du brouillon" />
    </div>

    <div
      v-else-if="!isLoaded"
      class="flex w-full flex-1 flex-col items-center justify-center gap-4 text-center"
    >
      <p class="error-color">{{ errorMessage }}</p>
      <button
        type="button"
        class="button-primary rounded-md px-4 py-2"
        @click="loadDraft"
      >
        Réessayer
      </button>
    </div>

    <div v-else class="flex min-h-0 w-full flex-1 flex-col">
      <div class="mb-3 flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 class="text-2xl font-semibold">Édition libre</h1>
          <p class="secondary-color text-sm">
            Page {{ pageId }} · révision {{ revisionNumber || "—" }} · desktop
            12 colonnes
          </p>
        </div>

        <div class="flex items-center gap-3">
          <button
            type="button"
            class="form-control rounded-md border px-4 py-2 text-sm"
            :disabled="isSaving || !dirty"
            @click="saveDraft"
          >
            {{ isSaving ? "Enregistrement…" : "Enregistrer" }}
          </button>
          <button
            type="button"
            class="button-primary rounded-md px-4 py-2 text-sm"
            :disabled="isSaving || isPublishing"
            @click="openPublishModal"
          >
            Publier
          </button>
          <span
            class="secondary-background primary-border rounded-full border px-3 py-1 text-sm"
          >
            {{ Object.keys(pageDocument.blocks).length }} bloc{{
              Object.keys(pageDocument.blocks).length === 1 ? "" : "s"
            }}
          </span>
        </div>
      </div>

      <p
        v-if="errorMessage"
        class="error-color mb-3 text-sm font-medium"
        role="alert"
      >
        {{ errorMessage }}
      </p>
      <p
        v-else-if="statusMessage"
        class="success-color mb-3 text-sm"
        aria-live="polite"
      >
        {{ statusMessage }}
      </p>

      <div
        ref="canvas"
        class="cms-editor-grid primary-border flex min-h-0 w-full flex-1 flex-col rounded-xl border-2 border-dashed p-2"
        @dragover.prevent
        @drop.prevent="addBlock($event)"
        @pointerdown="captureGridInteraction"
      >
        <div
          v-if="rootLayout.length === 0"
          class="pointer-events-none flex min-h-96 flex-1 items-center justify-center p-8 text-center"
        >
          <div>
            <p class="text-xl font-medium">
              Glissez un bloc depuis la palette située à droite
            </p>
            <p class="secondary-color mt-2">
              Le contenu, la hiérarchie et les positions sont enregistrés
              séparément dans le JSON.
            </p>
          </div>
        </div>

        <ClientOnly v-else>
          <GridLayout
            v-model:layout="rootLayout"
            :col-num="pageDocument.settings.desktopColumns"
            :row-height="gridRowHeight"
            :margin="[10, 10]"
            :is-draggable="true"
            :is-resizable="true"
            :vertical-compact="true"
            :use-css-transforms="true"
          >
            <GridItem
              v-for="item in rootLayout"
              :key="item.i"
              :i="item.i"
              :x="item.x"
              :y="item.y"
              :w="item.w"
              :h="item.h"
              :min-w="item.minW"
              :min-h="item.minH"
              :max-w="item.maxW"
              :max-h="item.maxH"
              drag-allow-from=".cms-root-drag-handle"
              drag-ignore-from=".cms-no-drag"
              resize-ignore-from=".cms-no-drag"
              @moved="finishGridInteraction()"
              @resized="
                finishGridResize(
                  item.i,
                  pageDocument.blocks[item.i]?.type === 'section'
                    ? item.i
                    : null,
                )
              "
            >
              <CmsEditorBlock
                v-if="pageDocument.blocks[item.i]"
                :block="blockById(item.i)"
                :media-url="mediaUrl(item.i)"
                :uploading="uploadingBlockId === item.i"
                drag-handle-class="cms-root-drag-handle"
                @history-boundary="recordHistory"
                @remove="removeBlock"
                @update-content="updateBlockContent"
                @upload-media="uploadMedia"
              >
                <template #section>
                  <div
                    class="cms-section-drop min-h-full p-1"
                    @dragover.prevent.stop
                    @drop.prevent.stop="addBlock($event, item.i)"
                  >
                    <p
                      v-if="childLayout(item.i).length === 0"
                      class="secondary-color pointer-events-none flex h-full min-h-24 items-center justify-center p-3 text-sm"
                    >
                      Glissez plusieurs blocs dans cette zone
                    </p>

                    <GridLayout
                      v-else
                      :layout="childLayout(item.i)"
                      :col-num="pageDocument.settings.desktopColumns"
                      :row-height="gridRowHeight"
                      :margin="[8, 8]"
                      :is-draggable="true"
                      :is-resizable="true"
                      :vertical-compact="true"
                      :use-css-transforms="true"
                      @update:layout="updateChildLayout(item.i, $event)"
                      @layout-updated="syncSectionHeight(item.i)"
                    >
                      <GridItem
                        v-for="child in childLayout(item.i)"
                        :key="child.i"
                        :i="child.i"
                        :x="child.x"
                        :y="child.y"
                        :w="child.w"
                        :h="child.h"
                        :min-w="child.minW"
                        :min-h="child.minH"
                        :max-w="child.maxW"
                        :max-h="child.maxH"
                        drag-allow-from=".cms-child-drag-handle"
                        drag-ignore-from=".cms-no-drag"
                        resize-ignore-from=".cms-no-drag"
                        @moved="finishGridInteraction(item.i)"
                        @resized="finishGridResize(child.i, item.i)"
                      >
                        <CmsEditorBlock
                          v-if="pageDocument.blocks[child.i]"
                          :block="blockById(child.i)"
                          :media-url="mediaUrl(child.i)"
                          :uploading="uploadingBlockId === child.i"
                          drag-handle-class="cms-child-drag-handle"
                          @history-boundary="recordHistory"
                          @remove="removeBlock"
                          @update-content="updateBlockContent"
                          @upload-media="uploadMedia"
                        />
                      </GridItem>
                    </GridLayout>
                  </div>
                </template>
              </CmsEditorBlock>
            </GridItem>
          </GridLayout>
        </ClientOnly>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="isPublishModalOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
        role="presentation"
        @click.self="closePublishModal"
        @keydown.esc="closePublishModal"
      >
        <section
          ref="publishDialog"
          class="primary-background primary-border max-h-[90dvh] w-full max-w-lg overflow-y-auto rounded-xl border p-6 shadow-2xl outline-none"
          role="dialog"
          tabindex="-1"
          aria-modal="true"
          aria-labelledby="publish-page-title"
          aria-describedby="publish-page-description"
        >
          <header class="flex items-start justify-between gap-4">
            <div>
              <h2 id="publish-page-title" class="text-2xl font-semibold">
                Publier la page
              </h2>
              <p id="publish-page-description" class="secondary-color mt-2 text-sm">
                Choisissez comment votre identité apparaîtra sur cette page publique.
              </p>
            </div>
            <button
              type="button"
              class="secondary-color shrink-0 rounded-md p-1 disabled:opacity-40"
              :disabled="isPublishing"
              aria-label="Fermer"
              @click="closePublishModal"
            >
              <XMarkIcon class="size-7" aria-hidden="true" />
            </button>
          </header>

          <form class="mt-6" @submit.prevent="publish">
            <fieldset :disabled="isPublishing" class="space-y-3">
              <legend class="sr-only">Visibilité de l’auteur</legend>

              <label
                class="primary-border flex cursor-pointer items-center gap-4 rounded-lg border p-4 transition hover:bg-(--secondary-background)"
                :class="publicationVisibility === 'public' ? 'secondary-background ring-2 ring-(--focus-color)' : ''"
              >
                <input
                  v-model="publicationVisibility"
                  type="radio"
                  name="publication-visibility"
                  value="public"
                  class="size-5 shrink-0 accent-(--accent-color)"
                >
                <GlobeAltIcon class="size-7 shrink-0" aria-hidden="true" />
                <span>
                  <span class="block font-semibold">Publier publiquement</span>
                  <span class="secondary-color mt-1 block text-sm">
                    La page sera visible par tous avec votre identité.
                  </span>
                </span>
              </label>

              <label
                class="primary-border flex cursor-pointer items-center gap-4 rounded-lg border p-4 transition hover:bg-(--secondary-background)"
                :class="publicationVisibility === 'anonymous' ? 'secondary-background ring-2 ring-(--focus-color)' : ''"
              >
                <input
                  v-model="publicationVisibility"
                  type="radio"
                  name="publication-visibility"
                  value="anonymous"
                  class="size-5 shrink-0 accent-(--accent-color)"
                >
                <EyeSlashIcon class="size-7 shrink-0" aria-hidden="true" />
                <span>
                  <span class="block font-semibold">Publier anonymement</span>
                  <span class="secondary-color mt-1 block text-sm">
                    La page sera visible par tous sans révéler votre identité.
                  </span>
                </span>
              </label>
            </fieldset>

            <p v-if="errorMessage" class="error-color mt-4 text-sm font-medium" role="alert">
              {{ errorMessage }}
            </p>

            <footer class="mt-6 flex justify-end gap-3">
              <button
                type="button"
                class="primary-border rounded-lg border px-4 py-2 disabled:opacity-40"
                :disabled="isPublishing"
                @click="closePublishModal"
              >
                Annuler
              </button>
              <button
                type="submit"
                class="button-primary rounded-lg px-4 py-2 disabled:opacity-40"
                :disabled="isPublishing"
              >
                {{ isPublishing ? "Publication…" : "Publier" }}
              </button>
            </footer>
          </form>
        </section>
      </div>
    </Teleport>
  </section>
</template>

<style scoped>
.cms-editor-grid {
  background-color: var(--primary-background);
  background-image: radial-gradient(var(--primary-border) 1px, transparent 1px);
  background-size: calc((100% - 20px) / 12) 50px;
}

.cms-section-drop {
  background-color: color-mix(
    in srgb,
    var(--third-background) 30%,
    transparent
  );
}
</style>
