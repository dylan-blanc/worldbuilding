// Configures Nuxt for the development server and the production static-generation build.
// Environment values flow from Docker Compose to the Docker build, Nuxt runtime config and browser API calls.
import tailwindcss from "@tailwindcss/vite"

const apiBase = process.env.PROD_API_BASE || process.env.NUXT_PUBLIC_API_BASE || process.env.BASE_URL || "/api"
const siteUrl = process.env.PROD_FRONTEND_URL || process.env.FRONTEND_URL || "http://localhost:8080"

export default defineNuxtConfig({
  compatibilityDate: "2025-07-15",
  devtools: { enabled: process.env.NODE_ENV !== "production" },
  css: ["~/assets/css/main.css"],
  components: [
    {
      path: "~/components",
      pathPrefix: false,
    },
  ],
  imports: {
    dirs: ["composables", "composables/**"],
  },
  runtimeConfig: {
    public: {
      apiBase,
      siteUrl,
    },
  },
  vite: {
    plugins: [
      tailwindcss(),
    ],
  },
})
