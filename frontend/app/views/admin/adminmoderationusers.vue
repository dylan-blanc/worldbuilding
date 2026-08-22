<!--
  This view groups moderation cases by the owner of each reported page without exposing page JSON.
  Data follows GET /api/admin/moderation/users -> Moderation case/page/user joins -> child reports without JSON.
  Links return to AdminModeration with case and block query targets so the reported preview opens directly.
-->
<script setup lang="ts">
import { ArrowTopRightOnSquareIcon } from "@heroicons/vue/24/outline"
import type { ModerationStatus, ReportedContentType } from "~/types/shared/moderation"

interface OwnerModerationReport {
  id: number
  moderation_status: ModerationStatus
  reported_content_type: ReportedContentType
  reported_block_id: string
  reported_block_type: string | null
  reported_user_message: string | null
  reported_filter_name: string
  reporter_user_id: number
  reporter_username: string
  created_at: string
}

interface OwnerModerationCase {
  id: number
  moderation_status: ModerationStatus
  created_at: string
  updated_at: string
  reported_page_id: number
  page_title: string
  page_status: "public" | "private" | "banned"
  reports: OwnerModerationReport[]
}

interface OwnerModerationResponse {
  owners: Array<{
    owner_user_id: number
    owner_username: string
    owner_picture: string | null
    cases: OwnerModerationCase[]
  }>
}

interface OwnerModerationGroup {
  ownerUserId: number
  username: string
  picture: string | null
  cases: OwnerModerationCase[]
  reportCount: number
}

const config = useRuntimeConfig()
const loading = ref(true)
const errorMessage = ref("")
const ownerGroups = ref<OwnerModerationGroup[]>([])

function errorText(error: unknown, fallback: string): string {
  if (typeof error !== "object" || error === null) return fallback

  return (error as { data?: { error?: string } }).data?.error || fallback
}

function formatDate(value: string | null): string {
  if (!value) return "—"

  return new Intl.DateTimeFormat("fr-FR", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value))
}

function statusLabel(status: ModerationStatus): string {
  return status === "pending" ? "En attente" : status === "reviewed" ? "Traité" : "Rejeté"
}

function contentLabel(report: OwnerModerationReport): string {
  return report.reported_content_type === "page_display"
    ? "Page et image de présentation"
    : `Bloc ${report.reported_block_type || "contenu"} · ${report.reported_block_id}`
}

function moderationTarget(
  moderationCase: OwnerModerationCase,
  report?: OwnerModerationReport,
): object {
  const query: Record<string, string> = {
    section: "moderation",
    case: String(moderationCase.id),
  }

  report?.reported_content_type === "page_content"
    && (query.block = report.reported_block_id)
  report?.reported_content_type === "page_display"
    && (query.target = "page_display")

  return { path: "/adminpanel", query }
}

async function loadCases(): Promise<void> {
  loading.value = true
  errorMessage.value = ""

  try {
    const response = await $fetch<OwnerModerationResponse>(
      `${config.public.apiBase}/admin/moderation/users`,
      { credentials: "include" },
    )

    ownerGroups.value = (response.owners || [])
      .map(owner => ({
        ownerUserId: Number(owner.owner_user_id),
        username: owner.owner_username,
        picture: owner.owner_picture,
        cases: owner.cases,
        reportCount: owner.cases.reduce((total, moderationCase) => (
          total + moderationCase.reports.length
        ), 0),
      }))
      .sort((first, second) => second.reportCount - first.reportCount)
  } catch (error) {
    errorMessage.value = errorText(error, "Chargement des utilisateurs signalés impossible")
  } finally {
    loading.value = false
  }
}

onMounted(loadCases)
</script>

<template>
  <section class="flex flex-col gap-6">
    <header>
      <h2 class="text-2xl font-semibold">Modération par utilisateurs</h2>
      <p class="secondary-color mt-2 text-sm">
        Les dossiers sont regroupés selon le propriétaire des pages signalées.
      </p>
      <p v-if="errorMessage" class="error-color mt-3 text-sm font-medium" role="alert">{{ errorMessage }}</p>
    </header>

    <LoadingSpinner v-if="loading" label="Chargement des utilisateurs signalés" />

    <p
      v-else-if="ownerGroups.length === 0"
      class="secondary-background primary-border rounded-xl border p-8 text-center"
    >
      Aucun utilisateur ne possède de page signalée.
    </p>

    <div v-else class="grid gap-6">
      <article
        v-for="group in ownerGroups"
        :key="group.ownerUserId"
        class="secondary-background primary-border overflow-hidden rounded-xl border"
      >
        <header class="primary-border flex flex-wrap items-center justify-between gap-4 border-b p-5">
          <div class="flex items-center gap-3">
            <UserAvatar
              :picture="group.picture"
              :username="group.username"
              :page-id="group.cases[0]?.reported_page_id"
              source="page-owner"
              :anonymous="false"
              size="md"
            />
            <div>
              <h3 class="text-xl font-semibold">{{ group.username }}</h3>
              <p class="secondary-color text-sm">Utilisateur #{{ group.ownerUserId }}</p>
            </div>
          </div>
          <div class="flex gap-2 text-sm">
            <span class="primary-border rounded-full border px-3 py-1">
              {{ group.cases.length }} page{{ group.cases.length > 1 ? "s" : "" }}
            </span>
            <span class="warning-color rounded-full border border-(--warning-color) px-3 py-1 font-semibold">
              {{ group.reportCount }} signalement{{ group.reportCount > 1 ? "s" : "" }}
            </span>
          </div>
        </header>

        <div class="grid gap-4 p-5">
          <section
            v-for="moderationCase in group.cases"
            :key="moderationCase.id"
            class="primary-background primary-border rounded-lg border p-4"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h4 class="text-lg font-semibold">{{ moderationCase.page_title }}</h4>
                <p class="secondary-color mt-1 text-sm">
                  Page #{{ moderationCase.reported_page_id }} · dossier {{ statusLabel(moderationCase.moderation_status) }}
                </p>
              </div>
              <NuxtLink
                :to="moderationTarget(moderationCase)"
                class="form-control inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm focus:outline-none focus:ring-2"
              >
                Ouvrir le dossier
                <ArrowTopRightOnSquareIcon class="size-4" aria-hidden="true" />
              </NuxtLink>
            </div>

            <div class="mt-4 grid gap-3">
              <article
                v-for="report in moderationCase.reports"
                :key="report.id"
                class="secondary-background primary-border rounded-md border p-3"
              >
                <div class="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <p class="font-semibold">{{ report.reported_filter_name }}</p>
                    <p class="secondary-color mt-1 text-sm">
                      {{ contentLabel(report) }} · signalé par {{ report.reporter_username }}
                    </p>
                  </div>
                  <div class="text-right text-sm">
                    <p>{{ statusLabel(report.moderation_status) }}</p>
                    <p class="secondary-color">{{ formatDate(report.created_at) }}</p>
                  </div>
                </div>

                <p v-if="report.reported_user_message" class="mt-3 text-sm">
                  {{ report.reported_user_message }}
                </p>
                <p v-else class="secondary-color mt-3 text-sm italic">Aucun message ajouté.</p>

                <NuxtLink
                  :to="moderationTarget(moderationCase, report)"
                  class="secondary-color mt-3 inline-flex items-center gap-1 text-sm font-semibold hover:primary-color focus:outline-none focus:ring-2"
                >
                  Afficher le contenu signalé
                  <ArrowTopRightOnSquareIcon class="size-4" aria-hidden="true" />
                </NuxtLink>
              </article>
            </div>
          </section>
        </div>
      </article>
    </div>
  </section>
</template>
