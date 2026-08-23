<!--
  This shared component displays user avatars in Header, PageDisplay and the profile view.
  It delegates URL resolution to useProfilePicture, then shows an accessible icon or initial
  when the user is anonymous, has no picture, or the browser cannot load the resolved image.
-->
<script setup lang="ts">
import { UserCircleIcon } from "@heroicons/vue/24/solid"
import type { ProfilePictureSource } from "~/composables/minio/useProfilePicture"

type AvatarSize = "sm" | "md" | "xl"
type AvatarFallback = "icon" | "initial"

const props = withDefaults(defineProps<{
  picture?: string | null
  username?: string | null
  pageId?: number | null
  source?: ProfilePictureSource
  anonymous?: boolean
  size?: AvatarSize
  fallback?: AvatarFallback
  initialLength?: 1 | 2
  label?: string
}>(), {
  picture: null,
  username: null,
  pageId: null,
  source: "current-user",
  anonymous: false,
  size: "md",
  fallback: "initial",
  initialLength: 1,
  label: "",
})

const sizeClasses: Record<AvatarSize, {
  container: string
  image: string
  fallback: string
  icon: string
}> = {
  sm: {
    container: "size-14 md:size-16",
    image: "primary-border size-full border object-cover",
    fallback: "size-full text-base font-bold",
    icon: "size-full",
  },
  md: {
    container: "h-16 w-16",
    image: "size-full object-cover",
    fallback: "size-full bg-(--primary-color) text-base font-bold text-(--primary-background)",
    icon: "size-full",
  },
  xl: {
    container: "primary-background primary-border size-40 border",
    image: "size-full object-cover",
    fallback: "size-full text-4xl font-semibold",
    icon: "size-full",
  },
}

const classes = computed(() => sizeClasses[props.size])
const accessibleName = computed(() => props.label || (props.anonymous ? "Anonyme" : props.username?.trim() || "Anonyme"))
const initial = computed(() => {
  const name = props.anonymous ? "Anonyme" : props.username?.trim() || "Anonyme"

  return name.slice(0, props.initialLength).toUpperCase()
})

const { pictureUrl, useFallback } = useProfilePicture({
  picture: toRef(props, "picture"),
  source: toRef(props, "source"),
  pageId: toRef(props, "pageId"),
  anonymous: toRef(props, "anonymous"),
})
</script>

<template>
  <span
    :title="accessibleName"
    class="inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full"
    :class="classes.container"
  >
    <img
      v-if="pictureUrl"
      :src="pictureUrl"
      alt=""
      :class="classes.image"
      @error="useFallback"
    />
    <UserCircleIcon
      v-else-if="fallback === 'icon'"
      :class="classes.icon"
      aria-hidden="true"
    />
    <span
      v-else
      class="flex items-center justify-center"
      :class="classes.fallback"
      aria-hidden="true"
    >
      {{ initial }}
    </span>
    <span class="sr-only">{{ accessibleName }}</span>
  </span>
</template>
