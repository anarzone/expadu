# Composer + My Plans — conversational workspace

Connected to the approved Today C design, 18 September 2026. Start at `/dev/design/today-c/`; its Composer request box and plan actions open this conversational workspace. The accepted Today layout and URL are preserved.

Open `/dev/design/composer-studio/` directly, or enter from Today. Today’s header bookmark opens Saved; the plus menu beside the Composer input contains My Plans, Saved and History. Both are panels in the same workspace; the older `/dev/design/my-plans/` links now open the corresponding panel in Composer.

## Rich answers, coloured starters and Your day — 7 October

Owner request, after the App in colour redesign concept: build its Composer
ideas into Composer and round them out.

- **Your day.** On desktop a panel beside the conversation lists what Composer
  plans with: the tram to take now (Today's 10-minute rule), the 14:00
  appointment, anything added to today's plan or proposal, the visa deadline
  from your records, the area around the appointment after it ends, tonight's
  sample events and the next plans. Tapping one asks about it; the deadline
  opens Paperwork. It hides while My Plans, Saved or History is open. Phones
  show the day's facts as one scrolling row above the starters.
- **Starters in their section's colour**, only for what the day has: Travel
  (when to leave), Paperwork (what to bring, with your ready count), Events
  (tonight, with the date stamp) and Nearby (a café near the appointment, with
  its map picture), plus Plan my Saturday and A little time outdoors.
- **Answers lead with a short verdict**, then their own block:
  - the appointment trip: "Leave now — it’s 13:10.", the walk, line 4 and the
    walk as steps in cyan, why the 13:14 and not the 13:24, Open in Travel.
    "How do I get from Neumarkt to Mülheim by 14:00?" is the same trip now;
  - what to bring: "For your 14:00: 1 of 5 documents marked ready." from your
    own records, linked to the checklist in Paperwork, with no guidance on
    what the office requires;
  - events: the Events poster (AI illustration, labelled) with the date stamp;
  - nearby places: "Three cafés near Wiener Platz, Mülheim.";
  - step-free access: what the event's or venue's listing records (the same
    rule as the Events sheet), otherwise "Not listed." with the facts that are
    and aren't known.
- The event time row and "Fixed time" are ink and neutral; lavender stays
  Paperwork's.

The person's records (ready count, visa date) moved into
`connected/sample-day.js` so Today and Composer read the same ones; Today is
pixel-identical. Tests: `tests/requests.test.cjs`,
`tests/conversation-view.test.cjs`, `tests/journey-browser.cjs`. Screenshots:
`docs/reviews/2026-10-07-app-in-colour/composer-built-*.png`.

### One compact heading — owner request, 8 October

The sticky "✦ Composer · Minimize" bar under the app header is gone (with the
app header it took about 177px on a phone before any content). Its job is one
compact row at the top of the conversation that scrolls with it: "✦ Composer"
with "Wed 30 Sept · Sample day" under it, and "← Back to Paperwork" (or
whichever page Composer was opened from; Today otherwise) on the right. The
back button keeps the conversation, as Minimize did, and says so to screen
readers. The separate centred date line is folded in. Content starts about 40px
higher on phones; the app header is the only sticky header.
`ExpaduStore.composerOrigin()` names the page. `tests/connected-browser.cjs`
checks the heading and its "Back to Today".

### Follow-ups — 8 October

- **Your day fits every desktop width.** From 1000 to 1439 px the
  conversation ran 120–265 px off the right edge beside Your day, and on wide
  screens Your day sat under the conversation's toolbar. The conversation now
  starts 24 px past Your day and fills the width that is left (up to 1080 px);
  the request field centres on it. Checked at 1000, 1100, 1280, 1440 and
  1920 px.
- **Saved shows the sample day's events.** An event saved on Today, such as
  Street culture night, counted as "1 idea" in Saved with an empty list.
- **`/dev/design/my-plans/` opens Composer's My Plans** (or the panel in its
  link). The old copy of Composer's page there had fallen out of step with its
  scripts and failed with "ExpaduStore is not defined".
- **Browser checks for today's pages.** `tests/connected-browser.cjs` covers
  Today and Composer at 390 and 1280 px: Saved, an event's review and
  confirmation, My Plans, a request answered once and in view, no overflow.
  `tests/department-browser.cjs` covers Events, Paperwork, Travel and Services
  hand-offs landing in view below an older conversation, and a route that
  waits while another day's proposal is open, then joins its appointment once
  that proposal is confirmed. The earlier versions used Today C and Places
  controls that no longer exist.
