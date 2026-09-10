<!--
  This Nuxt page exposes /profil and delegates the authenticated account interface to UserProfile.
  The client-only middleware checks GET /api/me before the view loads private profile information.
-->
<script setup lang="ts">
import UserProfile from "~/views/shared/userprofile.vue"

definePageMeta({
  middleware: "pagecms-auth",
})
</script>

<template>
  <UserProfile />
</template>
