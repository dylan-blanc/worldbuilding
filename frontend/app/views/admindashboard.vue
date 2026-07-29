<!--
  This view provides the administration dashboard shell used by app/pages/adminpanel.vue.
  Access currently follows /adminpanel -> admin-auth middleware -> GET /api/me -> User SQL role.
  Future administration features will be added inside the main content area and secured by their PHP endpoints.
-->
<template>
  <div class="primary-background primary-color flex min-h-screen flex-col">
    <Header />

    <main class="mx-auto flex w-full max-w-6xl flex-1 px-4 py-12">
      <h1 class="text-3xl font-semibold">Page Admin</h1>
    </main>

    <Footer />
  </div>
</template>
