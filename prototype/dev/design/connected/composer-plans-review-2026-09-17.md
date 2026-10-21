# Composer and My Plans — first design checkpoint

17 September 2026 · Proposal for owner review · Related task: EXP-73

This review defines the next design step. It does not change the accepted previews or implement production functionality.

## What the current app actually provides

The application already has useful recommendation and planning infrastructure. In `resources/js/pages/composer.tsx`, results can display “Just this”, “A few options” and “Full day”. `app/Http/Controllers/ComposerController.php` supports composition, pins, locked choices, exclusions and swaps. These capabilities should inform the new experience.

The same page still uses `InterimRoute` for non-plan requests: paperwork questions, search and directions lead to a department page. An intent being recognised is not evidence that Composer can answer it in place.

`app/Composer/TodayPlanStore.php` stores one pinned plan per user in cache, with an expiry capped at 72 hours. This is useful for the existing Today feature, but cannot serve as the durable home for several future days or weeks. The source inspection establishes the local implementation; this review did not test the deployed app or live recommendation quality.

The current `composer-next` mockup combines a request surface, scripted responses, a permanent editable draft, and a Saved day snapshot dialog. Desktop gives the draft a full column regardless of the request. At 390 px, the large heading and input dominate the first screen, with request/draft navigation fixed below. Both layouts were inspected in the browser. Existing user choices were not edited.

The older `docs/composer-design.md` already describes a universal entrance but explicitly excludes weekly planning and limits the model to parsing. The owner's later direction expands the product scope. Those older limitations must not silently become requirements for the new design. The project's current restrictions on verified bureaucracy content still apply.

## Recommended structure

Use one adaptive Composer workspace. A short request and its useful response lead the page. Let the user continue with a follow-up while keeping the relevant context. Render answers as readable text with evidence, recommendations as comparable cards, journeys as route information, and proposed plans as editable timelines. Combine these when a request needs several of them.

Question, discovery and planning are three design scenarios, not three modes that users must choose before typing. For example, “Which of these works before my appointment?” needs both recommendations and scheduling context.

My Plans has its own stable page for reviewing and managing days and weeks. Keep a proposed plan visible inside Composer while discussing it; after confirmation it has a persistent identity in My Plans. Opening an existing plan in Composer should show which plan is being discussed and return a proposed revision without silently replacing it.

Saved ideas remain unscheduled bookmarks. A proposed location for this collection is a clearly labelled Saved section within My Plans, accessible directly from bookmark feedback. This navigation decision is still a proposal. A bookmark count must count saved ideas; it must not stand in for draft items, upcoming plans or all three at once.

## Three representative flows

| Request | Response in Composer | Follow-up and relationship to plans |
| --- | --- | --- |
| “What have I planned for Saturday?” | A concise answer using the person's existing plan, with times and links to the relevant activities. If no plan exists, say so. | “Move the park visit later” opens a proposed revision. Asking the question does not change the plan. |
| “Find something outdoors for Saturday afternoon.” | A small set of places/events with useful reasons, available time information and explicit unknowns. Ask for missing location or other decisive information only when needed. | Bookmark keeps an idea. Add to plan carries the selected item and any known date/time into a planning proposal. “Anything indoors instead?” refines the same request. |
| “Plan Saturday afternoon around my 15:00 appointment.” | An editable timeline with the fixed appointment, suggested activities and travel gaps. State when travel or availability cannot be verified. | Swap an activity or adjust the time, then confirm the proposal into My Plans. Reminders have visible settings and activation feedback. |

These are interaction examples, not fabricated recommendations or legal guidance. A paperwork question would show approved retrieved content and its source, or an explicit missing-coverage state.

## What each action changes

| Action | Result |
| --- | --- |
| Bookmark | Keeps an unscheduled idea. No date, reminder or reservation is implied. |
| Add to plan | Starts or joins a visible planning proposal. Retains item identity, event occurrence and known timing. Requests a date only if needed. |
| Confirm a proposed plan | Creates or updates the intended plan in My Plans. It does not make an external booking. |
| Ask Composer about a plan | Attaches that plan as visible context. The stored plan remains intact during discussion. |
| Apply a proposed revision | Updates that plan after the user can review the changes. Other days remain intact. |
| Enable a reminder | Creates a separately identifiable reminder, with delivery readiness shown honestly. |

Proposed plan lifecycle: request → proposal → edit → confirm → My Plans. Later revisions return through proposal and review. Saved ideas can feed a proposal without being consumed or lost.

## Desktop and mobile direction

Desktop starts with a readable central response area and a compact follow-up input. Recommendation cards can use the available width. Show a planning panel only while a plan is relevant to the request; do not permanently reserve half the page for an unrelated draft. Keep the shared Expadu header and established colours/type language.

Mobile presents the same response in one column. Put the answer or result near the top, keep the follow-up input reachable, and let a proposed timeline expand in context. Avoid the permanent Your request/Draft switch when the user is asking a question. Small choices such as a date or target plan should use a compact picker suited to the available space.

## Alternatives considered

- A chat-only design makes follow-ups easy but makes comparing options and directly editing a timeline cumbersome.
- Separate question/discovery/planning modes make the layout predictable but require users to classify their own request and complicate mixed requests.
- The recommended adaptive workspace supports natural requests while keeping results and actions tangible. It requires clear transitions and visible context so layout changes remain understandable.

## First mockup slice after this checkpoint

Build a separate Composer concept with a shared empty state and the three representative responses. Demonstrate one complete connection: discover an item → bookmark or add to a proposal → confirm → open the same plan in My Plans → propose a revision. Reuse the accepted card language and timeline foundation where appropriate, while making the page structure serve Composer's broader role.

Use explicit sample fixtures. Questions about saved plans can be answered from those fixtures; no invented bureaucracy instructions, unverified venue claims, fake live routes or actual reminder delivery. Keep the current Today C preview available for comparison.

Review desktop and mobile before expanding to full week navigation, reminder configuration, plan history, and additional department flows. The full product design and production migration remain unfinished.

## Decisions to resolve in the next stages

The workspace structure is the current checkpoint. Later decisions include where Saved lives in navigation, whether plans can include personal activities entered manually, how unfinished proposals are reopened, and reminder defaults. These should be resolved alongside the relevant screen rather than exposed as a large settings form before the first request.
