<!--
  This view manages the authenticated profile displayed by app/pages/profil.vue.
  Loading follows frontend -> GET /api/me -> UserProfileController::show()
  -> User/UserProfil prepared SQL and MinIO listing -> profile response -> UserAvatar/useProfilePicture.
  Saving follows multipart POST /api/me -> current-password verification -> optional MinIO upload
  -> User::updateProfile() prepared SQL -> refreshed profile response.
-->
<script setup lang="ts">
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const PASSWORD_PATTERN = /^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9\s]).{8,}$/

interface ProfileUser {
  id: number
  username: string
  useremail: string
  profil_picture: string | null
  roles: "user" | "admin"
  created_at: string
}

interface ProfilePicture {
  key: string
  size: number
  last_modified: string | null
}

interface ProfileResponse {
  message?: string
  user: ProfileUser
  stats: {
    total_likes: number
    total_followers: number
  }
  profile_pictures: ProfilePicture[]
}

interface NotificationCountResponse {
  unread_count: number
}

const config = useRuntimeConfig()
const sharedProfilePicture = useState<string | null>("profile-picture", () => null)
const sharedUserRole = useState<"user" | "admin" | null>("auth-role", () => null)
const username = ref("")
const email = ref("")
const newPassword = ref("")
const confirmPassword = ref("")
const currentPassword = ref("")
const selectedPicture = ref("")
const profileFile = ref<File | null>(null)
const profileInput = ref<HTMLInputElement | null>(null)
const localPreview = ref("")
const profile = ref<ProfileResponse | null>(null)
const currentPasswordInput = ref<HTMLInputElement | null>(null)
const loading = ref(true)
const pending = ref(false)
const logoutPending = ref(false)
const submitted = ref(false)
const currentPasswordSubmitted = ref(false)
const showCurrentPassword = ref(false)
const errorMessage = ref("")
const successMessage = ref("")
const unreadNotificationCount = ref(0)

const usernameError = computed(() => (
  submitted.value && username.value.trim() === "" ? "Le nom d'utilisateur est requis" : ""
))

const emailError = computed(() => {
  if (email.value === "") return submitted.value ? "L'email est requis" : ""

  return EMAIL_PATTERN.test(email.value) ? "" : "Saisissez une adresse email valide"
})

const newPasswordError = computed(() => {
  if (newPassword.value === "") return ""

  return PASSWORD_PATTERN.test(newPassword.value)
    ? ""
    : "Utilisez au moins 8 caractères, une majuscule, un chiffre et un caractère spécial"
})

const confirmPasswordError = computed(() => {
  if (newPassword.value === "" && confirmPassword.value === "") return ""
  if (confirmPassword.value === "") return submitted.value ? "Confirmez le nouveau mot de passe" : ""

  return newPassword.value === confirmPassword.value ? "" : "Les mots de passe ne correspondent pas"
})

const hasProtectedChanges = computed(() => {
  const currentUser = profile.value?.user

  return Boolean(
    currentUser
    && (
      username.value.trim() !== currentUser.username
      || email.value.trim().toLowerCase() !== currentUser.useremail.toLowerCase()
      || newPassword.value !== ""
    )
  )
})

const currentPasswordError = computed(() => (
  currentPasswordSubmitted.value && currentPassword.value === "" ? "Le mot de passe actuel est requis" : ""
))

const errorText = (error: unknown, fallback: string): string => {
  if (typeof error !== "object" || error === null) return fallback

  return (error as { data?: { error?: string } }).data?.error || fallback
}

const { resolveUrl: pictureUrl } = useProfilePicture()
const displayedPicture = computed(() => (
  localPreview.value || selectedPicture.value || profile.value?.user.profil_picture || null
))

const applyProfile = (response: ProfileResponse) => {
  profile.value = response
  username.value = response.user.username
  email.value = response.user.useremail
  selectedPicture.value = response.user.profil_picture || ""
  sharedProfilePicture.value = response.user.profil_picture
  sharedUserRole.value = response.user.roles
}

