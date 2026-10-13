import { Link, router, usePage } from "@inertiajs/react";
import {
    Building2,
    ChevronRight,
    LayoutDashboard,
    LayoutGrid,
    LogOut,
    Menu,
    MessageCircle,
    Network,
    Store,
    Users,
    UserCircle,
    Warehouse,
    X,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { Fragment, useEffect, useState } from "react";
import type { PropsWithChildren } from "react";
import ChatWidget from "@/components/chat/chat-widget";
import UserAvatar from "@/components/user-avatar";
import { chatWindow, useChatWindowOpen } from "@/lib/chat-window";
import UserBarcode from "@/components/user-barcode";
import { cn } from "@/lib/utils";
import { logout } from "@/routes";
import { edit as editProfile } from "@/routes/profile";

type MenuItem = {
    title: string;
    href: string;
    icon: LucideIcon;
    /** Nagłówek sekcji wyświetlany nad pozycją (rozdzielenie, jak "Konto") */
    section?: string;
    /** Podmenu rozwijane pod pozycją */
    children?: { title: string; href: string }[];
    /** Zamiast przejścia na stronę otwiera okienko czatu nad aplikacją */
    action?: "chat";
};

type Props = PropsWithChildren<{
    /** Adres bazowy panelu roli; domyślnie panel zalogowanego użytkownika */
    base?: string;
    /** Nazwa panelu; domyślnie nazwa panelu zalogowanego użytkownika */
    brand?: string;
}>;

const STORAGE_KEY = "adminlte-sidebar-collapsed";

function readCollapsed(): boolean {
    try {
        return localStorage.getItem(STORAGE_KEY) === "1";
    } catch {
        return false;
    }
}

/**
 * Powłoka w stylu AdminLTE: ciemne menu z marką i panelem użytkownika, biały pasek
 * górny, treść z nagłówkiem oraz stopka. Menu zwija się z animacją do samych ikon.
 */
export default function AdminLteShell({
    base: baseProp,
    brand: brandProp,
    children,
}: Props) {
    const { url, props } = usePage();
    const [mobileOpen, setMobileOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(false);

    const base = baseProp ?? props.rolePanel?.base ?? "/";
    const brand = brandProp ?? props.rolePanel?.name ?? "";
    const userName = props.auth?.user?.name ?? "";
    const userAvatar = props.auth?.user?.avatar_url;
    const chatOpen = useChatWindowOpen();

    useEffect(() => setCollapsed(readCollapsed()), []);

    const toggleCollapsed = () => {
        setCollapsed((value) => {
            try {
                localStorage.setItem(STORAGE_KEY, value ? "0" : "1");
            } catch {
                // brak dostępu do localStorage – stan tylko na czas życia strony
            }

            return !value;
        });
    };

    // Hurtownie i sklepy z podmenu dostępne tylko w panelu administratora
    const isAdminPanel = base === "/platform/admin";
    const isOrganizationPanel = base === "/platform/organization";
    const isBranchLeaderPanel = base === "/shop/leader" || base === "/warehouse/leader";
    const isBranchWorkerPanel = base === "/shop/worker" || base === "/warehouse/worker";
    const isBranchManagerPanel = base === "/shop/owner" || base === "/warehouse/owner";

    const items: MenuItem[] = [
        {
            title: "Dashboard",
            href: `${base}/dashboard`,
            icon: LayoutDashboard,
        },
        { title: "Panel", href: base, icon: LayoutGrid },
        ...(isAdminPanel
            ? [
                  {
                      section: "Placówki",
                      title: "Organizacje",
                      href: `${base}/organizations`,
                      icon: Network,
                  },
                  {
                      title: "Hurtownie",
                      href: `${base}/warehouses`,
                      icon: Warehouse,
                      children: [
                          {
                              title: "Główne siedziby",
                              href: `${base}/warehouses/headquarters`,
                          },
                          {
                              title: "Placówki",
                              href: `${base}/warehouses/branches`,
                          },
                      ],
                  },
                  {
                      title: "Sklepy",
                      href: `${base}/shops`,
                      icon: Store,
                      children: [
                          {
                              title: "Główne siedziby",
                              href: `${base}/shops/headquarters`,
                          },
                          { title: "Placówki", href: `${base}/shops/branches` },
                      ],
                  },
              ]
            : []),
        // Panel właściciela organizacji: sklepy i hurtownie (na razie tylko widoki)
        ...(isOrganizationPanel
            ? [
                  {
                      section: "Struktura",
                      title: "Sklepy",
                      href: `${base}/shops`,
                      icon: Store,
                  },
                  {
                      title: "Hurtownie",
                      href: `${base}/warehouses`,
                      icon: Warehouse,
                  },
                  { title: "Zespół", href: `${base}/team`, icon: Users },
              ]
            : []),
        // Panel kierownika placówki: pracownicy i zaproszenia
        ...(isBranchLeaderPanel
            ? [
                  {
                      section: "Struktura",
                      title: "Pracownicy",
                      href: `${base}/workers`,
                      icon: Users,
                  },
                  { title: "Zespół", href: `${base}/team`, icon: Users },
              ]
            : []),
        // Panel pracownika placówki: zespół (kierownik i współpracownicy)
        ...(isBranchWorkerPanel
            ? [
                  {
                      section: "Struktura",
                      title: "Zespół",
                      href: `${base}/team`,
                      icon: Users,
                  },
              ]
            : []),
        // Panel kierownika siedziby sklepu lub hurtowni: placówki i zaproszenia ich kierowników
        ...(isBranchManagerPanel
            ? [
                  {
                      section: "Struktura",
                      title: "Placówki",
                      href: `${base}/branches`,
                      icon: Store,
                  },
                  { title: "Zespół", href: `${base}/team`, icon: Users },
              ]
            : []),
        // Czat z osobami z organizacji (dla każdej roli)
        {
            section: "Komunikacja",
            title: "Czat",
            href: "/chat",
            icon: MessageCircle,
            action: "chat" as const,
        },
    ];

    // Grupy podmenu: domyślnie otwarta ta, w której jest aktualna strona
    const [openGroups, setOpenGroups] = useState<Record<string, boolean>>({});

    const isGroupOpen = (item: MenuItem) =>
        openGroups[item.href] ?? url.split("?")[0].startsWith(item.href);

    const toggleGroup = (item: MenuItem) =>
        setOpenGroups((prev) => ({ ...prev, [item.href]: !isGroupOpen(item) }));

    const isActive = (href: string) => {
        const path = url.split("?")[0];

        return href === base ? path === base : path.startsWith(href);
    };

    // Napisy chowają się płynnie: zerowa szerokość i przezroczystość zamiast display:none
    const label = cn(
        "overflow-hidden whitespace-nowrap transition-all duration-300",
        collapsed ? "lg:max-w-0 lg:opacity-0" : "max-w-44 opacity-100",
    );

    const sectionHeading = cn(
        "px-4 pb-1 pt-4 text-xs font-semibold uppercase tracking-wide text-[#8a9097]",
        label,
    );

    const itemClass = (active: boolean) =>
        cn(
            "mx-2 mb-1 flex items-center gap-3 rounded px-3 py-2 text-sm text-[#c2c7d0] transition-colors hover:bg-white/10 hover:text-white",
            collapsed && "lg:justify-center lg:gap-0 lg:px-0",
            active && "bg-[#007bff] text-white shadow hover:bg-[#007bff]",
        );

    return (
        <div className="flex min-h-screen bg-[#f4f6f9] text-[#212529]">
            <aside
                className={cn(
                    "fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-[#343a40] shadow-lg transition-all duration-300 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0",
                    mobileOpen ? "translate-x-0" : "-translate-x-full",
                    collapsed ? "lg:w-[4.5rem]" : "lg:w-64",
                )}
            >
                {/* Marka */}
                <Link
                    href={`${base}/dashboard`}
                    className={cn(
                        "flex h-14 items-center gap-3 border-b border-[#4b545c] px-4 text-white",
                        collapsed && "lg:justify-center lg:gap-0 lg:px-0",
                    )}
                >
                    <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-white/90 text-sm font-bold text-[#343a40] shadow">
                        R
                    </span>
                    <span className={cn(label, "text-lg font-light")}>
                        Radegast Trade
                    </span>
                </Link>

                {/* Panel użytkownika: imię i nazwisko, a pod nim kod kreskowy */}
                <div className="border-b border-[#4b545c] px-4 py-3">
                    <div
                        className={cn(
                            "flex items-center gap-3",
                            collapsed && "lg:justify-center lg:gap-0",
                        )}
                    >
                        <UserAvatar src={userAvatar} name={userName} size={32} />
                        <span className={cn(label, "text-sm text-[#c2c7d0]")}>
                            {userName}
                        </span>
                    </div>
                    <div
                        className={cn(
                            "mt-3 flex justify-center rounded bg-white px-2 py-1.5",
                            collapsed && "lg:-mx-2 lg:px-0.5",
                        )}
                    >
                        <UserBarcode width={collapsed ? 0.5 : 1.3} />
                    </div>
                </div>

                <nav className="flex-1 overflow-y-auto py-2">
                    <p
                        className={cn(
                            "px-4 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-[#8a9097]",
                            label,
                        )}
                    >
                        {brand}
                    </p>
                    {items.map((item) => (
                        <Fragment key={item.href}>
                            {item.section && (
                                <p className={sectionHeading}>{item.section}</p>
                            )}
                            {item.children ? (
                                <div>
                                    <button
                                        type="button"
                                        title={item.title}
                                        onClick={() => {
                                            // zwinięte menu: rozwiń je, żeby pokazać podmenu
                                            if (collapsed) {
                                                toggleCollapsed();
                                            }

                                            toggleGroup(item);
                                        }}
                                        className={cn(
                                            itemClass(false),
                                            "w-[calc(100%-1rem)] text-left",
                                        )}
                                    >
                                        <item.icon className="size-4 shrink-0" />
                                        <span className={cn(label, "flex-1")}>
                                            {item.title}
                                        </span>
                                        <ChevronRight
                                            className={cn(
                                                "size-3.5 shrink-0 transition-transform duration-300",
                                                isGroupOpen(item) &&
                                                    "rotate-90",
                                                collapsed && "lg:hidden",
                                            )}
                                        />
                                    </button>

                                    {/* animowane rozwijanie: wysokość 0fr -> 1fr */}
                                    <div
                                        className={cn(
                                            "grid transition-all duration-300",
                                            isGroupOpen(item) && !collapsed
                                                ? "grid-rows-[1fr] opacity-100"
                                                : "grid-rows-[0fr] opacity-0",
                                        )}
                                    >
                                        <div className="overflow-hidden">
                                            {item.children.map((child) => (
                                                <Link
                                                    key={child.href}
                                                    href={child.href}
                                                    onClick={() =>
                                                        setMobileOpen(false)
                                                    }
                                                    className={cn(
                                                        itemClass(
                                                            isActive(
                                                                child.href,
                                                            ),
                                                        ),
                                                        "ml-6 gap-2 py-1.5",
                                                    )}
                                                >
                                                    <Building2 className="size-3.5 shrink-0" />
                                                    <span className="whitespace-nowrap">
                                                        {child.title}
                                                    </span>
                                                </Link>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            ) : item.action === "chat" ? (
                                <button
                                    key={item.href}
                                    type="button"
                                    title={item.title}
                                    onClick={() => {
                                        setMobileOpen(false);

                                        if (!props.canChat) {
                                            // konto bez organizacji: strona z informacją, okienka nie ma
                                            router.visit(item.href);
                                        } else if (!url.startsWith("/chat")) {
                                            chatWindow.toggle();
                                        }
                                    }}
                                    className={cn(
                                        itemClass(chatOpen || url.startsWith("/chat")),
                                        "w-full text-left",
                                    )}
                                >
                                    <item.icon className="size-4 shrink-0" />
                                    <span className={label}>{item.title}</span>
                                </button>
                            ) : (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    title={item.title}
                                    onClick={() => setMobileOpen(false)}
                                    className={itemClass(isActive(item.href))}
                                >
                                    <item.icon className="size-4 shrink-0" />
                                    <span className={label}>{item.title}</span>
                                </Link>
                            )}
                        </Fragment>
                    ))}

                    <p
                        className={cn(
                            "px-4 pb-1 pt-4 text-xs font-semibold uppercase tracking-wide text-[#8a9097]",
                            label,
                        )}
                    >
                        Konto
                    </p>
                    <Link
                        href={editProfile().url}
                        title="Profil"
                        onClick={() => setMobileOpen(false)}
                        className={itemClass(url.startsWith("/settings"))}
                    >
                        <UserCircle className="size-4 shrink-0" />
                        <span className={label}>Profil</span>
                    </Link>
                    <Link
                        href={logout()}
                        as="button"
                        title="Wyloguj"
                        className={cn(
                            itemClass(false),
                            "w-[calc(100%-1rem)] text-left",
                        )}
                    >
                        <LogOut className="size-4 shrink-0" />
                        <span className={label}>Wyloguj</span>
                    </Link>
                </nav>
            </aside>

            {mobileOpen && (
                <button
                    type="button"
                    aria-label="Zamknij menu"
                    className="fixed inset-0 z-30 bg-black/50 lg:hidden"
                    onClick={() => setMobileOpen(false)}
                />
            )}

            <div className="flex min-w-0 flex-1 flex-col">
                {/* Pasek górny */}
                <header className="flex h-14 items-center gap-4 border-b border-[#dee2e6] bg-white px-4">
                    <button
                        type="button"
                        className="text-[#6c757d] hover:text-[#212529] lg:hidden"
                        aria-label="Menu"
                        onClick={() => setMobileOpen((v) => !v)}
                    >
                        {mobileOpen ? (
                            <X className="size-5" />
                        ) : (
                            <Menu className="size-5" />
                        )}
                    </button>
                    <button
                        type="button"
                        className="hidden text-[#6c757d] hover:text-[#212529] lg:block"
                        aria-label={collapsed ? "Rozwiń menu" : "Zwiń menu"}
                        onClick={toggleCollapsed}
                    >
                        <Menu className="size-5" />
                    </button>
                    <nav className="hidden gap-4 text-sm text-[#6c757d] md:flex">
                        <Link
                            href={`${base}/dashboard`}
                            className="hover:text-[#212529]"
                        >
                            Dashboard
                        </Link>
                        <Link href={base} className="hover:text-[#212529]">
                            Panel
                        </Link>
                    </nav>
                    <span className="ml-auto flex items-center gap-2 text-sm text-[#6c757d]">
                        <UserAvatar src={userAvatar} name={userName} size={28} />
                        {userName}
                    </span>
                </header>

                <main className="flex-1">{children}</main>

                <ChatWidget />

                <footer className="flex flex-wrap justify-between gap-2 border-t border-[#dee2e6] bg-white px-4 py-3 text-sm text-[#869099]">
                    <span>
                        <strong>
                            Copyright &copy; {new Date().getFullYear()} Radegast
                            Trade.
                        </strong>{" "}
                        Wszelkie prawa zastrzeżone.
                    </span>
                    <span className="hidden sm:inline">
                        <strong>Panel</strong> {brand}
                    </span>
                </footer>
            </div>
        </div>
    );
}
