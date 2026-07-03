<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useConfirm } from '@/composables/useConfirm';

const { state, settle } = useConfirm();
</script>

<template>
    <Dialog
        :open="state.open"
        @update:open="(open) => !open && settle(false)"
    >
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle>{{ state.title }}</DialogTitle>
                <DialogDescription v-if="state.description">
                    {{ state.description }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="settle(false)">
                    {{ state.cancelLabel }}
                </Button>
                <Button
                    :variant="state.destructive ? 'destructive' : 'default'"
                    @click="settle(true)"
                >
                    {{ state.confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