- **Your records follow Paperwork.** The checklist answer ("1 of 5 documents
  marked ready") and Your day's visa deadline read Paperwork's plan through
  `BureaucracyContract.records()` (`bureaucracy-next/contract.js`), so marking
  a document ready or recording the submission there shows here. Without
  Paperwork's saved state they are the sample day's values.

## Add to plan is orange — 7 October

Owner request: **Add to plan** on event, place and preview cards is the
answer's main action, so it uses the action orange (`--color-action`, white
text 5.4:1) like Today's add button. **Added** is a state and stays cyan. This
replaces the cyan Add to plan from "Shared palette and fixes" below. Evidence:
`docs/reviews/2026-10-07-app-in-colour/partb-composer-add-to-plan-*.png`.

Also fixed: an ordinary "from A to B" journey question (including the first
starter, "Getting to your 14:00 Blue Card appointment") failed with "Cannot
read properties of undefined". `tests/journey-browser.cjs` covers it.

## Hand-offs from other pages — 7 October

- **What you asked is in view.** Anything a page hands over (Travel's trip
  help or a chosen route, an event's Review plan, Paperwork step help, a
  service request) is the newest entry and is scrolled into view, even after a
  long earlier conversation. Panels (My Plans, Saved, History) still open on
  top.
- **Trip requests in words.** Travel's "Help with this trip" reads as "Help with
  my trip from Neumarkt to Mülheim, arriving by 14:00 on Wed 30 Sept", and its
  card lists From, To, When and Travel instead of field names and ISO dates.
- **Waiting selections say so.** When Composer can't add a selection yet (for
  example another day's plan is unfinished), the conversation says why, beside
  "Try adding again".
- **"When should I leave for my 14:00?"** answers from the sample day's
  appointment trip with Today's rule: leave now for the 13:14, 12 min to spare;
  the 13:24 in the plan leaves only 2.

Checked from Today, Events, Paperwork, Travel and Services (each with an
earlier conversation): the Composer bar on each page, Today's My Plans, Events'
Review plan, Paperwork's step help, Travel's trip and route help and Services'
request all land on their own entry.

## Polish and fixes — 7 October

Composer review items 7–16.

- **New replies stay in view:** after a reply arrives, your question and the
  reply sit just below the Composer bar; a reply too tall for both starts there,
  so its card isn't hidden under the input.
- **Sheets on phones:** Quick look, Add appointment and Try Composer open as a
  bottom sheet that slides up and closes by dragging its header down, like
  Today's sheets. Desktop keeps the compact dialog and the hover preview.
- **Quick look:** shows the listing's facts, exactly what it doesn't say (for
  example "Opening hours, entry and accessibility aren't in the listing"), the
  event date in words, and Add to plan. The Map button has a pin icon.
- **One plan, once:** while the live plan for a day is in the conversation,
  earlier versions stay in Version history instead of repeating as "Earlier
  plan" cards. Undo says what it undoes ("Undo confirmation" or "Undo this
  change").
- **My Plans:** the week marks Today; the date picker is one calendar button
  beside the day's heading; build notes ("Weekly planning is coming later",
  "Preview only") are gone.
- **Loading:** "Thinking…" and Cancel share one line.
- **Plain words:** Try Composer offers "What's on tonight", "Getting somewhere"
  and "Registering your address"; plan changes are "Ask me first" or "Make small
  edits for me" (button: "Edit for me"); bookings and payments "always need your
  OK".
- **A start screen that knows your day:** "Getting to your 14:00 Blue Card
  appointment", "What's on tonight", "Plan my Saturday", "A little time
  outdoors".
- **Places:** each card names what its listing lacks instead of "Access and
  hours to check"; the outdoors answer offers "More outdoor places".
- **Small fixes:** Shift+Enter starts a new line; History says "1 message" and
  dates today's conversations with the sample day.

## Helpful replies and page context — 7 October

Composer review items 1–6.

- **One sample day:** Composer reads `../connected/sample-day.js` (Wednesday
  30 September 2026, 13:10, the same day Today shows) instead of the real
  clock. "Today", "tomorrow", weekdays and "this weekend" count from it, and a
  plan for today starts after 13:10.
