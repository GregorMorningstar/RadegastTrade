import type { EventDropArg } from '@fullcalendar/core';
import plLocale from '@fullcalendar/core/locales/pl';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin, { type EventResizeDoneArg } from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import FullCalendar from '@fullcalendar/react';
import timeGridPlugin from '@fullcalendar/timegrid';
import { localDate, priorityColors, type Task } from '@/lib/tasks';

type Props = {
    tasks: Task[];
    onOpen: (task: Task) => void;
    onReschedule: (task: Task, startsAt: string, endsAt: string, allDay: boolean) => Promise<void>;
};

/** Calendar of tasks: drag a block to move it, drag its edge to change the length (only tasks the user may change). */
export function TaskCalendar({ tasks, onOpen, onReschedule }: Props) {
    const byId = new Map(tasks.map((task) => [String(task.id), task]));

    const events = tasks.map((task) => ({
        id: String(task.id),
        title: (task.is_milestone ? '◆ ' : '') + task.title + (task.progress ? ` (${task.progress}%)` : ''),
        start: task.all_day ? task.starts_at.slice(0, 10) : task.starts_at,
        end: task.all_day ? nextDay(task.ends_at) : task.ends_at,
        allDay: task.all_day || task.is_milestone,
        editable: task.can_edit,
        backgroundColor: priorityColors[task.priority],
        borderColor: priorityColors[task.priority],
        classNames: task.status === 'done' ? ['opacity-60', 'line-through'] : [],
    }));

    const moved = async (info: EventDropArg | EventResizeDoneArg) => {
        const task = byId.get(info.event.id);
        const start = info.event.start;

        if (!task || !start) {
            info.revert();

            return;
        }

        const allDay = info.event.allDay;
        // FullCalendar's all-day end is exclusive; the API takes the last day
        const end = info.event.end ?? start;
        const lastDay = new Date(end);
        lastDay.setDate(lastDay.getDate() - (allDay && info.event.end ? 1 : 0));

        try {
            await onReschedule(
                task,
                allDay ? localDate(start) : start.toISOString(),
                allDay ? localDate(lastDay < start ? start : lastDay) : end.toISOString(),
                allDay,
            );
        } catch {
            info.revert();
        }
    };

    return (
        <FullCalendar
            plugins={[dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin]}
            locale={plLocale}
            initialView="dayGridMonth"
            headerToolbar={{ left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listMonth' }}
            height="auto"
            firstDay={1}
            nowIndicator
            dayMaxEvents={4}
            events={events}
            eventDrop={moved}
            eventResize={moved}
            eventClick={(info) => {
                const task = byId.get(info.event.id);

                if (task) {
                    onOpen(task);
                }
            }}
        />
    );
}

function nextDay(iso: string): string {
    const date = new Date(iso);
    date.setDate(date.getDate() + 1);

    return localDate(date);
}
