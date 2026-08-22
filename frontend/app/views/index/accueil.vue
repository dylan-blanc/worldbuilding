<template>
  <div class="primary-background primary-color flex min-h-screen flex-col">
    <Header />

    <main class="mx-auto flex w-full max-w-6xl flex-1 px-4 py-12">
      <section class="flex w-full flex-col gap-6">
        <FilterBar />
        <PageDisplay />
      </section>
    </main>

    <Footer />
  </div>
</template>
