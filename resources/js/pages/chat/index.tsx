import { Head } from '@inertiajs/react';
import { Card, Content, ContentHeader } from '@/components/adminlte/blocks';
import InvitationInbox from '@/components/trade/invitation-inbox';
import ChatBox from '@/components/chat/chat-box';
import { useChat } from '@/components/chat/use-chat';

function ChatPage({ organizationName }: { organizationName: string | null }) {
    const chat = useChat(true);

    return (
        <>
            <p className="mb-3 text-sm text-[#6c757d]">
                Możesz rozmawiać tylko z osobami z organizacji <strong>{organizationName}</strong>. Zielona kropka
                oznacza osobę aktywną w ciągu ostatnich dwóch minut.
            </p>
            <ChatBox chat={chat} variant="page" />
        </>
    );
}

/** Pełna strona czatu w stylu Messengera (ten sam czat co w okienku w rogu ekranu). */
export default function ChatIndex({
    canChat,
    organizationName,
}: {
    canChat: boolean;
    organizationName: string | null;
}) {
    return (
        <>
            <Head title="Czat" />
            <ContentHeader title="Czat" crumbs={[{ label: 'Panel', href: '/' }, { label: 'Czat' }]} />
            <Content>
                <InvitationInbox />
                {canChat ? (
                    <ChatPage organizationName={organizationName} />
                ) : (
                    <Card title="Czat" color="warning">
                        Czat jest dostępny dla kont przypisanych do organizacji. To konto nie należy do żadnej organizacji.
                    </Card>
                )}
            </Content>
        </>
    );
}
