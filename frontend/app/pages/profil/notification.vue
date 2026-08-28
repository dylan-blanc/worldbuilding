<!--
  This authenticated SSG page displays private moderation decisions stored for the current user.
  Browser requests follow /profil/notification -> GET/PATCH /api/me/notifications -> NotificationController
  -> UserNotification owner-scoped SQL; links expose only the affected page and editor after authorization.
-->
<script setup lang="ts">
import type { CmsBlock } from "~/types/cms/cms"

interface NotificationDetails {
  page_title?: string
  content_type?: "page_display" | "page_content"
  block_type?: string | null
  block_id?: string
  moderation_filters?: string[]
  content_snapshot?: CmsBlock | null
}

interface UserNotification {
  id: number
  page_id: number | null
  notification_type: "moderation_content_removed" | "moderation_page_picture_removed"
  title: string
  message: string
  details: NotificationDetails
  read_at: string | null
  created_at: string
}

interface NotificationResponse {
  notifications: UserNotification[]
  unread_count: number
}

definePageMeta({
  middleware: "pagecms-auth",
})

const config = useRuntimeConfig()
const apiFetch = useApi()
const notifications = ref<UserNotification[]>([])
const unreadCount = ref(0)
const loading = ref(true)
const updatingId = ref<number | null>(null)
const errorMessage = ref("")

const formatDate = (value: string) => new Intl.DateTimeFormat("fr-FR", {
  dateStyle: "medium",
  timeStyle: "short",
}).format(new Date(value))

const loadNotifications = async () => {
  loading.value = true
  errorMessage.value = ""

  try {
    const response = await $fetch<NotificationResponse>(`${config.public.apiBase}/me/notifications`, {
      credentials: "include",
    })
    notifications.value = response.notifications || []
    unreadCount.value = Number(response.unread_count) || 0
  } catch {
    errorMessage.value = "Chargement des notifications impossible"
  } finally {
    loading.value = false
  }
}

const markRead = async (notification: UserNotification) => {
  if (notification.read_at || updatingId.value !== null) return

  updatingId.value = notification.id

  try {
    const response = await apiFetch<{ notification: UserNotification }>(
      `${config.public.apiBase}/me/notifications/${notification.id}/read`,
      {
        method: "PATCH",
        credentials: "include",
      },
    )
    const index = notifications.value.findIndex(item => item.id === notification.id)
    index !== -1 && (notifications.value[index] = response.notification)
    unreadCount.value = Math.max(0, unreadCount.value - 1)
  } catch {
    errorMessage.value = "Mise à jour de la notification impossible"
  } finally {
    updatingId.value = null
  }
}

onMounted(loadNotifications)
</script>

<template>
  <div class="primary-background primary-color flex min-h-screen flex-col">
    <Header />

    <main class="mx-auto flex w-full max-w-5xl flex-1 px-4 py-10">
      <section class="w-full">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h1 class="text-3xl font-semibold">Mes notifications</h1>
            <p class="secondary-color mt-1">Retrouvez les décisions concernant vos pages.</p>
          </div>
          <span class="notification-background notification-contrast-color rounded-full px-3 py-1 text-sm font-semibold">
            {{ unreadCount }} non lue{{ unreadCount === 1 ? "" : "s" }}
          </span>
        </div>

        <LoadingSpinner v-if="loading" class="mt-12" label="Chargement des notifications" />

        <div v-else-if="errorMessage" class="mt-8 text-center">
          <p class="error-color" role="alert">{{ errorMessage }}</p>
          <button type="button" class="button-primary mt-4 rounded-md px-4 py-2" @click="loadNotifications">Réessayer</button>
        </div>

        <p v-else-if="notifications.length === 0" class="secondary-color mt-10 text-center">
          Vous n’avez aucune notification.
        </p>

        <ol v-else class="mt-8 grid gap-4">
          <li
            v-for="notification in notifications"
            :key="notification.id"
            class="secondary-background primary-border rounded-xl border p-5"
            :class="notification.read_at ? 'opacity-75' : 'border-l-4 border-l-(--notification-color)'"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h2 class="text-lg font-semibold">{{ notification.title }}</h2>
                <p class="secondary-color text-sm">{{ formatDate(notification.created_at) }}</p>
              </div>
              <span v-if="!notification.read_at" class="notification-background notification-contrast-color rounded-full px-2 py-1 text-xs font-semibold">Non lue</span>
            </div>

            <p class="mt-3">{{ notification.message }}</p>
            <p v-if="notification.details.page_title" class="mt-2 text-sm">
              Page : <strong>{{ notification.details.page_title }}</strong>
            </p>
            <p v-if="notification.details.block_type" class="secondary-color mt-1 text-sm">
              Type de contenu : {{ notification.details.block_type }}
            </p>
            <div v-if="notification.details.moderation_filters?.length" class="mt-3 flex flex-wrap gap-2">
              <span
                v-for="filter in notification.details.moderation_filters"
                :key="filter"
                class="primary-background primary-border rounded-full border px-2 py-1 text-xs"
              >
                {{ filter }}
              </span>
            </div>

            <div
              v-if="notification.page_id && notification.details.content_snapshot"
              class="primary-background primary-border mt-4 max-h-64 overflow-auto rounded-lg border"
            >
              <CmsResultBlock
                :block="notification.details.content_snapshot"
                :page-id="notification.page_id"
                :reportable="false"
              />
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
              <NuxtLink
                v-if="notification.page_id"
                :to="`/pageresult/${notification.page_id}`"
                class="form-control rounded-md border px-3 py-2 text-sm"
                @click="markRead(notification)"
              >
                Voir la page
              </NuxtLink>
              <NuxtLink
                v-if="notification.page_id"
                :to="{ path: '/pagecms', query: { page: notification.page_id } }"
                class="button-primary rounded-md px-3 py-2 text-sm"
                @click="markRead(notification)"
              >
                Éditer le contenu
              </NuxtLink>
              <button
                v-if="!notification.read_at"
                type="button"
                class="form-control rounded-md border px-3 py-2 text-sm"
                :disabled="updatingId !== null"
                @click="markRead(notification)"
              >
                Marquer comme lue
              </button>
            </div>
          </li>
        </ol>
      </section>
    </main>

    <Footer />
  </div>
</template>
