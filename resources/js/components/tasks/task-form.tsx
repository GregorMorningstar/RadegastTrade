import { useState } from 'react';
import type { FormEvent } from 'react';
import { ApiError, localDate, pad, type AssignmentInput, type Option, type Targets, type Task } from '@/lib/tasks';

type Props = {
    task: Task | null;
    tasks: Task[];
    targets: Targets;
    priorities: Option[];
    statuses: Option[];
    canCreate: boolean;
    onSave: (data: Record<string, unknown>) => Promise<void>;
    onDelete: (task: Task) => Promise<void>;
    onClose: () => void;
};

const field = 'w-full rounded border border-[#ced4da] px-2 py-1.5 text-sm focus:border-[#80bdff] focus:outline-none';

function toInput(iso: string | undefined, allDay: boolean): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    return allDay ? localDate(date) : `${localDate(date)}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function key(type: string, unitType: string | null | undefined, id: number | null | undefined): string {
    return `${type}:${unitType ?? ''}:${id ?? ''}`;
}

/** Modal to add a task, change it (creator, owner) or report the progress only (assigned people). */
export function TaskForm({ task, tasks, targets, priorities, statuses, canCreate, onSave, onDelete, onClose }: Props) {
    const editing = task !== null;
    const full = !editing || task.can_edit;
    // a person who is not the creator or the owner may only mark the task as done (a milestone: accept it)
    const readOnly = editing && !task.can_edit && !task.can_complete;

    const now = new Date();
    now.setMinutes(0, 0, 0);
    now.setHours(now.getHours() + 1);

    const [title, setTitle] = useState(task?.title ?? '');
    const [description, setDescription] = useState(task?.description ?? '');
    const [priority, setPriority] = useState<string>(task?.priority ?? 'normal');
    const [status, setStatus] = useState<string>(task?.status ?? 'todo');
    const [progress, setProgress] = useState(task?.progress ?? 0);
    const [milestone, setMilestone] = useState(task?.is_milestone ?? false);
    const [allDay, setAllDay] = useState(task?.all_day ?? false);
    const [startsAt, setStartsAt] = useState(toInput(task?.starts_at ?? now.toISOString(), task?.all_day ?? false));
    const [endsAt, setEndsAt] = useState(toInput(task?.ends_at ?? new Date(now.getTime() + 3_600_000).toISOString(), task?.all_day ?? false));
    const [dependsOn, setDependsOn] = useState<string>(task?.depends_on_id ? String(task.depends_on_id) : '');
    const [selected, setSelected] = useState<Set<string>>(new Set((task?.assignees ?? []).map((item) => key(item.type, item.unit_type, item.id))));
    const [search, setSearch] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [message, setMessage] = useState<string | null>(null);

    const toggle = (value: string) =>
        setSelected((current) => {
            const next = new Set(current);

            if (next.has(value)) {
                next.delete(value);
            } else {
                next.add(value);
            }

            return next;
        });

    const changeAllDay = (checked: boolean) => {
        setAllDay(checked);
        setStartsAt((value) => (checked ? value.slice(0, 10) : `${value.slice(0, 10)}T09:00`));
        setEndsAt((value) => (checked ? value.slice(0, 10) : `${value.slice(0, 10)}T10:00`));
    };

    const assignments = (): AssignmentInput[] => {
        const list: AssignmentInput[] = [];

        if (selected.has(key('organization', null, null))) {
            list.push({ type: 'organization' });
        }

        targets.headquarters.forEach((item) => selected.has(key('headquarters', item.type, item.id)) && list.push({ type: 'headquarters', unit_type: item.type, id: item.id }));
        targets.branches.forEach((item) => selected.has(key('branch', item.type, item.id)) && list.push({ type: 'branch', unit_type: item.type, id: item.id }));
        targets.people.forEach((item) => selected.has(key('user', null, item.id)) && list.push({ type: 'user', id: item.id }));

        return list;
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setErrors({});
        setMessage(null);

        const iso = (value: string) => (value ? (allDay ? value : new Date(value).toISOString()) : null);
        const data: Record<string, unknown> = full
            ? {
                  title,
                  description: description || null,
                  priority,
                  status,
                  progress,
                  is_milestone: milestone,
                  all_day: allDay,
                  starts_at: iso(startsAt),
                  ends_at: milestone ? iso(startsAt) : iso(endsAt),
                  depends_on_id: dependsOn ? Number(dependsOn) : null,
                  assignments: assignments(),
              }
            : { status: 'done' };

        try {
            await onSave(data);
        } catch (error) {
            if (error instanceof ApiError) {
                setErrors(error.errors);
                setMessage(error.message);
            }
        } finally {
            setBusy(false);
        }
    };

    const remove = async () => {
        if (task && window.confirm('Usunąć to zadanie?')) {
            setBusy(true);

            try {
                await onDelete(task);
            } catch (error) {
                setMessage(error instanceof ApiError ? error.message : 'Nie udało się usunąć.');
                setBusy(false);
            }
        }
    };

    const error = (name: string) => errors[name]?.[0] && <p className="mt-1 text-xs text-[#dc3545]">{errors[name][0]}</p>;
    const needle = search.trim().toLowerCase();
    const matches = (name: string) => !needle || name.toLowerCase().includes(needle);
    const hqName = (type: string, id: number) => targets.headquarters.find((item) => item.type === type && item.id === id)?.name;

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <form onSubmit={submit} className="my-8 w-full max-w-2xl rounded bg-white shadow-lg">
                <div className="flex items-center justify-between border-b border-[#dee2e6] px-4 py-3">
                    <h3 className="text-lg">{editing ? (full ? 'Zadanie' : task.title) : 'Nowe zadanie'}</h3>
                    <button type="button" onClick={onClose} className="text-2xl leading-none text-[#6c757d]" aria-label="Zamknij">
                        ×
                    </button>
                </div>

                <div className="grid gap-3 p-4">
                    {message && <div className="rounded bg-[#f8d7da] px-3 py-2 text-sm text-[#721c24]">{message}</div>}

                    {full ? (
                        <>
                            <label className="grid gap-1 text-sm">
                                Tytuł
                                <input className={field} value={title} onChange={(e) => setTitle(e.target.value)} required maxLength={255} />
                                {error('title')}
                            </label>
                            <label className="grid gap-1 text-sm">
                                Opis
                                <textarea className={field} rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
                            </label>
                            <div className="grid grid-cols-2 gap-3">
                                <label className="grid gap-1 text-sm">
                                    Priorytet
                                    <select className={field} value={priority} onChange={(e) => setPriority(e.target.value)}>
                                        {priorities.map((item) => (
                                            <option key={item.value} value={item.value}>
                                                {item.label}
                                            </option>
                                        ))}
                                    </select>
                                    {error('priority')}
                                </label>
                                <label className="grid gap-1 text-sm">
                                    Zależy od
                                    <select className={field} value={dependsOn} onChange={(e) => setDependsOn(e.target.value)}>
                                        <option value="">— brak —</option>
                                        {tasks
                                            .filter((item) => item.id !== task?.id)
                                            .map((item) => (
                                                <option key={item.id} value={item.id}>
                                                    {item.title}
                                                </option>
                                            ))}
                                    </select>
                                    {error('depends_on_id')}
                                </label>
                            </div>
                            <div className="flex flex-wrap gap-4 text-sm">
                                <label className="flex items-center gap-2">
                                    <input type="checkbox" checked={milestone} onChange={(e) => setMilestone(e.target.checked)} />
                                    Kamień milowy
                                </label>
                                <label className="flex items-center gap-2">
                                    <input type="checkbox" checked={allDay} onChange={(e) => changeAllDay(e.target.checked)} />
                                    Cały dzień
                                </label>
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <label className="grid gap-1 text-sm">
                                    {milestone ? 'Termin' : 'Początek'}
                                    <input className={field} type={allDay ? 'date' : 'datetime-local'} value={startsAt} onChange={(e) => setStartsAt(e.target.value)} required />
                                    {error('starts_at')}
                                </label>
                                {!milestone && (
                                    <label className="grid gap-1 text-sm">
                                        Koniec
                                        <input className={field} type={allDay ? 'date' : 'datetime-local'} value={endsAt} onChange={(e) => setEndsAt(e.target.value)} required />
                                        {error('ends_at')}
                                    </label>
                                )}
                            </div>
                        </>
                    ) : (
                        <div className="text-sm">
                            {task.description && <p className="mb-2 text-[#495057]">{task.description}</p>}
                            <p className="text-[#6c757d]">
                                {task.priority_label} · {task.assignees.map((item) => item.label).join(', ')} · {task.created_by ? `dodał(a): ${task.created_by.name}` : ''}
                            </p>
                        </div>
                    )}

                    {editing && task.executors_total > 0 && (
                        <div className="rounded border border-[#dee2e6] p-3 text-sm">
                            <p className="font-medium">
                                Wykonali: {task.executors_done} z {task.executors_total}
                                {task.my_done && <span className="font-normal text-[#28a745]"> · Twoja część jest zakończona</span>}
                            </p>
                            {task.executors.length > 0 && (
                                <ul className="mt-2 grid gap-1 sm:grid-cols-2">
                                    {task.executors.map((person) => (
                                        <li key={person.id} className={person.done ? 'text-[#28a745]' : 'text-[#6c757d]'}>
                                            {person.done ? '✓' : '○'} {person.name} <span className="text-xs">({person.role})</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}

                    {full && (
                        <div className="grid grid-cols-2 gap-3">
                            <label className="grid gap-1 text-sm">
                                Status
                                <select className={field} value={status} onChange={(e) => setStatus(e.target.value)}>
                                    {statuses.map((item) => (
                                        <option key={item.value} value={item.value}>
                                            {item.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                Postęp: {status === 'done' ? 100 : progress}%
                                <input type="range" min={0} max={100} step={5} value={status === 'done' ? 100 : progress} disabled={status === 'done'} onChange={(e) => setProgress(Number(e.target.value))} />
                            </label>
                        </div>
                    )}

                    {full && (
                        <fieldset className="rounded border border-[#dee2e6] p-3 text-sm">
                            <legend className="px-1 text-sm">Dla kogo</legend>
                            {error('assignments')}
                            <input className={`${field} mb-2`} placeholder="Szukaj siedziby, placówki lub osoby…" value={search} onChange={(e) => setSearch(e.target.value)} />
                            <div className="grid max-h-56 gap-1 overflow-y-auto">
                                {targets.organization && (
                                    <label className="flex items-center gap-2 font-semibold">
                                        <input type="checkbox" checked={selected.has(key('organization', null, null))} onChange={() => toggle(key('organization', null, null))} />
                                        Cała organizacja
                                    </label>
                                )}
                                {targets.headquarters.filter((item) => matches(item.name)).map((item) => (
                                    <label key={`h${item.type}${item.id}`} className="flex items-center gap-2">
                                        <input type="checkbox" checked={selected.has(key('headquarters', item.type, item.id))} onChange={() => toggle(key('headquarters', item.type, item.id))} />
                                        Siedziba: {item.name} <span className="text-xs text-[#6c757d]">({item.type === 'shops' ? 'sklep' : 'hurtownia'})</span>
                                    </label>
                                ))}
                                {targets.branches.filter((item) => matches(item.name)).map((item) => (
                                    <label key={`b${item.type}${item.id}`} className="flex items-center gap-2">
                                        <input type="checkbox" checked={selected.has(key('branch', item.type, item.id))} onChange={() => toggle(key('branch', item.type, item.id))} />
                                        Placówka: {item.name} <span className="text-xs text-[#6c757d]">{hqName(item.type, item.headquarter_id)}</span>
                                    </label>
                                ))}
                                {targets.people.filter((item) => matches(item.name)).map((item) => (
                                    <label key={`u${item.id}`} className="flex items-center gap-2">
                                        <input type="checkbox" checked={selected.has(key('user', null, item.id))} onChange={() => toggle(key('user', null, item.id))} />
                                        {item.name} <span className="text-xs text-[#6c757d]">{item.role}</span>
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                    )}
                </div>

                <div className="flex items-center justify-between border-t border-[#dee2e6] px-4 py-3">
                    <div>
                        {editing && task.can_edit && (
                            <button type="button" onClick={remove} disabled={busy} className="rounded bg-[#dc3545] px-3 py-1.5 text-sm text-white disabled:opacity-60">
                                Usuń
                            </button>
                        )}
                    </div>
                    <div className="flex gap-2">
                        <button type="button" onClick={onClose} className="rounded border border-[#ced4da] px-3 py-1.5 text-sm">
                            {readOnly || (!canCreate && !editing) ? 'Zamknij' : 'Anuluj'}
                        </button>
                        {!readOnly && (
                            <button type="submit" disabled={busy} className="rounded bg-[#007bff] px-3 py-1.5 text-sm text-white disabled:opacity-60">
                                {!editing ? 'Dodaj zadanie' : full ? 'Zapisz' : task.is_milestone ? 'Zaakceptuj kamień milowy' : task.is_executor ? 'Zakończyłem swoją część' : 'Oznacz jako zakończone'}
                            </button>
                        )}
                    </div>
                </div>
            </form>
        </div>
    );
}
