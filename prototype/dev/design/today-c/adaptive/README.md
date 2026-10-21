# Adaptive Today — three layout previews

Requested by the owner on 30 September 2026. These are review alternatives;
the restored staggered dashboard at `today-c/` remains the accepted baseline.

Open `http://localhost:8765/dev/design/today-c/adaptive/` and use the three
preview controls. They select illustrative signals, not a saved user preference.

- **[Everyday](http://localhost:8765/dev/design/today-c/adaptive/?view=everyday):**
  the personal overview leads, followed by the saved-plan strip and mixed board.
- **[Big event](http://localhost:8765/dev/design/today-c/adaptive/?view=event):**
  one designated event leads with its existing immersive image, Save and Plan
  controls. The overview sits beside it on desktop and follows it on phones.
  The same event is moved out of the lower discovery row, avoiding duplication.
- **[Route delay](http://localhost:8765/dev/design/today-c/adaptive/?view=delay):**
  an illustrative delay is the first, larger item inside the overview. Scheduled
  and expected times, a route action and Composer are grouped together.
  Paperwork and weather remain alongside or immediately below it.

All variants retain the practical cards, paperwork controls, shared planning,
saved items and the remaining event discoveries. Phones retain one swipeable
event row. There is no new artwork, autoplay or background rearrangement.
The compact preview switcher is a review tool, not proposed application chrome.

## Priority and data boundaries

The presentation selector demonstrates this order:

1. An active, unexpired delay matching the person's route.
2. A designated important event on the displayed day that has not ended.
3. The ordinary overview.

Expired, resolved or unrelated delays cannot promote themselves. An ordinary
event, missing timing or a different event date does not create urgency.
These are proposal rules, not a live ranking service or an approved replacement
for all future agenda priorities. Existing recorded next actions remain inside
the overview; Composer and personal agenda state still supply that context.

The examples use 8 September 2026 for the event and interruption signals.
The delay and departure times are clearly labelled illustrative and not live.
Other cards continue to show browser-local records and labelled sample content.
Changing preview mode does not write plans, profiles, prompts or saved items.

"See routes" opens Departures with the example origin, destination and time;
it does not fabricate route results. Composer receives an editable draft that
identifies the delay as illustrative. Existing draft text is preserved and no
message is submitted automatically. Backend feeds remain outside this work.

## Review checks

The eight priority tests cover ordinary days, important events, competing
signals, route relevance, expiry, invalid data and input immutability.
All 251 relevant Today, Events, shared-flow and asset-version tests pass.
Browser checks cover phone, tablet and desktop layouts, event promotion without
duplicates, fixed-time planning, route handoff and editable Composer entry. Carousel checks
cover cards moving into and out of the featured slot; drag and keyboard
navigation use the current card order.

Screenshots: [everyday desktop](review-everyday-desktop.png),
[event desktop](review-event-desktop.png),
[delay phone](review-delay-phone.png), [event phone](review-event-phone.png).
