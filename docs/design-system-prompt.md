You are building Expadu — a smart city companion PWA for expats living in Germany.
Your first task is to scaffold the complete design system.

## What is Expadu
Expadu is a daily companion app for expats in Germany. It solves three problems in priority order:
1. Smart city navigation — better than KVB and similar apps. Real-time transit with intelligent
   routing based on events, disruptions, weather and user context
2. Local discovery — suggesting cafés to work from, events nearby, neighborhoods to explore,
   work spots based on time of day and user behaviour
3. Settling in — bureaucracy checklist, Bürgeramt slot alerts, document translator, visa guidance

The app is used daily, primarily on mobile. It should feel like a native app, not a website.
Target user: Non-EU professional, 25–40, recently moved to a German city, speaks English,
learning German, high stress around paperwork, wants community but doesn't know where to start.

## Brand
Name: Expadu
Domain: expadu.com
Tagline: "Your city. Your guide."
Personality: Warm, smart, reliable, local. Like a knowledgeable friend who lives in the city
and has been through everything you're going through.

## Tech Stack
- React 19 with TypeScript — all components use function components with hooks
- Vite 6 — asset bundling
- Tailwind CSS v4 — CSS-first config via @theme directive, no tailwind.config.js
- shadcn/ui — base component library (uses Radix UI primitives)
- TanStack Router — file-based routing
- TanStack Query — server state management
- Zustand — client/UI state management
- React Hook Form + Zod — all forms
- MapLibre GL JS — maps and transit visualization
- Laravel 12 API backend — all data via REST API + WebSockets

## Typography
- Display/headings: Fraunces (Google Fonts, serif) — page titles, hero text, card headlines
- UI/body: Geist (Vercel, sans-serif) — all interface text, buttons, labels, navigation
- Mono/data: Geist Mono — times, countdowns, distances, live departure numbers
  Load via @font-face in global CSS. Fraunces weights: 400, 500. Geist weights: 400, 500, 600, 700.

## Color System
Define all tokens in CSS via Tailwind v4 @theme directive:

@theme {
/* Backgrounds */
--color-bg: #F6F5F1;           /* warm off-white — page background */
--color-surface: #FFFFFF;      /* cards, sheets, modals, inputs */
--color-surface-2: #EFEDE7;    /* secondary surfaces, search inputs, avatar bg */
--color-border: #E2DFD6;       /* all borders, dividers, separators */

/* Text */
--color-text-1: #18170F;       /* primary text */
--color-text-2: #6B6860;       /* secondary text, subtitles */
--color-text-3: #AAA89F;       /* tertiary, placeholders, timestamps */

/* Accent — primary blue */
--color-accent: #1A4CD4;
--color-accent-soft: #EBF0FD;  /* blue tint backgrounds, active nav */
--color-accent-hover: #1541B8; /* button hover state */

/* Semantic colours */
--color-success: #0A7C52;
--color-success-soft: #EDFAF4;
--color-warn: #C47D0E;
--color-warn-soft: #FEF9EC;
--color-danger: #C4271A;
--color-danger-soft: #FDECEA;

/* Border radius */
--radius-sm: 8px;
--radius: 12px;
--radius-lg: 16px;
--radius-xl: 20px;
--radius-full: 9999px;

/* Spacing scale */
--spacing-1: 4px;
--spacing-2: 8px;
--spacing-3: 12px;
--spacing-4: 16px;
--spacing-5: 20px;
--spacing-6: 24px;
--spacing-8: 32px;
--spacing-10: 40px;
--spacing-12: 48px;

/* Shadows */
--shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
--shadow: 0 4px 16px rgba(0,0,0,0.08);
--shadow-lg: 0 8px 32px rgba(0,0,0,0.12);
--shadow-sheet: 0 -4px 32px rgba(0,0,0,0.10);

/* Z-index scale */
--z-base: 1;
--z-sticky: 40;
--z-overlay: 80;
--z-sheet: 90;
--z-modal: 100;
--z-toast: 110;
}

## Responsive Layout — Three Breakpoints
The app is mobile-first but works beautifully on desktop too.

Mobile (< 768px):
- Full screen content, no sidebar
- Sticky top bar: 56px height, white background, page title centred, action button right
- Floating pill dock at bottom: 5 items, safe area aware
- Bottom sheets slide up from bottom for detail views
- All cards are full width

Tablet (768px — 1280px):
- 68px icon rail sidebar (expands to 220px on hover)
- Main content area fills rest
- Right panel hidden below 960px, visible 960px–1280px at 280px
- No pill dock — sidebar handles navigation

Desktop (> 1280px):
- 260px full sidebar with labels
- Main content area max-width 680px centred
- 300px right panel with contextual info
- Sidebar has logo, nav groups, user chip at bottom

## Navigation Structure
Groups and items:
- Main: Home, Explore, Transit, Events
- Community: Language Exchange, Chat
- City: Neighborhoods, Services
- Settle: Bureaucracy, Just Arrived
- Account: Alerts, Profile

Mobile pill dock (5 items only): Home, Explore, Alerts, Profile, More
More → full-screen overlay menu showing all navigation
Active state: accent-soft background + accent-colored icon + blue dot below