- **Everyday questions:** greetings and "What can you do?" get a short answer
  with four suggestions; events by time and topic ("tonight", "this weekend",
  "concerts"); kids, sport and swimming from the sample places; doctors and
  other services point to Services; cafés, rain and opening times say honestly
  what the sample data can't answer. An unclear request no longer repeats the
  same sentence, and only the latest reply shows its suggestions.
- **Page context:** the Composer bar on Events, Travel, Paperwork and Services
  shows "About: …" (removable) and sends what the page shows
  (`../connected/page-context.js`). Composer answers "Which of these is good for
  meeting people?" from the listed events, uses Travel's journey, Paperwork's
  first step and Services' chosen category. The request shows "From …".
- **Details kept:** journey questions keep the places and arrival time
  ("from Neumarkt to Mülheim by 14:00"), or take them from Today's journey; the
  result links to Travel's sample routes. Day plans keep the plans you already
  have (Today's day, the sample day's coming plans, and any you mention, such as
  "I already have Language class in Ehrenfeld at 18:00").
- **Questions don't plan:** "When is my next appointment?" answers from your
  plans and changes nothing.
- **Upcoming events only:** Composer shows sample events still to come; captured
  listings that have passed are marked past and can't be added.

## Shared palette and fixes — 7 October

Composer uses the shared cream palette from `../connected/tokens.css` (see "Composer on the shared palette" in `../connected/design-decisions.md`). The toast now sits just above the input whatever its height, Edit time opens below itself without moving, the desktop side panel starts below the app header, and the Composer bar lines up with the conversation column.

## Connected workspace update

The three-tab bar is removed. Collections open beside the response on desktop and slide into a focused full-width view on mobile. Back, Escape and browser Back restore the Composer response, draft text, selected working date and page scroll. Confirmation updates the one active timeline in the conversation. The contextual View plan shortcut returns to it; My Plans holds the confirmed version.

Panels use a 300 ms entrance and 220 ms exit, with a short 230 ms response transition. Bookmark feedback is retained. Reduced-motion preferences skip these animations. Mobile panels make the background inert and keep keyboard focus within the active view.

## What to try

1. Choose **A little time outdoors**, bookmark a park, and open the header bookmark. Select **Add to plan** on each place you want, in recommendations or Saved. The collection stays open, each selected card shows **Added**, and the contextual plan shortcut updates. The active plan updates in the chat without moving you away from the recommendations. Select **View plan** when ready.
2. Edit the date or time, swap the park, or remove it. Confirm explicitly to put the day in My Plans.
3. Select the input's **My Plans** control. Choose the day and **Adjust with Composer**. The panel closes onto the proposal. Try “Move the park later”. Review the old/new times before **Apply changes**.
4. Ask “What have I planned?” to see an answer from the confirmed day. A question does not change the plan.
5. Try **Plan my Saturday**: the plan keeps your Walk by the Rhine at 10:30 and adds a park around it. Conflicting time edits are blocked before they change the plan. (“Plan Saturday afternoon around my 15:00 appointment” still adds the fixed sample appointment.)

## Ongoing conversation and approvals

Messages persist in this browser. New requests, the shared inline loader, cancellation/retry and replies appear in order. A single active plan card updates in its original position; saved snapshots stay read-only under Version history. Chat and manual time edits use the same overlap validation. Requests naming a fixed appointment, an unknown activity or an ambiguous park cannot silently edit another activity.

Try this complete flow:

1. Start fresh, then ask **Plan Saturday around my 15:00 appointment**.
2. Ask **Move Blücherpark to 14:00**, or edit its time directly. **15:00** is rejected because of the fixed appointment.
3. **Confirm plan** (or type “Confirm my plan”). Ask **Swap Blücherpark**. Choose an inline alternative or **Browse more places**, then Apply changes.
4. Inspect **Version history**, then **Undo last applied change**. Older alternatives become inactive as the plan changes.
5. Opt into **Allow routine plan changes**. A later valid edit applies automatically, with Undo. This setting never authorizes bookings, payments or submissions; none are connected in this preview.
6. Ask a registration question: the plan becomes a compact reference and the reply explains missing verified coverage. Open the current plan explicitly before asking to change it again.
7. **Start fresh** preserves original messages, plans, saved ideas and unfinished proposals. **History** reopens a section; refresh preserves it. A pending request interrupted by refresh becomes retryable.

