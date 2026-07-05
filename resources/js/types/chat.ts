export type ChatUserRef = {
    id: number;
    name: string;
    avatar_path: string | null;
};

export type ChatReactionGroup = {
    emoji: string;
    count: number;
    user_ids: number[];
};

export type ChatAttachmentItem = {
    id: number;
    url: string;
    original_name: string;
    width: number | null;
    height: number | null;
};

export type ChatPollVoter = { id: number; name: string };

export type ChatPollOptionItem = {
    id: number;
    label: string;
    voters: ChatPollVoter[];
};

export type ChatPollData = {
    id: number;
    question: string;
    allows_multiple: boolean;
    options: ChatPollOptionItem[];
};

/** Server-built snapshot of a shared event; `url` deep-links to it. */
export type ChatEventCard = {
    event_id: number;
    title: string;
    starts_at: string;
    ends_at: string;
    all_day: boolean;
    location: string | null;
    url: string;
};

export type ChatMessageItem = {
    id: number;
    user: ChatUserRef | null;
    is_deleted: boolean;
    body: string | null;
    reply_to: { id: number; user_name: string | null; excerpt: string | null } | null;
    event_card?: ChatEventCard | null;
    attachments: ChatAttachmentItem[];
    reactions: ChatReactionGroup[];
    poll: ChatPollData | null;
    is_pinned: boolean;
    created_at: string;
    /** Local-only flag for optimistic bubbles awaiting the server response. */
    pending?: boolean;
};

export type ChatMemberItem = ChatUserRef & {
    last_read_message_id: number | null;
};

export type ChatDivisionInfo = {
    id: number;
    name: string;
    photo_url: string | null;
};

export type ChatPage = {
    messages: ChatMessageItem[];
    has_more: boolean;
};

/** The curated reaction set; mirrors ChatReaction::EMOJIS server-side. */
export const CHAT_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'] as const;
