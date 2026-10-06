<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight } from '@lucide/vue';
import { computed } from 'vue';
import { format, parseISO } from '@/lib/calendarMath';
import { relative } from '@/lib/eventDisplay';
import adminAnnouncements from '@/routes/admin/announcements';
import adminAudit from '@/routes/admin/audit';
import adminInvitations from '@/routes/admin/invitations';
import adminPending from '@/routes/admin/pending-registrations';
import adminUsers from '@/routes/admin/users';
import type { DeskActivity, DeskAnnouncements, DeskPeople } from '@/types';

const props = defineProps<{
    people: DeskPeople | null;
    announcements: DeskAnnouncements | null;
    activity: DeskActivity[] | null;
    now: Date;
    canInvite: boolean;
}>();

type Line = {
    label: string;
    value: number;
    href: string;
    /** Non-zero means someone should act. */
    needsAction?: boolean;
    detail?: string;
};

const peopleLines = computed<Line[]>(() => {
    const people = props.people;

    if (!people) {
        return [];
    }

    const lines: Line[] = [
        {
            label: 'Sign-ups waiting',
            value: people.pending,
            href: adminPending.index().url,
            needsAction: true,
        },
        {
            label: 'Active without a division',
            value: people.without_division,
            href: adminUsers.index({ query: { status: 'active' } }).url,
            needsAction: true,
        },
    ];

    if (props.canInvite) {
        lines.push({
            label: 'Invitations open',
            value: people.invitations_open,
            href: adminInvitations.index().url,
            needsAction: people.invitations_expiring > 0,
            detail:
                people.invitations_expiring > 0
                    ? `${people.invitations_expiring} expire within 3 days`
                    : undefined,
        });
    }

    lines.push(
        {
            label: 'Suspended',
            value: people.suspended,
            href: adminUsers.index({ query: { status: 'suspended' } }).url,
        },
        {
            label: 'Active accounts',
            value: people.active,
            href: adminUsers.index().url,
        },
    );

    // Zero counts are not news; the total always stays as a reference.
    return lines.filter(
        (line) => line.value > 0 || line.label === 'Active accounts',
    );
});

const LEVEL: Record<DeskAnnouncements['live'][number]['level'], string> = {
    info: 'bg-chart-2',
    warning: 'bg-warning',
    critical: 'bg-destructive',
};

const LEVEL_LABEL: Record<DeskAnnouncements['live'][number]['level'], string> =
    {
        info: 'Info',
        warning: 'Warning',
        critical: 'Critical',
    };

/** Model logs say only "created"; service logs already read as sentences. */
const sentence = (entry: DeskActivity) => {
    const bare = ['created', 'updated', 'deleted', 'restored'];

    if (bare.includes(entry.description) && entry.subject_type) {
        return `${entry.description} ${entry.subject_type.replace(/([a-z])([A-Z])/g, '$1 $2').toLowerCase()}`;
    }

    return (
        entry.description.charAt(0).toLowerCase() + entry.description.slice(1)
    );
};

const columns = computed(
    () =>
        [props.people, props.announcements, props.activity].filter(
            (part) => part !== null,
        ).length,
);

const noteLink =
    'inline-flex items-center gap-1 rounded-sm text-[13px] text-muted-foreground underline-offset-4 outline-none hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring/60';
</script>

