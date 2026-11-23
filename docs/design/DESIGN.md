# NOC Center — Interface Design System

Status: **approved** (user sign-off 2026-11-22)
Source of truth: `docs/superpowers/specs/2026-11-22-noc-dashboard-design.md` §12.6
Interactive mockup: `docs/design/noc-dashboard-ui.figcraft`
Screen exports: `docs/design/screens/`

This document is the contract for the Blade + Livewire + Tailwind frontend. If a
value here conflicts with the spec, the spec wins; if a screen export conflicts
with this document, this document wins.

---

## 1. Design intent

An **operations ledger**, not a dashboard product.

- Dense, editorial, and quiet. The table is the hero; the page is a document.
- Hairline rules (`1px`) carry structure. Cards are used only for genuinely
  separate surfaces, never nested, never as decoration.
- Colour is semantic only: green/amber/rose/blue mean state, severity, or a
  link. Everything else is neutral.
- Numbers are tabular and right-aligned. Hostnames, tokens, and raw values are
  monospace.
- No ornament: no sparklines in tables, no rings, no gradient heroes, no
  left accent bars, no eyebrow labels, no decorative avatars, no oversized
  empty-state illustrations.
- Light and dark are peers. Both must be legible and intentional; dark is not
  an inverted afterthought.

The visual reference is an internal console for people who read it all day:
**scan, compare, act.**

---

## 2. Screens

| # | Screen | Dark export | Light export |
| --- | --- | --- | --- |
| 01 | Fleet dashboard | `screens/01-dashboard-dark.png` | `screens/01-dashboard-light.png` |
| 02 | Server detail | `screens/02-server-detail-dark.png` | `screens/02-server-detail-light.png` |
| 03 | Alerts (master–detail) | `screens/03-alerts-dark.png` | `screens/03-alerts-light.png` |
| 04 | Add server | `screens/04-add-server-dark.png` | `screens/04-add-server-light.png` |
| 05 | Token reveal | `screens/05-token-reveal-dark.png` | `screens/05-token-reveal-light.png` |
| 06 | Alert settings | `screens/06-alert-settings-dark.png` | `screens/06-alert-settings-light.png` |
| 07 | Sign in | `screens/07-sign-in-dark.png` | `screens/07-sign-in-light.png` |
| — | Responsive board (1024 rail, 390 dashboard, 390 alerts) | `screens/08-responsive.png` | — |

All desktop frames are 1440px wide. Content column is capped at 1440px with a
240px sidebar; the data area uses the remaining width and never scrolls
horizontally at ≥1280px.

---

## 3. Colour tokens

Tokens are exposed as CSS custom properties and Tailwind theme values. The
`data-theme="dark"` attribute on `<html>` selects dark; light is the default.
No component may hardcode a hex value.

### 3.1 Surfaces and text

| Token | Dark | Light | Use |
| --- | --- | --- | --- |
| `canvas` | `#0A0A0A` | `#F3F5F7` | Page background, sidebar |
| `surface` | `#111111` | `#FFFFFF` | Panels, table body, cards |
| `surface-2` | `#171717` | `#E9EEF3` | Inputs, active nav, chips, toggle track |
| `surface-3` | `#1F1F1F` | `#DDE5EE` | Hover rows, pressed states |
| `selected` | `#141414` | `#EEF2F7` | Selected table row / list item |
| `border` | `#262626` | `#D3DCE5` | Panel and control borders |
| `border-strong` | `#3A3A3A` | `#B8C4D2` | Emphasised border, checkbox |
| `divider` | `#1A1A1A` | `#E3E8EF` | Table and list rules |
| `text` | `#EDEDED` | `#101828` | Primary text |
| `text-sub` | `#A3A3A3` | `#475467` | Secondary text, nav labels |
| `text-muted` | `#737373` | `#667085` | Metadata, column headers |
| `ink` | `#0A0A0A` | `#FFFFFF` | Label on a primary button |
| `focus` | `#4D9FFF` | `#1D4ED8` | Focus ring |

Dark mode is **neutral black**, never navy, slate-blue, or purple.

### 3.2 State

