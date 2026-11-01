# Adaptive Today — visual proposal

Status: **for review; implementation has not begun.**

[Open the review](http://127.0.0.1:8776/dev/design/today-proposal/review.html).
The accepted `today-c/`, earlier concepts, and Laravel application are preserved.
This separate proposal uses plain review markup and isolated memory-only actions.
It does not read account data, call services, submit messages or save plans.

## Six high-fidelity views

| Situation       | Desktop / 1440px                   | Phone / complete 390px feed       | First 390 × 844 viewport                |
| --------------- | ---------------------------------- | --------------------------------- | --------------------------------------- |
| Everyday        | [View](views/everyday-desktop.png) | [View](views/everyday-mobile.png) | [View](views/everyday-mobile-first.png) |
| Important event | [View](views/event-desktop.png)    | [View](views/event-mobile.png)    | [View](views/event-mobile-first.png)    |
| Route delay     | [View](views/delay-desktop.png)    | [View](views/delay-mobile.png)    | [View](views/delay-mobile-first.png)    |

The review shows the full phone reading order with a first-viewport marker, and
links to full-resolution views, 320px captures and clickable state previews.

## Direction

The shared shell, original logo, five destinations, Geist typeface, pale
`#F6FAFB` canvas, `#211D15` ink, cyan selection and `#D9340A` accessible orange
remain recognisably Expadu. Expressive event imagery and selective brush
lettering supply energy. Practical roles share one connected white surface.
Desktop uses a 1248px content grid at 1440px, with 96px outer margins and
20px gutters. The everyday board keeps the earlier staggered character.

- **Everyday, 08:40:** the next confirmed 09:30 class and 09:02 journey lead.
  Paperwork and forecast share the overview; ordinary discoveries follow.
- **Important event, 14:00:** a designated arts highlight at 16:00 matches
  the sample person's interests. The next personal commitment is dinner
  at 18:30. The immersive event and compact overview align on desktop;
  the event comes first on phones, with the next personal action still in
  the first screen. The event is promoted once, leaving film and outdoors
  in the remaining collection.
- **Route delay, 08:40:** the usual journey to the 09:30 class is delayed
  by 12 minutes. Connections grows _inside_ Your day, with the route,
  scheduled 09:02 and expected 09:14 departure, a direct alternatives
  action and a secondary contextual Composer entry.

The proposed priority is immediate relevant change, next time-sensitive
personal action, significant relevant event today, ordinary overview and
general discovery. Relevance, active timing and an affected personal record
are required to lead. A major event cannot bury a more urgent personal
problem. Competing important items remain compact. Selection occurs on
opening or deliberate refresh; background updates must not shuffle the layout.
Live ranking and services are outside this proposal.

## Reading order and controls

Phones use Your day, relevant draft/route actions, one event collection and
optional settlement help. Event-led phones add the promoted event first;
delay phones reorder only the overview roles. All three events remain
available; a promoted item is removed from the discovery collection.
Native touch swiping, mouse dragging, keyboard arrows and labelled collection
controls are demonstrated. Focus and scroll context are retained when
panels close, including after a nested Composer handoff.

The labelled Composer entry lives in the compact sticky header. This is a
proposed refinement of the current floating launcher: it stays reachable
without covering event controls or process rows. Global search is deferred;
no search field is added. The city appears modestly below the greeting on
phones and retains its desktop header control.

Paperwork uses exact process rows, a meaningful progress statement and one
chevron convention: **open that content**. A primary process and a compact
second row coexist with one route to all paperwork. No carousel controls or
competing page counter remain. The progress track repeats the written count
visually and is not a navigation indicator.

An event image opens details. Frosted bookmark **Save** creates an idea, while
calendar-plus **Plan** opens a proposal for review. Neither confirms attendance.
The Plan panel retains the fixed sample session and offers a Composer review.
Composer carries the exact process, event, affected route or day beside the
existing editable draft and conversation. Opening never sends or mutates a
plan. Sending is explicitly unavailable in the proposal.

Activity options, swipe left or double-tap reveal a compact red Delete action;
the reveal alone does not remove the activity. The options button supplies a
keyboard route. Removal offers Undo. Panels use short interruptible transitions,
immediate press feedback and reduced-motion and reduced-transparency fallbacks.

## Empty, missing and uncertain data

[Empty agenda](http://127.0.0.1:8776/dev/design/today-proposal/?state=empty)
retains a planning action, useful personal tools, weather and discovery.
Settlement help is optional, dismissible and never blocks entry.
The ordinary connection says scheduled time and **no live status**. Review
details also show loading and stale states, and a deliberate typography and
category fallback for an event without imagery. Weather remains small unless
it materially affects the day. No unavailable provider or service is invented.

All three scenarios use **Wednesday, 30 September 2026** with a labelled sample
clock. Activities, processes, progress, transit and weather are fictional.
The EU Blue Card count is a fictional user-entered document list; it is not
a verified list of required documents. No requirements, deadlines, fees or
legal advice are authored. No actual recorded progress is imported or altered.

Existing AI illustrations from `events-next/photos/` were created for the
approved Festival visual exploration and depict activities, not the listed
events or venues. Their provenance is in that directory's README. They have
not been approved as provider media. This proposal introduces no new images,
licence decisions or media-catalogue changes.

## Review evidence

`render-review.cjs` captures all six full views, first phone viewports,
320px views, an empty-agenda view and interaction details, using isolated
browser contexts. `verification.json` records the observed checks and bounds.
Checks cover 1440px, 820px, 390px and 320px: page overflow, first action
visibility, touch height, role order and event uniqueness. Interaction
checks cover independent Save/Plan, exact process identity, preserved draft,
context handoff, restored focus, keyboard event browsing, reveal/Delete/Undo
and empty agenda. Every asset is local; no live requests are involved.

The review audit checks a clear lead/action, balanced desktop composition,
complete mobile sequence, one navigation convention, direct paperwork,
readable imagery, retained categories and distinctions between confirmed,
draft, saved and sample information. Visual inspection and browser checks
support the proposal; five-second comprehension still belongs to human review.

Run the local preview server from the project root with the `prototype`
directory as its root on port 8776, then run the capture script with Node 22.
The final checks are formatting and browser verification, not unrelated
application tests or a production build.
