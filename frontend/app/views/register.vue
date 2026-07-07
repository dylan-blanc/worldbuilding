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
  <div class="flex min-h-screen flex-col bg-slate-50 text-slate-950">
    <Header />

    <main class="mx-auto flex w-full max-w-6xl flex-1 items-center px-4 py-12">
      <section>
        <h1 class="text-3xl font-semibold">Inscription</h1>

        <form method="post" action="/api/register" class="mt-6 flex flex-col gap-4" @submit.prevent="handleRegister">
            <div>
                <label for="username" class="block text-sm font-medium text-slate-700">Nom d'utilisateur</label>
                <input v-model="username" type="text" id="username" name="username" required autocomplete="username" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
            </div>
            <div>
                <label for="email" class="block text-sm font-medium text-slate-700">Email</label>
                <input v-model="email" type="email" id="email" name="email" required autocomplete="email" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-700">Mot de passe</label>
                <input v-model="password" type="password" id="password" name="password" required autocomplete="new-password" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
            </div>
                <div>
                <label for="confirm-password" class="block text-sm font-medium text-slate-700">Confirmer le mot de passe</label>
                <input v-model="confirmPassword" type="password" id="confirm-password" name="confirm-password" required autocomplete="new-password" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
            </div>

          <p v-if="error" class="text-sm font-medium text-red-600">{{ error }}</p>
          <p v-if="success" class="text-sm font-medium text-emerald-700">{{ success }}</p>

          <button type="submit" :disabled="pending" class="mt-4 inline-flex justify-center rounded-md border border-transparent bg-indigo-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-slate-400">{{ pending ? "Inscription..." : "S'inscrire" }}</button>
        </form>
      </section>
    </main>

    <Footer />
  </div>
</template>
