<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="auth-backdrop flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10"
    >
        <div class="w-full max-w-[26rem]">
            <div
                class="flex flex-col gap-8 rounded-2xl border bg-card p-8 shadow-xs sm:p-10"
            >
                <div class="flex flex-col items-center gap-5">
                    <Link
                        :href="home()"
                        class="flex flex-col items-center font-medium"
                        aria-label="FDCP Calendar home"
                    >
                        <!-- The mark sits on its own light tile so it reads in
                             both themes without inverting the artwork. -->
                        <div
                            class="flex size-14 items-center justify-center rounded-2xl border bg-white shadow-xs"
                        >
                            <AppLogoIcon class="size-9" />
                        </div>
                    </Link>
                    <div class="space-y-1.5 text-center">
                        <h1
                            class="text-2xl font-semibold tracking-tight text-balance"
                        >
                            {{ title }}
                        </h1>
                        <p
                            v-if="description"
                            class="text-sm text-muted-foreground"
                        >
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