<template>
    <div
        v-if="columns > 0"
        class="grid grid-cols-1 border-t border-border lg:divide-x lg:divide-border"
        :class="{
            'lg:grid-cols-2': columns === 2,
            'lg:grid-cols-3': columns === 3,
        }"
    >
        <!-- People -->
        <section
            v-if="people"
            class="py-4 lg:pr-6"
            aria-labelledby="desk-people"
        >
            <div class="mb-2 flex items-center justify-between gap-3">
                <h2 id="desk-people" class="text-[15px] font-semibold">
                    People
                </h2>
                <Link
                    v-if="canInvite"
                    :href="adminInvitations.index()"
                    :class="noteLink"
                >
                    Invite someone
                    <ArrowUpRight class="size-3.5" aria-hidden="true" />
                </Link>
            </div>
            <ul class="divide-y divide-border">
                <li v-for="line in peopleLines" :key="line.label">
                    <Link
                        :href="line.href"
                        class="-mx-2 flex items-baseline justify-between gap-3 rounded-md px-2 py-2 text-[13px] outline-none hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring/60"
                    >
                        <span>
                            <span
                                :class="
                                    line.needsAction && line.value > 0
                                        ? 'text-foreground'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ line.label }}
                            </span>
                            <span
                                v-if="line.detail"
                                class="block text-xs text-warning-ink"
                            >
                                {{ line.detail }}
                            </span>
                        </span>
                        <span
                            class="tabular-nums"
                            :class="
                                line.needsAction && line.value > 0
                                    ? 'font-semibold text-foreground'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ line.value }}
                        </span>
                    </Link>
                </li>
            </ul>
        </section>

        <!-- Announcements -->
        <section
            v-if="announcements"
            class="border-t border-border py-4 lg:border-t-0 lg:px-6"
            aria-labelledby="desk-announcements"
        >
            <div class="mb-2 flex items-center justify-between gap-3">
                <h2 id="desk-announcements" class="text-[15px] font-semibold">
                    On everyone's calendar
                </h2>
                <Link :href="adminAnnouncements.index()" :class="noteLink">
                    {{ announcements.live.length ? 'Manage' : 'Post one' }}
                    <ArrowUpRight class="size-3.5" aria-hidden="true" />
                </Link>
            </div>

            <p
                v-if="announcements.live.length === 0"
                class="text-[13px] text-muted-foreground"
            >
                No announcement is showing right now. Post one when a notice has
                to reach every calendar, like a closure or a room out of
                service.
            </p>

            <ul v-else class="divide-y divide-border">
                <li
                    v-for="announcement in announcements.live"
                    :key="announcement.id"
                    class="flex items-start gap-2.5 py-2 text-[13px]"
                >
                    <span
                        class="mt-1.5 size-2 shrink-0 rounded-full"
                        :class="LEVEL[announcement.level]"
                        aria-hidden="true"
                    />
                    <span class="min-w-0">
                        <span class="block text-foreground">
                            {{ announcement.title }}
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            {{ LEVEL_LABEL[announcement.level] }} ·
                            {{ announcement.audience ?? 'Everyone' }}
                            <template v-if="announcement.ends_at">
                                · until
                                {{
                                    format(
                                        parseISO(announcement.ends_at),
                                        'MMM d, HH:mm',
                                    )
                                }}
                            </template>
                        </span>
                    </span>
                </li>
            </ul>

            <p
                v-if="announcements.scheduled > 0"
                class="mt-2 text-xs text-muted-foreground"
            >
                {{ announcements.scheduled }} more scheduled to start later.
            </p>
        </section>

        <!-- Activity -->
        <section
            v-if="activity"
            class="border-t border-border py-4 lg:border-t-0 lg:pl-6"
            aria-labelledby="desk-activity"
        >
            <div class="mb-2 flex items-center justify-between gap-3">
                <h2 id="desk-activity" class="text-[15px] font-semibold">
                    Recent activity
                </h2>
                <Link :href="adminAudit.index()" :class="noteLink">
                    Audit log
                    <ArrowUpRight class="size-3.5" aria-hidden="true" />
                </Link>
            </div>

            <p
                v-if="activity.length === 0"
                class="text-[13px] text-muted-foreground"
            >
                Approvals, account changes and setting updates are recorded here
                as they happen.
            </p>

            <ol v-else class="divide-y divide-border">
                <li
                    v-for="entry in activity.slice(0, 5)"
                    :key="entry.id"
                    class="flex items-baseline justify-between gap-3 py-2 text-[13px]"
                >
                    <span class="min-w-0 truncate">
                        <span class="text-foreground">{{
                            entry.causer ?? 'System'
                        }}</span>
                        <span class="text-muted-foreground">{{
                            ` ${sentence(entry)}`
                        }}</span>
                    </span>
                    <time
                        :datetime="entry.created_at"
                        class="shrink-0 text-xs text-muted-foreground tabular-nums"
                    >
                        {{
                            relative(
                                new Date(entry.created_at).getTime(),
                                now.getTime(),
                            )
                        }}
                    </time>
                </li>
            </ol>
        </section>
    </div>
</template>
