# Friendly connected Expadu experience

Approved scope: the owner asked for the six findings from the 3 October walkthrough to be implemented, reviewed deeply, corrected, and carried into the real frontend. They explicitly delegated routine decisions and requested uninterrupted execution. Composer remains the app's main engine. Backend feature development and deployment remain deferred.

## Experience

Preserve the current Today brief, full appointment timeline with nested travel, immersive event images, five top navigation destinations and existing Composer. Make Today answer what needs attention and what the person can do next. Events and Travel use the same day, date and destination context. Paperwork and Services remain contextual sources of help. No standalone Places navigation.

1. One day: manually added appointments, confirmed activities and pending event selections must be recognised in all relevant planning surfaces. Pending and confirmed states stay distinct. Edits preserve IDs, dates and existing drafts. Never seed sample personal appointments into real user plans.
2. Friendly first visit: a compact optional Composer invitation with concrete starting actions, without a tour, forced questionnaire, or assumed personal paperwork. Existing message text is preserved; choosing a prompt does not send automatically.
3. Clear completion: selecting an event ends with a direct Review plan action. A draft is not booked, confirmed or added to the saved plan until explicit approval.
4. Everyday correction: personal activities can edit title, place and times through their existing menu. Fixed event schedules are protected. Changed journey context needs rechecking. Validation and undo preserve unrelated plans.
5. Useful density: remove repeated empty starting sections. Keep real saved/unfinished work, practical content, one relevant paperwork continuation, and event discovery. Do not create synthetic news, legal facts, provider availability or personal records.
6. Direct recovery: an Alternatives action opens the route search with exact destination, day and arrival target, retaining the originating activity. Keep uncertainty explicit.

## Places contract

Coordinate with `Investigate places data quality` before final integration. Its latest local report describes 12,292 candidates, broad but uneven area coverage and very low approved photo coverage; this is not evidence of deployed data. Use existing APIs and supplied category/location/identity fields. Place cards work without photos, retain known practical facts and explicit uncertainty, and do not claim unknown cost is free or unknown hours are open. Preserve canonical destination relationships and actual facility coordinates; never substitute a city centroid or stock photo for a known place. Preserve all source/rights guards.

## Implementation boundaries

- Work on the accepted prototype and real Inertia/React frontend, using existing data and endpoints.
- Do not rewrite Composer's UI or add an independent planning engine.
- Keep prototypes clearly labelled; real application screens use real props, loading, empty and error states.
- Preserve unrelated dirty working-tree changes. No push, merge, deployment or catalogue import.
- User's unattended execution instruction overrides skill approval checkpoints. Preserve progress in the implementation plan.
- Mobile controls remain compact and touch-friendly, with purposeful icons, restrained motion, keyboard focus and reduced-motion support. The approved warm neutral Today canvas keeps orange actions, cyan selection/travel and restrained lavender/yellow accents.

## Acceptance

Test fresh and returning users, add/edit/undo, event selection/review, existing drafts, wrong-day handoffs, no-photo places, quiet/busy/delayed days, and narrow mobile/desktop views. Perform a fresh review after changes and fix important findings. Confirm the real frontend builds and typechecks; report any unconnected backend capability honestly.
