<!--
  This view renders one protected moderation case per reported user page in AdminDashboard.
  Lists follow GET /api/admin/moderation; case/report POST endpoints keep global and child decisions separate.
  The context accordion compares moderation_cases.reported_page_snapshot with current pages.pagecontent.
-->
<script setup lang="ts">
import {
  ArchiveBoxXMarkIcon,
  ChevronDownIcon,
  ChevronUpIcon,
  DocumentMagnifyingGlassIcon,
  MagnifyingGlassPlusIcon,
} from "@heroicons/vue/24/outline"
import type {
  ModerationCase,
  ModerationCaseContext,
  ModerationReport,
  ModerationStatus,
} from "~/types/shared/moderation"

interface ModerationResponse {
  cases: ModerationCase[]
}

interface ModerationActionResponse {
  message: string
  decision: {
    already_absent?: boolean
    case: {
      id: number
      moderation_status: ModerationStatus
    }
  }
}

type ComparedVersion = "reported" | "current"

const config = useRuntimeConfig()
const apiFetch = useApi()
const route = useRoute()
const { resolveUrl: resolvePagePicture } = usePagePicture()
const statuses: Array<{ value: ModerationStatus, label: string }> = [
  { value: "pending", label: "En attente" },
  { value: "reviewed", label: "Traités" },
  { value: "dismissed", label: "Rejetés" },
]
const casesByStatus = ref<Record<ModerationStatus, ModerationCase[]>>({
  pending: [],
  reviewed: [],
  dismissed: [],
})
const contexts = ref<Record<number, ModerationCaseContext>>({})
const selectedBlockIds = ref<Record<number, string>>({})
const comparedVersions = ref<Record<number, ComparedVersion>>({})
const pageDisplayDetails = ref<Record<number, boolean>>({})
const activeStatus = ref<ModerationStatus>("pending")
const openedCaseId = ref<number | null>(null)
const contextLoadingCaseId = ref<number | null>(null)
const updatingKey = ref("")
const zoomedCaseId = ref<number | null>(null)
const loading = ref(true)
const errorMessage = ref("")
const successMessage = ref("")
const deepLinkHandled = ref(false)

const activeCases = computed(() => casesByStatus.value[activeStatus.value])
const zoomedCase = computed(() => (
  Object.values(casesByStatus.value)
    .flat()
    .find(moderationCase => moderationCase.id === zoomedCaseId.value) || null
))

function errorText(error: unknown, fallback: string): string {
  if (typeof error !== "object" || error === null) return fallback

  return (error as { data?: { error?: string } }).data?.error || fallback
}

function reportCountClass(count: number): string {
  if (count >= 6) return "error-color border-(--error-color)"
  if (count >= 3) return "warning-color border-(--warning-color)"

  return "success-color border-(--success-color)"
}

function formatDate(value: string | null): string {
  if (!value) return "—"

  return new Intl.DateTimeFormat("fr-FR", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value))
}

function pageDisplayReports(moderationCase: ModerationCase): ModerationReport[] {
  return moderationCase.reports.filter(report => report.reported_content_type === "page_display")
}

function hasPendingPageDisplayReport(moderationCase: ModerationCase): boolean {
  return pageDisplayReports(moderationCase).some(report => report.moderation_status === "pending")
}

function reportedBlockIds(moderationCase: ModerationCase): string[] {
  return [...new Set(
    moderationCase.reports
      .filter(report => (
        report.reported_content_type === "page_content"
        && report.moderation_status === "pending"
      ))
      .map(report => report.reported_block_id),
  )]
}

function selectedBlockReports(moderationCase: ModerationCase): ModerationReport[] {
  const blockId = selectedBlockIds.value[moderationCase.id] || ""

  return moderationCase.reports.filter(report => (
    report.reported_content_type === "page_content"
    && report.reported_block_id === blockId
  ))
}

function selectedBlockHasPendingReport(moderationCase: ModerationCase): boolean {
  return selectedBlockReports(moderationCase)
    .some(report => report.moderation_status === "pending")
}

function comparedContent(moderationCase: ModerationCase): unknown {
  const context = contexts.value[moderationCase.id]

  return comparedVersions.value[moderationCase.id] === "current"
    ? context?.page.pagecontent
    : context?.case.reported_page_snapshot.pagecontent
}

