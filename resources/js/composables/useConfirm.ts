import { reactive, readonly } from 'vue';

type ConfirmOptions = {
    title: string;
    description?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    destructive?: boolean;
};

type ConfirmState = ConfirmOptions & {
    open: boolean;
    resolve: ((confirmed: boolean) => void) | null;
};

const state = reactive<ConfirmState>({
    open: false,
    title: '',
    description: undefined,
    confirmLabel: 'Confirm',
    cancelLabel: 'Cancel',
    destructive: false,
    resolve: null,
});

/**
 * Promise-based confirmation — the branded replacement for native confirm().
 * `<ConfirmDialog />` must be mounted once (AppShell does it).
 */
export function useConfirm() {
    const confirm = (options: ConfirmOptions): Promise<boolean> => {
        state.title = options.title;
        state.description = options.description;
        state.confirmLabel = options.confirmLabel ?? 'Confirm';
        state.cancelLabel = options.cancelLabel ?? 'Cancel';
        state.destructive = options.destructive ?? false;
        state.open = true;

        return new Promise<boolean>((resolve) => {
            state.resolve = resolve;
        });
    };

    const settle = (confirmed: boolean): void => {
        state.open = false;
        state.resolve?.(confirmed);
        state.resolve = null;
    };

    return { confirm, settle, state: readonly(state) };
}
