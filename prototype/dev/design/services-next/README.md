# Services — connected prototype

Preview: `http://127.0.0.1:8765/dev/design/services-next/`

## Finishing pass — 29 September 2026

Keep requirements on the page, with compact category navigation and a white
workspace. Area and language remain visible; insurance and notes sit under
More preferences. Category changes preserve each draft. Ask Composer carries
the requirements into the shared workspace, while the bookmark action retains
them locally. Neither action represents a booking.

Already have an appointment is a separate flow. The user supplies a name,
location and confirmation that it is already arranged, then enters the actual
date, start and end using shared date/time controls. The dedicated planning
sheet includes Your day and the common swipe/double-tap Delete reveal with Undo.
Its footer occupies its own row so it does not cover the timeline. No suggested
leisure times or invented appointment availability are offered.

Provider search is explicitly unavailable in this preview. No fictional provider
cards, appointments or success claims fill that gap. Saved requirements and
proposals use browser-local prototype state; live services and real application
integration remain separate.

## Verification

Reviewed the main page at 320px, 393px and 1280px. Checked draft retention across
categories, keeping requirements, appointment validation, exact-minute planning,
proposal addition and Undo. The memory-only fixture at
`tests/fixtures/services.html` isolates those checks from user data. Relevant
connected planning and motion tests pass.
