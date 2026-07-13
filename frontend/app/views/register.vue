<script setup lang="ts">
const config = useRuntimeConfig()
const username = ref("")
const email = ref("")
const password = ref("")
const confirmPassword = ref("")
const error = ref("")
const success = ref("")
const pending = ref(false)

const handleRegister = async () => {
  error.value = ""
  success.value = ""

  if (password.value !== confirmPassword.value) {
    error.value = "Les mots de passe ne correspondent pas"
    return
  }

  pending.value = true

  try {
    const response = await $fetch<{
      user: {
        id: number
        username: string
        useremail: string
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

    localStorage.removeItem("auth_token")
    localStorage.setItem("auth_user", JSON.stringify(response.user))
    success.value = "Compte cree"
    await navigateTo("/")
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : "Inscription impossible"
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <div class="primary-background primary-color flex min-h-screen flex-col">
    <Header />

    <main class="mx-auto flex w-full max-w-6xl flex-1 items-center px-4 py-12">
      <section>
        <h1 class="text-3xl font-semibold">Inscription</h1>

        <form method="post" action="/api/register" class="mt-6 flex flex-col gap-4" @submit.prevent="handleRegister">
            <div>
                <label for="username" class="secondary-color block text-sm font-medium">Nom d'utilisateur</label>
                <input v-model="username" type="text" id="username" name="username" required autocomplete="username" class="form-control mt-1 block w-full rounded-md shadow-sm sm:text-sm" />
            </div>
            <div>
                <label for="email" class="secondary-color block text-sm font-medium">Email</label>
                <input v-model="email" type="email" id="email" name="email" required autocomplete="email" class="form-control mt-1 block w-full rounded-md shadow-sm sm:text-sm" />
            </div>

            <div>
                <label for="password" class="secondary-color block text-sm font-medium">Mot de passe</label>
                <input v-model="password" type="password" id="password" name="password" required autocomplete="new-password" class="form-control mt-1 block w-full rounded-md shadow-sm sm:text-sm" />
            </div>
                <div>
                <label for="confirm-password" class="secondary-color block text-sm font-medium">Confirmer le mot de passe</label>
                <input v-model="confirmPassword" type="password" id="confirm-password" name="confirm-password" required autocomplete="new-password" class="form-control mt-1 block w-full rounded-md shadow-sm sm:text-sm" />
            </div>

          <p v-if="error" class="error-color text-sm font-medium">{{ error }}</p>
          <p v-if="success" class="success-color text-sm font-medium">{{ success }}</p>

          <button type="submit" :disabled="pending" class="button-primary mt-4 inline-flex justify-center rounded-md border border-transparent py-2 px-4 text-sm font-medium shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed">{{ pending ? "Inscription..." : "S'inscrire" }}</button>
        </form>
      </section>
    </main>

    <Footer />
  </div>
</template>
