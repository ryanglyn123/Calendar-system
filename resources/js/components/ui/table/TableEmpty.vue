<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        class?: HTMLAttributes['class'];
        colspan?: number;
    }>(),
    { colspan: 1 },
);

const delegatedProps = computed(() => {
    const { class: _, ...rest } = props;
    return rest;
});
</script>

<template>
    <tr data-slot="table-empty">
        <td
            :colspan="delegatedProps.colspan"
            :class="
                cn(
                    'p-8 text-center text-sm text-muted-foreground',
                    props.class,
                )
            "
        >
            <slot />
        </td>
    </tr>
</template>
