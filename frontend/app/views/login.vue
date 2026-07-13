<script setup lang="ts">
const config = useRuntimeConfig()
const email = ref("")
const password = ref("")
const error = ref("")
const pending = ref(false)

const handleLogin = async () => {
  error.value = ""
  pending.value = true

  try {
    const response = await $fetch<{
      user: {
        id: number
        username: string
        useremail: string
      }
    }>(`${config.public.apiBase}/login`, {
      method: "POST",
      credentials: "include",
      body: {
        mail: email.value,
        password: password.value,
      },
    })

    localStorage.removeItem("auth_token")
    localStorage.setItem("auth_user", JSON.stringify(response.user))
    await navigateTo("/")
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : "Connexion impossible"
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <div class="primary-background primary-color flex min-h-screen flex-col">
    <Header />

    <main class="mx-auto flex w-full max-w-6xl flex-1 align-items-center items-center px-4 py-12">
      <section>
        <h1 class="text-3xl font-semibold">Connexion</h1>

        <form method="post" action="/api/login" class="mt-6 flex flex-col gap-4" @submit.prevent="handleLogin">
            <div>
                <label for="email" class="secondary-color block text-sm font-medium">Email</label>
                <input v-model="email" type="email" id="email" name="email" required autocomplete="email" class="form-control mt-1 block w-full rounded-md shadow-sm sm:text-sm" />
            </div>
            <div>
                <label for="password" class="secondary-color block text-sm font-medium">Mot de passe</label>
                <input v-model="password" type="password" id="password" name="password" required autocomplete="current-password" class="form-control mt-1 block w-full rounded-md shadow-sm sm:text-sm" />
            </div>
          <p v-if="error" class="error-color text-sm font-medium">{{ error }}</p>
          <button type="submit" :disabled="pending" class="button-primary mt-4 inline-flex justify-center rounded-md border border-transparent py-2 px-4 text-sm font-medium shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed">{{ pending ? "Connexion..." : "Se connecter" }}</button>
        </form>

        <p class="secondary-color mt-4 text-sm">
          Pas encore de compte ?
          <NuxtLink to="/register" class="primary-color font-medium underline">S'inscrire</NuxtLink>
        </p>
      </section>
    </main>

    <Footer />
  </div>
</template>
