Design a complete, adaptive Today page for **Expadu**, a daily companion for people living in a new city. Create a high-fidelity visual proposal for review before implementation.

The central question is: **“What matters to me today, and what can I do next?”**

The page should feel personal, useful and lively. It must combine the clarity of a well-designed native application with the visual excitement of discovering something happening in the city.

**Product and audience**

Expadu connects personal plans, events, journeys, paperwork and services through an assistant called Composer. Cologne is the initial city, but the design must work elsewhere.

Users have different needs. Someone may be commuting, preparing documents, attending an event or simply exploring. Some are older, unfamiliar with local bureaucracy or using the application for the first time. They should understand the page without learning a system of unexplained controls.

Events bring personality and enjoyment. Personal plans and actionable changes determine urgency. Today must accommodate both.

**Design direction**

Use a coherent, expressive dashboard with an intentional staggered composition on desktop and a carefully ordered feed on mobile.

Preserve the useful character of the earlier mixed dashboard: different content types, varied card proportions, strong photography and compact practical information. Improve the relationships between these elements.

Avoid turning the page into a generic website with a marketing headline, oversized promotional button and rows of interchangeable boxes. Equally, avoid an empty “minimal” dashboard that removes useful content.

Visual richness should come from composition, imagery, typography and meaningful colour—not from adding more controls.

**One adaptive page, three situations**

Produce three states of the same design system. The hierarchy should change because the user’s situation changes.

1. **Everyday**

   The personal overview leads. It quickly communicates the next relevant plan, journey information, paperwork progress and useful weather.

   If there is an upcoming activity today, make its time and next action easy to find. If the user has no agenda, show a calm, useful overview with clear starting points.

   Event discovery follows the practical overview. Keep it visually attractive without making an ordinary recommendation more important than the person’s day.

2. **Important event**

   A genuinely significant, relevant event can become the main visual feature: a city festival, concert, sporting occasion, film event or another worthwhile highlight.

   On desktop, give the event a generous immersive area and keep the personal overview visible beside it. On mobile, show the featured event first, followed closely by the overview.

   Promote the existing event card rather than displaying the same event again in the discovery section. The remaining discoveries should stay available.

   The event earns this position through relevance and editorial importance, not because it happens to be first in a list.

3. **Important personal update**

   Show a delay affecting the person’s usual route as the first item **inside the personal overview**.

   Give the affected journey stronger visual emphasis while retaining its relationship with the other overview items. It should feel like the overview has adapted, rather than a warning banner being pasted above the page.

   Present the route, size of the delay, scheduled time, expected time and an obvious action to check alternatives. Offer Composer as a secondary way to get help.

   Keep paperwork, weather, plans and events accessible below or alongside it.

**Priority rules**

Use this proposed order:

- An immediate, relevant change affecting an existing commitment or journey.
- The next time-sensitive personal action.
- A significant event or city update relevant today.
- The ordinary overview and practical next steps.
- General discovery.

A disruption affecting an unrelated route should not lead. Neither should expired news, a past event or a generic recommendation.

A major event must not bury a more urgent personal problem. Where several important items exist, choose one clear lead and present the others compactly.

Keep the layout stable while someone is reading. Apply substantial reordering when the page opens or the user refreshes, with restrained transitions. Do not continuously shuffle cards in the background.

These rules describe the intended experience; live feeds and backend ranking are outside this design task.

**Personal overview**

Use **“Your day”** as the working title. Keep the greeting and date modest so they do not consume the most valuable screen space.

The overview should be a compact, connected surface with clearly distinguishable information:

- **Plans:** the next relevant activity and its time, with direct access to the day’s agenda.
- **Connections:** the next relevant journey, current journey status or a simple route-planning entry.
- **Paperwork:** a meaningful next step and recorded progress.
- **Weather:** a small useful forecast; promote weather only when it materially affects the day.

These are adaptable content roles, not four mandatory equal-sized cards. Combine or shorten items when appropriate.

Communicate status through concise titles, times, progress and familiar symbols. Avoid explanatory paragraphs in the overview.

