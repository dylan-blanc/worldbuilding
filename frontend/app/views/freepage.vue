<!--
  This view is the empty free-edition workspace displayed by /pagecms.
  It is selected by pagecms.vue after Createpage emits startFreeEdition.
  The future WYSIWYG canvas will receive CMS toolbar actions and persist content through the backend flow.
-->
<template>
  <section class="mx-auto flex w-full max-w-7xl flex-1 px-4 py-8 md:px-8">
    <div
      class="secondary-background primary-border flex min-h-[32rem] w-full items-center justify-center rounded-xl border-2 border-dashed p-8 text-center"
    >
      <div>
        <h1 class="text-3xl font-semibold">Édition libre</h1>
        <p class="secondary-color mt-3">Votre espace de création WYSIWYG apparaîtra ici.</p>
      </div>
    </div>
  </section>
</template>
