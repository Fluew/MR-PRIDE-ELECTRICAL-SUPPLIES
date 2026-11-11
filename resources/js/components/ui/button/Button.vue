<script setup lang="ts">
import type { PrimitiveProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import type { ButtonVariants } from "."
import { Primitive } from "reka-ui"
import { cn } from "@/lib/utils"
import { buttonVariants } from "."
import SpecularButton from "@/components/ui/SpecularButton.vue"

interface Props {
  as?: PrimitiveProps["as"]
  asChild?: boolean
  variant?: ButtonVariants["variant"] | any
  size?: ButtonVariants["size"] | any
  class?: HTMLAttributes["class"]
  specular?: boolean
  disabled?: boolean
  type?: "button" | "submit" | "reset"
  href?: string | any
  radius?: number
}

const props = withDefaults(defineProps<Props>(), {
  as: "button",
  specular: true,
})
</script>

<template>
  <SpecularButton
    v-if="specular && !asChild && variant !== 'link' && variant !== 'ghost'"
    v-bind="$attrs"
    :as="as"
    :type="type"
    :href="href"
    :disabled="disabled"
    :variant="variant"
    :size="size"
    :radius="radius"
    :class="cn(buttonVariants({ variant, size }), props.class)"
  >
    <slot />
  </SpecularButton>
  <Primitive
    v-else
    v-bind="$attrs"
    data-slot="button"
    :data-variant="variant"
    :data-size="size"
    :as="as"
    :as-child="asChild"
    :disabled="disabled"
    :type="type"
    :class="cn(buttonVariants({ variant, size }), props.class)"
  >
    <slot />
  </Primitive>
</template>
