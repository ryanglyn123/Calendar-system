<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/invitation';

defineOptions({
    layout: {
        title: 'Accept your invitation',
        description: 'Set up your account to join the FDCP Calendar',
    },
});

const props = defineProps<{
    token: string;
    email: string;
    inviter: string;
}>();
</script>

<template>
    <Head title="Accept invitation" />

    <div
        class="mb-6 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground"
    >
        <span class="font-medium text-foreground">{{ inviter }}</span>
        invited
        <span class="font-medium text-foreground">{{ email }}</span>
        to the FDCP Calendar.
    </div>

    <Form
        v-bind="store.form(props.token)"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="name">Full name</Label>
                <Input
                    id="name"
                    type="text"
                    name="name"
                    required
                    autofocus
                    autocomplete="name"
                    placeholder="Juan dela Cruz"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="new-password"
                    placeholder="Password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                    placeholder="Confirm password"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button type="submit" class="mt-2 w-full" :disabled="processing">
                <Spinner v-if="processing" />
                Create account
            </Button>
        </div>
    </Form>
</template>
