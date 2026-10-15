import { Head, usePage } from '@inertiajs/react';
import { ListTodo } from 'lucide-react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';

/** Tasks: the same (still empty) view for every role; each role has its own page file that renders it. */
export default function Tasks() {
    const base = usePage().props.rolePanel?.base ?? '/';

    return (
        <>
            <Head title="Zadania" />
            <ContentHeader title="Zadania" crumbs={[{ label: 'Panel', href: base }, { label: 'Zadania' }]} />
            <Content>
                <Card title="Zadania" color="primary">
                    <div className="flex flex-col items-center gap-2 py-8 text-center text-sm text-[#6c757d]">
                        <ListTodo className="size-8" />
                        Nie ma jeszcze żadnych zadań.
                    </div>
                </Card>
            </Content>
        </>
    );
}
