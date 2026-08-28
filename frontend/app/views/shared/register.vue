<!--
  This view renders the registration form used by app/pages/register.vue.
  Local validation gives immediate feedback before data follows frontend -> POST /api/register
  -> AuthController::register() -> User existence/create queries -> SQL insert -> session response.
  The backend repeats every security rule and returns only user-facing errors to this form.
-->
<script setup lang="ts">
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const config = useRuntimeConfig()
const apiFetch = useApi()
const authenticated = useState<boolean | null>("auth-status", () => null)
const profilePicture = useState<string | null>("profile-picture", () => null)
const userRole = useState<"user" | "admin" | null>("auth-role", () => null)
const username = ref("")
const email = ref("")
const password = ref("")
const confirmPassword = ref("")
const revealPasswords = ref(false)
const error = ref("")
const success = ref("")
const pending = ref(false)
const submitted = ref(false)

const usernameError = computed(() => {
  return submitted.value && username.value.trim() === "" ? "Le nom d'utilisateur est requis" : ""
})

const emailError = computed(() => {
  if (email.value === "") return submitted.value ? "L'email est requis" : ""

  return EMAIL_PATTERN.test(email.value) ? "" : "Saisissez une adresse email valide"
})

const passwordError = computed(() => {
  if (password.value === "") return submitted.value ? "Le mot de passe est requis" : ""

  const length = Array.from(password.value.normalize("NFC")).length

  return length >= 15 && length <= 64
    ? ""
    : "Utilisez entre 15 et 64 caractères"
})

const confirmPasswordError = computed(() => {
  if (confirmPassword.value === "") return submitted.value ? "Confirmez le mot de passe" : ""

  return password.value.normalize("NFC") === confirmPassword.value.normalize("NFC")
    ? ""
    : "Les mots de passe ne correspondent pas"
})

const errorText = (exception: unknown): string => {
  if (typeof exception !== "object" || exception === null) return "Inscription impossible"

  return (exception as { data?: { error?: string } }).data?.error || "Inscription impossible"
}

const handleRegister = async () => {
  submitted.value = true
  error.value = ""
  success.value = ""

  if (usernameError.value || emailError.value || passwordError.value || confirmPasswordError.value) return

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
    }>(`${config.public.apiBase}/register`, {
      method: "POST",
      credentials: "include",
      body: {
        username: username.value,
        mail: email.value,
        password: password.value,
      },
    })

    authenticated.value = true
    profilePicture.value = response.user.profil_picture
    userRole.value = response.user.roles
    success.value = "Compte cree"
    await navigateTo("/")
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
        <h1 class="text-center text-3xl font-semibold">Inscription</h1>
        <p class="secondary-color mt-2 text-center text-sm">Créez votre compte pour donner vie à vos univers.</p>

        <form method="post" action="/api/register" class="mt-6 flex flex-col gap-4" novalidate @submit.prevent="handleRegister">
          <div>
            <label for="username" class="secondary-color block text-sm font-medium">Nom d'utilisateur</label>
            <input id="username" v-model="username" type="text" name="username" required autocomplete="username" :aria-invalid="Boolean(usernameError)" aria-describedby="username-error" class="form-control mt-1 block w-full rounded-md border px-3 py-2 shadow-sm focus:outline-none focus:ring-2 sm:text-sm" />
            <p v-if="usernameError" id="username-error" class="error-color mt-1 text-sm" aria-live="polite">{{ usernameError }}</p>
          </div>
          <div>
            <label for="register-email" class="secondary-color block text-sm font-medium">Email</label>
            <input id="register-email" v-model="email" type="email" name="email" required autocomplete="email" :aria-invalid="Boolean(emailError)" aria-describedby="register-email-error" class="form-control mt-1 block w-full rounded-md border px-3 py-2 shadow-sm focus:outline-none focus:ring-2 sm:text-sm" />
            <p v-if="emailError" id="register-email-error" class="error-color mt-1 text-sm" aria-live="polite">{{ emailError }}</p>
          </div>

          <div>
            <label for="register-password" class="secondary-color block text-sm font-medium">Mot de passe</label>
            <input id="register-password" v-model="password" :type="revealPasswords ? 'text' : 'password'" name="password" required autocomplete="new-password" :aria-invalid="Boolean(passwordError)" aria-describedby="register-password-error" class="form-control mt-1 block w-full rounded-md border px-3 py-2 shadow-sm focus:outline-none focus:ring-2 sm:text-sm" />
            <p v-if="passwordError" id="register-password-error" class="error-color mt-1 text-sm" aria-live="polite">{{ passwordError }}</p>
          </div>
          <div>
            <label for="confirm-password" class="secondary-color block text-sm font-medium">Confirmer le mot de passe</label>
            <input id="confirm-password" v-model="confirmPassword" :type="revealPasswords ? 'text' : 'password'" name="confirm-password" required autocomplete="new-password" :aria-invalid="Boolean(confirmPasswordError)" aria-describedby="confirm-password-error" class="form-control mt-1 block w-full rounded-md border px-3 py-2 shadow-sm focus:outline-none focus:ring-2 sm:text-sm" />
            <p v-if="confirmPasswordError" id="confirm-password-error" class="error-color mt-1 text-sm" aria-live="polite">{{ confirmPasswordError }}</p>
          </div>

          <button type="button" class="secondary-color self-start text-sm underline" @click="revealPasswords = !revealPasswords">
            {{ revealPasswords ? "Masquer les mots de passe" : "Afficher les mots de passe" }}
          </button>

          <p v-if="error" class="error-color text-sm font-medium" role="alert">{{ error }}</p>
          <p v-if="success" class="success-color text-sm font-medium">{{ success }}</p>

          <button type="submit" :disabled="pending" class="button-primary mt-2 inline-flex justify-center rounded-md border border-transparent px-4 py-2 text-sm font-medium shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed">
            {{ pending ? "Inscription..." : "S'inscrire" }}
          </button>
        </form>

        <p class="secondary-color mt-5 text-center text-sm">
          Déjà un compte ?
          <NuxtLink to="/login" class="primary-color font-medium underline">Se connecter</NuxtLink>
        </p>
      </section>
    </main>

    <Footer />
  </div>
</template>
