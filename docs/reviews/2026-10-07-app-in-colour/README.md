# App in colour — review, 7 October 2026

Current pages beside the "App in colour" concept, plus the Part B fixes.
Concept notes: `prototype/dev/design/connected/concepts/app-in-colour-2026-10-07/README.md`.
Design canvas: <https://claude.ai/artifact/7g3WeH5wdAkjaSeXk4PXt1>.

Captured with Playwright from `python3 prototype/serve.py` on 7 October 2026:
1440 × 900 at 1x and 390 × 844 at 2x, first screen only unless the name says
otherwise. All content is the sample day (Wed 30 Sept, 13:10); nothing is live.

## Part A — concept (not approved)

| Screen | Current | Concept |
| --- | --- | --- |
| Today 1440 | `today-1440-current.png` | `today-1440-concept.png` (midday) |
| Today 390 | `today-390-current.png` | `today-390-concept.png` (midday) |
| Today by time, 390 | | `today-390-{morning,midday,afternoon,evening,night}.png` |
| Today by time, 1440 (top 330px) | | `today-1440-{morning,midday,afternoon,evening,night}-top.png` |
| Composer start 1440 / 390 | `composer-{1440,390}-current.png` | `composer-{1440,390}-concept.png` |
| "What's on tonight?" 1440 / 390 | `composer-events-{1440,390}-current.png` | `composer-events-{1440,390}-concept.png` |
| Paperwork 1440 / 390 | `paperwork-{1440,390}-current.png` | `paperwork-{1440,390}-concept.png` |
| Services 1440 / 390 | `services-{1440,390}-current.png` | `services-{1440,390}-concept.png` |
| Titles, Geist vs Bricolage (1440 crops) | `titles-*-geist.png` | `titles-*-bricolage.png` |
| Today 390 with Bricolage | | `today-390-bricolage.png` |

Checks: page height is the same at all five times (Today 2,226px at 390,
1,501px at 1440); no sideways scroll at 390; on a 390 × 844 phone Today shows
the 14:00 appointment, its leave-by and Add a plan, and Composer shows all
starters above the input. Text pairs on the bands and tiles are 4.5:1 or more
(table in the concept README).

## Redesign — Today and Composer (not approved)

Standalone mock pages, `prototype/dev/design/connected/concepts/app-in-colour-2026-10-07/redesign/`.

| Screen | Files |
| --- | --- |
| Today 1440 (first screen, full page) | `redesign-today-1440.png`, `redesign-today-1440-full.png` |
| Today 390 (first screen, full page) | `redesign-today-390.png`, `redesign-today-390-full.png` |
| Today, other bands | `redesign-today-1440-evening.png`, `redesign-today-390-evening.png`, `redesign-today-390-night.png` |
| Today with the planned 13:24 leading | `redesign-today-1440-planned-tram.png` |
| Composer start 1440 / 390 | `redesign-composer-1440.png`, `redesign-composer-390.png` |
| Composer answers | `redesign-composer-chat-1440.png`, `redesign-composer-chat-1440-full.png` (all five), `redesign-composer-chat-390.png`, `redesign-composer-chat-390-tonight.png` |

Checks: no sideways scroll at 390; Today's 14:00, Leave now and Journey
details fit the first 844px; Composer's starters and input fit the first
screen (884px page, the chips row sits just above the input).

## Part B — fixes to the current pages (committed)

| Fix | Evidence |
| --- | --- |
| Events: "Let the evening happen" card 1,701px → 420px tall at 1440 (1,181 → 420 at 1024); phones unchanged at 384px | `partb-events-before-1440.png`, `partb-events-after-{1440,1024,390}.png` |
| Today names the tram stops like Travel: "Line 4 · Neumarkt → Wiener Platz" | `partb-trip-today-{1440,390}.png`, `partb-trip-travel-{1440,390}.png` |
| Travel's captured example says it was captured Mon 7 Sept at 23:37 and that these are that night's times | `partb-travel-captured-{1440,390}.png` |
| Composer's Add to plan is orange; Added stays cyan | `partb-composer-add-to-plan-{1440,390}.png` |

Today's "Check departures" board already shows the sample day's clock
(Neumarkt, 13:10, line 4 at 13:14 and 13:24), committed in `d808ffc2`. Which
tram leads the trip (the planned 13:24 on Today, the 13:14 in Travel) is
waiting for the owner.
