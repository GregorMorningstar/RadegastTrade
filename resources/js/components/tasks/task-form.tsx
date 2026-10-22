import { useState } from 'react';
import Barcode from 'react-barcode';
import type { FormEvent } from 'react';
import { ApiError, localDate, pad, tasksApi, type AssignmentInput, type Option, type Targets, type Task } from '@/lib/tasks';

type Props = {
    task: Task | null;
    /** Opens the form of a new task already set as a standing one (the "Dodaj zadanie stałe" button) */
    recurringDefault?: boolean;
    tasks: Task[];
    targets: Targets;
    priorities: Option[];
    statuses: Option[];
    canCreate: boolean;
    onSave: (data: Record<string, unknown>) => Promise<void>;
    /** The task was finished with the code from the e-mail */
    onCompleted: (task: Task) => void;
    onDelete: (task: Task) => Promise<void>;
    onClose: () => void;
};

const field = 'w-full rounded border border-[#ced4da] px-2 py-1.5 text-sm focus:border-[#80bdff] focus:outline-none';

const WEEKDAYS = [[1, 'Pn'], [2, 'Wt'], [3, 'Śr'], [4, 'Cz'], [5, 'Pt'], [6, 'So'], [7, 'Nd']] as const;

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
export function TaskForm({ task, recurringDefault, tasks, targets, priorities, statuses, canCreate, onSave, onCompleted, onDelete, onClose }: Props) {
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
    // a standing task: a period, the weekdays it repeats on and (optionally) the hours of each day
    const series = task?.recurrence ?? null;
    const [recurring, setRecurring] = useState(series !== null || (task === null && recurringDefault === true));
    const [recFrom, setRecFrom] = useState(series?.from ?? localDate(now));
    const [recTo, setRecTo] = useState(series?.to ?? localDate(new Date(now.getTime() + 27 * 86_400_000)));
    const [recDays, setRecDays] = useState<number[]>(series?.days ?? [1, 2, 3, 4, 5]);
    const [recHours, setRecHours] = useState(series !== null && series.start_time !== null);
    const [recStart, setRecStart] = useState(series?.start_time ?? '08:00');
    const [recEnd, setRecEnd] = useState(series?.end_time ?? '10:00');
    // the person who does the task confirms it with a code from their e-mail (off by default)
    const [needsCode, setNeedsCode] = useState(task?.requires_completion_code ?? false);
    const coded = editing && task.requires_completion_code;
    const [codeSent, setCodeSent] = useState(false);
    const [code, setCode] = useState('');
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
                  ...(recurring
                      ? { recurrence: { from: recFrom, to: recTo, days: [...recDays].sort(), start_time: recHours ? recStart : null, end_time: recHours ? recEnd : null } }
                      : {
                            // an edited standing task turned into an ordinary one drops its series
                            ...(task?.recurrence ? { recurrence: null } : {}),
                            is_milestone: milestone,
                            all_day: allDay,
                            starts_at: iso(startsAt),
                            ends_at: milestone ? iso(startsAt) : iso(endsAt),
                        }),
                  depends_on_id: dependsOn ? Number(dependsOn) : null,
                  assignments: assignments(),
                  requires_completion_code: needsCode,
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

    const sendCode = async () => {
        if (!task) {
            return;
        }

        setBusy(true);
        setMessage(null);

        try {
            await tasksApi.requestCompletionCode(task.id);
            setCodeSent(true);
            setCode('');
        } catch (error) {
            setMessage(error instanceof ApiError ? (error.errors.task?.[0] ?? error.message) : 'Nie udało się wysłać kodu.');
        } finally {
            setBusy(false);
        }
    };

    const confirmCode = async () => {
        if (!task) {
            return;
        }

        setBusy(true);
        setMessage(null);

        try {
            onCompleted(await tasksApi.complete(task.id, code));
        } catch (error) {
            setMessage(error instanceof ApiError ? (error.errors.code?.[0] ?? error.message) : 'Nie udało się potwierdzić wykonania.');
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
                    <h3 className="text-lg">{editing ? (full ? (task.recurrence ? 'Zadanie stałe' : 'Zadanie') : task.title) : recurring ? 'Nowe zadanie stałe' : 'Nowe zadanie'}</h3>
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
                                    Powiąż z innym zadaniem (opcjonalnie)
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
                                    <input
                                        type="checkbox"
                                        checked={milestone}
                                        onChange={(e) => {
                                            setMilestone(e.target.checked);

                                            if (e.target.checked) {
                                                setRecurring(false);
                                            }
                                        }}
                                    />
                                    Kamień milowy
                                </label>
                                <label className="flex items-center gap-2" title="Powtarza się w wybrane dni tygodnia w podanym okresie">
                                    <input type="checkbox" checked={recurring} disabled={milestone} onChange={(e) => setRecurring(e.target.checked)} />
                                    Zadanie stałe (powtarzane)
                                </label>
                                {!recurring && (
                                    <label className="flex items-center gap-2">
                                        <input type="checkbox" checked={allDay} onChange={(e) => changeAllDay(e.target.checked)} />
                                        Cały dzień
                                    </label>
                                )}
                            </div>
                            {recurring ? (
                                <fieldset className="grid gap-3 rounded border border-[#dee2e6] p-3 text-sm">
                                    <legend className="px-1">Powtarzanie</legend>
                                    <div className="grid grid-cols-2 gap-3">
                                        <label className="grid gap-1">
                                            Od dnia
                                            <input className={field} type="date" value={recFrom} onChange={(e) => setRecFrom(e.target.value)} required />
                                            {error('recurrence.from')}
                                        </label>
                                        <label className="grid gap-1">
                                            Do dnia
                                            <input className={field} type="date" value={recTo} min={recFrom} onChange={(e) => setRecTo(e.target.value)} required />
                                            {error('recurrence.to')}
                                        </label>
                                    </div>
                                    <div className="grid gap-1">
                                        <span>Dni tygodnia</span>
                                        <div className="flex flex-wrap gap-1">
                                            {WEEKDAYS.map(([day, name]) => (
                                                <button
                                                    key={day}
                                                    type="button"
                                                    aria-pressed={recDays.includes(day)}
                                                    onClick={() => setRecDays((current) => (current.includes(day) ? current.filter((item) => item !== day) : [...current, day]))}
                                                    className={`w-10 rounded border px-2 py-1 ${recDays.includes(day) ? 'border-[#007bff] bg-[#007bff] text-white' : 'border-[#ced4da] bg-white text-[#495057]'}`}
                                                >
                                                    {name}
                                                </button>
                                            ))}
                                        </div>
                                        <div className="flex gap-3 text-xs text-[#007bff]">
                                            <button type="button" className="hover:underline" onClick={() => setRecDays([1, 2, 3, 4, 5, 6, 7])}>
                                                codziennie
                                            </button>
                                            <button type="button" className="hover:underline" onClick={() => setRecDays([1, 2, 3, 4, 5])}>
                                                dni robocze
                                            </button>
                                            <button type="button" className="hover:underline" onClick={() => setRecDays([6, 7])}>
                                                weekend
                                            </button>
                                        </div>
                                        {error('recurrence.days')}
                                    </div>
                                    <div className="grid gap-2">
                                        <span>Czas w ciągu dnia</span>
                                        <label className="flex items-center gap-2">
                                            <input type="radio" name="rec-hours" checked={!recHours} onChange={() => setRecHours(false)} />
                                            Cały dzień (00:00–23:59)
                                        </label>
                                        <label className="flex items-center gap-2">
                                            <input type="radio" name="rec-hours" checked={recHours} onChange={() => setRecHours(true)} />
                                            Zakres godzin
                                        </label>
                                        {recHours && (
                                            <div className="grid grid-cols-2 gap-3">
                                                <label className="grid gap-1">
                                                    Od godziny
                                                    <input className={field} type="time" value={recStart} onChange={(e) => setRecStart(e.target.value)} required />
                                                    {error('recurrence.start_time')}
                                                </label>
                                                <label className="grid gap-1">
                                                    Do godziny
                                                    <input className={field} type="time" value={recEnd} onChange={(e) => setRecEnd(e.target.value)} required />
                                                    {error('recurrence.end_time')}
                                                </label>
                                            </div>
                                        )}
                                    </div>
                                </fieldset>
                            ) : (
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
                            )}
                        </>
                    ) : (
                        <div className="text-sm">
                            {task.description && <p className="mb-2 text-[#495057]">{task.description}</p>}
                            {task.recurrence && <p className="mb-2 text-[#495057]">Zadanie stałe: {task.recurrence.label}</p>}
                            <p className="text-[#6c757d]">
                                {task.priority_label} · {task.assignees.map((item) => item.label).join(', ')} · {task.created_by ? `dodał(a): ${task.created_by.name}` : ''}
                            </p>
                        </div>
                    )}

                    {task?.barcode && (
                        <div>
                            <p className="mb-1 text-xs text-[#6c757d]">Kod kreskowy zadania</p>
                            <Barcode value={task.barcode} format="EAN13" width={1.4} height={40} fontSize={11} margin={0} background="transparent" />
                        </div>
                    )}

                    {editing && task.recurrence && (
                        <div className="rounded border border-[#dee2e6] bg-[#f8f9fa] p-3 text-sm">
                            <p className="font-medium">{task.occurs_today ? 'Dziś jest dzień tego zadania.' : 'Dziś nie ma tego zadania.'}</p>
                            <p className="text-[#6c757d]">
                                Wykonałeś je w {task.done_dates.length} {task.done_dates.length === 1 ? 'dzień' : 'dni'}
                                {task.done_dates.length > 0 && `, ostatnio ${new Date(task.done_dates[task.done_dates.length - 1]).toLocaleDateString('pl-PL')}`}.
                            </p>
                        </div>
                    )}

                    {editing && task.executors_total > 0 && (
                        <div className="rounded border border-[#dee2e6] p-3 text-sm">
                            <p className="font-medium">
                                {task.recurrence ? 'Dziś wykonali' : 'Wykonali'}: {task.executors_done} z {task.executors_total}
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

                    {full && !recurring && (
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
                        <label className="flex items-center gap-2 text-sm" title="Wykonanie zadania zapisze się dopiero po wpisaniu kodu wysłanego na e-mail osoby, która je wykonuje">
                            <input type="checkbox" checked={needsCode} onChange={(e) => setNeedsCode(e.target.checked)} />
                            Wymagaj potwierdzenia wykonania kodem z e-maila (kod dostaje osoba wykonująca)
                        </label>
                    )}

                    {coded && task.can_complete && (
                        <div className="grid gap-2 rounded border border-[#dee2e6] bg-[#f8f9fa] p-3 text-sm">
                            <p className="font-medium">Wykonanie wymaga kodu z e-maila</p>
                            <p className="text-[#6c757d]">Kod dostaniesz na swój adres. Wpisz go, aby zapisać, że to Ty wykonałeś(-aś) {task.recurrence ? 'zadanie dzisiaj' : 'to zadanie'}.</p>
                            {codeSent && (
                                <input
                                    className={field}
                                    value={code}
                                    onChange={(e) => setCode(e.target.value.replace(/D/g, '').slice(0, 6))}
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    placeholder="000000"
                                    autoFocus
                                />
                            )}
                            <div className="flex gap-2">
                                <button type="button" disabled={busy} onClick={sendCode} className="rounded border border-[#ced4da] bg-white px-3 py-1.5 disabled:opacity-60">
                                    {codeSent ? 'Wyślij kod ponownie' : 'Wyślij kod na e-mail'}
                                </button>
                                {codeSent && (
                                    <button type="button" disabled={busy || code.length !== 6} onClick={confirmCode} className="rounded bg-[#28a745] px-3 py-1.5 text-white disabled:opacity-60">
                                        Potwierdź wykonanie
                                    </button>
                                )}
                            </div>
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
                        {editing && task.recurrence && task.can_complete && !coded && (
                            <button type="button" disabled={busy} onClick={() => onSave({ status: 'done' }).catch(() => undefined)} className="rounded bg-[#28a745] px-3 py-1.5 text-sm text-white disabled:opacity-60">
                                Wykonane dzisiaj
                            </button>
                        )}
                        {!readOnly && !(editing && !full && (task.recurrence || coded)) && (
                            <button type="submit" disabled={busy} className="rounded bg-[#007bff] px-3 py-1.5 text-sm text-white disabled:opacity-60">
                                {!editing ? (recurring ? 'Dodaj zadanie stałe' : 'Dodaj zadanie') : full ? 'Zapisz' : task.is_milestone ? 'Zaakceptuj kamień milowy' : task.is_executor ? 'Zakończyłem swoją część' : 'Oznacz jako zakończone'}
                            </button>
                        )}
                    </div>
                </div>
            </form>
        </div>
    );
}
