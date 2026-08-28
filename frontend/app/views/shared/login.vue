<!--
  This view renders the login form used by app/pages/login.vue.
  The submitted credentials follow frontend -> POST /api/login -> AuthController::login()
  -> User::findByEmail() -> SQL lookup -> PHP session cookie -> in-memory Nuxt authentication state.
  User-facing API errors are read from the JSON response and displayed inside the form.
-->
<script setup lang="ts">
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

const errorText = (exception: unknown): string => {
  if (typeof exception !== "object" || exception === null) return "Connexion impossible"

  return (exception as { data?: { error?: string } }).data?.error || "Connexion impossible"
}

const handleLogin = async () => {
  error.value = ""
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
      <section class="secondary-background primary-border w-full max-w-md rounded-2xl border p-6 shadow-xl sm:p-8">
        <h1 class="text-center text-3xl font-semibold">Connexion</h1>
        <p class="secondary-color mt-2 text-center text-sm">Retrouvez vos univers et poursuivez leur création.</p>

        <form method="post" action="/api/login" class="mt-6 flex flex-col gap-4" @submit.prevent="handleLogin">
          <div>
            <label for="email" class="secondary-color block text-sm font-medium">Email</label>
            <input id="email" v-model="email" type="email" name="email" required autocomplete="email" class="form-control mt-1 block w-full rounded-md border px-3 py-2 shadow-sm focus:outline-none focus:ring-2 sm:text-sm" />
          </div>
          <div>
            <label for="password" class="secondary-color block text-sm font-medium">Mot de passe</label>
            <input id="password" v-model="password" :type="revealPassword ? 'text' : 'password'" name="password" required autocomplete="current-password" class="form-control mt-1 block w-full rounded-md border px-3 py-2 shadow-sm focus:outline-none focus:ring-2 sm:text-sm" />
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
