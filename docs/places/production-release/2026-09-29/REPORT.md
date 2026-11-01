# Production Places qualification — 29 September 2026

**Production comparison is complete. The scoped release candidate is committed and locally verified; no
production deployment or catalogue write has occurred.**

Production has **8,798 stored records** and runs older code without the identity,
reviewed grouping, shared facts or recovery features already tested on staging.
The staging figure of 6,585 Composer candidates does not describe production.

Of the frozen 4,643-record package:

- 1,378 public source keys already exist uniquely; 3,265 are absent.
- 1,193 matched refreshes use different production IDs from staging.
- 749 existing source matches also resemble unknown-origin legacy records and
  remain identity-review holds. The screen proposes no automatic merge.
- 3,335 named package records have no detected identity ambiguity; this is a
  provisional review cohort, not an approved production release count.
- All 559 descriptive-name records lack fee evidence; 90 have raw public-access
  tags. None qualifies an unconditional free/public claim from these inputs.

Production retains 4,680 records without a source field, of which 3,359 are marked
recommendable by the old flags. These flags are not modern evidence qualification.
There are 187 stored records with approved/active media attachments (169 under old
recommendation flags); the upcoming match-review requirement is unavailable on the
old schema. These are not verified new-policy photo-coverage counts. Stadt Köln
media remains pending, never approved by this work.

## Concrete fixes prepared

An isolated candidate based on production main carries the reviewed Places/media
code and its required migrations. It excludes unrelated staging product changes.
A new safeguard prevents container startup from recreating renamed places through
legacy name-only seeds. Eight catalogue/photo schedules default to dormant until
target qualification, with explicit controls for later enablement. The baseline
passed 229 tests / 750 assertions; the safeguards were tested failing first and
then passed 3 tests / 25 assertions. The first full fast suite passed 1,642 tests / 6,818 assertions with two skips;
the frontend build and type check passed. Review found two additional release
defects: ungated monthly boundary jobs and incompatible older queued validation
jobs. Both were reproduced, fixed and re-reviewed; 15 focused tests / 95 assertions
passed afterward. Normal commit hooks subsequently passed 1,644 tests / 6,835 assertions with two
existing late-day weather skips, formatting and secret scanning. The code commit is
`846546df20eef53d0f0874e6ac1940da43a56135`; remote CI subsequently passed at the
published report checkpoint below.

## Release gates remaining

The code, required migrations and media impact must be reviewed together. Existing
media attachments receive pending match status; they cannot be silently approved
to preserve an old coverage count. Production-specific identity resolutions and
post-migration row fingerprints must be qualified, followed by recovery rehearsal
and API/Composer acceptance before importing any production batch. No staging
manifest, numeric IDs or journal UUIDs may be reused. Photo and useful free-football
coverage are still unresolved.

The audit used one enforced read-only database connection without application
providers, jobs, HTTP APIs or user tables. Raw catalogue values used by the identity
screen stayed in server memory; local outputs contain summaries, hashes and public
source-key exceptions. No raw rows were written to disk or exported.

Evidence: [runtime/schema](runtime-schema.json), [source-key comparison](reconciliation.json),
[identity screen](identity-screen.json), [held fact evidence](held-evidence-review.json),
[qualification cohorts](qualification-cohorts.json), [plan](PLAN.md), [status](STATUS.json).

## Historical publishing checkpoint — superseded by approval below

The local candidate is committed at `554eab971d3b2b43ec9312a893d292302bb6b0b3`.
Automatic approval review rejected pushing private source to the GitHub repository
without explicit destination approval. The owner has been asked to approve the
candidate branch push and draft PR only. Nothing was pushed; no PR or remote CI
run exists yet. Production remains unchanged. EXP-69/EXP-72 and BookStack pages
33/36 were updated and fully read back, preserving their history.

Server rehearsal preflight confirmed the same database server and role for the
two environments. No rehearsal database was created, no catalogue was copied,
and no user tables were read. An isolated catalogue rehearsal remains the next
data qualification step. See [publication evidence](publication.json).

## Approved publishing — PR #61

The owner approved the candidate push and draft PR. The branch is published and
[draft PR #61](https://github.com/anarzone/expadu/pull/61) is open against main at
`554eab971d3b2b43ec9312a893d292302bb6b0b3`. The earlier publishing blocker is resolved.
[CI run 36632276523](https://github.com/anarzone/expadu/actions/runs/36632276523)
completed successfully: Lint, Test and Browser Tests all passed. GitGuardian also
passed. Image builds and deployments were skipped for this PR. No merge or
production deployment occurred. The PR remains draft pending the production data
and media acceptance described above; EXP-69 and EXP-72 remain In Progress.
