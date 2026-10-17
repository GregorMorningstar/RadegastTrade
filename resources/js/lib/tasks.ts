export type Option = { value: string; label: string };

export type Assignee = { type: 'organization' | 'headquarters' | 'branch' | 'user'; label: string; id: number | null; unit_type: string | null };

export type Task = {
    id: number;
    title: string;
    description: string | null;
    priority: 'low' | 'normal' | 'high' | 'urgent';
    priority_label: string;
    status: 'todo' | 'in_progress' | 'done';
    status_label: string;
    progress: number;
    is_milestone: boolean;
    all_day: boolean;
    starts_at: string;
    ends_at: string;
    depends_on_id: number | null;
    depends_on_title: string | null;
    created_by: { id: number; name: string } | null;
    assignees: Assignee[];
    can_edit: boolean;
    can_complete: boolean;
    is_executor: boolean;
    my_done: boolean;
    executors_total: number;
    executors_done: number;
    executors: { id: number; name: string; role: string; done: boolean }[];
};

export type Targets = {
    organization: boolean;
    headquarters: { type: string; id: number; name: string }[];
    branches: { type: string; id: number; name: string; headquarter_id: number }[];
    people: { id: number; name: string; role: string }[];
};

export type AssignmentInput = { type: Assignee['type']; unit_type?: string; id?: number };

export const priorityColors: Record<Task['priority'], string> = {
    low: '#6c757d',
    normal: '#007bff',
    high: '#fd7e14',
    urgent: '#dc3545',
};

function xsrf(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export class ApiError extends Error {
    constructor(
        message: string,
        public errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

async function call<T>(method: string, url: string, body?: unknown): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (response.status === 204) {
        return undefined as T;
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new ApiError(data.message ?? 'Operacja nie powiodła się.', data.errors ?? {});
    }

    return data as T;
}

export const tasksApi = {
    create: (data: Record<string, unknown>) => call<Task>('POST', '/tasks', data),
    update: (id: number, data: Record<string, unknown>) => call<Task>('PATCH', `/tasks/${id}`, data),
    reschedule: (id: number, data: { starts_at: string; ends_at: string; all_day?: boolean }) => call<Task>('PATCH', `/tasks/${id}/schedule`, data),
    remove: (id: number) => call<void>('DELETE', `/tasks/${id}`),
};

export function formatDate(iso: string, allDay: boolean): string {
    const date = new Date(iso);

    return allDay
        ? date.toLocaleDateString('pl-PL')
        : date.toLocaleString('pl-PL', { dateStyle: 'short', timeStyle: 'short' });
}

export function pad(value: number): string {
    return String(value).padStart(2, '0');
}

export function localDate(date: Date): string {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}
