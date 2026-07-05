import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { getEcho } from '@/echo';
import { api } from '@/lib/api';
import chatApi from '@/routes/api/chat';
import chatMessages from '@/routes/api/chat/messages';
import chatPhoto from '@/routes/api/chat/photo';
import type {
    ChatDivisionInfo,
    ChatMemberItem,
    ChatMessageItem,
    ChatPage,
    ChatPollData,
    ChatReactionGroup,
} from '@/types/chat';

/**
 * One store, one Echo subscription: the sidebar badge, the floating dock,
 * and the full chat page all read and write here.
 */
export const useChatStore = defineStore('chat', () => {
    const division = ref<ChatDivisionInfo | null>(null);
    const messages = ref<ChatMessageItem[]>([]);
    const hasMore = ref(false);
    const loaded = ref(false);
    const loadingOlder = ref(false);
    const members = ref<ChatMemberItem[]>([]);
    const unread = ref(0);
    const lastReadMessageId = ref<number | null>(null);
    const myId = ref<number | null>(null);

    /** 'hidden' = dismissed; 'head' = floating circle; 'mini' = small box. */
    const dock = ref<'hidden' | 'head' | 'mini'>('hidden');

    /** True while a chat surface (page or mini box) is visible and focused. */
    const active = ref(false);

    const typing = ref(new Map<number, { name: string; until: number }>());

    /** Pinned messages, newest pin first; feeds the banner and settings. */
    const pinned = ref<ChatMessageItem[]>([]);
    let pinnedLoaded = false;

    /** Set by jumpTo(); the panel scrolls that message into view. */
    const jumpRequestId = ref<number | null>(null);

    let subscribed = false;
    let lastTypingWhisper = 0;
    let readTimer: ReturnType<typeof setTimeout> | undefined;

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

    function channelName(): string {
        return `division.${division.value?.id}.chat`;
    }

    /**
     * Called once per page load (from the dock) with the shared props.
     * Idempotent; keeps the live unread count across SPA navigations.
     */
    function bootstrap(
        info: ChatDivisionInfo | null,
        unreadCount: number,
        userId: number,
    ): void {
        myId.value = userId;

        if (info === null) {
            division.value = null;

            return;
        }

        if (division.value?.id !== info.id) {
            division.value = info;
            unread.value = unreadCount;
            const saved = localStorage.getItem('chat-dock');
            dock.value = saved === 'head' || saved === 'mini' ? saved : 'hidden';
        }

        subscribe();
    }

    function subscribe(): void {
        const echo = getEcho();

        if (echo === null || subscribed || division.value === null) {
            return;
        }

        subscribed = true;

        echo.private(channelName())
            .listen('.message.sent', (payload: ChatMessageItem) => {
                onIncoming(payload);
            })
            .listen('.message.deleted', (payload: { id: number }) => {
                patchMessage(payload.id, {
                    is_deleted: true,
                    body: null,
                    attachments: [],
                });
            })
            .listen(
                '.reaction.updated',
                (payload: {
                    message_id: number;
                    reactions: ChatReactionGroup[];
                }) => {
                    patchMessage(payload.message_id, {
                        reactions: payload.reactions,
                    });
                },
            )
            .listen(
                '.poll.updated',
                (payload: { message_id: number; poll: ChatPollData }) => {
                    patchMessage(payload.message_id, { poll: payload.poll });
                },
            )
            .listen(
                '.message.pinned',
                (payload: { message_id: number; is_pinned: boolean }) => {
                    patchMessage(payload.message_id, {
                        is_pinned: payload.is_pinned,
                    });

                    if (pinnedLoaded) {
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
                if (division.value) {
                    division.value.photo_url = payload.photo_url;
                }
            })
            .listenForWhisper(
                'typing',
                (payload: { id: number; name: string }) => {
                    if (payload.id === myId.value) {
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

    function onIncoming(message: ChatMessageItem): void {
        if (messages.value.some((m) => m.id === message.id)) {
            return;
        }

        messages.value.push(message);
        typing.value.delete(message.user?.id ?? -1);

        if (active.value) {
            queueMarkRead();
        } else {
            unread.value++;
        }
    }

    function patchMessage(
        id: number,
        patch: Partial<ChatMessageItem>,
    ): void {
        const index = messages.value.findIndex((m) => m.id === id);

        if (index !== -1) {
            messages.value.splice(index, 1, {
                ...messages.value[index],
                ...patch,
            });
        }
    }

    /** Hydrate from the full page's inlined props. */
    function hydrate(
        page: ChatPage,
        memberList: ChatMemberItem[],
        lastRead: number | null,
        userId: number,
    ): void {
        // Seed myId now (not in onMounted's bootstrap): isMine drives message
        // alignment, so a late id flips own messages left→right on refresh.
        myId.value = userId;
        messages.value = [...page.messages].reverse();
        hasMore.value = page.has_more;
        members.value = memberList;
        lastReadMessageId.value = lastRead;
        loaded.value = true;
    }

    /** First open of the mini box on a page with no inlined chat data. */
    async function ensureLoaded(): Promise<void> {
        if (loaded.value || division.value === null) {
            return;
        }

        const [page, memberList] = await Promise.all([
            api.get<ChatPage>(chatMessages.index().url),
            api.get<{ data: ChatMemberItem[] }>(chatApi.members().url),
        ]);

        messages.value = [...page.messages].reverse();
        hasMore.value = page.has_more;
        members.value = memberList.data;
        loaded.value = true;
    }

    async function loadOlder(): Promise<void> {
        if (
            loadingOlder.value ||
            !hasMore.value ||
            messages.value.length === 0
        ) {
            return;
        }

        loadingOlder.value = true;

        try {
            const page = await api.get<ChatPage>(
                chatMessages.index({
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
        /** Share an event: the server snapshots it into an event card. */
        eventId?: number;
        photos?: File[];
        poll?: {
            question: string;
            options: string[];
            allows_multiple: boolean;
        };
    }): Promise<void> {
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
            chatMessages.store().url,
            form,
        );

        onIncoming(response.data);
        queueMarkRead();
    }

    async function deleteMessage(id: number): Promise<void> {
        await api.delete(chatMessages.destroy(id).url);
        patchMessage(id, { is_deleted: true, body: null, attachments: [] });
    }

    async function toggleReaction(id: number, emoji: string): Promise<void> {
        const response = await api.post<{ data: ChatReactionGroup[] }>(
            chatApi.reactions.toggle(id).url,
            { emoji },
        );

        patchMessage(id, { reactions: response.data });
    }

    async function togglePin(id: number): Promise<void> {
        const response = await api.post<{ data: { is_pinned: boolean } }>(
            chatApi.pin.toggle(id).url,
            {},
        );

        patchMessage(id, { is_pinned: response.data.is_pinned });

        if (pinnedLoaded) {
            await loadPinned();
        }
    }

    async function loadPinned(): Promise<void> {
        if (division.value === null) {
            return;
        }

        const response = await api.get<{ data: ChatMessageItem[] }>(
            chatApi.pinned().url,
        );

        pinned.value = response.data;
        pinnedLoaded = true;
    }

    /** Scroll a message into view in the open panel (banner, pinned list). */
    function jumpTo(id: number): void {
        jumpRequestId.value = id;
    }

    /** Page older history until the target message id is in the list. */
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

    async function vote(
        messageId: number,
        pollId: number,
        optionIds: number[],
    ): Promise<void> {
        const response = await api.put<{ data: ChatPollData }>(
            chatApi.polls.votes(pollId).url,
            { option_ids: optionIds },
        );

        patchMessage(messageId, { poll: response.data });
    }

    /** Debounced: read receipts are always on, but one POST per second. */
    function queueMarkRead(): void {
        const latest = messages.value.at(-1);

        if (
            !active.value ||
            latest === undefined ||
            (lastReadMessageId.value ?? 0) >= latest.id
        ) {
            return;
        }

        unread.value = 0;

        clearTimeout(readTimer);
        readTimer = setTimeout(() => {
            const target = messages.value.at(-1);

            if (target === undefined) {
                return;
            }

            lastReadMessageId.value = target.id;

            const me = members.value.find((m) => m.id === myId.value);

            if (me) {
                me.last_read_message_id = target.id;
            }

            void api.post(chatApi.read().url, {
                last_read_message_id: target.id,
            });
        }, 400);
    }

    function setActive(next: boolean): void {
        active.value = next;

        if (next) {
            unread.value = 0;
            queueMarkRead();
        }
    }

    function whisperTyping(name: string): void {
        const now = Date.now();

        if (now - lastTypingWhisper < 2000 || division.value === null) {
            return;
        }

        lastTypingWhisper = now;

        getEcho()
            ?.private(channelName())
            .whisper('typing', { id: myId.value, name });
    }

    function openMini(): void {
        dock.value = 'mini';
        localStorage.setItem('chat-dock', 'mini');
        void ensureLoaded();
    }

    function closeMini(): void {
        dock.value = 'head';
        localStorage.setItem('chat-dock', 'head');
        setActive(false);
    }

    /** Full page's minimize button: leave a floating head behind. */
    function minimizeToHead(): void {
        dock.value = 'head';
        localStorage.setItem('chat-dock', 'head');
    }

    /** Dismiss entirely; only minimizing the full page brings it back. */
    function dismiss(): void {
        dock.value = 'hidden';
        localStorage.setItem('chat-dock', 'hidden');
        setActive(false);
    }

    return {
        division,
        messages,
        hasMore,
        loaded,
        loadingOlder,
        members,
        unread,
        lastReadMessageId,
        myId,
        dock,
        active,
        typingNames,
        readsByMessage,
        bootstrap,
        subscribe,
        hydrate,
        ensureLoaded,
        loadOlder,
        send,
        deleteMessage,
        toggleReaction,
        togglePin,
        pinned,
        loadPinned,
        jumpRequestId,
        jumpTo,
        ensureMessageLoaded,
        vote,
        queueMarkRead,
        setActive,
        whisperTyping,
        openMini,
        closeMini,
        minimizeToHead,
        dismiss,
        photoUploadUrl: computed(() => chatPhoto.update().url),
    };
});
