<!--
  This Nuxt page exposes /adminpanel and delegates its protected interface to AdminDashboard.
  The client-only admin-auth middleware calls GET /api/me and redirects every non-admin visitor to /.
-->
<script setup lang="ts">
import AdminDashboard from "~/views/admindashboard.vue"

definePageMeta({
  middleware: "admin-auth",
})
</script>

<template>
  <AdminDashboard />
</template>