const loadProfile = async () => {
  loading.value = true
  errorMessage.value = ""

  try {
    const [response, notificationCount] = await Promise.all([
      $fetch<ProfileResponse>(`${config.public.apiBase}/me`, {
        credentials: "include",
      }),
      $fetch<NotificationCountResponse>(`${config.public.apiBase}/me/notifications/unread-count`, {
        credentials: "include",
      }).catch(() => ({ unread_count: 0 })),
    ])

    applyProfile(response)
    unreadNotificationCount.value = Number(notificationCount.unread_count) || 0
  } catch (error) {
    errorMessage.value = errorText(error, "Chargement du profil impossible")
  } finally {
    loading.value = false
  }
}

const clearLocalPreview = () => {
  localPreview.value && URL.revokeObjectURL(localPreview.value)
  localPreview.value = ""
}

const selectUploadedFile = (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] || null

  clearLocalPreview()
  profileFile.value = file
  file && (localPreview.value = URL.createObjectURL(file))
  errorMessage.value = ""
  successMessage.value = ""
}

const selectPreviousPicture = (picture: ProfilePicture) => {
  clearLocalPreview()
  profileFile.value = null
  selectedPicture.value = picture.key
  profileInput.value && (profileInput.value.value = "")
  errorMessage.value = ""
  successMessage.value = ""
}

const saveProfile = async () => {
  submitted.value = true
  errorMessage.value = ""
  successMessage.value = ""

  if (hasProtectedChanges.value && !showCurrentPassword.value) {
    showCurrentPassword.value = true
    currentPasswordSubmitted.value = false
    await nextTick()
    currentPasswordInput.value?.focus()

    return
  }

  currentPasswordSubmitted.value = hasProtectedChanges.value

  if (
    usernameError.value
    || emailError.value
    || newPasswordError.value
    || confirmPasswordError.value
    || currentPasswordError.value
  ) return

  const formData = new FormData()
  formData.append("username", username.value.trim())
  formData.append("useremail", email.value.trim())
  formData.append("current_password", currentPassword.value)
  formData.append("new_password", newPassword.value)
  formData.append("selected_picture", selectedPicture.value)
  profileFile.value && formData.append("profile_picture", profileFile.value)
  pending.value = true

  try {
    const response = await $fetch<ProfileResponse>(`${config.public.apiBase}/me`, {
      method: "POST",
      credentials: "include",
      body: formData,
    })

    clearLocalPreview()
    profileFile.value = null
    profileInput.value && (profileInput.value.value = "")
    applyProfile(response)
    currentPassword.value = ""
    newPassword.value = ""
    confirmPassword.value = ""
    submitted.value = false
    currentPasswordSubmitted.value = false
    showCurrentPassword.value = false
    successMessage.value = response.message || "Profil mis à jour"
    localStorage.setItem("auth_user", JSON.stringify(response.user))
  } catch (error) {
    errorMessage.value = errorText(error, "Enregistrement du profil impossible")
  } finally {
    pending.value = false
  }
}

const logout = async () => {
  logoutPending.value = true
  errorMessage.value = ""

  try {
    await $fetch(`${config.public.apiBase}/logout`, {
      method: "POST",
      credentials: "include",
    })

    localStorage.removeItem("auth_user")
    sharedProfilePicture.value = null
    sharedUserRole.value = null
    await navigateTo("/")
  } catch (error) {
    errorMessage.value = errorText(error, "Déconnexion impossible")
  } finally {
    logoutPending.value = false
  }
}

watch(hasProtectedChanges, (requiresPassword) => {
  if (requiresPassword) return

  currentPassword.value = ""
  currentPasswordSubmitted.value = false
  showCurrentPassword.value = false
})

onMounted(loadProfile)
onBeforeUnmount(clearLocalPreview)
</script>