function effectiveReportStatus(
  moderationCase: ModerationCase,
  report: ModerationReport,
): string {
  if (moderationCase.moderation_status === "dismissed" && report.moderation_status === "pending") {
    return "Rejeté de fait par le dossier"
  }

  return report.moderation_status === "pending"
    ? "En attente"
    : report.moderation_status === "reviewed" ? "Traité" : "Rejeté"
}

async function fetchStatus(status: ModerationStatus): Promise<ModerationCase[]> {
  const response = await $fetch<ModerationResponse>(`${config.public.apiBase}/admin/moderation`, {
    credentials: "include",
    query: { status },
  })

  return response.cases || []
}

async function loadCases(): Promise<void> {
  loading.value = true
  errorMessage.value = ""

  try {
    const [pending, reviewed, dismissed] = await Promise.all([
      fetchStatus("pending"),
      fetchStatus("reviewed"),
      fetchStatus("dismissed"),
    ])

    casesByStatus.value = { pending, reviewed, dismissed }
    await openDeepLinkedCase()
  } catch (error) {
    errorMessage.value = errorText(error, "Chargement des dossiers de modération impossible")
  } finally {
    loading.value = false
  }
}

async function openDeepLinkedCase(): Promise<void> {
  if (deepLinkHandled.value) return

  deepLinkHandled.value = true
  const caseId = Number(typeof route.query.case === "string" ? route.query.case : 0)
  if (!Number.isInteger(caseId) || caseId < 1) return

  const moderationCase = Object.values(casesByStatus.value)
    .flat()
    .find(item => item.id === caseId)
  if (!moderationCase) return

  activeStatus.value = moderationCase.moderation_status
  const blockId = typeof route.query.block === "string" ? route.query.block : ""
  blockId && (selectedBlockIds.value[caseId] = blockId)
  route.query.target === "page_display" && (pageDisplayDetails.value[caseId] = true)

  await nextTick()
  await toggleContext(moderationCase)
  await nextTick()
  document.getElementById(`moderation-case-${caseId}`)?.scrollIntoView({
    behavior: "smooth",
    block: "start",
  })
}

async function executeModerationAction(
  moderationCase: ModerationCase,
  action: "dismiss-case" | "dismiss-report" | "remove-content",
  report: ModerationReport | null = null,
): Promise<void> {
  if (updatingKey.value || (action !== "dismiss-case" && !report)) return

  const confirmation = action === "dismiss-case"
    ? "Ignorer tous les signalements de cette page et clore le dossier ?"
    : action === "dismiss-report"
      ? "Ignorer définitivement ce signalement ?"
      : "Retirer ce contenu des versions courantes et programmer la suppression de ses médias ?"

  if (!globalThis.confirm(confirmation)) return

  updatingKey.value = action === "dismiss-case" ? `case-${moderationCase.id}` : `report-${report!.id}`
  errorMessage.value = ""
  successMessage.value = ""

  try {
    const endpoint = action === "dismiss-case"
      ? `/admin/moderation/cases/${moderationCase.id}/dismiss`
      : `/admin/moderation/${report!.id}/${action === "dismiss-report" ? "dismiss" : "remove"}`

    const response = await apiFetch<ModerationActionResponse>(`${config.public.apiBase}${endpoint}`, {
      method: "POST",
      credentials: "include",
    })
    successMessage.value = action === "dismiss-case"
      ? "Signalements en attente ignorés et dossier clos"
      : action === "dismiss-report"
        ? "Signalement ignoré"
        : response.decision.already_absent
          ? "Signalement traité : le contenu était déjà absent"
          : "Contenu retiré et notification créée"
    comparedVersions.value[moderationCase.id] = "current"
    report?.reported_block_id && delete selectedBlockIds.value[moderationCase.id]
    delete contexts.value[moderationCase.id]
    openedCaseId.value = null
    await loadCases()
    const updatedStatus = response.decision.case.moderation_status
    const updatedCase = casesByStatus.value[updatedStatus]
      .find(item => item.id === moderationCase.id)

    if (updatedCase) {
      activeStatus.value = updatedStatus
      await toggleContext(updatedCase)
    }
  } catch (error) {
    errorMessage.value = errorText(error, "Décision de modération impossible")
  } finally {
    updatingKey.value = ""
  }
}

