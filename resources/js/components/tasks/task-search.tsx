import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { ApiError, formatDate, priorityColors, tasksApi, type Option, type Task, type TaskSearchResult } from '@/lib/tasks';

type Props = {
    statuses: Option[];
    priorities: Option[];
    onOpen: (task: Task) => void;
    /** Changes when a task was saved or removed elsewhere, so the results are fetched again */
    version: number;
};

const field = 'rounded border border-[#ced4da] px-2 py-1.5 text-sm focus:border-[#80bdff] focus:outline-none';

/**
 * Searching the tasks the person may see (the server decides what that is: the owner every task of the organization, the
 * others their unit and below, plus the tasks that are theirs). Filters: text, status, priority, kind, mine, period.
 */
export function TaskSearch({ statuses, priorities, onOpen, version }: Props) {
    const [filters, setFilters] = useState({ q: '', status: '', priority: '', kind: '', mine: false, from: '', to: '' });
    const [result, setResult] = useState<TaskSearchResult | null>(null);
    const [page, setPage] = useState(1);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const run = useCallback(
        async (wanted: number) => {
            setBusy(true);
            setError(null);

            try {
                setResult(await tasksApi.search({ ...filters, page: wanted }));
                setPage(wanted);
            } catch (e) {
                setError(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Nie udało się wyszukać zadań.');
            } finally {
                setBusy(false);
            }
        },
        // the filters are applied when the person submits (or when something changed elsewhere), not on every key
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [filters],
    );

    useEffect(() => {
        void run(page);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [version]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        void run(1);
    };

    const set = <K extends keyof typeof filters>(key: K, value: (typeof filters)[K]) => setFilters((current) => ({ ...current, [key]: value }));

    return (
        <div>
            <form onSubmit={submit} className="mb-4 grid gap-2 rounded border border-[#dee2e6] bg-[#f8f9fa] p-3 text-sm md:grid-cols-4">
                <input className={`${field} md:col-span-2`} placeholder="Szukaj w tytule, opisie lub po kodzie kreskowym zadania" value={filters.q} maxLength={100} onChange={(e) => set('q', e.target.value)} aria-label="Szukaj zadania" />
                <select className={field} value={filters.status} onChange={(e) => set('status', e.target.value)} aria-label="Status">
                    <option value="">Każdy status</option>
                    {statuses.map((item) => (
                        <option key={item.value} value={item.value}>
                            {item.label}
                        </option>
                    ))}
                </select>
                <select className={field} value={filters.priority} onChange={(e) => set('priority', e.target.value)} aria-label="Priorytet">
                    <option value="">Każdy priorytet</option>
                    {priorities.map((item) => (
                        <option key={item.value} value={item.value}>
                            {item.label}
                        </option>
                    ))}
                </select>
                <select className={field} value={filters.kind} onChange={(e) => set('kind', e.target.value)} aria-label="Rodzaj">
                    <option value="">Zwykłe i stałe</option>
                    <option value="ordinary">Tylko zwykłe</option>
                    <option value="recurring">Tylko stałe (powtarzane)</option>
                </select>
                <label className="flex items-center gap-1">
                    Od
                    <input className={field} type="date" value={filters.from} onChange={(e) => set('from', e.target.value)} />
                </label>
                <label className="flex items-center gap-1">
                    Do
                    <input className={field} type="date" value={filters.to} min={filters.from} onChange={(e) => set('to', e.target.value)} />
                </label>
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={filters.mine} onChange={(e) => set('mine', e.target.checked)} />
                    Tylko moje (dodane przeze mnie lub do mnie)
                </label>
                <div className="flex gap-2 md:col-span-4">
                    <button type="submit" disabled={busy} className="rounded bg-[#007bff] px-3 py-1.5 text-white disabled:opacity-60">
                        Szukaj
                    </button>
                    <button
                        type="button"
                        className="rounded border border-[#ced4da] px-3 py-1.5"
                        onClick={() => {
                            setFilters({ q: '', status: '', priority: '', kind: '', mine: false, from: '', to: '' });
                            setTimeout(() => void run(1), 0);
                        }}
                    >
                        Wyczyść
                    </button>
                </div>
            </form>

            {error && <div className="mb-3 rounded bg-[#f8d7da] px-3 py-2 text-sm text-[#721c24]">{error}</div>}

            {result && result.data.length === 0 && !busy && <p className="py-6 text-center text-sm text-[#6c757d]">Nie znaleziono zadań.</p>}

            {result && result.data.length > 0 && (
                <>
                    <p className="mb-2 text-xs text-[#6c757d]">Znaleziono: {result.total}</p>
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead>
                                <tr className="border-b border-[#dee2e6]">
                                    <th className="py-2 pr-4 font-semibold">Zadanie</th>
                                    <th className="py-2 pr-4 font-semibold">Termin</th>
                                    <th className="py-2 pr-4 font-semibold">Priorytet</th>
                                    <th className="py-2 pr-4 font-semibold">Status</th>
                                    <th className="py-2 font-semibold">Dla kogo</th>
                                </tr>
                            </thead>
                            <tbody>
                                {result.data.map((task) => (
                                    <tr key={task.id} className="border-b border-[#dee2e6] last:border-0">
                                        <td className="py-2 pr-4 align-top">
                                            <button type="button" onClick={() => onOpen(task)} className="text-left text-[#007bff] hover:underline">
                                                {task.recurrence ? '↻ ' : task.is_milestone ? '◆ ' : ''}
                                                {task.title}
                                            </button>
                                            {task.barcode && <span className="ml-2 text-xs text-[#6c757d]">{task.barcode}</span>}
                                        </td>
                                        <td className="py-2 pr-4 align-top">{task.recurrence ? task.recurrence.label : formatDate(task.starts_at, task.all_day)}</td>
                                        <td className="py-2 pr-4 align-top">
                                            <span className="rounded px-2 py-0.5 text-xs text-white" style={{ background: priorityColors[task.priority] }}>
                                                {task.priority_label}
                                            </span>
                                        </td>
                                        <td className="py-2 pr-4 align-top">{task.recurrence ? 'stałe' : `${task.status_label} (${task.progress}%)`}</td>
                                        <td className="py-2 align-top">{task.assignees.map((item) => item.label).join(', ')}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {result.last_page > 1 && (
                        <div className="mt-3 flex items-center justify-between text-sm">
                            <span className="text-[#6c757d]">
                                Strona {result.current_page} z {result.last_page}
                            </span>
                            <div className="flex gap-2">
                                <button type="button" disabled={busy || result.current_page <= 1} onClick={() => void run(result.current_page - 1)} className="rounded border border-[#ced4da] px-3 py-1 disabled:opacity-50">
                                    Poprzednia
                                </button>
                                <button type="button" disabled={busy || result.current_page >= result.last_page} onClick={() => void run(result.current_page + 1)} className="rounded border border-[#ced4da] px-3 py-1 disabled:opacity-50">
                                    Następna
                                </button>
                            </div>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