<template>
  <div class="primary-background primary-color flex min-h-screen flex-col">
    <Header />

    <main class="mx-auto flex w-full max-w-6xl flex-1 px-4 py-10">
      <div v-if="loading" class="flex w-full items-center justify-center">
        <LoadingSpinner label="Chargement du profil" />
      </div>

      <section v-else-if="!profile" class="m-auto text-center">
        <p class="error-color" role="alert">{{ errorMessage }}</p>
        <button type="button" class="button-primary mt-4 rounded-md px-4 py-2" @click="loadProfile">Réessayer</button>
      </section>

      <section v-else class="w-full">
        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
          <div>
            <h1 class="text-3xl font-semibold">Mon profil</h1>
            <p class="secondary-color mt-1">Gérez votre identité et retrouvez l’activité de vos univers.</p>
          </div>
          <div class="flex flex-wrap items-center gap-3">
            <NuxtLink
              to="/profil/notification"
              class="notification-background notification-contrast-color inline-flex items-center gap-3 rounded-md px-4 py-2 font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--focus-color)"
            >
              Mes notifications
              <span class="rounded-full border border-current px-2 py-0.5 text-sm" aria-label="Notifications non lues">
                {{ unreadNotificationCount }}
              </span>
            </NuxtLink>
            <button
              type="button"
              :disabled="logoutPending"
              class="inline-flex items-center rounded-md bg-(--warning-color) px-4 py-2 font-semibold text-white hover:brightness-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--focus-color) disabled:cursor-not-allowed disabled:opacity-60"
              @click="logout"
            >
              {{ logoutPending ? "Déconnexion..." : "Se déconnecter" }}
            </button>
          </div>
        </div>

        <div class="mb-8 grid gap-4 sm:grid-cols-2">
          <article class="secondary-background primary-border rounded-xl border p-5">
            <p class="secondary-color text-sm">Likes reçus sur mes pages</p>
            <p class="mt-2 text-3xl font-semibold">{{ profile.stats.total_likes }}</p>
          </article>
          <article class="secondary-background primary-border rounded-xl border p-5">
            <p class="secondary-color text-sm">Abonnements à mes pages</p>
            <p class="mt-2 text-3xl font-semibold">{{ profile.stats.total_followers }}</p>
          </article>
        </div>

        <form class="grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]" novalidate @submit.prevent="saveProfile">
          <div class="secondary-background primary-border rounded-2xl border p-6 sm:p-8">
            <h2 class="text-xl font-semibold">Informations du compte</h2>

            <div class="mt-6 grid gap-5 sm:grid-cols-2">
              <div>
                <label for="profile-username" class="secondary-color block text-sm font-medium">Nom d'utilisateur</label>
                <input id="profile-username" v-model="username" type="text" autocomplete="username" :aria-invalid="Boolean(usernameError)" aria-describedby="profile-username-error" class="form-control mt-1 w-full rounded-md border px-3 py-2 focus:outline-none focus:ring-2" />
                <p id="profile-username-error" class="error-color mt-1 text-sm" aria-live="polite">{{ usernameError }}</p>
              </div>

              <div>
                <label for="profile-email" class="secondary-color block text-sm font-medium">Email</label>
                <input id="profile-email" v-model="email" type="email" autocomplete="email" :aria-invalid="Boolean(emailError)" aria-describedby="profile-email-error" class="form-control mt-1 w-full rounded-md border px-3 py-2 focus:outline-none focus:ring-2" />
                <p id="profile-email-error" class="error-color mt-1 text-sm" aria-live="polite">{{ emailError }}</p>
              </div>

              <div>
                <label for="profile-password" class="secondary-color block text-sm font-medium">Nouveau mot de passe</label>
                <input id="profile-password" v-model="newPassword" type="password" placeholder="********" autocomplete="new-password" :aria-invalid="Boolean(newPasswordError)" aria-describedby="profile-password-error" class="form-control mt-1 w-full rounded-md border px-3 py-2 focus:outline-none focus:ring-2" />
                <p id="profile-password-error" class="error-color mt-1 text-sm" aria-live="polite">{{ newPasswordError }}</p>
              </div>

              <div>
                <label for="profile-password-confirmation" class="secondary-color block text-sm font-medium">Confirmer le nouveau mot de passe</label>
                <input id="profile-password-confirmation" v-model="confirmPassword" type="password" placeholder="********" autocomplete="new-password" :aria-invalid="Boolean(confirmPasswordError)" aria-describedby="profile-password-confirmation-error" class="form-control mt-1 w-full rounded-md border px-3 py-2 focus:outline-none focus:ring-2" />
                <p id="profile-password-confirmation-error" class="error-color mt-1 text-sm" aria-live="polite">{{ confirmPasswordError }}</p>
              </div>
            </div>

            <div v-if="showCurrentPassword && hasProtectedChanges" class="primary-border mt-6 border-t pt-6">
              <label for="current-password" class="secondary-color block text-sm font-medium">Mot de passe actuel requis pour enregistrer</label>
              <input id="current-password" ref="currentPasswordInput" v-model="currentPassword" type="password" autocomplete="current-password" :aria-invalid="Boolean(currentPasswordError)" aria-describedby="current-password-error" class="form-control mt-1 w-full rounded-md border px-3 py-2 focus:outline-none focus:ring-2" />
              <p id="current-password-error" class="error-color mt-1 text-sm" aria-live="polite">{{ currentPasswordError }}</p>
            </div>

            <p class="error-color mt-5 text-sm font-medium" role="alert">{{ errorMessage }}</p>
            <p class="success-color mt-5 text-sm font-medium" aria-live="polite">{{ successMessage }}</p>

            <button type="submit" :disabled="pending" class="button-primary mt-5 w-full rounded-md px-4 py-2 font-medium focus:outline-none focus:ring-2 disabled:cursor-not-allowed">
              {{ pending ? "Enregistrement..." : "Enregistrer les modifications" }}
            </button>
          </div>

          <aside class="secondary-background primary-border rounded-2xl border p-6">
            <h2 class="text-xl font-semibold">Image de profil</h2>

            <UserAvatar
              :picture="displayedPicture"
              :username="username"
              source="current-user"
              size="xl"
              :initial-length="2"
              label="Aperçu de l'image de profil"
              class="mx-auto mt-5"
            />

            <label for="profile-picture" class="button-primary mt-5 block cursor-pointer rounded-md px-4 py-2 text-center text-sm font-medium">
              Choisir une nouvelle image
            </label>
            <input id="profile-picture" ref="profileInput" type="file" accept="image/jpeg,image/png,image/webp,image/avif,image/gif" class="sr-only" @change="selectUploadedFile" />
            <p class="secondary-color mt-2 text-center text-xs">JPEG, PNG, WebP, AVIF ou GIF · 10 Mo maximum</p>

            <div v-if="profile.profile_pictures.length" class="primary-border mt-6 border-t pt-5">
              <h3 class="text-sm font-semibold">Anciennes images</h3>
              <div class="mt-3 grid grid-cols-3 gap-3">
                <button
                  v-for="picture in profile.profile_pictures"
                  :key="picture.key"
                  type="button"
                  class="primary-border aspect-square overflow-hidden rounded-lg"
                  :class="selectedPicture === picture.key && !profileFile ? 'border-4' : 'border'"
                  :aria-label="`Réutiliser l'image du ${picture.last_modified || 'profil'}`"
                  @click="selectPreviousPicture(picture)"
                >
                  <img :src="pictureUrl(picture.key)" alt="" class="size-full object-cover" />
                </button>
              </div>
            </div>
          </aside>
        </form>
      </section>
    </main>

    <Footer />
  </div>
</template>