| State | Dark | Light | Shape |
| --- | --- | --- | --- |
| Online / healthy | `#34D399` | `#047857` | 6–7px dot + text |
| Warning | `#FBBF24` | `#B45309` | dot + text |
| Critical | `#FB7185` | `#BE123C` | dot + text |
| Stale | `#FBBF24` | `#B45309` | dot + text |
| Offline | `#FB7185` | `#BE123C` | dot + text |
| Never seen / unknown | `#94A3B8` | `#667085` | dot + text |
| Link / accent | `#4D9FFF` | `#1D4ED8` | text only |
| Acknowledged | `text-sub` | `text-sub` | text only (no colour) |

Rules:

- Always pair a status dot with its label. Never rely on colour alone.
- Severity is a dot plus an uppercase 8.5px label, not a filled pill.
- Acknowledged is deliberately neutral; only open critical/warning carries
  colour.

### 3.3 Chart series

| Series | Dark | Light |
| --- | --- | --- |
| CPU | `#60A5FA` | `#2563EB` |
| Memory | `#A78BFA` | `#7C3AED` |
| Disk | `#FBBF24` | `#B45309` |
| Network | `#22D3EE` | `#0E7490` |

Maximum four series. No dual axes. Units live in the tick labels and legend.

---

## 4. Typography

| Role | Family | Size / line | Weight | Notes |
| --- | --- | --- | --- | --- |
| Brand | Inter | 14 / 20 | 600 | “NOC Center” |
| Page title | Inter | 22 / 28 | 600 | tracking −1% |
| Section title | Inter | 13 / 20 | 600 | |
| Body | Inter | 12.5 / 18 | 400 | |
| Table cell | Inter | 11.5 / 16 | 400–500 | server name 500 |
| Label | Inter | 8.5 / 12 | 500 | uppercase, tracking 8–12% |
| Metadata | Inter | 9–10 / 14 | 400 | `text-muted` |
| Metric value | Inter | 18–20 / 24 | 600 | tabular numerals |
| Mono | JetBrains Mono | 9.5–12 / 16 | 400 | hostnames, tokens, numbers |

- Use `font-variant-numeric: tabular-nums` on every metric, age, and
  right-aligned number.
- Self-host Inter and one mono weight as `.woff2`; `font-display: swap`;
  preload only the body weight.
- Do not use more than two families. No display fonts.

---

## 5. Layout and spacing

- Spacing scale: `4 / 8 / 12 / 16 / 24 / 32 / 40 / 56`. Layout gaps are
  multiples of 8; 4 is allowed inside compact controls.
- Sidebar: **240px** expanded, **64px** collapsed icon rail. Toggle persists in
  `localStorage` and is applied before first paint (inline bootstrap script with
  a CSP nonce) to avoid flash.
- Content padding: 32px horizontal at ≥1280px, 24px at 1024–1279px, 16px
  below.
- Top bar height: 64px, with a single hairline bottom border. No shadow.
- Page header: title + one-line summary + contextual actions on the right.
- Radius: panels `10px`, controls `6px`, chips/toggles `10px` (pill), dots
  circular. Never mix radii within one control.
- Borders: `1px` only. No `box-shadow` on static panels; a single soft shadow
  is allowed on overlays (modal, dropdown, tooltip) only.

---

## 6. Components

### 6.1 Sidebar

- Brand mark: three 3px bars, or the `N` tile in compact screens; wordmark
  “NOC Center” with “OPERATIONS CONSOLE” at 8.5px, `text-muted`, tracking 10%.
- Groups: `FLEET` (Dashboard, Servers, Alerts, Activity) and `ADMIN`
  (Settings, Users, Audit log), each with a 9px uppercase group label.
- Active item: `surface-2` fill, 6px radius, text `text` at 500. Inactive:
  `text-sub` at 400.
- Counts are monospace, right-aligned, 9.5px; alert count uses `crit`.
- Footer: signed-in name, role, chevron, and a `Collapse` control at the very
  bottom.
- Collapsed rail: icons only (20px), tooltip on hover/focus, no labels.

### 6.2 Top bar

