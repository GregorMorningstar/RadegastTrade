export type ChatContact = {
    id: number;
    name: string;
    role: string;
    /** Organizacja osoby ze współpracującej firmy (null dla osób z mojej organizacji) */
    organization: string | null;
    /** Kim jest osoba: właściciel, sklep, hurtownia lub platforma; i na jakim poziomie */
    kind: 'owner' | 'shop' | 'warehouse' | 'platform';
    level: 'organization' | 'headquarters' | 'branch' | 'worker' | 'platform';
    avatar_url: string;
    active: boolean;
    /** Ta osoba właśnie pisze do mnie */
    typing: boolean;
    unread: number;
    last_message: { body: string; mine: boolean; created_at: string } | null;
};

export type ChatMessage = {
    id: number;
    sender_id: number;
    recipient_id: number;
    body: string;
    created_at: string;
    read_at: string | null;
};

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function request<T>(url: string, init: RequestInit = {}): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...init,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
            ...(init.body ? { 'Content-Type': 'application/json' } : {}),
            ...init.headers,
        },
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return response.json() as Promise<T>;
}

export const chatApi = {
    contacts: () => request<{ me: number; contacts: ChatContact[]; unread: number }>('/chat/contacts'),
    messages: (userId: number) =>
        request<{ messages: ChatMessage[]; typing: boolean }>(`/chat/${userId}/messages`),
    typing: (userId: number) => fetch(`/chat/${userId}/typing`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
    }),
    send: (userId: number, body: string) =>
        request<{ message: ChatMessage }>(`/chat/${userId}/messages`, {
            method: 'POST',
            body: JSON.stringify({ body }),
        }),
};
