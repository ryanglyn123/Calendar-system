<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"
import { Button } from '@/components/ui/button'
import { computed, onMounted, ref } from "vue"
import { useSidebar } from "./utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()

const { isMobile, state, toggleSidebar } = useSidebar()

// Same rule as Sidebar.vue: the server renders the desktop icon, so the
// mobile icon only appears after mount to keep hydration in step.
const hydrated = ref(false)
onMounted(() => {
  hydrated.value = true
})

// Mobile opens a sheet over the trigger, so there it is always the menu.
const expanded = computed(() => !(isMobile.value && hydrated.value) && state.value === 'expanded')

// Hamburger when collapsed; open, it folds into a chevron "<" (Lucide's
// ChevronLeft: tip at 9,12, arms to 15,6 and 15,18): "collapse this way".
// The outer bars pivot on their left ends into the arms; the middle bar
// shrinks into the tip.
const bar = 'origin-left transition-[translate,rotate,scale,opacity] duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] [transform-box:fill-box] motion-reduce:transition-none'
</script>

<template>
  <Button
    data-sidebar="trigger"
    data-slot="sidebar-trigger"
    variant="ghost"
    size="icon"
    :class="cn('h-7 w-7', props.class)"
    :aria-expanded="expanded"
    @click="toggleSidebar"
  >
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      stroke-linecap="round"
      aria-hidden="true"
    >
      <line x1="4" y1="6" x2="20" y2="6" :class="[bar, expanded && 'translate-x-[5px] translate-y-[6px] -rotate-45 scale-x-[0.53]']" />
      <line x1="4" y1="12" x2="20" y2="12" :class="[bar, expanded && 'translate-x-[5px] scale-x-0 opacity-0']" />
      <line x1="4" y1="18" x2="20" y2="18" :class="[bar, expanded && 'translate-x-[5px] -translate-y-[6px] rotate-45 scale-x-[0.53]']" />
    </svg>
    <span class="sr-only">Toggle sidebar</span>
  </Button>
</template>
