<!--
  DatedFilter renders a reusable dropdown for selecting one ranking and one rolling period.
  FilterBar supplies options and current values, then receives update events and translates the selection
  into frontend route parameters; this component has no router, API or application-state dependency.
-->
<script setup lang="ts">
type DatedFilterOption = {
  id: string
  label: string
  disabled?: boolean
  disabledHint?: string
}

type DatedFilterSelection = {
  order: string
  period: string
}

const props = withDefaults(defineProps<{
  label?: string
  order: string
  period: string
  orderOptions: readonly DatedFilterOption[]
  periodOptions: readonly DatedFilterOption[]
}>(), {
  label: "Populaire",
})

const emit = defineEmits<{
  "update:order": [value: string]
  "update:period": [value: string]
  change: [selection: DatedFilterSelection]
}>()

const isOpen = ref(false)
const menuId = useId()
const orderName = `${menuId}-order`
const periodName = `${menuId}-period`

function emitSelection(order: string, period: string): void {
  emit("change", { order, period })
}

function selectOrder(order: string): void {
  emit("update:order", order)
  emitSelection(order, props.period)
}

function selectPeriod(period: string): void {
  emit("update:period", period)
  emitSelection(props.order, period)
}

function toggleMenu(): void {
  isOpen.value = !isOpen.value

  if (!isOpen.value || (props.order !== "" && props.period !== "")) return

  const order = props.orderOptions.find((option) => !option.disabled)?.id || ""
  const period = props.periodOptions.find((option) => !option.disabled)?.id || ""

  emit("update:order", order)
  emit("update:period", period)
  emitSelection(order, period)
}
</script>

<template>
  <div class="relative">
    <button
      type="button"
      class="button-primary relative z-30 inline-flex min-h-11 items-center gap-2 px-5 py-2 text-sm font-medium focus:outline-none"
      :class="isOpen ? 'rounded-t-3xl' : 'rounded-full'"
      :aria-controls="menuId"
      :aria-expanded="isOpen"
      @click="toggleMenu"
    >
      {{ label }}
      <slot name="icon" />
    </button>

    <div
      v-if="isOpen"
      :id="menuId"
      class="absolute left-0 top-full z-20 grid w-[min(28rem,calc(100vw-3rem))] gap-6 rounded-bl-3xl rounded-br-3xl rounded-tr-3xl bg-(--button-primary-background) px-4 py-5 text-(--button-primary-color) shadow-xl sm:grid-cols-2"
    >
      <fieldset class="min-w-0">
        <legend class="mb-2 text-base font-medium">Ordre de tri</legend>
        <label
          v-for="option in orderOptions"
          :key="option.id"
          class="flex items-center gap-1 text-sm"
          :class="option.disabled ? 'cursor-not-allowed line-through opacity-70' : 'cursor-pointer'"
          :title="option.disabled ? option.disabledHint : undefined"
        >
          <input
            type="radio"
            :name="orderName"
            :value="option.id"
            :checked="order === option.id"
            :disabled="option.disabled"
            class="size-5 shrink-0 accent-(--accent-color)"
            @change="selectOrder(option.id)"
          >
          <span>{{ option.label }}</span>
        </label>
      </fieldset>

      <fieldset class="min-w-0">
        <legend class="mb-2 text-base font-medium">Période de temps</legend>
        <label
          v-for="option in periodOptions"
          :key="option.id"
          class="flex cursor-pointer items-center gap-1 text-sm"
        >
          <input
            type="radio"
            :name="periodName"
            :value="option.id"
            :checked="period === option.id"
            :disabled="option.disabled"
            class="size-5 shrink-0 accent-(--accent-color)"
            @change="selectPeriod(option.id)"
          >
          <span>{{ option.label }}</span>
        </label>
      </fieldset>
    </div>
  </div>
</template>
