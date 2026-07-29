<!--
  This inline creator renders the plus placeholders used inside the drawn filter tree.
  Clicking the plus opens a text field; Enter or the blue check button emits the parent-aware creation.
-->
<script setup lang="ts">
import { CheckIcon, PlusIcon } from "@heroicons/vue/24/outline"

const props = defineProps<{
  label: string
  disabled?: boolean
}>()

const emit = defineEmits<{
  create: [name: string]
}>()

const opened = ref(false)
const name = ref("")
const input = ref<HTMLInputElement | null>(null)

const open = async () => {
  if (props.disabled) return

  opened.value = true
  await nextTick()
  input.value?.focus()
}

const close = () => {
  opened.value = false
  name.value = ""
}

const submit = () => {
  const normalizedName = name.value.trim()

  if (normalizedName === "" || props.disabled) return

  emit("create", normalizedName)
  close()
}
</script>

<template>
  <form
    v-if="opened"
    class="primary-background primary-border flex min-h-20 items-center gap-2 rounded-sm border-2 border-dashed p-2"
    @submit.prevent="submit"
    @keydown.esc.prevent="close"
  >
    <label class="min-w-0 flex-1">
      <span class="sr-only">{{ label }}</span>
      <input
        ref="input"
        v-model="name"
        type="text"
        maxlength="255"
        :placeholder="label"
        class="form-control h-10 w-full rounded-md border px-3 text-sm focus:outline-none focus:ring-2"
      />
    </label>
    <button
      type="submit"
      class="button-primary flex size-10 shrink-0 items-center justify-center rounded-md focus:outline-none focus:ring-2"
      :aria-label="`Valider ${label}`"
      title="Valider"
    >
      <CheckIcon class="size-6" aria-hidden="true" />
    </button>
  </form>

  <button
    v-else
    type="button"
    class="primary-border flex min-h-20 w-full items-center justify-center rounded-sm border-2 border-dashed transition focus:outline-none focus:ring-2 disabled:cursor-not-allowed"
    :disabled="disabled"
    :aria-label="label"
    :title="label"
    @click="open"
  >
    <PlusIcon class="size-9" aria-hidden="true" />
  </button>
</template>
