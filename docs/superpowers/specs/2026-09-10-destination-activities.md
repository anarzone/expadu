# EXP-68: Destination activities across Places and Composer

## Outcome

General discovery presents one destination for a park or sports complex. A person asking for a particular activity can select the actual facility, with its own coordinates and restrictions. Automatic planning must not fill a day with different facilities belonging to the same destination. Explicit choices remain visible.

This is a relationship between different places, not EXP-67 identity reconciliation. A tennis court never becomes an alias of its park.

## Current gaps

- Places collapses every contained place and every `park_name` row into a destination, including independent venues and unresolved name-only relationships.
- Composer ignores containment. Different names and categories bypass its existing name-and-distance duplicate heuristic.
- Containment imports assign museums and other independent venues to a containing polygon. Containment alone cannot prove that two places form one visit.
- Child activity queries currently check active status without consistently checking recommendation eligibility or canonical identity.
- Generic facilities and restricted facilities can both be non-recommendable. An activity request must not bypass that flag wholesale.

## Shared relationship contract

Keep `parent_spot_id` as geometric containment. Add an explicit, reviewed component relationship for grouping, recorded separately from containment and retained across import refreshes. An independent or uncertain venue keeps its own discovery identity. Component decisions require evidence; neither shared names nor a nearby polygon alone authorize a decision.

Use one shared destination policy at Places, Home and Composer query boundaries. Resolve canonical identity first. A valid component relationship points to a canonical destination; missing, cyclic or conflicting relationships remain explicit review cases. A migration creates the relationship storage without silently classifying the whole catalogue.

General discovery selects eligible destinations and independent places. It suppresses confirmed component facilities before ranking and pool limits. Explicit activity selection includes eligible matching facilities and keeps their IDs, coordinates, category, hours and practical facts. Existing coarse Places filters continue to return destination cards; a separate fine activity filter provides precise facility selection without changing the current UI contract.

Destination activity chips come only from canonical, eligible component children. A child match never makes an inactive or restricted parent eligible. An ineligible component parent also blocks automatic recommendations of its components until access evidence distinguishes independent access. Detail and historical-reference reads remain available under existing access controls.

## Composer contract

Add an internal nullable `destinationGroupId` to Candidate. A confirmed component uses its canonical destination ID; independent spots use their canonical Spot ID. Events and personal appointments keep their existing identity semantics.

Keep Candidate.id and coordinates attached to the selected place. A child pin and a saved child slot remain that child. Automatic filling and swapping cannot reuse a destination group already occupied by another slot. Two deliberately pinned facilities may both remain if feasible; do not silently discard the user's selections. A parent pin plus a child pin should be surfaced as deliberate repeated visits, rather than rewritten into one invisible choice.

Hydrate destination-group metadata from current records when reading saved plans. Preserve slot order and times. Unknown or no-longer-eligible candidates continue to produce the explicit responses introduced by EXP-67. Group decisions invalidate Home's scalar catalogue cache.

## Data rollout

The 100-destination staging pilot in EXP-72 supplies the first reviewed component and independent decisions. Keep uncertain operation/access relationships pending. Do not automatically promote all non-recommendable courts, and do not infer ownership from a website or a generic name.

Import refreshes may update geometric containment but must preserve reviewed operation/grouping decisions. A conflicting refresh becomes a review case. Source-backed decisions and explicit independent overrides take precedence over a geographic guess.

## Required verification

1. One general browse/plan destination for a park with mixed component activities.
2. Single and multiple fine activity requests retain the facility ID and exact coordinates.
3. Child pins and saved slots survive keep/hydrate/swap and suppress automatic parent/sibling repetition.
4. Swapping a different slot cannot introduce an occupied destination group.
5. Independent museums, cafés and sports venues inside a park remain discoverable.
6. Restricted/inactive parents and children do not become eligible through grouping; activity chips obey the same gates.
7. Aliases never reappear through parent or activity joins.
8. Ambiguous/orphan `park_name` values cannot hide a place or attach it to a distant same-named park.
9. Import refresh preserves reviewed grouping and independent decisions.
10. Grouping happens before pool limits, so one large complex cannot crowd out other destinations.
11. Repeated explicit choices remain visible; automatic recommendations remain destination-unique.

## Scope and delivery

Use the approved Today C direction at `http://127.0.0.1:8765/dev/design/today-c/` for later presentation. This backend contract does not redesign the UI. Preserve existing media approval and health gates. PHP 8.4, Node 22, PostGIS and focused Pest regressions apply. Production catalogue changes remain subject to the EXP-72 review.

EXP-68 is in preparation; this document does not claim that grouping is implemented or that the catalogue has been classified.
