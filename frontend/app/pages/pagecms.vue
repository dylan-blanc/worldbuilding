<script setup lang="ts">
import Createpage from "~/views/createpage.vue"

definePageMeta({
  middleware: "pagecms-auth",
})
</script>

<template>
  <Createpage />
</template>