async function toggleContext(moderationCase: ModerationCase): Promise<void> {
  if (openedCaseId.value === moderationCase.id) {
    openedCaseId.value = null
    return
  }

  openedCaseId.value = moderationCase.id
  comparedVersions.value[moderationCase.id] ||= "reported"
  if (contexts.value[moderationCase.id]) return

  contextLoadingCaseId.value = moderationCase.id
  errorMessage.value = ""

  try {
    contexts.value[moderationCase.id] = await $fetch<ModerationCaseContext>(
      `${config.public.apiBase}/admin/moderation/cases/${moderationCase.id}/context`,
      { credentials: "include" },
    )
  } catch (error) {
    errorMessage.value = errorText(error, "Chargement de la comparaison impossible")
    openedCaseId.value = null
  } finally {
    contextLoadingCaseId.value = null
  }
}

function selectBlock(moderationCase: ModerationCase, blockId: string): void {
  selectedBlockIds.value[moderationCase.id] = selectedBlockIds.value[moderationCase.id] === blockId
    ? ""
    : blockId
}

function togglePageDisplayDetails(moderationCase: ModerationCase): void {
  pageDisplayDetails.value[moderationCase.id] = !pageDisplayDetails.value[moderationCase.id]
}

function toggleImageZoom(caseId: number): void {
  zoomedCaseId.value = zoomedCaseId.value === caseId ? null : caseId
}

onMounted(loadCases)
</script>

