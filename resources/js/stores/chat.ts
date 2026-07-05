import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { getEcho } from '@/echo';
import { api } from '@/lib/api';
import chatApi from '@/routes/api/chat';
import chatMessages from '@/routes/api/chat/messages';
import chatPhoto from '@/routes/api/chat/photo';
import chatPin from '@/routes/api/chat/pin';
import chatPolls from '@/routes/api/chat/polls';
import chatReactions from '@/routes/api/chat/reactions';
import conversationsApi from '@/routes/api/conversations';
import type {
    ChatCandidate,
    ChatConversationSummary,
    ChatMemberItem,
    ChatMessageItem,
    ChatPage,
    ChatPollData,
    ChatReactionGroup,
} from '@/types/chat';

/**
 * One store, one Echo connection: the sidebar badge, the floating dock, and
 * the full chat page share the conversation list and the open thread. Every
 * conversation the user belongs to gets its own private channel so unread
 * counts stay live even when its thread is not open.
 */
export const useChatStore = defineStore('chat', () => {
    const conversations = ref<ChatConversationSummary[]>([]);
    const activeId = ref<number | null>(null);
    const myId = ref<number | null>(null);

    // Active thread working set (reloaded when the active conversation changes).
    const messages = ref<ChatMessageItem[]>([]);
    const members = ref<ChatMemberItem[]>([]);
    const pinned = ref<ChatMessageItem[]>([]);
    const hasMore = ref(false);
    const loaded = ref(false);
    const loadingOlder = ref(false);
    const lastReadMessageId = ref<number | null>(null);

    /** 'hidden' = dismissed; 'head' = floating circle; 'mini' = small box. */
    const dock = ref<'hidden' | 'head' | 'mini'>('hidden');

    /** True while a chat surface (page or mini box) is visible and focused. */
    const active = ref(false);

    const typing = ref(new Map<number, { name: string; until: number }>());
    const jumpRequestId = ref<number | null>(null);

    /** Draft for the "new group chat" dialog (may be seeded from an event). */
    const newGroup = ref<{
        open: boolean;
        eventId: number | null;
        eventTitle: string | null;
        seed: ChatCandidate[];
    }>({ open: false, eventId: null, eventTitle: null, seed: [] });

    const subscribed = new Set<number>();
    let bootstrapped = false;
    let lastTypingWhisper = 0;
    let readTimer: ReturnType<typeof setTimeout> | undefined;

    const sortedConversations = computed(() =>
        [...conversations.value].sort(
            (a, b) =>
                new Date(b.last_activity).getTime() -
                new Date(a.last_activity).getTime(),
        ),
    );

    const activeConversation = computed(
        () => conversations.value.find((c) => c.id === activeId.value) ?? null,
    );

    const totalUnread = computed(() =>
        conversations.value.reduce((sum, c) => sum + c.unread, 0),
    );

    const typingNames = computed(() => {
        const now = Date.now();

        return [...typing.value.values()]
            .filter((entry) => entry.until > now)
            .map((entry) => entry.name);
    });

    /** Members (minus self) keyed by the message id they have read up to. */
    const readsByMessage = computed(() => {
        const map = new Map<number, ChatMemberItem[]>();

        for (const member of members.value) {
            if (
                member.id === myId.value ||
                member.last_read_message_id === null
            ) {
                continue;
            }

            const list = map.get(member.last_read_message_id) ?? [];
            list.push(member);
            map.set(member.last_read_message_id, list);
        }

        return map;
    });

    function previewOf(message: ChatMessageItem): string {
        if (message.is_deleted) {
            return 'Message removed';
        }

        if (message.body) {
            return message.body;
        }

        if (message.poll) {
            return 'Poll';
        }

        if (message.attachments.length > 0) {
            return 'Photo';
        }

        return message.event_card ? 'Shared an event' : '';
    }

    /**
     * Called once per page load (from the dock) with the shared conversation
     * list. Idempotent; keeps live state across SPA navigations.
     */
    function bootstrap(list: ChatConversationSummary[], userId: number): void {
        myId.value = userId;

        // Merge server list without clobbering live unread we already bumped.
        const known = new Map(conversations.value.map((c) => [c.id, c]));
        conversations.value = list.map((incoming) => {
            const existing = known.get(incoming.id);

            return existing && bootstrapped
                ? { ...incoming, unread: existing.unread }
                : incoming;
        });

        if (!bootstrapped) {
            const saved = localStorage.getItem('chat-dock');
            dock.value = saved === 'head' || saved === 'mini' ? saved : 'hidden';
            bootstrapped = true;
        }

        subscribeAll();
    }

    function subscribeAll(): void {
        const echo = getEcho();

        if (echo === null) {
            return;
        }

        for (const conversation of conversations.value) {
            subscribeTo(conversation.id);
        }
    }

    function subscribeTo(id: number): void {
        const echo = getEcho();

        if (echo === null || subscribed.has(id)) {
            return;
        }

        subscribed.add(id);

        echo.private(`conversation.${id}.chat`)
            .listen('.message.sent', (payload: ChatMessageItem) => {
                onIncoming(id, payload);
            })
            .listen('.message.deleted', (payload: { id: number }) => {
                if (id === activeId.value) {
                    patchMessage(payload.id, {
                        is_deleted: true,
                        body: null,
                        attachments: [],
                    });
                }
            })
            .listen(
                '.reaction.updated',
                (payload: {
                    message_id: number;
                    reactions: ChatReactionGroup[];
                }) => {
                    if (id === activeId.value) {
                        patchMessage(payload.message_id, {
                            reactions: payload.reactions,
                        });
                    }
                },
            )
            .listen(
                '.poll.updated',
                (payload: { message_id: number; poll: ChatPollData }) => {
                    if (id === activeId.value) {
                        patchMessage(payload.message_id, { poll: payload.poll });
                    }
                },
            )
            .listen(
                '.message.pinned',
                (payload: { message_id: number; is_pinned: boolean }) => {
                    if (id === activeId.value) {
                        patchMessage(payload.message_id, {
                            is_pinned: payload.is_pinned,
                        });
                        void loadPinned();
                    }
                },
            )
            .listen(
                '.read.updated',
                (payload: {
                    user_id: number;
                    last_read_message_id: number;
                }) => {
                    if (id !== activeId.value) {
                        return;
                    }

                    const member = members.value.find(
                        (m) => m.id === payload.user_id,
                    );

                    if (member) {
                        member.last_read_message_id =
                            payload.last_read_message_id;
                    }
                },
            )
            .listen('.settings.updated', (payload: { photo_url: string }) => {
                const summary = conversations.value.find((c) => c.id === id);

                if (summary) {
                    summary.photo_url = payload.photo_url;
                }
            })
            .listenForWhisper(
                'typing',
                (payload: {
                    conversation_id: number;
                    id: number;
                    name: string;
                }) => {
                    if (
                        payload.conversation_id !== activeId.value ||
                        payload.id === myId.value
                    ) {
                        return;
                    }

                    typing.value.set(payload.id, {
                        name: payload.name,
                        until: Date.now() + 2500,
                    });
                    typing.value = new Map(typing.value);

                    setTimeout(() => {
                        typing.value = new Map(typing.value);
                    }, 2600);
                },
            );
    }

    function onIncoming(conversationId: number, message: ChatMessageItem): void {
        const summary = conversations.value.find((c) => c.id === conversationId);

        if (summary) {
            summary.last_preview = previewOf(message);
            summary.last_activity = message.created_at;
        }

        if (conversationId === activeId.value) {
            if (messages.value.some((m) => m.id === message.id)) {
                return;
            }

            messages.value.push(message);
            typing.value.delete(message.user?.id ?? -1);

            if (active.value) {
                queueMarkRead();
            } else if (summary) {
                summary.unread++;
            }

            return;
        }

        if (summary && message.user?.id !== myId.value) {
            summary.unread++;
        }
    }

    function patchMessage(id: number, patch: Partial<ChatMessageItem>): void {
        const index = messages.value.findIndex((m) => m.id === id);

        if (index !== -1) {
            messages.value.splice(index, 1, {
                ...messages.value[index],
                ...patch,
            });
        }
    }

    /** Load (or switch to) a conversation's thread. */
    async function openConversation(id: number): Promise<void> {
        if (activeId.value === id && loaded.value) {
            return;
        }

        activeId.value = id;
        loaded.value = false;
        messages.value = [];
        members.value = [];
        pinned.value = [];
        hasMore.value = false;
        lastReadMessageId.value = null;

        const [page, memberList] = await Promise.all([
            api.get<ChatPage>(chatMessages.index(id).url),
            api.get<{ data: ChatMemberItem[] }>(chatApi.members(id).url),
        ]);

        // A late switch may have happened while awaiting; ignore stale loads.
        if (activeId.value !== id) {
            return;
        }

        messages.value = [...page.messages].reverse();
        hasMore.value = page.has_more;
        members.value = memberList.data;
        loaded.value = true;

        void loadPinned();

        if (active.value) {
            queueMarkRead();
        }
    }

    /** Ensure some conversation is open (first in the list by default). */
    async function ensureLoaded(): Promise<void> {
        if (activeId.value !== null) {
            if (!loaded.value) {
                await openConversation(activeId.value);
            }

            return;
        }

        const first = sortedConversations.value[0];

        if (first) {
            await openConversation(first.id);
        }
    }

    async function loadOlder(): Promise<void> {
        if (
            loadingOlder.value ||
            !hasMore.value ||
            messages.value.length === 0 ||
            activeId.value === null
        ) {
            return;
        }

        loadingOlder.value = true;

        try {
            const page = await api.get<ChatPage>(
                chatMessages.index(activeId.value, {
                    query: { before_id: messages.value[0].id },
                }).url,
            );

            messages.value = [
                ...[...page.messages].reverse(),
                ...messages.value,
            ];
            hasMore.value = page.has_more;
        } finally {
            loadingOlder.value = false;
        }
    }

    async function send(input: {
        body?: string;
        replyToId?: number | null;
        eventId?: number;
        photos?: File[];
        poll?: {
            question: string;
            options: string[];
            allows_multiple: boolean;
        };
    }): Promise<void> {
        if (activeId.value === null) {
            return;
        }

        const form = new FormData();

        if (input.body) {
            form.append('body', input.body);
        }

        if (input.replyToId) {
            form.append('reply_to_id', String(input.replyToId));
        }

        if (input.eventId) {
            form.append('event_id', String(input.eventId));
        }

        for (const photo of input.photos ?? []) {
            form.append('photos[]', photo);
        }

        if (input.poll) {
            form.append('poll[question]', input.poll.question);
            form.append(
                'poll[allows_multiple]',
                input.poll.allows_multiple ? '1' : '0',
            );
            input.poll.options.forEach((option, index) => {
                form.append(`poll[options][${index}]`, option);
            });
        }

        const response = await api.postForm<{ data: ChatMessageItem }>(
            chatMessages.store(activeId.value).url,
            form,
        );

        onIncoming(activeId.value, response.data);
        queueMarkRead();
    }

    async function deleteMessage(id: number): Promise<void> {
        await api.delete(chatMessages.destroy(id).url);
        patchMessage(id, { is_deleted: true, body: null, attachments: [] });
    }

    async function toggleReaction(id: number, emoji: string): Promise<void> {
        const response = await api.post<{ data: ChatReactionGroup[] }>(
            chatReactions.toggle(id).url,
            { emoji },
        );

        patchMessage(id, { reactions: response.data });
    }

    async function togglePin(id: number): Promise<void> {
        const response = await api.post<{ data: { is_pinned: boolean } }>(
            chatPin.toggle(id).url,
            {},
        );

        patchMessage(id, { is_pinned: response.data.is_pinned });
        await loadPinned();
    }

    async function vote(
        messageId: number,
        pollId: number,
        optionIds: number[],
    ): Promise<void> {
        const response = await api.put<{ data: ChatPollData }>(
            chatPolls.votes(pollId).url,
            { option_ids: optionIds },
        );

        patchMessage(messageId, { poll: response.data });
    }

    async function reloadMembers(): Promise<void> {
        if (activeId.value === null) {
            return;
        }

        const response = await api.get<{ data: ChatMemberItem[] }>(
            chatApi.members(activeId.value).url,
        );

        members.value = response.data;
    }

    async function loadPinned(): Promise<void> {
        if (activeId.value === null) {
            return;
        }

        const response = await api.get<{ data: ChatMessageItem[] }>(
            chatApi.pinned(activeId.value).url,
        );

        pinned.value = response.data;
    }

    function jumpTo(id: number): void {
        jumpRequestId.value = id;
    }

    async function ensureMessageLoaded(id: number): Promise<void> {
        // ponytail: capped linear paging; fine for 30-per-page history.
        for (let i = 0; i < 30; i++) {
            const first = messages.value[0];

            if (!hasMore.value || first === undefined || first.id <= id) {
                return;
            }

            await loadOlder();
        }
    }

    /** Debounced: read receipts are always on, but one POST per second. */
    function queueMarkRead(): void {
        const id = activeId.value;
        const latest = messages.value.at(-1);
        const summary = activeConversation.value;

        if (
            id === null ||
            !active.value ||
            latest === undefined ||
            (lastReadMessageId.value ?? 0) >= latest.id
        ) {
            return;
        }

        if (summary) {
            summary.unread = 0;
        }

        clearTimeout(readTimer);
        readTimer = setTimeout(() => {
            const target = messages.value.at(-1);

            if (target === undefined || activeId.value !== id) {
                return;
            }

            lastReadMessageId.value = target.id;

            const me = members.value.find((m) => m.id === myId.value);

            if (me) {
                me.last_read_message_id = target.id;
            }

            void api.post(chatApi.read(id).url, {
                last_read_message_id: target.id,
            });
        }, 400);
    }

    function setActive(next: boolean): void {
        active.value = next;

        if (next) {
            if (activeConversation.value) {
                activeConversation.value.unread = 0;
            }

            queueMarkRead();
        }
    }

    function whisperTyping(name: string): void {
        const now = Date.now();

        if (now - lastTypingWhisper < 2000 || activeId.value === null) {
            return;
        }

        lastTypingWhisper = now;

        getEcho()
            ?.private(`conversation.${activeId.value}.chat`)
            .whisper('typing', {
                conversation_id: activeId.value,
                id: myId.value,
                name,
            });
    }

    // --- Conversation management -------------------------------------------

    async function createConversation(input: {
        name: string;
        memberIds: number[];
        eventId?: number;
    }): Promise<number> {
        const response = await api.post<{ data: ChatConversationSummary }>(
            conversationsApi.store().url,
            {
                name: input.name,
                member_ids: input.memberIds,
                event_id: input.eventId,
            },
        );

        conversations.value.push(response.data);
        subscribeTo(response.data.id);

        return response.data.id;
    }

    async function updateConversation(
        id: number,
        input: { name?: string; memberIds?: number[] },
    ): Promise<void> {
        const response = await api.patch<{ data: ChatConversationSummary }>(
            conversationsApi.update(id).url,
            { name: input.name, member_ids: input.memberIds },
        );

        const index = conversations.value.findIndex((c) => c.id === id);

        if (index !== -1) {
            conversations.value.splice(index, 1, response.data);
        }
    }

    async function leaveConversation(id: number): Promise<void> {
        await api.post(conversationsApi.leave(id).url, {});

        conversations.value = conversations.value.filter((c) => c.id !== id);

        if (activeId.value === id) {
            activeId.value = null;
            loaded.value = false;
            messages.value = [];
        }
    }

    async function searchCandidates(term: string): Promise<ChatCandidate[]> {
        const response = await api.get<{ data: ChatCandidate[] }>(
            conversationsApi.candidates({ query: { q: term } }).url,
        );

        return response.data;
    }

    // --- Dock controls ------------------------------------------------------

    function openMini(): void {
        dock.value = 'mini';
        localStorage.setItem('chat-dock', 'mini');
        setActive(true);
        void ensureLoaded();
    }

    function closeMini(): void {
        dock.value = 'head';
        localStorage.setItem('chat-dock', 'head');
        setActive(false);
    }

    function minimizeToHead(): void {
        dock.value = 'head';
        localStorage.setItem('chat-dock', 'head');
    }

    function dismiss(): void {
        dock.value = 'hidden';
        localStorage.setItem('chat-dock', 'hidden');
        setActive(false);
    }

    function backToList(): void {
        activeId.value = null;
        loaded.value = false;
        messages.value = [];
    }

    /** Open the new-group dialog, optionally seeded from an event. */
    function openNewGroup(opts?: {
        eventId?: number;
        eventTitle?: string;
        seed?: ChatCandidate[];
    }): void {
        newGroup.value = {
            open: true,
            eventId: opts?.eventId ?? null,
            eventTitle: opts?.eventTitle ?? null,
            seed: opts?.seed ?? [],
        };

        // Surface the dock so the created chat has somewhere to land.
        if (dock.value === 'hidden') {
            dock.value = 'head';
            localStorage.setItem('chat-dock', 'head');
        }
    }

    function closeNewGroup(): void {
        newGroup.value = { ...newGroup.value, open: false };
    }

    return {
        conversations,
        sortedConversations,
        activeId,
        activeConversation,
        totalUnread,
        myId,
        messages,
        members,
        pinned,
        hasMore,
        loaded,
        loadingOlder,
        lastReadMessageId,
        dock,
        active,
        typingNames,
        readsByMessage,
        jumpRequestId,
        newGroup,
        openNewGroup,
        closeNewGroup,
        bootstrap,
        openConversation,
        ensureLoaded,
        loadOlder,
        send,
        deleteMessage,
        toggleReaction,
        togglePin,
        vote,
        loadPinned,
        reloadMembers,
        jumpTo,
        ensureMessageLoaded,
        queueMarkRead,
        setActive,
        whisperTyping,
        createConversation,
        updateConversation,
        leaveConversation,
        searchCandidates,
        openMini,
        closeMini,
        minimizeToHead,
        dismiss,
        backToList,
        photoUploadUrl: computed(() =>
            activeId.value === null
                ? ''
                : chatPhoto.update(activeId.value).url,
        ),
    };
});
