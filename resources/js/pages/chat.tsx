import { ChatRightPanel } from '@/components/chat/chat-right-panel';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

/* ─── Types ─── */
type Message = {
    from: 'me' | 'them';
    text: string;
    time: string;
};

type Conversation = {
    id: string;
    name: string;
    ico: string;
    sub: string;
    tag: string;
    tagBg: string;
    tagColor: string;
    online: boolean;
    unread: number;
    msgs: Message[];
};

/* ─── Data (hardcoded, matching prototype) ─── */
const initialConversations: Conversation[] = [
    {
        id: 'sarah',
        name: 'Sarah K.',
        ico: '\u{1F1EC}\u{1F1E7}',
        sub: 'English \u2194 German',
        tag: 'Language',
        tagBg: '#EBF0FD',
        tagColor: '#1A4CD4',
        online: true,
        unread: 2,
        msgs: [
            { from: 'them', text: 'Hey! Are you free for a session this week?', time: '10:32' },
            { from: 'them', text: 'I can do Wednesday evening or Saturday morning \u{1F60A}', time: '10:33' },
            { from: 'me', text: 'Saturday works great for me! 10am?', time: '11:05' },
            { from: 'them', text: "Perfect, let's do it. Caf\u00E9 Schmitz again?", time: '11:12' },
        ],
    },
    {
        id: 'anna',
        name: 'Anna B.',
        ico: '\u{1F1E9}\u{1F1EA}',
        sub: 'German \u2194 English',
        tag: 'Language',
        tagBg: '#EBF0FD',
        tagColor: '#1A4CD4',
        online: false,
        unread: 0,
        msgs: [
            { from: 'me', text: 'Hallo Anna! Ich bin Anar aus Aserbaidschan.', time: 'Yesterday' },
            { from: 'them', text: 'Hallo Anar! Dein Deutsch ist schon gut \u{1F604}', time: 'Yesterday' },
            { from: 'me', text: 'Danke! I still have a lot to learn though.', time: 'Yesterday' },
            { from: 'them', text: 'We can practice together \u2014 when are you free?', time: 'Yesterday' },
        ],
    },
    {
        id: 'marco',
        name: 'Marco L.',
        ico: '\u{1F1EE}\u{1F1F9}',
        sub: 'Expat Mixer organiser',
        tag: 'Event',
        tagBg: '#FEF9EC',
        tagColor: '#C47D0E',
        online: false,
        unread: 1,
        msgs: [
            { from: 'them', text: 'Hi! Thanks for joining the International Expat Mixer \u{1F389}', time: '2 days ago' },
            { from: 'them', text: "We're planning the next one for April \u2014 any suggestions for venue?", time: '2 days ago' },
        ],
    },
    {
        id: 'lisa',
        name: 'Lisa M.',
        ico: '\u{1F1EB}\u{1F1F7}',
        sub: 'French \u2194 German',
        tag: 'Language',
        tagBg: '#EBF0FD',
        tagColor: '#1A4CD4',
        online: false,
        unread: 0,
        msgs: [
            { from: 'them', text: 'Bonjour! I saw your profile on the language exchange \u{1F1EB}\u{1F1F7}', time: '3 days ago' },
            { from: 'me', text: "Hi Lisa! I'd love to practice. What times work for you?", time: '3 days ago' },
        ],
    },
];

const autoReplies = ['That sounds great! \u{1F60A}', 'Perfect, see you then!', "I'll be there!", 'Sounds good to me \u{1F44D}', 'Great idea!'];

/* ─── Tabs ─── */
const tabs = [
    { id: 'all', label: 'All' },
    { id: 'language', label: 'Language' },
    { id: 'events', label: 'Events' },
];

/* ─── Helpers ─── */
function formatTime(): string {
    const now = new Date();
    return now.getHours() + ':' + String(now.getMinutes()).padStart(2, '0');
}