<template>
  <section class="flex flex-col gap-6">
    <header>
      <h2 class="text-2xl font-semibold">Modération des pages signalées</h2>
      <p class="secondary-color mt-2 text-sm">
        Chaque page apparaît une seule fois, avec ses signalements et ses décisions individuelles.
      </p>
      <p v-if="errorMessage" class="error-color mt-3 text-sm font-medium" role="alert">{{ errorMessage }}</p>
      <p v-if="successMessage" class="success-color mt-3 text-sm font-medium" aria-live="polite">{{ successMessage }}</p>
    </header>

    <nav class="primary-border grid grid-cols-3 overflow-hidden rounded-lg border" aria-label="Statut global des pages">
      <button
        v-for="status in statuses"
        :key="status.value"
        type="button"
        class="flex min-h-12 items-center justify-center gap-2 px-3 py-2 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-inset focus:ring-(--focus-color)"
        :class="activeStatus === status.value ? 'button-primary' : 'primary-background'"
        :aria-pressed="activeStatus === status.value"
        @click="activeStatus = status.value"
      >
        <span>{{ status.label }}</span>
        <span class="rounded-full border border-current px-2 py-0.5 text-xs">
          {{ casesByStatus[status.value].length }}
        </span>
      </button>
    </nav>

    <LoadingSpinner v-if="loading" label="Chargement des dossiers de modération" />

    <p
      v-else-if="activeCases.length === 0"
      class="secondary-background primary-border rounded-xl border p-8 text-center"
    >
      Aucune page dans cette catégorie.
    </p>

    <div v-else class="grid gap-5">
      <article
        v-for="moderationCase in activeCases"
        :id="`moderation-case-${moderationCase.id}`"
        :key="moderationCase.id"
        class="secondary-background primary-border overflow-hidden rounded-xl border"
      >
        <div class="grid gap-6 p-5 lg:grid-cols-[330px_minmax(0,1fr)]">
          <section>
            <div
              class="primary-background relative mx-auto h-[430px] w-full max-w-[330px] overflow-hidden rounded-lg border-4 lg:mx-0"
              :class="hasPendingPageDisplayReport(moderationCase)
                ? 'border-(--warning-color)'
                : 'primary-border'"
            >
              <button
                v-if="pageDisplayReports(moderationCase).length"
                type="button"
                class="size-full focus:outline-none focus:ring-2 focus:ring-inset focus:ring-(--focus-color)"
                aria-label="Afficher les signalements de l’image de présentation"
                @click="togglePageDisplayDetails(moderationCase)"
              >
                <img
                  v-if="moderationCase.page_picture"
                  :src="resolvePagePicture(moderationCase.reported_page_id, moderationCase.page_picture)"
                  :alt="`Image de présentation de ${moderationCase.page_title}`"
                  class="size-full object-contain"
                >
                <span v-else class="secondary-color flex size-full items-center justify-center text-sm">
                  Aucune image de présentation
                </span>
              </button>
              <img
                v-else-if="moderationCase.page_picture"
                :src="resolvePagePicture(moderationCase.reported_page_id, moderationCase.page_picture)"
                :alt="`Image de présentation de ${moderationCase.page_title}`"
                class="size-full object-contain"
              >
              <p v-else class="secondary-color flex size-full items-center justify-center text-sm">
                Aucune image de présentation
              </p>
              <button
                v-if="moderationCase.page_picture"
                type="button"
                class="form-control absolute right-2 top-2 rounded-md border p-2 focus:outline-none focus:ring-2"
                aria-label="Agrandir l’image de présentation"
                @click.stop="toggleImageZoom(moderationCase.id)"
              >
                <MagnifyingGlassPlusIcon class="size-5" aria-hidden="true" />
              </button>
            </div>

          </section>

          <section class="min-w-0">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="secondary-color text-sm font-semibold">Informations de la page</p>
                <h3 class="text-2xl font-semibold [overflow-wrap:anywhere]">
                  {{ moderationCase.page_title }}
                </h3>
                <p class="secondary-color mt-1">
                  Dossier global : <strong>{{ statuses.find(item => item.value === moderationCase.moderation_status)?.label }}</strong>
                </p>
              </div>
              <div class="flex flex-wrap justify-end gap-2 text-xs">
                <span
                  class="rounded-full border-2 px-3 py-1 text-sm font-bold"
                  :class="reportCountClass(Number(moderationCase.report_count))"
                >
                  {{ moderationCase.report_count }} signalement{{ Number(moderationCase.report_count) > 1 ? "s" : "" }}
                </span>
                <span class="warning-color rounded-full border border-current px-2 py-1">
                  {{ moderationCase.pending_report_count }} en attente
                </span>
                <span class="success-color rounded-full border border-current px-2 py-1">
                  {{ moderationCase.reviewed_report_count }} traité(s)
                </span>
                <span class="error-color rounded-full border border-current px-2 py-1">
                  {{ moderationCase.dismissed_report_count }} rejeté(s)
                </span>
              </div>
            </div>

            <p v-if="moderationCase.page_description" class="secondary-color mt-4">
              {{ moderationCase.page_description }}
            </p>

            <dl class="primary-border mt-5 grid gap-4 border-t pt-5 text-sm sm:grid-cols-2 xl:grid-cols-3">
              <div>
                <dt class="secondary-color">Propriétaire</dt>
                <dd class="mt-1 flex items-center gap-2 font-semibold">
                  <UserAvatar
                    :picture="moderationCase.page_owner_picture"
                    :username="moderationCase.page_owner_username"
                    :page-id="moderationCase.reported_page_id"
                    source="page-owner"
                    :anonymous="false"
                    size="sm"
                  />
                  {{ moderationCase.page_owner_username }}
                </dd>
              </div>
              <div>
                <dt class="secondary-color">Statut de publication</dt>
                <dd>{{ moderationCase.page_status }}</dd>
              </div>
              <div>
                <dt class="secondary-color">Création</dt>
                <dd>{{ formatDate(moderationCase.page_created_at) }}</dd>
              </div>
              <div>
                <dt class="secondary-color">Dernière modification</dt>
                <dd>{{ formatDate(moderationCase.page_updated_at) }}</dd>
              </div>
              <div>
                <dt class="secondary-color">Statistiques</dt>
                <dd>
                  {{ moderationCase.number_of_view }} vues ·
                  {{ moderationCase.number_of_likes }} likes ·
                  {{ moderationCase.number_of_followers }} abonnés
                </dd>
              </div>
            </dl>

            <section
              v-if="pageDisplayDetails[moderationCase.id]"
              class="mt-5 rounded-xl border-2 border-(--warning-color) p-4"
            >
              <h4 class="font-semibold">Signalements de la page et de son image</h4>
              <div class="mt-3 grid gap-3">
                <article
                  v-for="report in pageDisplayReports(moderationCase)"
                  :key="report.id"
                  class="primary-background primary-border rounded-lg border p-4"
                >
                  <div class="flex flex-wrap items-start justify-between gap-2">
                    <p>
                      <strong>{{ report.reported_filter_name }}</strong>
                      · signalé par {{ report.reporter_username }}
                    </p>
                    <span class="secondary-color text-sm">{{ formatDate(report.created_at) }}</span>
                  </div>
                  <p class="secondary-color mt-1 text-sm">{{ effectiveReportStatus(moderationCase, report) }}</p>
                  <p v-if="report.reported_user_message" class="mt-2 text-sm">{{ report.reported_user_message }}</p>
                  <div v-if="report.moderation_status === 'pending'" class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="form-control rounded-md border px-2 py-1 text-xs" :disabled="Boolean(updatingKey)" @click="executeModerationAction(moderationCase, 'dismiss-report', report)">Ignorer ce signalement</button>
                    <button v-if="moderationCase.page_picture" type="button" class="rounded-md border border-(--error-color) px-2 py-1 text-xs text-(--error-color)" :disabled="Boolean(updatingKey)" @click="executeModerationAction(moderationCase, 'remove-content', report)">Supprimer l’image</button>
                  </div>
                </article>
              </div>
            </section>

            <div class="mt-6 flex flex-wrap gap-2">
              <button
                v-if="moderationCase.moderation_status === 'pending'"
                type="button"
                class="rounded-md border border-(--error-color) px-3 py-2 text-sm text-(--error-color) focus:outline-none focus:ring-2"
                :disabled="Boolean(updatingKey)"
                @click="executeModerationAction(moderationCase, 'dismiss-case')"
              >
                <span class="inline-flex items-center gap-2">
                  <ArchiveBoxXMarkIcon class="size-5" aria-hidden="true" />
                  Ignorer tous les signalements
                </span>
              </button>
              <button
                type="button"
                class="form-control ml-auto inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm focus:outline-none focus:ring-2"
                :aria-expanded="openedCaseId === moderationCase.id"
                @click="toggleContext(moderationCase)"
              >
                <DocumentMagnifyingGlassIcon class="size-5" aria-hidden="true" />
                Page et historique
                <ChevronUpIcon v-if="openedCaseId === moderationCase.id" class="size-4" aria-hidden="true" />
                <ChevronDownIcon v-else class="size-4" aria-hidden="true" />
              </button>
            </div>
          </section>
        </div>

        <section
          v-if="openedCaseId === moderationCase.id"
          class="primary-background primary-border border-t p-5"
          :aria-label="`Comparaison de ${moderationCase.page_title}`"
        >
          <LoadingSpinner
            v-if="contextLoadingCaseId === moderationCase.id"
            label="Chargement de la page et de son historique"
          />

          <template v-else-if="contexts[moderationCase.id]">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h4 class="text-lg font-semibold">Contenu JSON de la page</h4>
                <p class="secondary-color mt-1 text-sm">
                  Les blocs ayant un signalement en attente sont jaunes. Cliquez sur un bloc pour consulter ses signalements.
                </p>
              </div>
              <div class="primary-border grid grid-cols-2 overflow-hidden rounded-lg border">
                <button
                  type="button"
                  class="px-4 py-2 text-sm font-semibold"
                  :class="comparedVersions[moderationCase.id] !== 'current' ? 'button-primary' : 'secondary-background'"
                  @click="comparedVersions[moderationCase.id] = 'reported'"
                >
                  JSON signalé
                </button>
                <button
                  type="button"
                  class="px-4 py-2 text-sm font-semibold"
                  :class="comparedVersions[moderationCase.id] === 'current' ? 'button-primary' : 'secondary-background'"
                  @click="comparedVersions[moderationCase.id] = 'current'"
                >
                  JSON actuel
                </button>
              </div>
            </div>

            <div class="secondary-background primary-border mt-4 overflow-x-auto rounded-xl border p-2">
              <CmsModerationPreview
                :content="comparedContent(moderationCase)"
                :page-id="moderationCase.reported_page_id"
                :reported-block-ids="reportedBlockIds(moderationCase)"
                @select-reported-block="selectBlock(moderationCase, $event)"
              />
            </div>

            <section
              v-if="selectedBlockIds[moderationCase.id]"
              class="mt-5 rounded-xl border-2 p-5"
              :class="selectedBlockHasPendingReport(moderationCase)
                ? 'border-(--warning-color)'
                : 'primary-border'"
            >
              <h4 class="text-lg font-semibold">
                Signalements du bloc {{ selectedBlockIds[moderationCase.id] }}
              </h4>
              <div class="mt-4 grid gap-3">
                <article
                  v-for="report in selectedBlockReports(moderationCase)"
                  :key="report.id"
                  class="secondary-background primary-border rounded-lg border p-4"
                >
                  <div class="flex flex-wrap items-start justify-between gap-2">
                    <p>
                      <strong>{{ report.reported_filter_name }}</strong>
                      · signalé par {{ report.reporter_username }}
                    </p>
                    <span class="secondary-color text-sm">{{ formatDate(report.created_at) }}</span>
                  </div>
                  <p class="secondary-color mt-1 text-sm">{{ effectiveReportStatus(moderationCase, report) }}</p>
                  <p v-if="report.reported_user_message" class="mt-2 text-sm">{{ report.reported_user_message }}</p>
                  <div
                    v-if="report.reported_content_snapshot"
                    class="primary-background primary-border mt-3 max-h-64 overflow-auto rounded-lg border"
                  >
                    <CmsResultBlock
                      :block="report.reported_content_snapshot"
                      :page-id="report.reported_page_id"
                      :reportable="false"
                    />
                  </div>
                  <div v-if="report.moderation_status === 'pending'" class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="form-control rounded-md border px-2 py-1 text-xs" :disabled="Boolean(updatingKey)" @click="executeModerationAction(moderationCase, 'dismiss-report', report)">Ignorer ce signalement</button>
                    <button type="button" class="rounded-md border border-(--error-color) px-2 py-1 text-xs text-(--error-color)" :disabled="Boolean(updatingKey)" @click="executeModerationAction(moderationCase, 'remove-content', report)">Retirer ce contenu</button>
                  </div>
                </article>
              </div>
            </section>

            <div class="mt-5 grid gap-5 x:grid-cols-[1fr_20rem]">
              <section class="secondary-background primary-border rounded-xl border p-4">
                <h4 class="font-semibold">Historique des révisions</h4>
                <ol class="mt-3 grid grid-cols-1 gap-3 text-sm md:grid-cols-3 lg:grid-cols-5 2xl:grid-cols-7 min-[1920px]:grid-cols-9">
                  <li
                    v-for="revision in contexts[moderationCase.id]!.revisions"
                    :key="revision.id"
                    class="primary-border border-l-2 pl-3"
                  >
                    <p class="font-semibold">
                      Révision {{ revision.revision_number }} · {{ revision.revision_status }}
                    </p>
                    <p class="secondary-color">{{ formatDate(revision.updated_at) }}</p>
                    <p v-if="revision.created_by_username" class="secondary-color">
                      Par {{ revision.created_by_username }}
                    </p>
                  </li>
                </ol>
              </section>
            </div>
          </template>
        </section>
      </article>
    </div>

    <Teleport to="body">
      <button
        v-if="zoomedCase?.page_picture"
        type="button"
        class="primary-background primary-border fixed left-1/2 top-1/2 z-60 h-[430px] w-[330px] -translate-x-1/2 -translate-y-1/2 cursor-zoom-out overflow-hidden rounded-lg border shadow-2xl focus:outline-none focus:ring-2 focus:ring-(--focus-color)"
        :aria-label="`Fermer l’image de ${zoomedCase.page_title}`"
        @click="toggleImageZoom(zoomedCase.id)"
      >
        <img
          :src="resolvePagePicture(zoomedCase.reported_page_id, zoomedCase.page_picture)"
          :alt="`Image de présentation de ${zoomedCase.page_title}`"
          class="size-full object-contain"
        >
      </button>
    </Teleport>
  </section>
</template>