- Breadcrumb: `Fleet / Servers / web-01`, separators in `divider`, current
  page in `text`.
- Search: 212px control with magnifier and `⌘K` hint. Opens the command
  palette; it is not a plain text input.
- Theme toggle, notification bell with count badge, and account menu.
- On mobile the breadcrumb collapses to the page title; search and theme move
  into the overflow menu.

### 6.3 Page header

- 22px title, 11.5px summary line, actions right-aligned and 32–36px tall.
- Primary action: `text` fill with `ink` label. Secondary: transparent with
  `border`. Never two primary buttons in one header.

### 6.4 Summary strip

- Six metrics separated by 1px vertical `divider` rules, top and bottom
  hairlines, no card.
- Each metric: 8.5px uppercase label, 18–20px value, 9px meta.
- Use this instead of stat cards. It must wrap to 3×2 at 1024px and 2×3 at
  390px.

### 6.5 Tabs

- Text tabs with a monospace count where relevant.
- Active: `text` at 500 with a 2px underline in `accent` (or `text` on
  monochrome screens); inactive: `text-muted`.
- Tabs scroll horizontally on mobile; the active tab is scrolled into view.

### 6.6 Tables

- Header: 8.5px uppercase `text-muted`, tracking 8%, hairline below.
- Rows: 40–48px, hairline `divider` between rows, no vertical rules.
- Selected row: `selected` fill. Hover: `surface-3` (light) / `#1F1F1F`
  (dark).
- Numeric columns right-aligned and monospace. Status column combines dot +
  label. Server column has name (500) over hostname (8.5px mono, `text-muted`),
  with `server_type` as a small secondary label, a `label` chip (asset code such
  as JHD-001), and the primary IP in mono. Provider and location appear in the
  detail view and filters, not as table columns.
- Missing values are an em dash `—` in `text-muted`, never blank or `0`.
- Below 768px the table becomes stacked cards; each card preserves status dot,
  name, hostname, and the three resource values.

### 6.7 Alerts

- Master list on the left, detail pane on the right (492px). Below 1024px the
  detail becomes a full-screen route.
- Alert row: severity dot + uppercase label, title at 12.5px/500, one metadata
  line, status, and age right-aligned in mono.
- Detail pane: severity, state + age, title, server line, first/last seen,
  `CONTEXT` definition list, `DELIVERY` table, `TIMELINE`, then
  `Acknowledge` (secondary) and `Resolve` (primary).
- Delivery table columns: channel, opened, recovery. Error text is redacted and
  shown in `text-muted`, never as a raw provider payload.

### 6.8 Forms

- Labels are 8.5px uppercase `text-muted`; helper text 9px below the field.
- Inputs: 42–44px tall, `surface-2` fill, `border` 1px, 6px radius, 11.5px
  text. Focus uses a 2px `focus` ring and does not shift layout.
- Two-column grid at ≥768px, single column below. Long text uses a full-width
  textarea.
- Server form sections: Identity (name, hostname, primary IP, label), Placement
  (type, provider, location — free text with suggestions from existing values),
  Collection (interval, alerts on/off, notes). The alerts toggle is on by
  default and applies to this server only; turning it off closes its active
  alerts without sending recovery messages.
- Toggles: 36×20 track, 14px knob, `text` fill when on, `surface-2` when off.
- Errors appear inline under the field in `crit` with an icon-free 9.5px line
  and `aria-describedby`.
- Required fields are marked with a 3px `crit` dot, not an asterisk.

### 6.9 Token reveal

- One-time token in a 56px `surface` box with a `Copy` control; token is
  monospace and wraps on mobile.
- A warning panel above the token: “Copy this token now. It will not be shown
  again.”
- Install command block with copy; a three-step checklist with completed state.
- Token detail panel shows prefix, created, expires, rotation grace, hash
  algorithm. Never show the hash itself.

### 6.10 Sign in

- Centered 400px card on a 900px canvas; no marketing column.
- 24px title, one-line subtitle, email + password, remember-me, forgot link,
  full-width primary button.
- Footer: system status dot, last check, version, timezone note.
- Theme toggle top-right; no public registration link.

