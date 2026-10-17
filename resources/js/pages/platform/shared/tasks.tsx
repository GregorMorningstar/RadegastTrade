import { Head, usePage } from '@inertiajs/react';
import { CalendarDays, GanttChart, Plus, Table2 } from 'lucide-react';
import { useState } from 'react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import { TaskCalendar } from '@/components/tasks/task-calendar';
import { TaskForm } from '@/components/tasks/task-form';
import { TaskGantt } from '@/components/tasks/task-gantt';
import { ApiError, formatDate, priorityColors, tasksApi, type Option, type Targets, type Task } from '@/lib/tasks';

type Props = {
    tasks: Task[];
    can_create: boolean;
    targets: Targets;
    priorities: Option[];
    statuses: Option[];
};

type View = 'calendar' | 'gantt' | 'table';

const views: { id: View; label: string; icon: typeof Table2 }[] = [
    { id: 'calendar', label: 'Kalendarz', icon: CalendarDays },
    { id: 'gantt', label: 'Wykres Gantta', icon: GanttChart },
    { id: 'table', label: 'Tabela', icon: Table2 },
];

/** Tasks: calendar, Gantt chart and table over the same list; the same view for every role (the server decides what each one sees). */
export default function Tasks({ tasks: initial, can_create, targets, priorities, statuses }: Props) {
    const base = usePage().props.rolePanel?.base ?? '/';
    const [tasks, setTasks] = useState<Task[]>(initial);
    const [view, setView] = useState<View>('calendar');
    const [form, setForm] = useState<{ task: Task | null } | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    const replace = (saved: Task) => setTasks((current) => (current.some((item) => item.id === saved.id) ? current.map((item) => (item.id === saved.id ? saved : item)) : [...current, saved]));

    // Moving in time: the view shows the new place at once (the API answers with the stored task); on failure it goes back
    const reschedule = async (task: Task, startsAt: string, endsAt: string, allDay: boolean) => {
        setNotice(null);

        try {
            replace(await tasksApi.reschedule(task.id, { starts_at: startsAt, ends_at: endsAt, all_day: allDay }));
        } catch (error) {
            setNotice(error instanceof ApiError ? error.message : 'Nie udało się zmienić terminu.');

            throw error;
        }
    };

    // Linking in the Gantt chart: the target task waits for the source one
    const linkTasks = async (source: Task, target: Task) => {
        setNotice(null);

        try {
            replace(await tasksApi.update(target.id, { depends_on_id: source.id }));
        } catch (error) {
            setNotice(error instanceof ApiError ? (error.errors.depends_on_id?.[0] ?? error.message) : 'Nie udało się powiązać zadań.');

            throw error;
        }
    };

    const unlinkTask = async (task: Task) => {
        setNotice(null);

        try {
            replace(await tasksApi.update(task.id, { depends_on_id: null }));
        } catch (error) {
            setNotice(error instanceof ApiError ? error.message : 'Nie udało się usunąć powiązania.');
        }
    };

    const save = async (data: Record<string, unknown>) => {
        const saved = form?.task ? await tasksApi.update(form.task.id, data) : await tasksApi.create(data);
        replace(saved);
        setForm(null);
    };

    const remove = async (task: Task) => {
        await tasksApi.remove(task.id);
        setTasks((current) => current.filter((item) => item.id !== task.id).map((item) => (item.depends_on_id === task.id ? { ...item, depends_on_id: null, depends_on_title: null } : item)));
        setForm(null);
    };

    const open = (task: Task) => setForm({ task });
    const sorted = [...tasks].sort((a, b) => a.starts_at.localeCompare(b.starts_at) || a.id - b.id);

    return (
        <>
            <Head title="Zadania" />
            <ContentHeader title="Zadania" crumbs={[{ label: 'Panel', href: base }, { label: 'Zadania' }]} />
            <Content>
                <Card title="Zadania" color="primary">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div className="inline-flex overflow-hidden rounded border border-[#ced4da]">
                            {views.map(({ id, label, icon: Icon }) => (
                                <button key={id} type="button" onClick={() => setView(id)} className={`flex items-center gap-1.5 px-3 py-1.5 text-sm ${view === id ? 'bg-[#007bff] text-white' : 'bg-white hover:bg-[#f8f9fa]'}`}>
                                    <Icon className="size-4" />
                                    {label}
                                </button>
                            ))}
                        </div>
                        <div className="flex flex-wrap items-center gap-3 text-xs text-[#6c757d]">
                            {priorities.map((item) => (
                                <span key={item.value} className="flex items-center gap-1">
                                    <span className="size-2.5 rounded-sm" style={{ background: priorityColors[item.value as Task['priority']] }} />
                                    {item.label}
                                </span>
                            ))}
                            <span>◆ kamień milowy</span>
                        </div>
                        {can_create && (
                            <button type="button" onClick={() => setForm({ task: null })} className="flex items-center gap-1.5 rounded bg-[#28a745] px-3 py-1.5 text-sm text-white">
                                <Plus className="size-4" />
                                Dodaj zadanie
                            </button>
                        )}
                    </div>

                    {notice && <div className="mb-3 rounded bg-[#f8d7da] px-3 py-2 text-sm text-[#721c24]">{notice}</div>}

                    {view === 'calendar' && <TaskCalendar tasks={tasks} onOpen={open} onReschedule={reschedule} />}
                    {view === 'gantt' && <TaskGantt tasks={tasks} onOpen={open} onReschedule={reschedule} onLink={linkTasks} onUnlink={unlinkTask} />}
                    {view === 'table' && <TaskTable tasks={sorted} onOpen={open} />}
                </Card>
            </Content>

            {form && <TaskForm task={form.task} tasks={tasks} targets={targets} priorities={priorities} statuses={statuses} canCreate={can_create} onSave={save} onDelete={remove} onClose={() => setForm(null)} />}
        </>
    );
}

