import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { User } from '@/types';

export function useAuth() {
    const page = usePage();

    const user = computed(() => page.props.auth.user as User);

    const hasPermission = (permission: string): boolean => {
        const current = user.value;

        return current.is_super_admin || current.permissions.includes(permission);
    };

    const hasAnyPermission = (...permissions: string[]): boolean =>
        permissions.some((permission) => hasPermission(permission));

    return { user, hasPermission, hasAnyPermission };
}