## Page List
Home, Explore, Transit, Events, Language Exchange, Chat/Inbox,
Neighborhoods, Services, Bureaucracy, Just Arrived (onboarding),
Alerts, Profile, Welcome flow (5 screens), Settings

## Component Patterns — implement all of these

### Layout Components
AppLayout — three-column responsive shell, handles breakpoint switching
AppSidebar — full sidebar for desktop, icon rail for tablet
MobileDock — bottom pill navigation for mobile
MobileTopBar — sticky header with title and action slot
RightPanel — contextual sidebar content, desktop only

### Card Components
FeedSection — wrapper with title, optional action link, children
BlueHighlightCard — accent background, urgent item slot, headline, timeline rows
QuickAccessGrid — 2x2 or 3x2 grid of icon + label tiles
EventCard — title, emoji, date/time, location, attendee count, category tag
SpotCard — café/workspace with distance, noise level, wifi badge, crowd indicator
DepartureRow — line number badge, destination, platform, live countdown timer

### Sheet and Modal Components
BottomSheet — slides up from bottom, drag handle, overlay backdrop, drag-to-close,
mouse drag support for desktop, lock body scroll when open, smooth spring animation
PageSheet — full screen takeover sheet for detail views on mobile
ConfirmModal — title, description, confirm/cancel buttons, danger variant
ToastNotification — appears top-right desktop, top-centre mobile, auto-dismiss,
success/warn/danger/info variants

### Form Components
All forms use React Hook Form with Zod validation.
TextInput — label, input, error message, helper text, icon slot
SelectInput — dropdown with search, option groups
ToggleSwitch — 44x24px pill, animated knob, success colour when on
SettingRow — label left + value/toggle right + optional chevron, border-bottom separator
InlineEdit — Facebook-style: display row tap to expand input in place,
Save/Cancel buttons, one field open at a time, Escape to close
TagSelector — multi-select pill chips, toggle on/off, with emoji prefix option
ProgressBar — animated fill, label, percentage optional

### Navigation Components
NavItem — icon + label + optional badge, active/hover states
BreadcrumbBar — for desktop nested views
TabBar — horizontal tabs with active underline indicator, scrollable on mobile

### Data Display
StatChip — small pill with number + label (used in profile header stats)
CategoryTag — coloured pill tag for event/partner/alert types
LiveBadge — pulsing green dot + "Live" label for real-time data
CountdownTimer — ticking live departure timer, turns red under 2 minutes
OnlineIndicator — green dot on avatar, border matches surface colour
UnreadBadge — small circle with count, accent background

## Animation and Interaction Principles
- Bottom sheets: spring physics, not linear easing. Use CSS transitions with
  cubic-bezier(0.32, 1, 0.4, 1) for natural feel
- Navigation transitions: 200ms fade + slight vertical translate
- Card hover: subtle scale(1.01) + shadow increase on desktop
- Button press: scale(0.97) active state
- Skeleton loaders on all data-fetching states, never show empty containers
- Optimistic updates on all user actions (join event, complete task, send message)
- All touch targets minimum 44x44px

## PWA Requirements
- Service worker via vite-plugin-pwa (Workbox)
- Offline-first for transit data: cache last known departures, show stale indicator
- Background sync for checklist task completion when offline
- Web Push notifications via VAPID keys (Laravel backend)
- Install prompt handled gracefully, shown after 3rd visit
- App manifest: standalone display, theme-color matches --color-accent
- iOS safe area insets handled via env(safe-area-inset-*)

## File Structure to Create
src/
├── components/
│   ├── layout/          # AppLayout, AppSidebar, MobileDock, MobileTopBar, RightPanel
│   ├── cards/           # FeedSection, BlueHighlightCard, EventCard, SpotCard, DepartureRow
│   ├── sheets/          # BottomSheet, PageSheet, ConfirmModal, ToastNotification
│   ├── forms/           # TextInput, SelectInput, ToggleSwitch, SettingRow, InlineEdit
│   ├── navigation/      # NavItem, BreadcrumbBar, TabBar
│   └── display/         # StatChip, CategoryTag, LiveBadge, CountdownTimer, UnreadBadge
├── layouts/
│   ├── AppLayout.tsx
│   └── AuthLayout.tsx
├── pages/               # One file per page, matches route structure
├── composables/         # Custom hooks: useTransit, useAuth, useBottomSheet, useToast
├── stores/              # Zustand stores: ui.ts, auth.ts, transit.ts
├── services/
│   └── api.ts           # All API calls via axios, base URL from env
├── types/               # TypeScript interfaces mirroring Laravel API responses
├── lib/
│   └── utils.ts         # cn() helper, date formatters, distance formatters
└── router/
└── index.tsx        # TanStack Router route tree

## Coding Conventions — enforce strictly
- All components: function components with TypeScript, no class components
- Props: always typed with explicit interface, never use `any`
- Hooks: all custom hooks prefixed with `use`, live in /composables
- API calls: never inline — always go through /services/api.ts
- Styling: Tailwind classes only, no inline styles, no CSS modules
- State: server state via TanStack Query, UI state via Zustand, form state via RHF
- Every component file exports one default component
- No barrel index.ts files — import directly from component files
- All user-facing strings in English
- TypeScript strict mode enabled
