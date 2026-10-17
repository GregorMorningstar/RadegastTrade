import { useMemo, useRef, useState } from 'react';
import type { PointerEvent } from 'react';
import { priorityColors, type Task } from '@/lib/tasks';

const DAY = 36;
const ROW = 40;
const LABEL = 220;
const HEADER = 56;
const DAY_MS = 86_400_000;

type Props = {
    tasks: Task[];
    onOpen: (task: Task) => void;
    onReschedule: (task: Task, startsAt: string, endsAt: string, allDay: boolean) => Promise<void>;
    /** Makes `target` wait for `source` (dragging from the dot at the end of a bar onto another bar). */
    onLink: (source: Task, target: Task) => Promise<void>;
    /** Removes the dependency of the task (clicking its arrow). */
    onUnlink: (task: Task) => Promise<void>;
};

type Link = { id: number; x: number; y: number };

type Drag = { id: number; mode: 'move' | 'start' | 'end'; originX: number; shift: number };

function startOfDay(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function shifted(iso: string, days: number): Date {
    const date = new Date(iso);
    date.setDate(date.getDate() + days);

    return date;
}

/** Gantt-style chart: bars with progress, milestones as diamonds, dependency arrows and a red "today" line. */
export function TaskGantt({ tasks, onOpen, onReschedule, onLink, onUnlink }: Props) {
    const [drag, setDrag] = useState<Drag | null>(null);
    const dragRef = useRef<Drag | null>(null);
    const [link, setLink] = useState<Link | null>(null);
    const linkRef = useRef<Link | null>(null);
    const plot = useRef<HTMLDivElement>(null);

    const sorted = useMemo(() => [...tasks].sort((a, b) => a.starts_at.localeCompare(b.starts_at) || a.id - b.id), [tasks]);
    const today = startOfDay(new Date());

    const { origin, days } = useMemo(() => {
        const starts = sorted.map((task) => startOfDay(new Date(task.starts_at)).getTime());
        const ends = sorted.map((task) => startOfDay(new Date(task.ends_at)).getTime());
        const nowTime = startOfDay(new Date()).getTime();
        const first = Math.min(nowTime, ...starts) - 3 * DAY_MS;
        const last = Math.max(nowTime, ...ends) + 10 * DAY_MS;

        return { origin: first, days: Math.max(30, Math.round((last - first) / DAY_MS)) };
    }, [sorted]);

    const dayIndex = (value: string | Date) => Math.round((startOfDay(new Date(value)).getTime() - origin) / DAY_MS);

    const geometry = (task: Task) => {
        let start = dayIndex(task.starts_at);
        let end = task.is_milestone ? start : dayIndex(task.ends_at);

        if (drag && drag.id === task.id) {
            if (drag.mode === 'move') {
                start += drag.shift;
                end += drag.shift;
            } else if (drag.mode === 'start') {
                start = Math.min(start + drag.shift, end);
            } else {
                end = Math.max(end + drag.shift, start);
            }
        }

        return { left: start * DAY, width: task.is_milestone ? DAY : (end - start + 1) * DAY };
    };

    const begin = (event: PointerEvent, task: Task, mode: Drag['mode']) => {
        if (!task.can_edit) {
            return;
        }

        event.stopPropagation();
        event.currentTarget.setPointerCapture(event.pointerId);
        dragRef.current = { id: task.id, mode: task.is_milestone ? 'move' : mode, originX: event.clientX, shift: 0 };
        setDrag(dragRef.current);
    };

    const plotPoint = (event: PointerEvent) => {
        const box = plot.current?.getBoundingClientRect();

        return { x: event.clientX - (box?.left ?? 0), y: event.clientY - (box?.top ?? 0) };
    };

    const beginLink = (event: PointerEvent, task: Task) => {
        event.stopPropagation();
        event.currentTarget.setPointerCapture(event.pointerId);
        linkRef.current = { id: task.id, ...plotPoint(event) };
        setLink(linkRef.current);
    };

    const finishLink = async (event: PointerEvent, source: Task) => {
        event.stopPropagation();
        const current = linkRef.current;
        linkRef.current = null;
        setLink(null);

        if (!current) {
            return;
        }

        const target = sorted[Math.floor(plotPoint(event).y / ROW)];

        if (target && target.id !== source.id) {
            try {
                await onLink(source, target);
            } catch {
                // the page reports the error
            }
        }
    };

    const track = (event: PointerEvent) => {
        if (linkRef.current) {
            linkRef.current = { ...linkRef.current, ...plotPoint(event) };
            setLink(linkRef.current);

            return;
        }

        if (!dragRef.current) {
            return;
        }

        dragRef.current = { ...dragRef.current, shift: Math.round((event.clientX - dragRef.current.originX) / DAY) };
        setDrag(dragRef.current);
    };

    const finish = async (event: PointerEvent, task: Task) => {
        event.stopPropagation();
        const current = dragRef.current;
        dragRef.current = null;
        setDrag(null);

        if (!current || current.id !== task.id) {
            return;
        }

        if (current.shift === 0) {
            onOpen(task);

            return;
        }

        const start = shifted(task.starts_at, current.mode === 'end' ? 0 : current.shift);
        const end = shifted(task.ends_at, current.mode === 'start' ? 0 : current.shift);

        if (end < start) {
            return;
        }

        try {
            await onReschedule(task, start.toISOString(), end.toISOString(), task.all_day);
        } catch {
            // the page reports the error; the bar returns to its place
        }
    };

    if (sorted.length === 0) {
        return <p className="py-8 text-center text-sm text-[#6c757d]">Nie ma jeszcze żadnych zadań.</p>;
    }

    const rowOf = new Map(sorted.map((task, index) => [task.id, index]));
    const width = days * DAY;
    const months: { label: string; left: number; width: number }[] = [];

    for (let i = 0; i < days; i++) {
        const label = new Date(origin + i * DAY_MS).toLocaleDateString('pl-PL', { month: 'long', year: 'numeric' });
        const last = months[months.length - 1];

        if (last && last.label === label) {
            last.width += DAY;
        } else {
            months.push({ label, left: i * DAY, width: DAY });
        }
    }

    return (
        <div className="overflow-x-auto rounded border border-[#dee2e6]" onPointerMove={track}>
            <div className="relative" style={{ width: width + LABEL }}>
                <div className="flex border-b border-[#dee2e6] bg-[#f8f9fa]" style={{ height: HEADER }}>
                    <div className="sticky left-0 z-20 shrink-0 border-r border-[#dee2e6] bg-[#f8f9fa] px-2 py-1 text-xs font-semibold" style={{ width: LABEL }}>
                        Zadanie
                    </div>
                    <div className="relative" style={{ width }}>
                        {months.map((month) => (
                            <div key={month.left} className="absolute top-0 truncate border-l border-[#dee2e6] px-1 text-xs font-semibold capitalize" style={{ left: month.left, width: month.width, height: 24 }}>
                                {month.label}
                            </div>
                        ))}
                        {Array.from({ length: days }, (_, i) => {
                            const weekday = new Date(origin + i * DAY_MS).getDay();

                            return (
                                <div
                                    key={i}
                                    className={`absolute flex items-center justify-center border-l border-[#e9ecef] text-[10px] ${weekday === 0 || weekday === 6 ? 'text-[#dc3545]' : 'text-[#6c757d]'}`}
                                    style={{ left: i * DAY, width: DAY, top: 24, height: 32 }}
                                >
                                    {new Date(origin + i * DAY_MS).getDate()}
                                </div>
                            );
                        })}
                    </div>
                </div>

                <div className="relative flex" style={{ height: sorted.length * ROW }}>
                    <div className="sticky left-0 z-20 shrink-0 border-r border-[#dee2e6] bg-white" style={{ width: LABEL }}>
                        {sorted.map((task) => (
                            <button key={task.id} type="button" onClick={() => onOpen(task)} className="block w-full truncate border-b border-[#f1f3f5] px-2 text-left text-sm hover:bg-[#f8f9fa]" style={{ height: ROW }} title={task.title}>
                                {task.is_milestone ? '◆ ' : ''}
                                {task.title}
                            </button>
                        ))}
                    </div>

                    <div ref={plot} className="relative" style={{ width }}>
                        {Array.from({ length: days }, (_, i) => {
                            const weekday = new Date(origin + i * DAY_MS).getDay();

                            return <div key={i} className={`absolute top-0 bottom-0 border-l border-[#f1f3f5] ${weekday === 0 || weekday === 6 ? 'bg-[#f8f9fa]' : ''}`} style={{ left: i * DAY, width: DAY }} />;
                        })}
                        {sorted.map((task, index) => (
                            <div key={task.id} className="absolute right-0 left-0 border-b border-[#f1f3f5]" style={{ top: index * ROW, height: ROW }} />
                        ))}

                        <div className="absolute top-0 bottom-0 z-[5] w-px bg-[#dc3545]" style={{ left: dayIndex(today) * DAY + DAY / 2 }} title="Dziś" />

                        <svg className="pointer-events-none absolute inset-0 z-[8]" width={width} height={sorted.length * ROW}>
                            <defs>
                                <marker id="gantt-arrow" markerWidth="6" markerHeight="6" refX="5" refY="3" orient="auto">
                                    <path d="M0,0 L6,3 L0,6 z" fill="#6c757d" />
                                </marker>
                            </defs>
                            {sorted.map((task) => {
                                const from = task.depends_on_id !== null ? rowOf.get(task.depends_on_id) : undefined;

                                if (from === undefined) {
                                    return null;
                                }

                                const parent = sorted[from];
                                const a = geometry(parent);
                                const b = geometry(task);
                                const x1 = a.left + a.width - (parent.is_milestone ? DAY / 4 : 0);
                                const y1 = from * ROW + ROW / 2;
                                const x2 = b.left + (task.is_milestone ? DAY / 4 : 0);
                                const y2 = (rowOf.get(task.id) ?? 0) * ROW + ROW / 2;
                                const mid = Math.max(x1 + 8, x2 - 8);

                                const d = `M${x1},${y1} H${mid} V${y2} H${x2}`;

                                return (
                                    <g key={task.id}>
                                        <path d={d} fill="none" stroke="#6c757d" strokeWidth="1.5" markerEnd="url(#gantt-arrow)" />
                                        {task.can_edit && (
                                            <path
                                                d={d}
                                                fill="none"
                                                stroke="transparent"
                                                strokeWidth="10"
                                                className="cursor-pointer"
                                                style={{ pointerEvents: 'stroke' }}
                                                onClick={() => window.confirm('Usunąć powiązanie z poprzednim zadaniem?') && onUnlink(task)}
                                            >
                                                <title>Kliknij, aby usunąć powiązanie</title>
                                            </path>
                                        )}
                                    </g>
                                );
                            })}
                            {link && (() => {
                                const from = rowOf.get(link.id);
                                const source = from === undefined ? null : sorted[from];

                                if (!source || from === undefined) {
                                    return null;
                                }

                                const a = geometry(source);

                                return <path d={`M${a.left + a.width},${from * ROW + ROW / 2} L${link.x},${link.y}`} fill="none" stroke="#007bff" strokeWidth="2" strokeDasharray="4 3" />;
                            })()}
                        </svg>

                        {sorted.map((task, index) => {
                            const g = geometry(task);
                            const color = priorityColors[task.priority];
                            const cursor = task.can_edit ? 'cursor-grab' : 'cursor-pointer';

                            return (
                                <div key={task.id} className="absolute z-[7] flex items-center" style={{ left: g.left, top: index * ROW + 7, height: ROW - 14, width: g.width }}>
                                    {task.is_milestone ? (
                                        <div
                                            onPointerDown={(event) => begin(event, task, 'move')}
                                            onPointerUp={(event) => finish(event, task)}
                                            onClick={() => !task.can_edit && onOpen(task)}
                                            className={`mx-auto size-5 rotate-45 touch-none ${cursor}`}
                                            style={{ background: color }}
                                            title={task.title}
                                        />
                                    ) : (
                                        <div
                                            onPointerDown={(event) => begin(event, task, 'move')}
                                            onPointerUp={(event) => finish(event, task)}
                                            onClick={() => !task.can_edit && onOpen(task)}
                                            className={`relative h-full w-full touch-none overflow-hidden rounded ${cursor}`}
                                            style={{ background: color + '55', border: `1px solid ${color}` }}
                                            title={`${task.title} (${task.progress}%)`}
                                        >
                                            <div className="absolute inset-y-0 left-0" style={{ width: `${task.progress}%`, background: color }} />
                                            {task.can_edit && (
                                                <>
                                                    <span className="absolute inset-y-0 left-0 w-2 cursor-ew-resize" onPointerDown={(event) => begin(event, task, 'start')} onPointerUp={(event) => finish(event, task)} />
                                                    <span className="absolute inset-y-0 right-0 w-2 cursor-ew-resize" onPointerDown={(event) => begin(event, task, 'end')} onPointerUp={(event) => finish(event, task)} />
                                                </>
                                            )}
                                        </div>
                                    )}
                                    {task.can_edit && (
                                        <span
                                            onPointerDown={(event) => beginLink(event, task)}
                                            onPointerUp={(event) => finishLink(event, task)}
                                            className="absolute top-1/2 -right-2.5 z-[9] size-3 -translate-y-1/2 cursor-crosshair touch-none rounded-full border-2 border-[#007bff] bg-white opacity-60 hover:opacity-100"
                                            title="Przeciągnij na inne zadanie, aby je powiązać"
                                        />
                                    )}
                                    <span className="pointer-events-none absolute left-full ml-4 text-xs whitespace-nowrap text-[#495057]">
                                        {task.title}
                                        {task.progress > 0 && !task.is_milestone ? ` ${task.progress}%` : ''}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
        </div>
    );
}
