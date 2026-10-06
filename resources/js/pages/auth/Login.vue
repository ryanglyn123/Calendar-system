<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ArrowBigUp, LockKeyhole, Mail } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { redirect as googleRedirect } from '@/routes/google';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Log in to FDCP Calendar',
        description: 'Use your FDCP account to continue.',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();

const field =
    'h-11 pl-10 bg-muted/60 hover:border-foreground/25 focus-visible:bg-card transition-[color,box-shadow,background-color,border-color]';

// Staff all share one domain, so offer to finish the address.
const domain = '@fdcp.gov.ph';
const email = ref('');

const suffix = computed(() => {
    const v = email.value;

    if (!v || /\s/.test(v)) {
        return '';
    }

    const typed = v.slice(v.indexOf('@') === -1 ? v.length : v.indexOf('@'));

    return domain.startsWith(typed) ? domain.slice(typed.length) : '';
});

function completeEmail(e: KeyboardEvent) {
    const el = e.target as HTMLInputElement;
    const accept =
        (e.key === 'Tab' && !e.shiftKey) ||
        (e.key === 'ArrowRight' && el.selectionStart === el.value.length);

    if (suffix.value && accept) {
        e.preventDefault();
        email.value += suffix.value;
    }
}

const capsLock = ref(false);

function checkCaps(e: KeyboardEvent) {
    capsLock.value = e.getModifierState?.('CapsLock') ?? false;
}
</script>

<template>
    <Head title="Log in" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-success"
    >
        {{ status }}
    </div>

    <Button variant="outline" class="mb-2 w-full" as-child>
        <a :href="googleRedirect().url">
            <svg class="size-4" viewBox="0 0 24 24" aria-hidden="true">
                <path
                    fill="#4285F4"
                    d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.74 3.27-8.1Z"
                />
                <path
                    fill="#34A853"
                    d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23Z"
                />
                <path
                    fill="#FBBC05"
                    d="M5.84 14.1a6.6 6.6 0 0 1 0-4.2V7.06H2.18a11 11 0 0 0 0 9.88l3.66-2.84Z"
                />
                <path
                    fill="#EA4335"
                    d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15A11 11 0 0 0 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52Z"
                />
            </svg>
            Sign in with Google
        </a>
    </Button>

    <PasskeyVerify separator="" />

    <div class="relative my-6">
        <div class="absolute inset-x-0 top-1/2 h-px bg-border" />
        <div class="relative flex justify-center text-xs uppercase">
            <span class="bg-card px-2 text-muted-foreground">
                Or use your email
            </span>
        </div>
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <div class="group relative">
                    <Input
                        id="email"
                        v-model="email"
                        type="email"
                        name="email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="name@fdcp.gov.ph"
                        :class="field"
                        @keydown="completeEmail"
                    />
                    <Mail
                        class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground transition-colors group-focus-within:text-foreground"
                    />
                    <!-- Ghost completion: typed text is invisible, only the
                         missing domain shows, lined up after the caret. -->
                    <div
                        v-if="suffix"
                        class="pointer-events-none absolute inset-px flex items-center overflow-hidden pr-14 pl-10 text-base whitespace-pre opacity-0 group-focus-within:opacity-100 md:text-sm"
                        aria-hidden="true"
                    >
                        <span class="invisible">{{ email }}</span>
                        <span class="text-muted-foreground/70">{{
                            suffix
                        }}</span>
                    </div>
                    <kbd
                        v-if="suffix"
                        class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 rounded border bg-card px-1.5 py-0.5 text-[11px] leading-none font-medium text-muted-foreground opacity-0 group-focus-within:opacity-100"
                        aria-hidden="true"
                    >
                        Tab
                    </kbd>
                    <span class="sr-only" aria-live="polite">
                        {{ suffix ? `Press Tab to add ${domain}` : '' }}
                    </span>
                </div>
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Password</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-sm"
                        :tabindex="5"
                    >
                        Forgot your password?
                    </TextLink>
                </div>
                <div class="group relative">
                    <PasswordInput
                        id="password"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        :class="field"
                        @keydown="checkCaps"
                        @keyup="checkCaps"
                        @blur="capsLock = false"
                    />
                    <LockKeyhole
                        class="pointer-events-none absolute top-1/2 left-3.5 z-10 size-4 -translate-y-1/2 text-muted-foreground transition-colors group-focus-within:text-foreground"
                    />
                </div>
                <p
                    v-if="capsLock"
                    class="flex w-fit items-center gap-1.5 rounded-md bg-warning/15 px-2 py-1 text-xs font-medium"
                    role="status"
                >
                    <ArrowBigUp class="size-3.5 text-warning" />
                    Caps Lock is on
                </p>
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" :tabindex="3" />
                    <span>Remember me</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Log in
            </Button>
        </div>

        <div
            v-if="canRegister"
            class="text-center text-sm text-muted-foreground"
        >
            Don't have an account?
            <TextLink :href="register()" :tabindex="5">Sign up</TextLink>
        </div>
    </Form>
</template>
