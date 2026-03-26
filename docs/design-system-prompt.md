# Expadu — Design System

> Smart city companion PWA for expats living in Germany.

---

## Brand

| Field       | Value                                                                 |
|-------------|-----------------------------------------------------------------------|
| Name        | Expadu                                                                |
| Domain      | expadu.com                                                            |
| Tagline     | "Your city. Your guide."                                              |
| Personality | Warm, smart, reliable, local — like a knowledgeable friend who lives in the city and has been through everything you're going through. |

---

## Tech Stack

| Layer          | Technology                                                       |
|----------------|------------------------------------------------------------------|
| Framework      | React 19 + TypeScript (function components with hooks)           |
| Bundler        | Vite 6                                                           |
| CSS            | Tailwind CSS v4 (CSS-first config via `@theme`, no `tailwind.config.js`) |
| Components     | shadcn/ui (Radix UI primitives)                                  |
| Routing        | TanStack Router (file-based)                                     |
| Server state   | TanStack Query                                                   |
| Client state   | Zustand                                                          |
| Forms          | React Hook Form + Zod                                            |
| Maps           | MapLibre GL JS                                                   |
| Backend        | Laravel 12 API (REST + WebSockets)                               |

---

## Typography

| Role             | Font         | Source       | Weights          | Usage                                      |
|------------------|--------------|--------------|------------------|---------------------------------------------|
| Display/headings | Fraunces     | Google Fonts | 400, 500         | Page titles, hero text, card headlines      |
| UI/body          | Geist        | Vercel       | 400, 500, 600, 700 | Interface text, buttons, labels, navigation |
| Mono/data        | Geist Mono   | Vercel       | —                | Times, countdowns, distances, live numbers  |

Load all fonts via `@font-face` in global CSS.

---

## Color System

All tokens defined in CSS via Tailwind v4 `@theme` directive:

```css
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

  /* Semantic */
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
```

---

## Responsive Layout

The app is mobile-first but works beautifully on desktop.

### Mobile (`< 768px`)

- Full-screen content, no sidebar
- **Top bar:** 56px height, white background, page title centered, action button right
- **Bottom dock:** floating pill with 5 items, safe-area aware
- Bottom sheets slide up for detail views
- All cards are full width

### Tablet (`768px – 1280px`)

- **Sidebar:** 68px icon rail (expands to 220px on hover)
- Main content fills remaining space
- Right panel: hidden below 960px, visible at 280px between 960–1280px
- No pill dock — sidebar handles navigation

### Desktop (`> 1280px`)

- **Sidebar:** 260px full sidebar with labels
- **Main content:** max-width 680px, centered
- **Right panel:** 300px with contextual info
- Sidebar has logo, nav groups, user chip at bottom

---

## Navigation

### Groups

| Group     | Items                                  |
|-----------|----------------------------------------|
| Main      | Home, Explore, Transit, Events         |
| Community | Language Exchange, Chat                |
| City      | Neighborhoods, Services                |
| Settle    | Bureaucracy, Just Arrived              |
| Account   | Alerts, Profile                        |

### Mobile Pill Dock (5 items)

Home, Explore, Alerts, Profile, **More**

- **More** opens a full-screen overlay showing all navigation items
- **Active state:** `accent-soft` background + accent-colored icon + blue dot below

---

## Pages

Home · Explore · Transit · Events · Language Exchange · Chat/Inbox · Neighborhoods · Services · Bureaucracy · Just Arrived (onboarding) · Alerts · Profile · Welcome flow (5 screens) · Settings

---

## Components

### Layout

| Component      | Purpose                                              |
|----------------|------------------------------------------------------|
| AppLayout      | Three-column responsive shell, handles breakpoints   |
| AppSidebar     | Full sidebar (desktop), icon rail (tablet)           |
| MobileDock     | Bottom pill navigation for mobile                    |
| MobileTopBar   | Sticky header with title and action slot             |
| RightPanel     | Contextual sidebar content, desktop only             |

### Cards

