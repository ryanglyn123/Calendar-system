import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import {
    Activity,
    Building2,
    CalendarClock,
    CalendarOff,
    CalendarRange,
    CarFront,
    MailPlus,
    Presentation,
    ScrollText,
    Settings2,
    ShieldCheck,
    Tags,
    UserCheck,
    Users,
} from '@lucide/vue';
import adminAudit from '@/routes/admin/audit';
import adminCalendars from '@/routes/admin/calendars';
import adminDivisions from '@/routes/admin/divisions';
import adminEventTypes from '@/routes/admin/event-types';
import adminHealth from '@/routes/admin/health';
import adminHolidays from '@/routes/admin/holidays';
import adminInvitations from '@/routes/admin/invitations';
import adminPending from '@/routes/admin/pending-registrations';
import adminResourceTypes from '@/routes/admin/resource-types';
import adminResources from '@/routes/admin/resources';
import adminRoles from '@/routes/admin/roles';
import adminSettings from '@/routes/admin/settings';
import adminUsers from '@/routes/admin/users';
import adminWorkingHours from '@/routes/admin/working-hours';

export type AdminSettingsLink = {
    title: string;
    description: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon: LucideIcon;
    permission: string;
};

export type AdminSettingsSection = {
    title: string;
    links: AdminSettingsLink[];
};

/** One source for the settings hub cards AND the settings sidebar. */
export const ADMIN_SETTINGS_SECTIONS: AdminSettingsSection[] = [
    {
        title: 'User Management',
        links: [
            {
                title: 'Users',
                description: 'Accounts, roles, and divisions.',
                href: adminUsers.index(),
                icon: Users,
                permission: 'users.manage',
            },
            {
                title: 'Pending Registrations',
                description: 'Sign-ups waiting for approval.',
                href: adminPending.index(),
                icon: UserCheck,
                permission: 'users.approve',
            },
            {
                title: 'Invitations',
                description: 'Invite staff by email.',
                href: adminInvitations.index(),
                icon: MailPlus,
                permission: 'users.manage',
            },
        ],
    },
    {
        title: 'Scheduling',
        links: [
            {
                title: 'Calendars',
                description: 'Managed calendars and their divisions.',
                href: adminCalendars.index(),
                icon: CalendarRange,
                permission: 'calendars.manage',
            },
            {
                title: 'Event Types',
                description: 'Categories and colors for events.',
                href: adminEventTypes.index(),
                icon: Tags,
                permission: 'event-types.manage',
            },
            {
                title: 'Holidays',
                description: 'Non-working days that block bookings.',
                href: adminHolidays.index(),
                icon: CalendarOff,
                permission: 'holidays.manage',
            },
            {
                title: 'Working Hours',
                description: 'Bookable hours for each weekday.',
                href: adminWorkingHours.index(),
                icon: CalendarClock,
                permission: 'working-hours.manage',
            },
        ],
    },
    {
        title: 'Resources',
        links: [
            {
                title: 'Resource Types',
                description: 'Categories of bookable resources.',
                href: adminResourceTypes.index(),
                icon: CarFront,
                permission: 'resource-types.manage',
            },
            {
                title: 'Resources',
                description: 'Rooms, vehicles, and equipment.',
                href: adminResources.index(),
                icon: Presentation,
                permission: 'resources.manage',
            },
        ],
    },
    {
        title: 'Organization & Access',
        links: [
            {
                title: 'Divisions',
                description: 'Departments and office units.',
                href: adminDivisions.index(),
                icon: Building2,
                permission: 'divisions.manage',
            },
            {
                title: 'Roles & Permissions',
                description: 'What each role is allowed to do.',
                href: adminRoles.index(),
                icon: ShieldCheck,
                permission: 'roles.manage',
            },
        ],
    },
    {
        title: 'System',
        links: [
            {
                title: 'General',
                description: 'Organization details and registration.',
                href: adminSettings.edit(),
                icon: Settings2,
                permission: 'settings.manage',
            },
            {
                title: 'System Health',
                description: 'Queues, mail, and background jobs.',
                href: adminHealth.index(),
                icon: Activity,
                permission: 'settings.manage',
            },
            {
                title: 'Audit Log',
                description: 'Who changed what, and when.',
                href: adminAudit.index(),
                icon: ScrollText,
                permission: 'audit.view',
            },
        ],
    },
];
