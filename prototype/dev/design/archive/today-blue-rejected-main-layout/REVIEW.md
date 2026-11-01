# Today — review against the agreed changes

Reviewed 7 September 2026. Scope: the interactive `today-blue` prototype.

| Decision | Result | Evidence / remaining limit |
| --- | --- | --- |
| Useful first visit without assumed personal data | Pass for the prototype | First visit has no saved ideas or checked steps. Local weather and connections ask for a city. The clarity panel, discovery and Composer remain available. Starting the sample checklist begins at zero. |
| Distinguish attention, saved choices and discovery | Pass | Priority cards retain contextual emphasis. Saved day has a cyan bookmark strip. Edits become “Unsaved choices”; clearing a draft remains reviewable. Undo restores its previous saved/draft status. Discovery has a short label. Topic changes keep the user's choices. |
| Smooth Composer loading, refinement and return | Pass for scripted flows | All request branches share immediate loading, cancellation and result focus. Returning during loading cancels late output. Retry retains the request. Indoor refinement shows an honest no-match state. Saving, returning and two-item handoff were checked. Typing alone stays on Today. Reduced-motion styling is provided. |
| Test the design against content quality | Partial | “Limited content” exercises a long place title, missing image and unavailable event dates. Detail and plan views remain usable. These are deliberate fixtures, not a review using verified live records. |

## Preserved

The accepted layout, top service navigation, cyan/orange palette, “A little clarity for your day” section and thumb-accessible Composer remain. No new homepage section or promotional hero was added. Legal figures and venue facts were not invented.

## Verification

- Nine focused automated tests passed: empty submit, common loading across three request types, cancellation, latest request winning, preserving constraints, visitor isolation, separate draft/saved arrays.
- Browser checks: first-visit checklist; saved choices across topic changes; typing without navigation; compose, save and return; leave during loading; stop/retry; indoor filter; clear draft and undo.
- No page overflow at 320, 390 and 1200 pixels in the checked states. Mobile and desktop screenshots inspected; no browser console errors.
- JavaScript syntax checks passed. Production app files were not changed.

## Verdict

**Review-ready prototype; not the final production version.** The agreed interaction changes are implemented. The remaining content decision needs real, rights-approved media and verified place/event records. Location search, live services and Composer intelligence remain disconnected; choices live only until reload. Real-data integration and user evaluation must happen before a final design sign-off. No additional homepage sections are currently justified.

## Main-page revision after research

The later visual pass supersedes the earlier discovery eyebrow labels and mobile swipe layout. It shortens the header, exposes all clarity summaries on mobile, changes place-card and event-list styling, strengthens cyan progress/selection, and reduces Composer obstruction. See [RESEARCH.md](./RESEARCH.md) for source links, the connection to the original report, measurements and the updated assessment. Thirteen focused tests now pass.