The context projection keeps the latest 12 entries plus a deterministic older-topic summary without deleting originals. This is a model-integration seam, not live AI memory: the scripted responder does not perform semantic retrieval. Authoritative plans and approval state remain separate from summaries. There is no server persistence, retention/deletion service or model/tool permission enforcement yet.

## Scope and boundaries

Composer remains the general engine for questions, recommendations, planning and other supported actions, with data and confirmation guardrails. Replacement cards are one visual interaction within that workspace, not a restriction on its broader capabilities. This prototype still uses scripted sample responses.

### Plan-item quick previews — approved final-review addition

The deferred quick-preview decision was approved at final review. `review-features.js` supplies the shared preview for plan activities, including tap access and the loading treatment.

- Desktop: a brief hover over an activity name reveals a compact preview of the actual place or event.
- Mobile: press-and-hold opens the preview; tap also provides a discoverable way to open it.
- Show actual item content, relevant details and actions, with the shared loading treatment while content is being fetched. Offer **Open full details**, keep the preview usable while interacting with it, and return to the same plan position on dismissal.
- Use a compact anchored view on desktop and a compact sheet on mobile. Do not squeeze an entire page into a tooltip or invent missing item details.

- [x] Included in the approved Composer workspace.

Confirmation sizing: View day and Make a change align without stretching one another; timeline descriptions retain their own compact spacing. The confirmation heading scales from 24–32 px, and the success badge is 32 px on phones / 36 px on larger screens. Action targets remain at least 44 px high.

Time editing uses a compact inline range with two 24-hour text fields and a checkmark save action. Enter `13:30` or `1330`; invalid or reversed ranges remain open with an inline explanation. The editor is 71 px high before errors, with 44 px input/action targets and no native time popup. Each field opens a compact picker with independent hour (00–23) and minute (00–59) wheels. Both values are centred before the popup is revealed, including the boundary values. Each wheel supports native touch/trackpad momentum, mouse dragging with a bounded glide, and arrow/Home/End keys; left/right switches columns. Double-click a time field to type the full time, or a wheel value to type only its hour or minute. Scrolling resumes wheel control from the typed value; the other column stays unchanged. Enter commits a typed wheel value, Escape cancels it, and invalid hour/minute values retain the previous selection. The picker floats over the timeline, has Done and Type time actions, and dismisses with Escape or outside interaction. A single animation settles each wheel onto its final row after native scrolling stops; a new gesture interrupts it immediately. Reduced motion skips gliding. Wheel choices remain in the editor until Save time applies them to the proposal. Phone and desktop browser checks verified first-open centring, independent wheel scrolling, mouse dragging, 00:00 and 23:59 alignment, exact-minute typing, keyboard navigation, saving and return of focus.

### Avoiding conflicting times

Adding a place proposes the next free one-hour slot from the sample's preferred afternoon time, skipping occupied intervals and preserving existing activities. Fixed appointments reserve their own time. If no slot fits or a fixed appointment conflicts, the addition is rejected without partial changes. The added-item message includes its proposed time. This only checks schedule overlap; travel and availability still require checking.

Saving an overlapping time edit is blocked inside the editor and leaves the plan unchanged. The message identifies the other activity and its occupied interval. Move-later sample requests also choose an unoccupied interval. Existing saved drafts with overlaps retain an explicit warning that includes the overlap duration; they are not silently rescheduled.

### Place replacement

The filter row combines a **Saved** toggle with **Category · Any**. Categories expand inline and collapse after selection; search, Saved and category filters apply together immediately. Category choices come from the captured catalogue. Empty results offer **Clear filters**. A selected replacement is cleared if filtering hides it, so an unseen choice cannot be applied. Escape closes expanded categories before dismissing the panel.

**Swap place** opens a dedicated replacement panel: full height on mobile and a wider side panel on desktop. Cards offer a photo when available, category, neighbourhood and captured details. Search matches names, categories and neighbourhoods; Saved restricts choices to bookmarks. Selecting a card is tentative until **Use this place** is pressed. The panel closes with a brief highlight on the updated activity and an Undo action. It excludes places already in the draft, shows each alternative’s category and area, and keeps the original start/end times. Choosing a replacement uses the shared preparation loader. Back or Escape dismisses the panel without changing the plan. The visible plan stays read-only while comparing replacements. Focus and scroll return to the activity. Confirmed plans remain unchanged until the revision is applied. These are captured sample options; travel and current availability still require checking.

### Shared loading motion

