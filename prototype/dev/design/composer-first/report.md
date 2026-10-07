# Today: a day that starts with you

Focused audit and design rationale · 6 September 2026

## My recommendation

Make Today an invitation to describe a need. Composer should turn that request into a small, understandable set of choices and keep those choices together. The screen should feel welcoming before it feels like a catalogue.

The earlier mockups missed that purpose: one retained the existing composition; the other replaced it with discovery listings. Changing the arrangement and colours did not establish a better reason to use Expadu. This proposal changes the sequence of the experience: request → clarification when necessary → relevant ideas → an editable day.

## What the deeper check established

I ran twelve requests through the local deterministic parser, with a synthetic profile and the clock fixed at noon in Cologne. I also inspected Today’s request/save flow, the LLM parser contract and the candidate data model. These are local findings, not a live LLM evaluation, production data census or measured usability study.

| Request | Observed local result | Why it matters |
| --- | --- | --- |
| I want somewhere quiet | Search intent; no planning constraints | An ordinary statement of need does not enter the inline planning flow. |
| Today I feel moody and want somewhere quiet | Plan intent; no category or vibe | The word “today” changes routing, but quietness is still not captured. |
| I want to hang out | Search intent | Social intent needs interpretation beyond a keyword search. |
| Today I want to hang out | Plan intent; companions set to friends | Wanting company is not evidence that someone already has friends to go with. |
| I want to play football | Search intent | The action alone does not establish planning intent. |
| Today I want to play football | Plan intent; category pitch | A generic pitch does not establish the sport, access or a game to join. |
| Today I want to play volleyball | Plan intent; no category | The specific sport is lost. |
| Today I want somewhere quiet, no parks | Plan intent; category park | A negated preference becomes a positive category. |
| Today I have only 30 minutes | Plan window noon–10 p.m. | The explicit time limit is lost in the default window. |
| Take me to Museum Ludwig | Navigation intent | Useful routing already exists and should be retained. |
| I need help with my registration | Bureaucracy intent | This should remain a reviewed task flow. |
| Today I want to meet new people | Plan intent; no companions value | Meeting strangers is not represented as a distinct goal. |

The full captured output is in [the parser evidence](./evidence/parser-probe.json).

## The important gaps

1. **Understanding needs:** the constraints object has a nullable vibe, but neither inspected parser fills it. The LLM tool contract also lacks explicit quietness, specific sport, exclusions and a “join people” goal. A better-looking input cannot compensate for those missing distinctions.
2. **Evidence behind a match:** a park is not necessarily quiet. A pitch is not a verified volleyball court or an organised game. The place model has a noise-level field, but the candidate object has no dedicated field carrying that value. Specific sport, session availability and verification need an explicit path through the system.
3. **Keeping the journey intact:** Today sends non-plan requests and failures to the separate Composer page. Its inline response reads slots, without carrying the plan notices into this display. “Open in Composer” carries the prompt rather than the edited selection. These transitions can obscure what happened and what was kept.
4. **Saving precisely:** Today’s current inline save already sends the remaining item IDs. Preserve that correct behaviour. A redesign should extend this consistency across editing and handoff, rather than assume every save path is broken.
5. **Attention:** the current Today includes planning, attention tiles, discovery rails and a widgets rail. Those can be useful separately, but their combined priority conflicts with the requested “tell Expadu what I need” starting point.

## The proposed screen

**Before a request:** a short greeting, one prominent input and three optional starting phrases. Weather and paperwork remain a quiet context row. Places and city services remain accessible through navigation. No discovery catalogue appears before someone asks.

**After a request:** keep the original words visible and compact the welcome area. Ask one useful question only when the answer changes the recommendation—for example, a pitch or a game to join. Show a relevant idea with its reason and any uncertainty. Allow another direction without restarting the whole experience.

**When choosing:** add, review, remove and save in the same page. Further requests preserve the draft. The saved day changes only on an explicit save. The prototype makes these behaviours clickable.

I considered a discovery dashboard, a pure chat page and this input with structured results. A dashboard asks the user to browse too early. Pure chat makes comparing places and inspecting saved choices harder. The selected structure makes expression easy while keeping the answer tangible.

## Colour, form and friendliness

| Role | Colour | Purpose |
| --- | --- | --- |
| Canvas | #FFFDF9 | A warm, light background that gives content room. |
| Text | #273440 | Clear dark text without stark black. |
| Primary action | #BE4228 | A warm coral focal point for submitting and choosing. |
| Secondary actions | #096C76 | A quieter blue-green for details and navigation. |
| Playful accent | #FFE3A2 | A soft yellow backing behind the input and active starter. |
| Supportive surface | #E2F1E9 | Gentle green for choices and saved-state feedback. |

The original Expadu mark stays. Readable sans-serif type, a slightly offset input backing, rounded controls and restrained press feedback carry the personality. Playfulness has a small, consistent role; it does not add more tasks or sections. This is a proposed visual direction, not evidence that users will prefer it.

The first screen deliberately uses no place photography. Result illustrations are labelled as illustrations, never proof of a venue’s appearance. Production photos still need approved rights and healthy sources; no Stadt Köln image permission is assumed.

## What this mockup does—and what remains

This is a working local interaction prototype with a small set of scripted examples. It is not connected to Composer, live weather, departures, legal tasks or venue availability. Saved choices last for the page session. Real place names and a sample event are used to inspect layout; they are not verified recommendations.

Try quiet → fresh air → add → review → save; then ask for football and add another idea. Remove one before saving again. Try volleyball or “quiet, no parks” to inspect the honest no-match state. Paperwork and directions lead to distinct service states.

Before production implementation, prioritise a richer request contract, verified candidate attributes, explicit handling of hard constraints, and a shared draft that survives every handoff. Keep bureaucracy’s approval gate and media rights gate intact. Reliable places, events and departures remain prerequisites for useful Composer answers.

The next design decision is whether this entry screen feels inviting enough and whether its result flow is understandable. It should be reviewed as an interactive proposal before changing the app-wide visual language.

## Source trail

Local files inspected: `resources/js/pages/dashboard.tsx` (inline compose, remove, save and handoff); `app/Composer/HeuristicPromptParser.php`; `app/Composer/OpenAiCompatiblePromptParser.php`; `app/Composer/Constraints.php`; `app/Composer/Candidate.php`; `app/Models/Spot.php`. The parser probe is repeatable with the saved synthetic inputs and fixed time. Production was not modified.