### 6.11 Overlays

- Modal max-width 560px, 16px radius, `surface`, `border`, one soft shadow.
  Trap focus, close on `Escape`, restore focus on close.
- Dropdown menus use `surface`, `border`, 6px radius, 6px padding; 32px
  items.
- Tooltips are `surface-2` with `border`, 9px text, 4px radius; never the only
  place information lives.

### 6.12 Loading and empty states

- Show skeletons after 300ms; never a full-page spinner.
- Keep previous data visible during polling with a thin 2px progress bar.
- Per-panel error state with a retry action; one failing panel must not blank
  the page.
- Empty states are one sentence plus one action. No illustrations.

---

## 7. Responsive behaviour

| Width | Behaviour |
| --- | --- |
| ≥1280 | Full 240px sidebar, 9-column table, alert master–detail |
| 1024–1279 | 240px sidebar, table may drop the notes column, detail pane 420px |
| 768–1023 | 64px icon rail, 6-column table, alert detail becomes a route |
| <768 | No sidebar; hamburger opens a slide-over; table becomes cards; summary strip is 2 columns; tabs scroll |
| 390 | Single column, 16px gutters, 44px targets, sticky primary action in forms |

- Minimum touch target: 44×44px.
- Never hide a critical value on mobile; move it into the card body instead.
- The collapsed rail and mobile drawer share state.

---

## 8. Accessibility

- Skip link to `#main`, one `<h1>` per page, landmarks for nav/main.
- Visible 2px focus ring using `focus`; never `outline: none` without a
  replacement.
- Status is conveyed by text as well as colour; icons are `aria-hidden` when
  adjacent text repeats them.
- Tables use `<th scope>` and `aria-sort` on sortable headers.
- Modals trap focus and restore it; menus support arrow keys.
- Charts expose a text summary and a data table fallback.
- Announce explicit refreshes via `aria-live="polite"`; do not announce polls.
- Honour `prefers-reduced-motion`; no transition longer than 200ms.
- Contrast: body text ≥ 7:1, metadata ≥ 4.5:1, status text ≥ 4.5:1 on its
  surface.

---

## 9. Motion

- 120–160ms `ease-out` for hover, focus, and menu open.
- 180–200ms for drawer and modal.
- No parallax, no ambient animation, no animated gradients.
- Polling never animates layout; only the thin progress bar moves.

---

## 10. Copy

- English, sentence case, no exclamation marks.
- Prefer the specific over the generic: “No sample for 2h 14m”, not “Offline
  for a while”.
- Times: UTC in storage, `Asia/Jakarta` in the UI, always with a timezone
  hint on first use.
- Numbers: thousands separators, one decimal for percentages, binary units
  for disk/memory.
- Destructive actions name the object: “Revoke token `noc_7f3a…`”.
- Error messages say what failed, why if known, and what to do next; never
  echo secrets or raw query text.

---

## 11. Implementation notes

- Tailwind 4 `@theme` exposes the tokens above as `--color-canvas`,
  `--color-surface`, `--color-text`, etc.; components use utility classes,
  not raw hex.
- Theme is `class="dark"` from a nonce-protected bootstrap script; the toggle
  writes `localStorage.theme` and updates `<html data-theme>`.
- Icons: inline SVG sprite, 1.2–1.6px stroke, 14–20px, `currentColor`.
- Charts: Chart.js 4.6, lazy-loaded on the detail page only; grid lines use
  `divider`, ticks `text-muted` at 8.5px.
- Fonts self-hosted; no CDN.
- All interactive copy actions call `navigator.clipboard` with a 2s
  “Copied” confirmation and an `aria-live` announcement.

---

## 12. Anti-patterns (do not ship)

- Card grids, nested cards, coloured card headers.
- Sparklines, progress rings, gauges, gradient heroes.
- Left accent bars on alerts or list items.
- Decorative avatars or initials circles in tables.
- Uppercase “eyebrow” text above titles.
- Purple/indigo accents, navy dark backgrounds.
- More than four chart series or dual axes.
- Full-page spinners, layout-shifting skeletons, colour-only status.
- Disabled buttons without an explanation.
