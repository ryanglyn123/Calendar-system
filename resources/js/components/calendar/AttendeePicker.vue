<script setup lang="ts">
import { X } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { ref } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { api } from '@/lib/api';
import { search as usersSearch } from '@/routes/api/users';
import type { UserSearchResult } from '@/types/calendar';

const selected = defineModel<{ id: number; name: string }[]>({
    required: true,
});

const query = ref('');
const results = ref<UserSearchResult[]>([]);
const searching = ref(false);

const runSearch = useDebounceFn(async () => {
    if (query.value.trim().length < 2) {
        results.value = [];

        return;
    }

    searching.value = true;

    try {
        const response = await api.get<{ data: UserSearchResult[] }>(
            usersSearch({ query: { q: query.value.trim() } }).url,
        );

        results.value = response.data.filter(
            (user) => !selected.value.some((s) => s.id === user.id),
        );
    } finally {
        searching.value = false;
    }
}, 250);

const add = (user: UserSearchResult) => {
    selected.value = [...selected.value, { id: user.id, name: user.name }];
    query.value = '';
    results.value = [];
};

const remove = (id: number) => {
    selected.value = selected.value.filter((s) => s.id !== id);
};
</script>

<template>
    <div class="grid gap-2">
        <Label for="attendee-search">Guests</Label>

        <div v-if="selected.length > 0" class="flex flex-wrap gap-1.5">
            <span
                v-for="person in selected"
                :key="person.id"
                class="inline-flex items-center gap-1 rounded-full bg-muted py-0.5 pr-1 pl-2.5 text-xs"
            >
                {{ person.name }}
                <button
                    type="button"
                    class="rounded-full p-0.5 hover:bg-background"
                    :aria-label="`Remove ${person.name}`"
                    @click="remove(person.id)"
                >
                    <X class="size-3" aria-hidden="true" />
                </button>
            </span>
        </div>

        <div class="relative">
            <Input
                id="attendee-search"
                v-model="query"
                placeholder="Search people by name or email"
                autocomplete="off"
                @input="runSearch"
            />

            <ul
                v-if="results.length > 0"
                class="absolute top-full right-0 left-0 z-30 mt-1 overflow-hidden rounded-md border bg-popover shadow-md"
                role="listbox"
            >
                <li v-for="user in results" :key="user.id" role="option">
                    <button
                        type="button"
                        class="flex w-full items-baseline justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-muted"
                        @click="add(user)"
                    >
                        <span>
                            {{ user.name }}
                            <span
                                v-if="user.division"
                                class="text-xs text-muted-foreground"
                            >
                                · {{ user.division }}
                            </span>
                        </span>
                        <span class="truncate text-xs text-muted-foreground">
                            {{ user.email }}
                        </span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
