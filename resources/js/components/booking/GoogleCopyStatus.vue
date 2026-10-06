<script setup lang="ts">
import { ExternalLink } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { api, ApiError } from '@/lib/api';
import { restore } from '@/routes/api/bookings/google';

/**
 * Where the requester's Google Calendar copy of a booking stands. The
 * booking is always the truth: an edit or deletion made in Google is
 * shown here, and "put it back" re-copies the booking's own version.
 */
export type GoogleCopy = {
    state: 'synced' | 'edited_in_google' | 'removed_in_google' | 'failed';
    html_link: string | null;
};

const props = defineProps<{
    bookingId: number;
    copy: GoogleCopy;
    /** One short chip for lists. */
    compact?: boolean;
}>();

const SHORT: Record<GoogleCopy['state'], string> = {
    synced: 'In Google Calendar',
    edited_in_google: 'Edited in Google',
    removed_in_google: 'Not in Google',
    failed: 'Google sync failed',
};

const message = computed(
    () =>
        ({
            synced: 'This booking is in your Google Calendar.',
            edited_in_google:
                'This booking was changed in your Google Calendar. The booking itself still holds the time shown here.',
            removed_in_google:
                'This booking was removed from your Google Calendar. The booking itself is unchanged.',
            failed: 'This booking could not be added to your Google Calendar yet. It will be retried.',
        })[props.copy.state],
);

const working = ref(false);

const putBack = async () => {
    working.value = true;

    try {
        await api.post(restore(props.bookingId).url);
        toast.success('Updating your Google Calendar…');
    } catch (e) {
        toast.error(
            e instanceof ApiError ? e.message : 'Could not reach the server.',
        );
    } finally {
        working.value = false;
    }
};
</script>

<template>
    <span
        v-if="compact"
        class="shrink-0 rounded-full px-2 py-px text-[11px]"
        :class="
            copy.state === 'synced'
                ? 'bg-muted text-muted-foreground'
                : 'bg-destructive/10 text-destructive'
        "
    >
        {{ SHORT[copy.state] }}
    </span>

    <div
        v-else
        class="flex flex-col gap-2 rounded-2xl bg-muted/60 p-4 text-sm sm:flex-row sm:items-center sm:justify-between"
        role="status"
    >
        <p :class="copy.state === 'synced' ? '' : 'text-foreground'">
            {{ message }}
        </p>
        <div class="flex shrink-0 gap-2">
            <Button
                v-if="
                    copy.state === 'edited_in_google' ||
                    copy.state === 'removed_in_google'
                "
                size="sm"
                variant="outline"
                :disabled="working"
                @click="putBack"
            >
                {{
                    copy.state === 'removed_in_google'
                        ? 'Add it back'
                        : 'Restore the booking’s version'
                }}
            </Button>
            <Button
                v-if="copy.html_link && copy.state !== 'removed_in_google'"
                as-child
                size="sm"
                variant="ghost"
            >
                <a
                    :href="copy.html_link"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <ExternalLink aria-hidden="true" />
                    Open
                </a>
            </Button>
        </div>
    </div>
</template>
