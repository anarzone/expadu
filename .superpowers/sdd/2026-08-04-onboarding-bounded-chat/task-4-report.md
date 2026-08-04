# Task 4 Report: Consent, quota, and bounded message endpoint

## Outcome

- Added authenticated consent grant/withdrawal and bounded message endpoints.
- Added encrypted, hidden raw-message persistence with a 30-day expiry, `MassPrunable`, and a daily `model:prune` schedule.
- Enforced an atomic 20-message rolling 24-hour quota while holding the active case row lock.
- Added independent five-per-minute user and IP burst budgets with short-window fixed copy.
- Restricted extraction to the authenticated user's active case and exact current unanswered server-issued question.
- Returned only application-authored copies and labels; candidates do not mutate facts, questions, fact versions, or plan snapshots.
- Kept disabled/incompletely configured providers deterministic with zero message storage and zero network dispatch.
- Regenerated Wayfinder with form helpers.

## TDD evidence

- RED: 14 failed, 5 passed (43 assertions). Expected missing routes, message model/table, and named limiter.
- Focused GREEN: 31 passed (164 assertions):
  - `tests/Feature/Bureaucracy/BureaucracyAiPrivacyTest.php`
  - `tests/Feature/Bureaucracy/CaseMessageEndpointTest.php`
  - `tests/Feature/RateLimitingTest.php`
- Direct neighboring GREEN: 75 passed (266 assertions): Task 3 extraction contract/provider, structured question endpoint, and plan snapshot tests.
- `npm run types:check`: passed after Wayfinder regeneration with `--with-form`.
- `vendor/bin/pint --dirty --format agent`: passed.
- `git diff --check`: passed.

## Broader compatibility note

A broader neighbor run produced 88 passes and one existing failure in `CaseMatcherTest`: a legacy family-reunification profile predicate expects an implicit sponsor branch, while the Task 1 safe-default behavior correctly leaves it `not_covered`. No Task 4, extraction, structured-question, or snapshot test failed in that run.

## Limitations / next task

- No real provider request was made; all provider behavior remains HTTP-faked or contract-faked until configuration is supplied.
- The always-visible assistant and consent sheet are Task 5 UI work.
- Final six-case and full legacy-suite reconciliation remains Task 6.