Distinguish confirmed plans, drafts and saved ideas. A bookmark is not a confirmed activity.

**Fix the paperwork interaction**

Remove the current combination of an opening arrow, previous/next arrows and a page counter inside one card.

Paperwork should be understandable without discovering a carousel.

Use a compact process panel:

- A document icon and “Paperwork” identify the category.
- The most relevant process appears as the primary row.
- Its title and short progress statement are immediately readable.
- Tapping the row opens that exact process.
- Additional relevant processes can appear as compact rows, with one clear route to all paperwork when necessary.

Use one navigation treatment consistently. If a trailing chevron indicates that a row opens details, it should always mean that throughout the page. Do not add another prominent arrow that performs the same action.

A progress count such as “1 of 5 documents ready” must refer to actual progress. Do not place a competing “1/3” carousel counter beside it.

Preserve access to every process without displaying every detail on Today. Do not invent requirements, deadlines, fees or legal advice.

**Event cards and imagery**

Events should bring energy to the page. Design them as immersive image surfaces, with essential information integrated into the artwork.

Use expressive photography or suitable event artwork showing people, atmosphere and activity. Support a broad programme: music, sport, film, games, exhibitions, outdoor activities and city celebrations.

Keep event titles, dates and essential timing readable over a carefully controlled gradient. Avoid dividing the card into a photograph on top and a large white information block underneath.

Use:

- A compact date marker.
- A distinctive but readable event title.
- Small icons for time, location and admission where useful.
- A translucent, blurred bookmark control for Save.
- A separate calendar-plus control for planning.

The image/card opens details. Save and planning remain distinct, independent actions. Their touch areas must not interfere with opening the event.

Use bold display lettering selectively for a featured event. Ordinary interface text should remain calm and consistent.

If an image is unavailable, provide a deliberate typography-and-category treatment. Do not stretch an empty placeholder or imply that an unrelated image depicts the actual event.

Use existing approved assets where possible. Clearly identify illustrative or sample imagery; do not imply that generated scenes are photographs of a real listed event.

**Desktop composition**

Make deliberate use of the available width. On a 1440px screen, the main content should feel comfortably substantial, with balanced outer margins.

Use a consistent underlying grid to align mixed card sizes. The staggered arrangement should have a clear reading order and a balanced silhouette.

For the everyday state, the overview leads into the mixed dashboard. For the event state, the lead image and compact overview form the top composition. For the delay state, the connections item grows within the overview.

Size practical cards according to their content. Avoid large empty areas around one line of information. Give event imagery room without making every event a giant poster.

Do not repeat the same paperwork status, journey and agenda count across multiple prominent sections. A summary and a deeper action can coexist when they serve different purposes.

Preserve all useful capabilities while consolidating redundant presentation.

**Mobile composition**

Design the phone experience first, around a 390 × 844 viewport. Also check narrow 320px screens.

The first useful item and its main action should be visible without scrolling. Keep the header, navigation, greeting and section spacing compact enough to achieve this.

Use a clear vertical sequence:

- Everyday: personal overview → relevant next steps → event discovery.
- Important event: featured event → personal overview → remaining content.
- Personal disruption: affected journey within the overview → other personal information → discovery.

On ordinary days, use one horizontal event collection with a visible hint of the next card. Support native swiping, mouse dragging and keyboard navigation.

On event-led days, the promoted event appears once. The remaining event collection may follow later, without repeating the featured item.

Avoid squeezing two information-heavy poster cards into narrow columns. Do not stack multiple competing event carousels.

Practical information should remain readable and easy to tap. Do not shrink text to compensate for oversized chrome.

**Icons, labels and actions**

Prefer familiar symbols where the meaning is clear. Use one consistent custom icon family with matching proportions and stroke weight.

Maintain a stable vocabulary:

- Bookmark: save for later.
- Calendar-plus: add to a proposed plan.
- Calendar: agenda or date.
- Transit symbol: journey or departures.
- Document: paperwork.
- Composer symbol: ask or continue with Composer.
- Chevron: open or disclose content, according to one consistent convention.