`../connected/loading.js` and `loading.css` provide the reusable Expadu spark, button-sized indicator, status, content skeletons and a centred first-load treatment. See `/dev/design/loading-preview/` to inspect every variant. Composer responses and confirmation use them now; the other accepted department previews and production app have not yet been wired to this shared component.

Indicators appear only after 140 ms. The scripted Composer demo intentionally waits 1.1 seconds to show the treatment, with explicitly labelled sample preparation. Production callers should finish the indicator when their real operation settles, without the sample delay. Existing responses stay visible; only the initial empty invitation gives way to skeletons. Cancel or navigating to another view aborts the pending sample operation. Repeated submission is disabled while busy. Reduced-motion settings stop the spark and skeleton animations.

Swap place also uses the preparation loader. Adding a captured place is an immediate local selection: it keeps the current cards and scroll position, preserves the typed request, and shows a checkmark and draft count without an artificial loading pause. The same View plan control stays reachable inside Saved on mobile. Pickers, removal, bookmarks and navigation also keep their direct feedback.

Timeline refinement: activity spacing is 16 px on desktop and 14 px on mobile, with 44 px mobile action targets. The proposal label has its own row; the date and calendar control share the next row. Browser checks verified matching vertical centres on phone and desktop, three-activity layout, an in-bounds mobile date picker and successful swap/add preparation flows.

This is a scripted design prototype, not a live Composer engine. Discovery uses two existing captured park listings, while the replacement chooser also includes four other existing captured places; unsupported requests and bureaucracy questions show an explicit missing-coverage response. No new legal guidance is authored.

Plans and chat use `expadu-composer-studio-v1`. The shared `expadu-connected-design-v1` store carries department selections, request text and bookmark details. Bookmarks synchronize in both directions without scheduling them. Existing Composer bookmarks are merged on the first connection. Legacy saved-day snapshots remain stored under the old key; they are not treated as confirmed timed plans. Confirmation creates no booking, route or reminder. Stored approvals apply only to this local plan demonstration. Travel and current availability remain unverified. There is one unfinished proposal at a time; switching to another day requires finishing or discarding it.

Included: question/discovery/planning response layouts, bookmarks and Undo, inline date/time editing, swap/remove, confirmation, day/week selection, confirmed-plan questions and reviewed revisions. The same plan can be reopened after refreshing.

Still to design and integrate: general conversational intelligence, richer event/service sources, multiple concurrent proposals, full weekly planning, reminder configuration/delivery, routing and backend persistence. This connects the approved Composer design to Today C; it does not connect live services or the Laravel application.

## Verification

- Panel history changes only the URL fragment, preserving `index.html`, query parameters and legacy My Plans document paths. Three URL regression tests cover direct-file and served previews. Browser verification of the served `index.html` route confirmed Saved close and replacement apply preserve the document address. Direct `file://` browser automation is unavailable, so file URL preservation is covered at the helper level.

- Dedicated replacement panel checked on desktop and phone: name/category search, Saved filtering, tentative selection, cancel, explicit apply and Undo. Selected cards do not mutate the timeline until applied; original times are retained.
- Replacement regression: with both parks already in the proposal, the chooser exposes the four other captured places and excludes duplicates. Desktop and phone checks confirmed replacement and preserved times; Escape dismisses without changing the draft. Model tests protect fixed appointments and confirmed plans.

- Scheduling regression: an overlapping edit left the original timeline unchanged and showed the occupied interval inline. Adding a third activity from Saved used 17:00–18:00 after an occupied 16:00–17:00 slot; Saved stayed open and the reviewed proposal remained confirmable.

- Multi-selection regression: added both places from recommendations without navigation, retained the typed Composer request, then reviewed a two-place proposal. On mobile, added both from Saved while keeping the panel open; its Review plan action opened the complete draft. No horizontal page overflow.

