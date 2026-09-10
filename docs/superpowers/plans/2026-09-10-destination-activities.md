# Destination Activities Implementation Plan

**Goal:** Use reviewed destination membership consistently in Places and Composer without changing selected facility identities or coordinates.

**Architecture:** Store reviewed membership separately from geometric containment. A shared query policy filters destinations and eligible activities before ranking. Composer carries a destination group key through hydration and prevents automatic repeated destinations.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL/PostGIS, Pest.

**Spec:** `docs/superpowers/specs/2026-09-10-destination-activities.md`.

## Global constraints

No automatic catalogue classification, media approvals, production changes or frontend redesign. Preserve source IDs, coordinates, user choices and the dirty primary checkout. Use PHP 8.4 and Node 22. All task commits end with `Refs EXP-68`.

## Task 1: Reviewed membership and shared queries

- [x] Add failing tests for reviewed component/independent membership, invalid parents, alias resolution, preserved import decisions and changed containment.
- [x] Add nullable `destination_spot_id`, grouping evidence, review timestamp and containment snapshot fields to Spot. A null destination means independent or unreviewed; evidence distinguishes reviewed decisions. No data backfill.
- [x] Implement `DestinationGrouping::review(int $spotId, ?int $destinationId, string $evidence, string $fingerprint): void` with row locks, canonical resolution, evidence and destination validation. A component must point directly to a park/sports centre and cannot own components. Keep source and geometry fields unchanged. Invalidate a scalar grouping revision on review.
- [x] Implement `DestinationGrouping::eligible(Builder $query): Builder`, `general(Builder $query): Builder`, and `groupIds(array $spotIds): array`. Components require an eligible canonical destination and matching reviewed containment; uncertain/independent spots retain their own IDs. General discovery excludes components. Never bypass child eligibility.
- [x] Preserve membership through source imports and reconcile parent identity safely; refuse destructive catalogue operations on reviewed membership.
- [x] Verify focused membership/import regressions.

## Task 2: Composer destination uniqueness

- [x] Add failing pure pipeline tests for parent/child/sibling groups, different groups and explicit repeated picks.
- [x] Add `Candidate::$destinationGroupId` as a nullable string with a default of null. Repository spot candidates use `spot:<destination ID>` or their own canonical candidate ID. Events and appointments remain null.
- [x] SlotFiller suppresses occupied groups for automatic picks. Explicit feasible pins remain visible even when they share a group. Swapper excludes groups occupied by other slots; it may replace its own target with another activity in that destination.
- [x] CandidateRepository uses shared eligibility and general filtering before the ranking limit; explicitly requested fine/coarse activity categories may include components. `byIds()` preserves pinned facility identity and coordinates while hydrating current group metadata.
- [x] Verify pure pipeline and saved/hydrated Composer regressions.

## Task 3: Places, Home and operator integration

- [x] Add mixed activity API/Home regressions, including independent contained venues, restricted children/parents and name-only relationships.
- [x] Retain coarse Places category filters returning destination cards. Add `activity` fine-category selection returning actual eligible facilities. Derive parent activity chips only from eligible reviewed components. Remove name-only grouping guesses.
- [x] Use shared general filtering for Home/NearbyPlaces, neighbourhood directories and unfiltered map search, and include grouping revision in scalar cache keys. Destination context uses reviewed membership; independent places use geographic nearby results.
- [x] Add a review command with preview as default and explicit apply/evidence, documenting its use and pending catalogue classification. Do not classify all containment rows automatically.
- [x] Run affected PHP suites and required hooks, obtain independent review, publish a draft PR and update Jira/BookStack honestly. Staging classification remains the pilot's separate reviewed step.

## Verification record

The combined focused run passed 231 tests (843 assertions), covering Places APIs, identity and grouping, both import paths, Composer filling/endpoints/repository, Home cache refresh and nearby discovery. Independent review identified map search and neighbourhood directory queries that still bypassed grouping. Focused regressions reproduced both gaps; the follow-up changes apply the shared policy to those surfaces. The additional map/directory run passed 10 tests (27 assertions). Final independent review reports no remaining blockers. Required commit hooks passed: secret scan, formatting and 1,522 PHP tests (6,050 assertions; one skipped). Draft PR #50 targets staging; Jira and BookStack record implementation, operator steps and the pending pilot. PR CI and staging rollout remain pending.
