# EXP-69 staging Places readiness specification

Date: 2026-09-30. Ticket remains In Progress. Base: staging 89289db9641bb75a563e74b44be9b4717bd61b22.
Approved product direction: readable data shared by Places and Composer, retained links, reviewed facility discovery, explicit uncertainty, approved media rights, and the current design.
This is a scoped port of already implemented candidate behavior from 70d6581686114428c45d193682b697a11d242a14. It does not replace staging with the production candidate.

## Required behavior

1. Retained detail links continue to return the same ID, name and source facts. The detail response states recommendation_status = unavailable if the shared recommendation policy excludes the record; open_now and price_text are null in that case. Eligible details state available. An unavailable place must never appear to be simply closed for the day.
2. A reviewed descriptive facility stays out of general discovery. An explicit fine facility category can find it in Places, map search and Composer. Mixed broad/fine selectors only admit the explicitly requested fine facility category. A category is not evidence of a sport or a price: unknown stays unknown.
3. A positive activity qualification requires current known unconditional public access. Qualification binds to both source observation history and access-correction history, including withdrawn corrections. Later access changes invalidate the earlier qualification. A combined access/activity review uses the access correction written in its own transaction. Failed combined reviews roll back fully.
4. Startup curated name-only seeding and automatic catalogue/photo writes require explicit environment enablement; default disabled. Other services remain scheduled. Existing command registrations remain usable for explicit reviewed runs.
5. Preserve current staging source-withdrawal logic, existing media provider exclusions, approved-rights/active-health gates, retained identity aliases, current design and unrelated bureaucracy changes.

## Scope and completion

Backend API and Composer behavior, focused tests, configuration, release notes, isolated read-only staging API evidence, one fresh whole-branch review, a normal-hook commit and draft PR targeting staging.
No migration or data writes are part of this code port. No shared branch push, merge, app deployment, production operation, facility approval, media approval or outbound message.
This code does not claim 75% photo coverage, useful free-football coverage, frontend rendering of unavailable status, or full production readiness.
A later explicit code release must select its automation settings and verify authenticated external HTTP behavior. Media revalidation is included in the paused automation group; leaving the group disabled stops scheduled remote-health checks as well as acquisition, so this must be stated in the release decision.

## Existing evidence boundary

The staging source-null hold is committed separately: all 11,802 stored rows retained, 4,235 eligible place/component rows (4,224 general destinations), 119 policy-publishable hero associations (2.817%). These are environment-specific counts. This port does not acquire photos or qualify the pending 90 facilities.
The corrected football discovery probe uses activities=[soccer], categories=[pitch]. The unsupported football-label summaries remain historical and are not request-path evidence.
