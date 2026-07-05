/**
 * The single typed client for the calendar's session JSON endpoints.
 * Routes live inside Laravel's `web` group, so CSRF stays enforced —
 * this wrapper forwards the XSRF cookie Laravel already set.
 */

export class ApiError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly errors: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'ApiError';
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Echo's socket id, when connected. Sent as X-Socket-ID so the server's
 * broadcast(...)->toOthers() can skip echoing our own mutations back.
 */
function socketId(): string | undefined {
    const echo = (
        window as { Echo?: { socketId?: () => string | undefined } }
    ).Echo;

    return echo?.socketId?.();
}

function baseHeaders(): Record<string, string> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': xsrfToken(),
    };

    const socket = socketId();

    if (socket) {
        headers['X-Socket-ID'] = socket;
    }

    return headers;
}

async function parse<T>(response: Response): Promise<T> {
    if (response.status === 204) {
        return undefined as T;
    }

    const payload = await response.json().catch(() => null);

    if (!response.ok) {
        throw new ApiError(
            response.status,
            (payload as { message?: string } | null)?.message ??
                `Request failed (${response.status})`,
            (payload as { errors?: Record<string, string[]> } | null)
                ?.errors ?? {},
        );
    }

    return payload as T;
}

async function request<T>(
    method: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE',
    url: string,
    body?: unknown,
): Promise<T> {
    const response = await fetch(url, {
        method,
        headers: {
            ...baseHeaders(),
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    return parse<T>(response);
}

/**
 * Multipart POST for file uploads. No Content-Type header on purpose:
 * the browser sets it with the multipart boundary.
 */
async function postForm<T>(url: string, form: FormData): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        headers: baseHeaders(),
        credentials: 'same-origin',
        body: form,
    });

    return parse<T>(response);
}

export const api = {
    get: <T>(url: string) => request<T>('GET', url),
    post: <T>(url: string, body?: unknown) => request<T>('POST', url, body),
    postForm: <T>(url: string, form: FormData) => postForm<T>(url, form),
    patch: <T>(url: string, body?: unknown) => request<T>('PATCH', url, body),
    put: <T>(url: string, body?: unknown) => request<T>('PUT', url, body),
    delete: <T>(url: string) => request<T>('DELETE', url),
};
