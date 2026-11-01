# Connected departments implementation plan

Goal: implement the approved cross-page review in the Today C prototype, in the recommended order.
Spec: prototype/dev/design/connected/product-design-review-2026-09-18.md, approved by the user with “Go work on those things please in recommended order”.
Execution: inline; preserve unrelated working-tree changes and the approved layouts.

## Shared contract and visual direction

Keep Geist, pale #f4f9fa surfaces, dark #211d15 text, cyan #05badd, teal #326b7b and orange #d9340a actions. Compact left-aligned headers, visible results, quiet secondary controls; Today’s existing animated Composer launcher is the shared entry. No new hero sections.

Save is independent of scheduling. Add to plan collects a date/time and checks against pending and confirmed activities before queuing a proposal. Event occurrence dates stay fixed. Unknown attendance semantics require an explicit choice; no catalogue fact is inferred. Service briefs are not bookings. Existing appointments must be explicitly recorded by the user. Routes remain captured/unverified and are associated with an activity rather than presented as live.

## Ordered tasks

- [x] Shared: add a small pure handoff model for scheduled selections, conflicts, bookmarks and contextual return links. Add focused Node regression cases, then implement. Reuse Today Composer controls for department entry.
- [x] Events: compact heading/search/date/category controls, distinct bookmark and planning actions, attendance form, empty states, arrival handoff tied to chosen attendance.
- [x] Places: matching Save/Add to plan and compact discovery controls; planned dates and time checks in the shared form.
- [x] Services: category-first compact discovery, explicit unavailable-directory state, retained request review, user-recorded appointment form and Composer handoff. Do not invent provider records or enable unsupported booking.
- [x] Connections: preserve exact journey/service review context; recorded appointments enter proposals with source identity; link journeys to plan activities and mark them for rechecking after the activity changes. Keep Bureaucracy case content scoped.
- [x] Verify: focused existing and new suites, syntax and asset versions, browser checks of desktop and 390px layouts and complete representative journeys. Use separate local browser storage for mutation tests. Update review notes with implemented scope and remaining external-service limits.

## Implementation result — 18 September 2026

Implemented in the linked Today C prototype only. Events, Places and Today share explicit scheduling and independent bookmarks. Department entry reuses the approved quiet Composer launcher. Services retains requests and supports recording an appointment already arranged, with no provider or booking fabrication. Journeys attach to an activity as proposed changes and retain captured-data warnings; changed date, destination, start time or preceding activity invalidates the displayed journey check. Back to Composer restores the conversation position without replaying the request.

Bureaucracy process details flag an appointment that differs from its linked plan. Explicitly sharing the revised appointment opens a review; same-day updates replace that appointment in a proposal using stable identity. A move to another day requires removing the earlier plan activity before adding the new one. No case details are silently copied or source records modified. Bank process links carry only the selected service category. Today reads recorded action counts and the next upcoming confirmed activity.

Validation: focused model, conversation, handoff, navigation, cancellation, persistence and asset tests; all active prototype scripts syntax checked. Browser review at 390×844 and 1280×900. Exercised event attendance, separate bookmarks, overlap rejection, service requirements return, recorded appointment handoff, route attachment/date mismatch, return to Composer, Today planning sheet and Bank process → Services context. Existing preview data on the user's origin was preserved; mutation checks used a separate local origin.

Remaining product work is explicit: live event updates, route calculation, provider results/details/contact/booking, real notification permission/delivery, broader settings, and cross-day appointment rescheduling as one atomic operation. This pass is ready for interaction review; it is not a production-service integration or a full accessibility/device-keyboard certification.