function TaskTable({ tasks, onOpen }: { tasks: Task[]; onOpen: (task: Task) => void }) {
    if (tasks.length === 0) {
        return <p className="py-8 text-center text-sm text-[#6c757d]">Nie ma jeszcze żadnych zadań.</p>;
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
                <thead>
                    <tr className="border-b border-[#dee2e6] text-xs text-[#6c757d] uppercase">
                        <th className="px-2 py-2">Zadanie</th>
                        <th className="px-2 py-2">Priorytet</th>
                        <th className="px-2 py-2">Status</th>
                        <th className="px-2 py-2">Termin</th>
                        <th className="px-2 py-2">Dla kogo</th>
                        <th className="px-2 py-2">Postęp</th>
                    </tr>
                </thead>
                <tbody>
                    {tasks.map((task) => (
                        <tr key={task.id} onClick={() => onOpen(task)} className="cursor-pointer border-b border-[#f1f3f5] hover:bg-[#f8f9fa]">
                            <td className="px-2 py-2 font-medium">
                                {task.is_milestone && <span title="Kamień milowy">◆ </span>}
                                {task.title}
                                {task.depends_on_title && <div className="text-xs font-normal text-[#6c757d]">po: {task.depends_on_title}</div>}
                            </td>
                            <td className="px-2 py-2">
                                <span className="rounded px-2 py-0.5 text-xs text-white" style={{ background: priorityColors[task.priority] }}>
                                    {task.priority_label}
                                </span>
                            </td>
                            <td className="px-2 py-2">{task.status_label}</td>
                            <td className="px-2 py-2 whitespace-nowrap">
                                {formatDate(task.starts_at, task.all_day)}
                                {!task.is_milestone && <> – {formatDate(task.ends_at, task.all_day)}</>}
                            </td>
                            <td className="px-2 py-2">{task.assignees.map((item) => item.label).join(', ')}</td>
                            <td className="px-2 py-2">
                                <div className="h-2 w-24 overflow-hidden rounded bg-[#e9ecef]">
                                    <div className="h-full bg-[#28a745]" style={{ width: `${task.progress}%` }} />
                                </div>
                                <span className="text-xs text-[#6c757d]">
                                    {task.progress}%{task.executors_total > 1 && ` · ${task.executors_done}/${task.executors_total} osób`}
                                </span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
