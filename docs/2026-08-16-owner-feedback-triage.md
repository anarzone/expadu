# Owner feedback triage — 2026-08-16

Source: owner review doc (Landing page 9 points, Onboarding 4, Bureaucracy 8).
Grouped by **root cause**, not by page, because several reported symptoms share one.

Severity: **S1** trust/legal (wrong or invented statements about someone's status) ·
**S2** broken (feature does not work) · **S3** misleading (works, misinforms) · **S4** polish.

Status: `verified` = reproduced in code or over the network this session ·
`reported` = owner-observed, not yet reproduced by me.

---

## Group A — Residence facts asked in the wrong order and the wrong words  · S1

Root cause: onboarding asks for an expiry date *before* it knows which document you
hold, and labels the options in Amt vocabulary rather than the user's.

| # | Symptom | Status |
|---|---|---|
| ON-2 | "When does your visa expire?" is asked even when the person holds an **unlimited** permit, where no expiry exists | reported |
| ON-3 | Document type is asked *later*, though it is the answer that decides whether a date is meaningful at all | verified (SituationStep field order) |
| BU-1 | The Bureaucracy page is "confused" and shows answers that contradict each other — downstream of ON-2 | reported |
| (mine) | "Settlement permit" is Niederlassungserlaubnis; the owner could not find his own permanent residency under that label | verified |
| (mine) | Label is generic but the stored value is `settlement_permit_18c` (§18c only) — a §9 holder is recorded as §18c, and this feeds rule matching | verified |

**Fix:** ask document type first, derive whether an expiry applies, relabel to
"Permanent residence (Niederlassungserlaubnis)", and decide whether §9 and §18c need
separate values (legal call, owner).

---

## Group B — The plan asserts things the user never told it · S1

Root cause: completion and eligibility are inferred, and the inference is neither shown
nor explained.

| # | Symptom | Status |
|---|---|---|
| BU-2 | Settlement checklist claims **8 of 12 done** with no way to see *which* 8, or what the remaining 4 are. "Do next" lists only 2 | reported (screenshot missing) |
| BU-6b | "Already done" items were never declared during onboarding — **how did it decide?** | reported (screenshot missing) |
| BU-5 | "The Long game" (permanent residency) appears **both** as "you could apply next" **and** under "not eligible" — while the owner already holds settlement residency | verified: `shared.long_game` is `type: info`, `eu_filter: non_eu_only`, with **no `applies_if`** on whether the user already holds PR, so it shows to every non-EU user regardless of status |

**Fix:** gate `shared.long_game` on `current_residence_title`; make completion
attributable (show which tasks and why they count as done); never place one rule in two
contradictory lanes.

This is the most damaging group: it tells someone they are and are not eligible for
permanent residency in the same view.

---

## Group C — Multi-branch rules cannot express per-branch data · S2/S3

Root cause: schema gap. `how_to_steps` carries branch structure (A / B / C), but
`documents_required` and `links` are **flat lists** with no branch association.

| # | Symptom | Status |
|---|---|---|
| BU-3 | Driving licence shows one document list across three branches. Translation applies to B and C, not A (which requires nothing at all). One label hacks scoping into prose: "Exam route only: …" | verified in `shared_info.yaml` |
| BU-4 | The two links are flat and wrong for most readers: one is for re-registering a licence you hold, one is EU-exchange only | verified: flat `links:` list, no branch mapping |

**Fix:** allow `documents_required` and `links` to be scoped to a branch; where no branch
matches, show a short explanation instead of a misleading link. Applies to every
multi-branch rule, not just driving licence.

---

## Group D — Verified broken links · S2

| # | Symptom | Status |
|---|---|---|
| BU-8 | Quick Actions, first two links | **verified broken**: `termine.stadt-koeln.de/m/buergeramt/` → **400**; `…/ordnungsamt-auslaenderangelegenheiten` → **404**. Third link (city portal) → 200 |

Hardcoded in `bureaucracy-right-panel.tsx`. **Fix:** correct the URLs and add a link
check so a dead official URL is caught rather than shipped.

---

## Group E — Duplicated / unclear surfaces · S3

| # | Symptom | Status |
|---|---|---|
| BU-6a | What is the difference between "already done" and "completed"? Can they merge? Why are those items missing from the task section? | reported |
| BU-7 | Documents section should be reviewed or replaced outright | reported |

Likely the same root as the empty-checklist bug already fixed: two engines (case plan and
legacy catalogue) rendering overlapping concepts with different vocabulary.

---

## Group F — Landing page tools · S2/S3

Tools live in `resources/js/marketing-tools.ts` + `resources/views/marketing/tools/`.

| # | Symptom | Sev | Status |
|---|---|---|---|
| LP-5 | Permanent residency section does not work | S2 | reported |
| LP-4 | Deutschlandticket always concludes "yes, buy it" regardless of input | S2 | reported |
| LP-6 | Citizenship: "Do you support yourself without basic benefits?" is not understandable | S3 | reported |
| LP-7 | Netto-brutto: "Your fund's Zusatzbeitrag (%)" needs a plain-language explanation | S3 | reported |
| LP-3 | Deutschlandticket section — point incomplete in the doc | ? | needs owner input |

---

## Group G — Privacy / compliance surface · S1 (legal)

| # | Symptom |
|---|---|
| LP-9 | Login page carries nothing about Datenschutz, while collecting credentials |
| ON-1 | After "Your answers are stored…", link the **official** Datenschutz. Owner explicitly wants research first: how to do it properly and whether it is permissible |

A `datenschutz` route already exists (`marketing.datenschutz`), so wiring is cheap; the
wording is the part that needs review.

---

## Group H — Copy and polish · S3/S4

| # | Symptom | Sev |
|---|---|---|
| LP-8 | Landing in light mode flips instantly to dark on the sign-in page | S3 |
| LP-1 | "Cologne first — Built in English" communicates nothing | S4 |
| LP-2 | "Tell **to** Expadu" — wrong English, should be "Tell Expadu" | S4 |
| ON-4 | "German citizenship eligibility cases" — no sentence around it | needs owner input |

---

## Suggested order

1. **Group D** — verified broken, minutes to fix.
2. **Group B** — the contradiction about permanent residency is the biggest trust risk.
3. **Group A** — reorder + relabel; overlaps the onboarding simplification spec already written.
4. **Group C** — schema change, so it wants doing once and properly.
5. **Group G** — cheap wiring, but wording needs owner/legal review.
6. **Groups F, E, H**.

## Blocked on owner

- Three screenshots referenced in the doc (BU-2, BU-5, BU-6) did not survive text extraction.
- LP-3 and ON-4 have no complete sentence — intent unclear.
- §9 vs §18c split (Group A) is a legal-accuracy decision.