/* ─── Component ─── */
export default function Chat() {
    const [conversations, setConversations] = useState<Conversation[]>(() =>
        initialConversations.map((c) => ({ ...c, msgs: [...c.msgs] })),
    );
    const [activeTab, setActiveTab] = useState('all');
    const [activeThreadId, setActiveThreadId] = useState<string | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [inputValue, setInputValue] = useState('');
    const messagesRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    /* Scroll to bottom when messages change */
    useEffect(() => {
        if (messagesRef.current) {
            messagesRef.current.scrollTop = messagesRef.current.scrollHeight;
        }
    }, [activeThreadId, conversations]);

    /* Filter conversations */
    const filteredConversations = conversations.filter((c) => {
        const tagLower = c.tag.toLowerCase();
        const matchTab = activeTab === 'all' || tagLower === activeTab || tagLower + 's' === activeTab;
        const q = searchQuery.toLowerCase().trim();
        const matchQ =
            !q ||
            c.name.toLowerCase().includes(q) ||
            c.sub.toLowerCase().includes(q) ||
            c.msgs.some((m) => m.text.toLowerCase().includes(q));
        return matchTab && matchQ;
    });

    const activeConv = activeThreadId ? conversations.find((c) => c.id === activeThreadId) : null;

    /* Open thread */
    const openThread = useCallback(
        (id: string) => {
            setConversations((prev) => prev.map((c) => (c.id === id ? { ...c, unread: 0 } : c)));
            setActiveThreadId(id);
            setInputValue('');
            setTimeout(() => inputRef.current?.focus(), 50);
        },
        [],
    );

    /* Close thread */
    function closeThread() {
        setActiveThreadId(null);
    }

    /* Send message */
    function sendMessage() {
        const text = inputValue.trim();
        if (!text || !activeThreadId) return;

        const time = formatTime();
        setConversations((prev) =>
            prev.map((c) => (c.id === activeThreadId ? { ...c, msgs: [...c.msgs, { from: 'me', text, time }] } : c)),
        );
        setInputValue('');

        /* Auto-reply after 1.5s */
        const threadId = activeThreadId;
        setTimeout(() => {
            const reply = autoReplies[Math.floor(Math.random() * autoReplies.length)];
            setConversations((prev) =>
                prev.map((c) =>
                    c.id === threadId ? { ...c, msgs: [...c.msgs, { from: 'them', text: reply, time: formatTime() }] } : c,
                ),
            );
        }, 1500);
    }

    /* New chat toast */
    function handleNewChat() {
        /* Simple toast — matches prototype openChatNew() */
        alert('New conversation \u2014 connect via Language Exchange first');
    }

    /* Switch tab */
    function switchTab(tabId: string) {
        setActiveTab(tabId);
    }

    return (
        <AppLayout breadcrumbs={[{ title: 'Chat', href: '/chat' }]} rightPanel={<ChatRightPanel onOpenThread={openThread} />}>
            <Head title="Chat" />

            {/* ── Chat header ── */}
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    padding: '0 20px',
                    height: 57,
                    background: 'white',
                    borderBottom: '1px solid #E2DFD6',
                    position: 'sticky',
                    top: 0,
                    zIndex: 50,
                }}
                className="dark:border-[#3A3930] dark:bg-[#1E1D15]"
            >
                <div style={{ width: 32 }} />
                <span
                    style={{
                        fontFamily: "'Fraunces', serif",
                        fontSize: 20,
                        fontWeight: 500,
                        color: '#18170F',
                    }}
                    className="dark:text-[#F6F5F1]"
                >
                    Chats
                </span>
                <button
                    onClick={handleNewChat}
                    style={{
                        width: 32,
                        height: 32,
                        borderRadius: '50%',
                        background: 'transparent',
                        border: 'none',
                        color: '#1A4CD4',
                        fontSize: 26,
                        cursor: 'pointer',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                    }}
                    className="dark:text-[#5B8AF5]"
                >
                    +
                </button>
            </div>

            {/* ── Search bar ── */}
            <div
                style={{
                    padding: '10px 16px',
                    background: 'white',
                    borderBottom: '1px solid #E2DFD6',
                }}
                className="dark:border-[#3A3930] dark:bg-[#1E1D15]"
            >
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 8,
                        background: '#EFEDE7',
                        borderRadius: 12,
                        padding: '9px 14px',
                    }}
                    className="dark:bg-[#2A2920]"
                >
                    <span style={{ fontSize: 13, color: '#AAA89F' }}>{'\u{1F50D}'}</span>
                    <input
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        placeholder="Search\u2026"
                        style={{
                            flex: 1,
                            border: 'none',
                            background: 'transparent',
                            fontFamily: "Geist, sans-serif",
                            fontSize: 14,
                            outline: 'none',
                            color: '#18170F',
                        }}
                        className="placeholder:text-[#AAA89F] dark:text-[#F6F5F1]"
                    />
                    {searchQuery && (
                        <span
                            onClick={() => setSearchQuery('')}
                            style={{ fontSize: 13, color: '#AAA89F', cursor: 'pointer' }}
                        >
                            {'\u2715'}
                        </span>
                    )}
                </div>
            </div>

            {/* ── Inbox view ── */}
            {!activeThreadId && (
                <div>
                    {/* Tabs */}
                    <div
                        style={{
                            display: 'flex',
                            borderBottom: '1px solid #E2DFD6',
                            background: 'white',
                            position: 'sticky',
                            top: 57,
                            zIndex: 40,
                        }}
                        className="dark:border-[#3A3930] dark:bg-[#1E1D15]"
                    >
                        {tabs.map((t) => (
                            <button
                                key={t.id}
                                onClick={() => switchTab(t.id)}
                                style={{
                                    flex: 1,
                                    padding: 11,
                                    textAlign: 'center',
                                    fontSize: 12,
                                    fontWeight: 600,
                                    color: activeTab === t.id ? '#1A4CD4' : '#6B6860',
                                    cursor: 'pointer',
                                    borderBottom: `2px solid ${activeTab === t.id ? '#1A4CD4' : 'transparent'}`,
                                    transition: 'all .2s',
                                    background: 'transparent',
                                    borderTop: 'none',
                                    borderLeft: 'none',
                                    borderRight: 'none',
                                    fontFamily: "Geist, sans-serif",
                                }}
                                className={activeTab !== t.id ? 'hover:bg-[#EFEDE7] dark:hover:bg-[#2A2920]' : ''}
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>

                    {/* Conversation list */}
                    <div>
                        {filteredConversations.length === 0 ? (
                            <div style={{ padding: '32px 20px', textAlign: 'center', color: '#AAA89F', fontSize: 14 }}>
                                No conversations found
                            </div>
                        ) : (
                            filteredConversations.map((c) => {
                                const lastMsg = c.msgs[c.msgs.length - 1];
                                return (
                                    <div
                                        key={c.id}
                                        onClick={() => openThread(c.id)}
                                        className="hover:bg-[#EFEDE7] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:hover:bg-[#2A2920]"
                                        style={{
                                            display: 'flex',
                                            alignItems: 'center',
                                            gap: 12,
                                            padding: '14px 20px',
                                            borderBottom: '1px solid #E2DFD6',
                                            cursor: 'pointer',
                                            transition: 'background .15s',
                                            background: 'white',
                                        }}
                                    >
                                        {/* Avatar */}
                                        <div style={{ position: 'relative', flexShrink: 0 }}>
                                            <div
                                                style={{
                                                    width: 46,
                                                    height: 46,
                                                    borderRadius: '50%',
                                                    background: '#EFEDE7',
                                                    display: 'flex',
                                                    alignItems: 'center',
                                                    justifyContent: 'center',
                                                    fontSize: 22,
                                                }}
                                            >
                                                {c.ico}
                                            </div>
                                            {c.online && (
                                                <div
                                                    style={{
                                                        position: 'absolute',
                                                        bottom: 1,
                                                        right: 1,
                                                        width: 10,
                                                        height: 10,
                                                        borderRadius: '50%',
                                                        background: '#0A7C52',
                                                        border: '2px solid white',
                                                    }}
                                                />
                                            )}
                                        </div>

                                        {/* Body */}
                                        <div style={{ flex: 1, minWidth: 0 }}>
                                            <div
                                                style={{
                                                    display: 'flex',
                                                    alignItems: 'center',
                                                    gap: 6,
                                                    marginBottom: 3,
                                                }}
                                            >
                                                <span
                                                    style={{ fontSize: 14, fontWeight: 600, color: '#18170F' }}
                                                    className="dark:text-[#F6F5F1]"
                                                >
                                                    {c.name}
                                                </span>
                                                <span
                                                    style={{
                                                        fontSize: 10,
                                                        fontWeight: 600,
                                                        padding: '1px 7px',
                                                        borderRadius: 20,
                                                        background: c.tagBg,
                                                        color: c.tagColor,
                                                    }}
                                                >
                                                    {c.tag}
                                                </span>
                                            </div>
                                            <div
                                                style={{
                                                    fontSize: 12,
                                                    color: '#6B6860',
                                                    whiteSpace: 'nowrap',
                                                    overflow: 'hidden',
                                                    textOverflow: 'ellipsis',
                                                }}
                                            >
                                                {lastMsg.text}
                                            </div>
                                            <div style={{ fontSize: 11, color: '#AAA89F', marginTop: 2 }}>{c.sub}</div>
                                        </div>

                                        {/* Time + unread badge */}
                                        <div
                                            style={{
                                                display: 'flex',
                                                flexDirection: 'column',
                                                alignItems: 'flex-end',
                                                gap: 6,
                                                flexShrink: 0,
                                            }}
                                        >
                                            <span style={{ fontSize: 11, color: '#AAA89F' }}>{lastMsg.time}</span>
                                            {c.unread > 0 && (
                                                <span
                                                    style={{
                                                        width: 18,
                                                        height: 18,
                                                        borderRadius: '50%',
                                                        background: '#1A4CD4',
                                                        color: 'white',
                                                        fontSize: 10,
                                                        fontWeight: 700,
                                                        display: 'flex',
                                                        alignItems: 'center',
                                                        justifyContent: 'center',
                                                    }}
                                                >
                                                    {c.unread}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                );
                            })
                        )}
                    </div>
                </div>
            )}

            {/* ── Thread view ── */}
            {activeThreadId && activeConv && (
                <div style={{ display: 'flex', flex: 1, flexDirection: 'column' }}>
                    {/* Thread header */}
                    <div
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 12,
                            padding: '12px 20px',
                            borderBottom: '1px solid #E2DFD6',
                            background: 'white',
                            position: 'sticky',
                            top: 57,
                            zIndex: 40,
                        }}
                        className="dark:border-[#3A3930] dark:bg-[#1E1D15]"
                    >
                        {/* Back button */}
                        <button
                            onClick={closeThread}
                            style={{
                                width: 32,
                                height: 32,
                                borderRadius: '50%',
                                background: '#EFEDE7',
                                border: 'none',
                                fontSize: 16,
                                cursor: 'pointer',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                color: '#6B6860',
                            }}
                            className="dark:bg-[#2A2920] dark:text-[#AAA89F]"
                        >
                            {'\u2039'}
                        </button>

                        {/* Avatar */}
                        <div
                            style={{
                                width: 38,
                                height: 38,
                                borderRadius: '50%',
                                background: '#EBF0FD',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                fontSize: 18,
                                flexShrink: 0,
                            }}
                            className="dark:bg-[#1A2440]"
                        >
                            {activeConv.ico}
                        </div>

                        {/* Name + sub */}
                        <div style={{ flex: 1, minWidth: 0 }}>
                            <div style={{ fontSize: 14, fontWeight: 600 }} className="dark:text-[#F6F5F1]">
                                {activeConv.name}
                            </div>
                            <div style={{ fontSize: 11, color: '#AAA89F' }}>{activeConv.sub}</div>
                        </div>

                        {/* Tag badge */}
                        <span
                            style={{
                                fontSize: 10,
                                padding: '2px 8px',
                                borderRadius: 20,
                                fontWeight: 600,
                                background: activeConv.tagBg,
                                color: activeConv.tagColor,
                            }}
                        >
                            {activeConv.tag}
                        </span>
                    </div>

                    {/* Messages */}
                    <div
                        ref={messagesRef}
                        style={{
                            flex: 1,
                            overflowY: 'auto',
                            padding: '16px 20px',
                            display: 'flex',
                            flexDirection: 'column',
                            gap: 10,
                            paddingBottom: 80,
                        }}
                    >
                        {activeConv.msgs.map((m, i) => {
                            const isMe = m.from === 'me';
                            return (
                                <div
                                    key={i}
                                    style={{
                                        display: 'flex',
                                        flexDirection: 'column',
                                        alignItems: isMe ? 'flex-end' : 'flex-start',
                                        gap: 2,
                                    }}
                                >
                                    <div
                                        style={{
                                            maxWidth: '75%',
                                            padding: '10px 14px',
                                            borderRadius: isMe ? '16px 16px 4px 16px' : '16px 16px 16px 4px',
                                            background: isMe ? '#1A4CD4' : 'white',
                                            color: isMe ? 'white' : '#18170F',
                                            fontSize: 14,
                                            lineHeight: 1.4,
                                            border: isMe ? 'none' : '1px solid #E2DFD6',
                                        }}
                                        className={
                                            isMe
                                                ? 'dark:bg-[#5B8AF5] dark:text-[#18170F]'
                                                : 'dark:border-[#3A3930] dark:bg-[#1E1D15] dark:text-[#F6F5F1]'
                                        }
                                    >
                                        {m.text}
                                    </div>
                                    <span style={{ fontSize: 10, color: '#AAA89F', padding: '0 4px' }}>{m.time}</span>
                                </div>
                            );
                        })}
                    </div>

                    {/* Input bar */}
                    <div
                        style={{
                            position: 'sticky',
                            bottom: 0,
                            background: '#F6F5F1',
                            borderTop: '1px solid #E2DFD6',
                            padding: '12px 16px',
                            display: 'flex',
                            gap: 10,
                            alignItems: 'flex-end',
                        }}
                        className="dark:border-[#3A3930] dark:bg-[#18170F]"
                    >
                        <div
                            style={{
                                flex: 1,
                                background: 'white',
                                border: '1.5px solid #E2DFD6',
                                borderRadius: 20,
                                padding: '10px 16px',
                                display: 'flex',
                                alignItems: 'center',
                                gap: 8,
                            }}
                            className="dark:border-[#3A3930] dark:bg-[#1E1D15]"
                        >
                            <input
                                ref={inputRef}
                                value={inputValue}
                                onChange={(e) => setInputValue(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') sendMessage();
                                }}
                                placeholder="Message\u2026"
                                style={{
                                    flex: 1,
                                    border: 'none',
                                    background: 'transparent',
                                    fontFamily: "'Geist', sans-serif",
                                    fontSize: 14,
                                    outline: 'none',
                                    color: '#18170F',
                                }}
                                className="placeholder:text-[#AAA89F] dark:text-[#F6F5F1]"
                            />
                        </div>
                        <button
                            onClick={sendMessage}
                            style={{
                                width: 40,
                                height: 40,
                                borderRadius: '50%',
                                background: '#1A4CD4',
                                border: 'none',
                                color: 'white',
                                fontSize: 18,
                                cursor: 'pointer',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                flexShrink: 0,
                            }}
                            className="dark:bg-[#5B8AF5]"
                        >
                            {'\u2191'}
                        </button>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