Use arrows sparingly. Every arrow must have an obvious purpose and should not compete with another similar arrow nearby.

Keep short labels when an icon alone would require guessing. Accessibility names and tooltips should be included, but tooltips must not be the only way to understand a mobile action.

Each card needs one clear primary action. Additional actions should be visually secondary. Avoid large generic buttons such as “Learn more” and repeated “View details” labels.

**Composer relationship**

Composer connects the day’s information and actions. It should remain easy to reach without becoming another large panel above the content.

Keep a consistent, compact entry point. When opened from a card, Composer should receive that card’s context:

- An affected route when checking alternatives.
- An event when considering attendance.
- A specific paperwork process when requesting help.
- The current day when reviewing plans.

Preserve the person’s existing draft and conversation. Let them review an editable request before sending. Opening a card action must not silently submit a message or change a plan.

The Composer control must not cover another important button or the last row of a card.

Global search is a recorded future feature. Preserve room for its later design rather than adding another input to Today now. Keep the existing logo and shared navigation.

**Colour, typography and surfaces**

Stay within Expadu’s shared visual language:

- Pale canvas around `#F6FAFB`.
- White practical surfaces.
- Dark primary text around `#211D15`.
- Cyan selections and state indicators.
- Orange actions, using the accessible action shade where needed.
- Purposeful yellow and purple accents for events and relevant notices.

Use Geist or the established application typeface for interface text. Let photography and selective display typography provide expression.

Keep surfaces friendly and soft. Use restrained tonal separation and light shadows. Avoid prominent black borders, boxed footers and layers of nested outlines.

Use frosted glass selectively over photography. Practical content should remain crisp and readable.

A relevant delay may use a restrained amber tint, with explicit timing and wording. Colour alone must not communicate urgency.

**Interaction and motion**

Use short, smooth, interruptible transitions. Touch feedback should be immediate and subtle. Respect reduced-motion preferences.

Keep layouts steady when opening details or changing state. Preserve focus and scroll position. Do not depend on hover to reveal essential controls.

Use compact inline disclosures or contextual panels for simple choices. Maintain browsing context.

Where a sticky action area is necessary, blend it into the content using a soft fade. Do not create a hard horizontal boundary or obscure the final content row.

Retain the established activity-removal interaction: swipe left or double-tap reveals a compact red Delete action beside the item. The reveal does not itself delete the activity. Preserve a keyboard-accessible route to the same action and an undo after removal.

**Content, empty states and trust**

Write short, useful microcopy. Prefer a clear noun, time, status or action over a full sentence.

An empty agenda should offer a useful starting action, while retaining weather, relevant practical tools and discovery. Do not leave the page empty or manufacture urgency.

New users should enter the application immediately. Settlement help is optional and offered in context; it must not block Today.

Show loading, unavailable and stale information honestly. A missing live connection must not look like a verified live status.

Use coherent dates and times within each review scenario. Clearly label sample updates and fictional examples. Keep real recorded progress separate from illustrative content.

**Required design output**

Present one coherent visual direction in six high-fidelity views:

- Everyday: mobile and desktop.
- Important event: mobile and desktop.
- Route delay: mobile and desktop.

Show enough of each page to assess the first viewport and the content below it. Include the complete mobile reading order.

Also show a small interaction detail for the paperwork panel, the event’s separate Save/Plan controls and the contextual Composer entry.

Annotate only what cannot be understood from the visual design. Explain briefly why each state leads with its chosen content.

**Review standard**

Before presenting the proposal, verify:

- Can a new user identify what matters and what to do next within five seconds?
- Does the first screen contain useful information and an accessible action?
- Is the desktop composition intentional and balanced?
- Does mobile preserve content without crowding or repetition?
- Are all arrows and icons unambiguous and consistent?
- Is paperwork usable without hidden carousel behaviour?
- Does the event imagery feel lively while remaining readable?
- Does adaptation promote relevant content without duplicating or deleting it?
- Are plans, saved ideas, drafts and sample information distinguishable?
- Is this recognisably Expadu across all three situations?

Deliver the design proposal for review. Implementation begins only after the direction is approved.