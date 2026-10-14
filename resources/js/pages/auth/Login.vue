<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import {
    ArrowBigUp,
    ChevronDown,
    LockKeyhole,
    Mail,
    ShieldCheck,
} from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { redirect as ssoRedirect } from '@/routes/sso';

defineOptions({
    layout: {
        title: 'Log in to FDCP Calendar',
        description: 'Use your FDCP account to continue.',
    },
});

const props = defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();

const page = usePage();
const ssoError = computed(() => page.props.errors?.sso);

// Email sign-in is for administrators only, so it stays folded away unless
// asked for, or it is the form that just failed or reset a password.
const adminOpen = ref(
    Boolean(
        page.props.errors?.email || page.props.errors?.password || props.status,
    ),
);

async function toggleAdmin() {
    adminOpen.value = !adminOpen.value;

    if (adminOpen.value) {
        await nextTick();
        document.getElementById('email')?.focus();
    }
}

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

    <p
        v-if="ssoError"
        role="alert"
        class="mb-4 rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2.5 text-sm text-destructive"
    >
        {{ ssoError }}
    </p>

    <Button class="h-11 w-full text-[15px]" as-child>
        <a :href="ssoRedirect().url" data-test="sso-button">
            <ShieldCheck class="size-[18px]" />
            Sign in with FDCP SSO
        </a>
    </Button>
    <p class="mt-2.5 text-center text-xs text-muted-foreground">
        One FDCP account for every FDCP system.
    </p>

    <div class="relative mt-8 mb-3">
        <div class="absolute inset-x-0 top-1/2 h-px bg-border" />
        <div class="relative flex justify-center text-xs uppercase">
            <span class="bg-card px-2 text-muted-foreground">
                Administrators
            </span>
        </div>
    </div>

    <button
        type="button"
        class="mx-auto flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        :aria-expanded="adminOpen"
        aria-controls="admin-login"
        @click="toggleAdmin"
    >
        <Mail class="size-4" />
        Sign in with email
        <ChevronDown
            class="size-4 transition-transform duration-200 motion-reduce:transition-none"
            :class="{ 'rotate-180': adminOpen }"
        />
    </button>

    <!-- Folded with a grid-rows transition; inert keeps the hidden fields
         out of the tab order and away from screen readers. -->
    <div
        id="admin-login"
        class="grid transition-[grid-template-rows] duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] motion-reduce:transition-none"
        :class="adminOpen ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
        :inert="!adminOpen"
    >
        <div class="overflow-hidden">
            <Form
                v-bind="store.form()"
                :reset-on-success="['password']"
                v-slot="{ errors, processing }"
                class="flex flex-col gap-6 px-px pt-4 pb-px"
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
                            >
                                Forgot your password?
                            </TextLink>
                        </div>
                        <div class="group relative">
                            <PasswordInput
                                id="password"
                                name="password"
                                required
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
                        <Label
                            for="remember"
                            class="flex items-center space-x-3"
                        >
                            <Checkbox id="remember" name="remember" />
                            <span>Remember me</span>
                        </Label>
                    </div>

                    <Button
                        type="submit"
                        variant="outline"
                        class="w-full"
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
                    <TextLink :href="register()">Sign up</TextLink>
                </div>
            </Form>
        </div>
    </div>
</template>
