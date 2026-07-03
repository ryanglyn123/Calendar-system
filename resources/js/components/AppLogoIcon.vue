<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import logoOnly from '@/../img/Logoonly.png';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <img
        :src="logoOnly"
        alt="FDCP logo"
        :class="className"
        v-bind="$attrs"
    />
</template>
