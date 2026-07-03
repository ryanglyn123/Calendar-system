<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { format, parseISO } from '@/lib/calendarMath';
import admin from '@/routes/admin';
import adminHolidays from '@/routes/admin/holidays';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Holidays', href: adminHolidays.index() },
        ],
    },
});

type HolidayRow = {
    id: number;
    name: string;
    date: string;
    recurs_annually: boolean;
    type: 'regular' | 'special_non_working' | 'company';
    blocks_bookings: boolean;
};

defineProps<{
    holidays: HolidayRow[];
}>();

const typeLabels: Record<HolidayRow['type'], string> = {
    regular: 'Regular holiday',
    special_non_working: 'Special non-working',
    company: 'FDCP holiday',
};

const editing = ref<HolidayRow | null>(null);
const creating = ref(false);

const form = useForm({
    name: '',
    date: '',
    recurs_annually: true,
    type: 'regular' as HolidayRow['type'],
    blocks_bookings: true,
});

const openCreate = () => {
    form.reset();
    form.clearErrors();
    creating.value = true;
};

const openEdit = (holiday: HolidayRow) => {
    form.clearErrors();
    form.name = holiday.name;
    form.date = holiday.date;
    form.recurs_annually = holiday.recurs_annually;
    form.type = holiday.type;
    form.blocks_bookings = holiday.blocks_bookings;
    editing.value = holiday;
};

const close = () => {
    creating.value = false;
    editing.value = null;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: close };

    if (editing.value) {
        form.patch(adminHolidays.update(editing.value.id).url, options);
    } else {
        form.post(adminHolidays.store().url, options);
    }
};

const destroy = (holiday: HolidayRow) =>
    router.delete(adminHolidays.destroy(holiday.id).url, {
        preserveScroll: true,
    });
</script>

<template>
    <Head title="Holidays" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <p class="text-sm text-muted-foreground">
                Holidays can block bookings on resource types that opt in.
            </p>
            <Button @click="openCreate">
                <Plus aria-hidden="true" />
                Add holiday
            </Button>
        </div>

        <div class="rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Holiday</TableHead>
                        <TableHead>Date</TableHead>
                        <TableHead>Kind</TableHead>
                        <TableHead>Blocks bookings</TableHead>
                        <TableHead class="w-24 text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty v-if="holidays.length === 0" :colspan="5">
                        No holidays configured.
                    </TableEmpty>
                    <TableRow v-for="holiday in holidays" :key="holiday.id">
                        <TableCell class="font-medium">
                            {{ holiday.name }}
                        </TableCell>
                        <TableCell>
                            {{
                                format(
                                    parseISO(`${holiday.date}T00:00:00`),
                                    'MMMM d, yyyy',
                                )
                            }}
                            <Badge
                                v-if="holiday.recurs_annually"
                                variant="outline"
                                class="ml-1.5"
                            >
                                yearly
                            </Badge>
                        </TableCell>
                        <TableCell class="text-muted-foreground">
                            {{ typeLabels[holiday.type] }}
                        </TableCell>
                        <TableCell>
                            {{ holiday.blocks_bookings ? 'Yes' : 'No' }}
                        </TableCell>
                        <TableCell class="text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Edit ${holiday.name}`"
                                    @click="openEdit(holiday)"
                                >
                                    <Pencil aria-hidden="true" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="text-destructive hover:text-destructive"
                                    :aria-label="`Delete ${holiday.name}`"
                                    @click="destroy(holiday)"
                                >
                                    <Trash2 aria-hidden="true" />
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <Dialog
            :open="creating || editing !== null"
            @update:open="(open) => !open && close()"
        >
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {{ editing ? `Edit ${editing.name}` : 'Add holiday' }}
                    </DialogTitle>
                    <DialogDescription>
                        Yearly holidays repeat automatically on the same date.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="holiday-name">Name</Label>
                        <Input id="holiday-name" v-model="form.name" />
                        <p
                            v-if="form.errors.name"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-2">
                            <Label for="holiday-date">Date</Label>
                            <Input
                                id="holiday-date"
                                v-model="form.date"
                                type="date"
                            />
                            <p
                                v-if="form.errors.date"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.date }}
                            </p>
                        </div>
                        <div class="grid gap-2">
                            <Label for="holiday-type">Kind</Label>
                            <Select v-model="form.type">
                                <SelectTrigger id="holiday-type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="regular">
                                        Regular holiday
                                    </SelectItem>
                                    <SelectItem value="special_non_working">
                                        Special non-working
                                    </SelectItem>
                                    <SelectItem value="company">
                                        FDCP holiday
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <Label class="flex items-center gap-3 font-normal">
                        <Switch v-model="form.recurs_annually" />
                        Repeats every year
                    </Label>
                    <Label class="flex items-center gap-3 font-normal">
                        <Switch v-model="form.blocks_bookings" />
                        Blocks bookings
                    </Label>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="close">Cancel</Button>
                    <Button :disabled="form.processing" @click="submit">
                        {{ editing ? 'Save changes' : 'Add holiday' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