| Component         | Purpose                                                        |
|-------------------|----------------------------------------------------------------|
| FeedSection       | Wrapper with title, optional action link, children             |
| BlueHighlightCard | Accent background, urgent item slot, headline, timeline rows   |
| QuickAccessGrid   | 2×2 or 3×2 grid of icon + label tiles                         |
| EventCard         | Title, emoji, date/time, location, attendee count, category tag|
| SpotCard          | Café/workspace with distance, noise, wifi, crowd indicator     |
| DepartureRow      | Line badge, destination, platform, live countdown timer        |

### Sheets & Modals

| Component         | Purpose                                                            |
|-------------------|--------------------------------------------------------------------|
| BottomSheet       | Slides up, drag handle, overlay backdrop, drag-to-close, mouse drag on desktop, lock body scroll, spring animation |
| PageSheet         | Full-screen takeover sheet for detail views on mobile              |
| ConfirmModal      | Title, description, confirm/cancel buttons, danger variant         |
| ToastNotification | Top-right (desktop), top-center (mobile), auto-dismiss, success/warn/danger/info variants |

### Forms

All forms use React Hook Form + Zod validation.

| Component    | Purpose                                                             |
|--------------|---------------------------------------------------------------------|
| TextInput    | Label, input, error message, helper text, icon slot                 |
| SelectInput  | Dropdown with search, option groups                                 |
| ToggleSwitch | 44×24px pill, animated knob, success color when on                  |
| SettingRow   | Label left + value/toggle right + optional chevron, border-bottom   |
| InlineEdit   | Facebook-style: tap to expand input, Save/Cancel, one open at a time, Escape to close |
| TagSelector  | Multi-select pill chips, toggle on/off, with emoji prefix option    |
| ProgressBar  | Animated fill, label, optional percentage                           |

### Navigation

| Component     | Purpose                                                |
|---------------|--------------------------------------------------------|
| NavItem       | Icon + label + optional badge, active/hover states     |
| BreadcrumbBar | For desktop nested views                               |
| TabBar        | Horizontal tabs with active underline, scrollable on mobile |

### Data Display

| Component       | Purpose                                                    |
|-----------------|------------------------------------------------------------|
| StatChip        | Small pill with number + label (profile header stats)      |
| CategoryTag     | Colored pill tag for event/partner/alert types             |
| LiveBadge       | Pulsing green dot + "Live" label for real-time data        |
| CountdownTimer  | Ticking live departure timer, turns red under 2 minutes    |
| OnlineIndicator | Green dot on avatar, border matches surface color          |
| UnreadBadge     | Small circle with count, accent background                 |

---

## Animation & Interaction

| Pattern              | Spec                                                        |
|----------------------|-------------------------------------------------------------|
| Bottom sheets        | Spring physics — `cubic-bezier(0.32, 1, 0.4, 1)`           |
| Navigation           | 200ms fade + slight vertical translate                      |
| Card hover (desktop) | `scale(1.01)` + shadow increase                             |
| Button press         | `scale(0.97)` active state                                  |
| Loading states       | Skeleton loaders on all data-fetching, never empty containers |
| User actions         | Optimistic updates (join event, complete task, send message) |
| Touch targets        | Minimum 44×44px                                             |

---

## PWA Requirements

- **Service worker:** vite-plugin-pwa (Workbox)
- **Offline-first transit:** cache last known departures, show stale indicator
- **Background sync:** checklist task completion when offline
- **Push notifications:** Web Push via VAPID keys (Laravel backend)
- **Install prompt:** shown after 3rd visit
- **Manifest:** standalone display, `theme-color` matches `--color-accent`
- **iOS safe area:** handled via `env(safe-area-inset-*)`

---

## File Structure

```
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
```

---

## Coding Conventions

- **Components:** function components with TypeScript only, no class components
- **Props:** always typed with explicit interface, never use `any`
- **Hooks:** all custom hooks prefixed with `use`, live in `/composables`
- **API calls:** never inline — always go through `/services/api.ts`
- **Styling:** Tailwind classes only, no inline styles, no CSS modules
- **State:** server state → TanStack Query, UI state → Zustand, form state → RHF
- **Exports:** one default component per file
- **Imports:** no barrel `index.ts` files — import directly from component files
- **Strings:** all user-facing strings in English
- **TypeScript:** strict mode enabled