- Twenty-one focused model tests cover bookmark separation, explicit confirmation, revisions, duplicate activities, overlaps, adjacent times, valid dates/times, other-day preservation, rejection of stale revisions, sequential selections that preserve the current response and existing draft edits, combined replacement filters, accent-insensitive search, empty results and typed time normalization.
- Replacement category controls checked at phone and desktop widths: combined Saved/category filtering, search, empty results, Clear filters, automatic category collapse and Escape dismissal. No horizontal page overflow.
- Five wheel-motion tests cover bounded momentum, smooth settling without overshoot, gesture interruption, cleanup during motion and reduced motion.
- Four additional loading tests cover instant completion without flashing, cleanup after cancellation, stale completion protection and escaped status content.
- Browser checked on desktop (1280 px) and narrow phone widths (354–390 px): discovery, bookmark, inline edit, confirm, My Plans, follow-up revision, apply, answer and refresh persistence.
- Browser verified overlap explanation and disabled confirmation, empty-plan answer, unsupported request, unknown request and Enter submission. No console warnings/errors appeared during the main flow. Checked phone layouts for horizontal page overflow.
- Formatting and JavaScript syntax checks pass. No application/backend files changed.
- Connected-panel regression checks: retained an unfinished typed request through Saved open/close; mobile browser Back restored the exact scroll position (326.82 px); browsing Sunday and pressing Escape restored Saturday's working proposal; saved-item handoff, inline confirmation and panel-to-revision flow checked. Desktop panel and mobile full-width layout reviewed; narrow viewport had no horizontal page overflow. Reduced-motion handling checked in the source; OS preference was not changed during QA.

To run the focused tests from the repository root:

```sh
node --test prototype/dev/design/composer-studio/tests/*.test.cjs
```

### Conversation verification — 18 September

- Phone (390 px) and desktop (1280 px): inline planning, chat time edit, explicit apply, replacement suggestions, stale suggestions, Undo, routine auto-apply, topic switch, Start fresh, History and reload verified. No horizontal document overflow.
- Manual overlapping edit and matching chat request both rejected against the same fixed appointment. A valid manual edit changed the active timeline. Cancellation retained the request and left the plan unchanged.
- Desktop My Plans showed the confirmed time while the chat held an unconfirmed time change. The unfinished input text survived opening and closing the panel. One active plan component remained in the DOM.
- Fifty focused Node tests cover domain logic, history migration, immutable versions, bounded context, approval defaults, routine changes, guarded Undo, date-move Undo, ambiguous/fixed targets, stale suggestion rendering, navigation, loading and wheel motion.

## Today C integration verification

- Today’s bookmark opens the same Saved collection. Adding multiple places stays on Today until Review opens their combined proposal.
- Confirmation returns as a confirmed plan on Today, and Review opens that exact date in the conversation.
- A request submitted on Today appears once in chat; refresh does not submit it again.
- Places and Events links open this workspace. Events preserve their captured date and fixed time. Selections that cannot join the current proposal remain visible with a retry action.
- Service, journey and incomplete appointment selections retain their recorded context. An appointment form prefills its recorded details and asks for the missing end time. No timing or booking is invented.
- Mobile (390 px) and desktop (1280 px) integration checks cover bookmarks, multiple selections, confirmation, exact-day return, refresh persistence and horizontal overflow.

Run the local-server integration check with `node prototype/dev/design/composer-studio/tests/connected-browser.cjs` while the prototype server is on port 8765.

The department recovery check is `node prototype/dev/design/composer-studio/tests/department-browser.cjs`.

### Today input surface

Today now uses the approved compact Composer surface: message above tools, a 36 px visual send circle inside a 44 px target, upward arrow and disabled empty state. The plus menu opens My Plans, Saved, History, Add appointment, Try Composer and Start fresh. The approval setting is shared with Composer. Existing confirmed plans and chat history remain preserved when entering through these controls.

### Bureaucracy follow-up review — 28 September

Composer and Bureaucracy share the same optional intro and personal-details
surface. It supports missing answers, explicit unknown/declined responses,
correction versus real change, history and synthetic conflicting-answer review.
Only confirmed answers replace saved details. Handoffs retain the selected
person, current question and unconfirmed choice, including authorised adult and
dependent sample views. Switching person or losing sample edit access closes
access to the old person's answers. Conversation entries retain only the subject
identifier, not copies of their answers or documents.

The unfinished Composer prompt, pending proposal and confirmed days remain
independent. Return goes to the same person in Bureaucracy. These are local
interaction fixtures; no legal assessment, family invitation or real access
grant is performed.

### Contextual places — 29 September

Places is no longer a top-level department. Discovery and Saved retain place
records in compact cards built around category, name, neighbourhood and recorded
facts. Optional photos are supporting thumbnails and collapse on failure. Detail
previews retain source information and add map/directions links carrying the exact
place identity. Save, Add to plan, replacement and explicit proposal approval use
the existing state model. The scripted catalogue is still sample data.
