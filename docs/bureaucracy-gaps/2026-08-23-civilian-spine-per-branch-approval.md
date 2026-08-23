# Gap note — the civilian spine is approved for one branch out of seven

- **Date:** 2026-08-23
- **Status:** draft — needs the content owner, cannot be closed by tooling
- **Surfaced by:** QA persona review against the case engine (`bureaucracy:coverage`)
- **Paths affected:** all except `core` (digital nomads + "other")
- **Resolved by:** —

## What is wrong

Fifteen of seventeen branches render an **empty verified plan** and fall through
to the unreviewed catalogue, under its "not part of the verified plan" banner.

The civilian spine exists in six copies per concept, one per branch. Only the
`core.*` copy was approved, and `core.yaml` declares `situation: core`, which
compiles to `purpose: [digital_nomad, other]`. So the approved rule reaches
nomads and "other", and nobody else.

| Concept | Approved | Unreviewed (`review_status: legacy`) |
|---|---|---|
| Anmeldung | `core.anmeldung` | `nee.` `eue.` `stu.` `fam.` `fre.` `gw.gewerbeanmeldung` |
| Steuer-ID | `core.steuer_id` | `nee.` `eue.` `stu.` `fam.steuer_ids` `fre.` |
| Health insurance | `core.health_insurance` | `nee.` `eue.` `stu.` `fam.` `fre.` |
| Bank account | `core.bank_account` | `nee.` `eue.` `stu.` `fam.` `fre.` |

`Task::authoritative()` excludes every card in the right-hand column, which is
correct behaviour — they carry no reviewed source. The gap is that the approval
pass covered one branch.

## Why this was not fixed in code

Two structural fixes were considered and both are wrong:

**Widening `core.*` to every branch and retiring the duplicates loses content.**
The per-branch cards are not copies. Each says something the generic one does
not:

- `fam.anmeldung` — "every family member must be registered, including
  children", four required documents, and its own title.
- `eue.anmeldung` — "As an EU citizen you don't need a visa or residence permit
  — freedom of movement covers you."
- `stu.anmeldung` — without the Meldebescheinigung you cannot enrol.
- `nee.anmeldung` — four documents rather than two.
- `fre.anmeldung` — the Meldebescheinigung as the basis of the freelance chain.

Replacing these with the generic card would delete guidance written for those
people, which is a content regression wearing the costume of a fix.

**Keeping both and widening `core.*` duplicates the card.** The catalogue only
suppresses a card when the verified plan already carries the *same key*, so an
EU employee would see two "Register your address" cards with different detail —
the duplicated-surfaces complaint (BU-6a) reintroduced.

Marking the branch cards `approved` is the actual fix, and it is a human act:
it stamps `reviewed_by` and `verified_at`, asserting that a person checked the
prose against the sources. Tooling must never write that.

## What the owner has to do

For each card in the right-hand column above, confirm the prose is accurate,
then add the approval block. The legal basis does not vary by branch — §17 and
§54 BMG govern Anmeldung for every resident — so the source list from the
approved core card can be reused verbatim:

```yaml
review_status: approved
jurisdiction: de-nrw-cologne
reviewed_by: expadu_content_owner
content_version: '2026-08-23.1'
source_verification: dual_source
verified_at: '2026-08-23'
legal_sources:
  - {kind: primary, label: '§17 BMG — Anmeldung, Abmeldung', url: 'https://www.gesetze-im-internet.de/bmg/__17.html'}
  - {kind: primary, label: '§54 BMG — Bußgeldvorschriften', url: 'https://www.gesetze-im-internet.de/bmg/__54.html'}
  - {kind: implementation, label: 'Stadt Köln · Anmeldung Ihres Wohnsitzes', url: 'https://www.stadt-koeln.de/service/produkte/00415/index.html'}
```

The equivalents for the other three concepts are in `core.yaml`: §139b AO for
the Steuer-ID (`single_source_approved` — no allow-listed implementation host
covers it), §5 SGB V for health insurance, §31 ZKG for the basic account.

**A cheaper alternative worth considering first.** Group C added branch scoping
to `documents_required` and `links`. The spine could become one universal
approved card per concept whose documents and steps are branch-scoped — one
review instead of six per concept, and no duplicated cards. That is a content
restructure, so it is also the owner's call, but it converts a recurring
six-fold review burden into a one-off.

## How this is now visible

`bureaucracy:coverage` used to print "✓ No gaps" while most branches had an
empty verified plan, because its invariants accept any *published* task. It now
prints a `Verified` column and names the affected branches, and no longer calls
a structural pass "no gaps". It is deliberately an advisory rather than a gate:
closing this means approving content against a legal source, and a red CI run
should not be what pressures that decision.
