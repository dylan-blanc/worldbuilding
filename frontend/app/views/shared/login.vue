<!--
  This view renders the login form used by app/pages/login.vue.
  Local email and password limits provide immediate feedback before submission.
  The submitted credentials follow frontend -> POST /api/login -> AuthController::login()
  -> User::findByEmail() -> SQL lookup -> PHP session cookie -> in-memory Nuxt authentication state.
  User-facing API errors are read from the JSON response and displayed inside the form.
-->
<script setup lang="ts">
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const EMAIL_MAX_LENGTH = 254
const PASSWORD_MAX_LENGTH = 64
const config = useRuntimeConfig()
const route = useRoute()
const apiFetch = useApi()
const authenticated = useState<boolean | null>("auth-status", () => null)
const profilePicture = useState<string | null>("profile-picture", () => null)
const userRole = useState<"user" | "admin" | null>("auth-role", () => null)
const email = ref("")
const password = ref("")
const revealPassword = ref(false)
const error = ref("")
const pending = ref(false)
const submitted = ref(false)

const emailError = computed(() => {
  const value = email.value.trim()

  if (value === "") return submitted.value ? "L'email est requis" : ""
  if (Array.from(value).length > EMAIL_MAX_LENGTH) return "L'email ne peut pas dépasser 254 caractères"

  return EMAIL_PATTERN.test(value) ? "" : "Saisissez une adresse email valide"
})

const passwordError = computed(() => {
  if (password.value === "") return submitted.value ? "Le mot de passe est requis" : ""

  return Array.from(password.value.normalize("NFC")).length <= PASSWORD_MAX_LENGTH
    ? ""
    : "Le mot de passe ne peut pas dépasser 64 caractères"
})

const errorText = (exception: unknown): string => {
  if (typeof exception !== "object" || exception === null) return "Connexion impossible"

  return (exception as { data?: { error?: string } }).data?.error || "Connexion impossible"
}

const handleLogin = async () => {
  submitted.value = true
  error.value = ""

  if (emailError.value || passwordError.value) return

  pending.value = true

  try {
    const response = await apiFetch<{
      user: {
        id: number
        username: string
        useremail: string
        profil_picture: string | null
        roles: "user" | "admin"
      }
    }>(`${config.public.apiBase}/login`, {
      method: "POST",
      credentials: "include",
      body: {
        mail: email.value,
        password: password.value,
      },
    })

    authenticated.value = true
    profilePicture.value = response.user.profil_picture
    userRole.value = response.user.roles
    const requestedPath = typeof route.query.redirect === "string" ? route.query.redirect : ""
    const redirectPath = requestedPath.startsWith("/") && !requestedPath.startsWith("//") ? requestedPath : "/"
    await navigateTo(redirectPath)
  } catch (exception) {
    error.value = errorText(exception)
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <div class="primary-background primary-color flex min-h-screen flex-col">
    <Header />

    <main class="mx-auto flex w-full max-w-6xl flex-1 items-center justify-center px-4 py-12">
      <section class="first-background primary-border w-full max-w-md rounded-2xl border-2 p-6 shadow-xl sm:p-8">
        <h1 class="text-center text-3xl font-semibold">Connexion</h1>
        <p class="secondary-color mt-2 text-center text-sm">Retrouvez vos univers et poursuivez leur création.</p>

        <form method="post" action="/api/login" class="mt-6 flex flex-col gap-4" novalidate @submit.prevent="handleLogin">
          <div>
            <label for="email" class="secondary-color block text-sm font-medium">Email</label>
            <input id="email" v-model="email" type="email" name="email" required autocomplete="email" :aria-invalid="Boolean(emailError)" aria-describedby="login-email-error" class="form-control mt-1 block w-full rounded-md border-2 px-3 py-2 shadow-sm focus:outline-none focus:ring-2 sm:text-sm" />
            <p v-if="emailError" id="login-email-error" class="error-color mt-1 text-sm" aria-live="polite">{{ emailError }}</p>
          </div>
          <div>
            <label for="password" class="secondary-color block text-sm font-medium">Mot de passe</label>
            <input id="password" v-model="password" :type="revealPassword ? 'text' : 'password'" name="password" required autocomplete="current-password" :aria-invalid="Boolean(passwordError)" aria-describedby="login-password-error" class="form-control mt-1 block w-full rounded-md border-2 px-3 py-2 shadow-sm focus:outline-none focus:ring-2 sm:text-sm" />
            <p v-if="passwordError" id="login-password-error" class="error-color mt-1 text-sm" aria-live="polite">{{ passwordError }}</p>
            <button type="button" class="secondary-color mt-2 text-sm underline" @click="revealPassword = !revealPassword">
              {{ revealPassword ? "Masquer le mot de passe" : "Afficher le mot de passe" }}
            </button>
          </div>

          <p v-if="error" class="error-color text-sm font-medium" role="alert">{{ error }}</p>

          <button type="submit" :disabled="pending" class="button-primary mt-2 inline-flex justify-center rounded-md border border-transparent px-4 py-2 text-sm font-medium shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed">
            {{ pending ? "Connexion..." : "Se connecter" }}
          </button>
        </form>

        <p class="secondary-color mt-5 text-center text-sm">
          Pas encore de compte ?
          <NuxtLink to="/register" class="primary-color font-medium underline">S'inscrire</NuxtLink>
        </p>
      </section>
    </main>

    <Footer />
  </div>
</template>
