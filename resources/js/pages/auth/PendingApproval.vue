<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Clock, LogOut, ShieldAlert } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';

defineOptions({
    layout: {
        title: 'Account status',
        description: '',
    },
});

const props = defineProps<{
    status: 'pending' | 'suspended';
}>();

const signOut = () => router.post(logout());

const copy =
    props.status === 'suspended'
        ? {
              icon: ShieldAlert,
              title: 'Your account is suspended',
              body: 'An administrator has suspended this account. Contact the MIS division if you believe this is a mistake.',
          }
        : {
              icon: Clock,
              title: 'Awaiting approval',
              body: 'Thanks for registering! An administrator needs to approve your account and assign your division before you can use the calendar. You will receive an email once approved.',
          };
</script>

<template>
    <Head title="Account status" />

    <div class="flex flex-col items-center gap-6 text-center">
        <div
            class="flex size-16 items-center justify-center rounded-full"
            :class="
                status === 'suspended'
                    ? 'bg-destructive/10 text-destructive'
                    : 'bg-gradient-gold text-white'
            "
        >
            <component :is="copy.icon" class="size-8" aria-hidden="true" />
        </div>

        <div class="space-y-2">
            <h1 class="text-xl font-semibold">{{ copy.title }}</h1>
            <p class="max-w-sm text-sm text-muted-foreground">
                {{ copy.body }}
            </p>
        </div>

        <Button variant="outline" @click="signOut">
            <LogOut aria-hidden="true" />
            Sign out
        </Button>
    </div>
</template>
