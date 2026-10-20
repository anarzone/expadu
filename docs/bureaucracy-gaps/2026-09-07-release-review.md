# Bureaucracy release — claim and source review

Status: work in progress, not a release approval or legal advice.

## Review authority

The owner authorised source-based gap resolution on 7 September 2026. Record this
as `owner_authorised_source_review`, with the responsible implementation revision;
do not label it an independent human or lawyer review. Runtime AI remains a bounded
fact extractor and cannot publish guidance. Existing publication/source checks stay
in force. The owner authorisation does not make an unsupported claim true.

## Local evidence used first

- `docs/bureaucracy-sources.md`: earlier source checks, uncertain figures and URLs.
- `docs/bureaucracy-gaps/2026-08-23-civilian-spine-per-branch-approval.md`: branch
  coverage/approval gap; not evidence that all duplicated prose is correct.
- `docs/bureaucracy-gaps/2026-07-18-blue-card-ne-requirements.md`: contribution
  periods and application-wait gap. The newer 21-month case card addresses part
  of this; the older card and the 27-month alternative still need reconciliation.
- `docs/bureaucracy-gaps/2026-07-18-blue-card-work-start-rule.md`: work permission
  must follow the actual title and its restrictions, not the intended next title.
- `docs/bureaucracy-gaps/proposed-spine-anmeldung.yaml`: read as an unapproved
  migration draft, not authoritative guidance. Its blanket registration, bank/
  university dependency, appointment tolerance and church-membership wording
  require claim-level review before reuse.
- `docs/resources.md`: useful historic application-resource inventory, not legal
  evidence or proof that a service marked active in March is currently deployed.

The read-only inventory found old URL/amount notes already resolved in current
records. Do not reopen these mechanically: student blocked-account amounts now use
figure substitution, BAföG's numeric maximum was removed, and the old permit,
registration and driving-licence URLs were replaced. Their current content still
needs ordinary source-window checks.

## Verified findings and required implementation

| Claim | Current records | Source-supported decision | Implementation status |
|---|---|---|---|
| Tax-ID delivery and follow-up | `core.steuer_id`, `eue.steuer_id`, `fre.steuer_id`, `nee.steuer_id`, `stu.steuer_id`, `fam.steuer_ids` | Remove the 2–4-week promise. BZSt provides a request path when no letter has arrived within three months of first registration/birth; the identifier lasts for life. The existing 28-day arrival deadline is not established by this source. Do not turn a service follow-up into a legal due date. | Source reviewed; catalogue correction/tests pending. |
| Appointment changes a permit deadline | `UserTask`, checklist formatter, reminder evaluator | Store/show appointment separately. Booking a slot does not itself establish a timely application or continuation of a title. | Compatibility model, checklist and reminder regressions pass; canonical event model remains T11. |
| Missing entry means visa-free | `Task::computeDeadlineFor` | Unknown entry must produce no calculated permit window and retain uncertainty. | Exact regression passes; complete unknown-date contract pending. |
| Impossible dates silently normalised | Deadline and historical fact inputs | Require an exact valid calendar date before arithmetic; reject future historical dates before confirmation or candidate display. | Expanded calendar/type and controller/provider regressions pass; full temporal fact history remains T06. |
| Physical card expiry changes permanent legal status | `Task::computeDeadlineFor`, case-plan input projection | §9(1) defines the settlement permit as unlimited. A card/document date must not be used as the expiry of that legal status. | Regression tests cover both confirmed §9 and §18c title values and conflicted expiry inputs. Separate card metadata remains T06/T12. |
| Registration always due for every resident, with an appointment grace period | `core.anmeldung` and branch copies | Registration generally follows actual occupation; account for §27 exceptions. Remove the unsupported tolerance promise. Missing provider confirmation does not justify hiding the issue; §19(2) provides immediate notification to the registration authority when confirmation is refused/delayed. | Sources reviewed; predicates and corrected content pending. |
| Blue Card duration alone proves settlement eligibility | `bc.ne_fast_track`, `case.bc.settlement.track_21_months`, independent eligibility service | §18c(2) ties the 27/21-month route to qualifying employment and corresponding pension/comparable provision, language and further statutory conditions. Card age alone is insufficient. Preserve authority verification. | Independent duration-only service and queued/saved eligibility alerts contained; complete criteria and catalogue reconciliation pending. |
| D visa automatically permits the intended new work | `nee.submit_application`, `bc.submit_application`, `ck.convert` | §4a requires attention to the actual title, permission and restrictions. Application and appointment are not interchangeable legal events. | Source reviewed; supported branch wording/criteria pending. |
| Broadcasting contribution has a 60-day arrival deadline | `core.rundfunkbeitrag` and branch copies | RBStV §7 relates commencement to occupation and §8 requires notification without delay; a generic 60-day allowance is not supported. One contribution per qualifying dwelling must include exemptions and shared payment, not one obligation per family member. | Primary text located; current consolidated-version cross-check and module pending. |
| Family relationship alone establishes health cover | Family/insurance copies | §10 SGB V distinguishes membership, residence, other insurance, self-employment, income type and child-specific conditions. Do not copy one household member’s insurance status to another. | Primary text successfully accessed; criterion inventory and source-reviewed module still pending. |

## Official sources checked

Checked 8 September 2026 unless stated otherwise. Access failures are recorded as
unverifiable, not as proof of a dead service or invalid law.

1. [BZSt — Steueridentifikationsnummer erhalten](https://online.portal.bzst.de/SharedDocs/Leistungsbeschreibung/DE/erneute_mitteilung_der_ID-Nr.html?nn=23996).
   Confirms initial assignment, lifetime continuity, where to find the number and
   the three-month non-receipt follow-up. The older English BZSt URL returned 403
   to the research tool; the official service portal was readable.
2. [AO §139b](https://www.gesetze-im-internet.de/ao_1977/__139b.html).
   Statutory identifier basis, not support for a postal delivery promise.
3. [AufenthG §18c](https://www.gesetze-im-internet.de/aufenthg_2004/__18c.html),
   [§4a](https://www.gesetze-im-internet.de/aufenthg_2004/__4a.html),
   [§81](https://www.gesetze-im-internet.de/aufenthg_2004/__81.html), and
   [Köln Blue Card service](https://www.stadt-koeln.de/service/produkte/20321/index.html)
   (checked 7 September). Application, title, work restrictions, contribution
   history and language are distinct facts. The Cologne page supplies a procedure,
   not a fixed settlement-application waiting time.
4. [BMG §17](https://www.gesetze-im-internet.de/bmg/__17.html),
   [§19](https://www.gesetze-im-internet.de/bmg/__19.html),
   [§27](https://www.gesetze-im-internet.de/bmg/__27.html), and
   [Köln registration service](https://www.stadt-koeln.de/service/produkte/00415/index.html)
   (checked 7 September). Ordinary registration is within two weeks after moving
   in, subject to statutory exceptions. Refused/delayed provider confirmation is
   a separate problem requiring notice to the authority; no booking-based grace
   period was established.
5. [RECHT.NRW RBStV, version displayed from 7 November 2020](https://recht.nrw.de/lrgv/bekanntmachung/07112020-rundfunkbeitragsstaatsvertrag/),
   especially §§2–4, 7–8. The old `lmi/owa` link redirects to a search page. The
   located text still uses older benefit names, so current-version reconciliation
   remains required before approving benefit-specific wording. Merely finding a
   government domain is not a complete review.
   Follow-up on 8 September: the current
   [Beitragsservice legal-basis page](https://www.rundfunkbeitrag.de/der-rundfunkbeitrag/beitragsservice)
   links its [RBStV PDF](https://www.rundfunkbeitrag.de/pxy/assets/4e51a426980e625defa738110a5aac106c558895/Rundfunkbeitragsstaatsvertrag.pdf),
   also marked November 2020. Cross-checked §§2–3 and 7–8 against RECHT.NRW:
   dwelling scope, exclusions, commencement and notification are consistent.
   The version's age alone is not evidence that these provisions expired.
   The current [household guidance](https://www.rundfunkbeitrag.de/buergerinnen-und-buerger/informationen)
   separately confirms shared payment and changes when the account holder leaves.
   Do not infer one new payment obligation per family member or impose an arrival
   grace period. Benefit-specific decisions and present fee publication still
   need their own claim-level review; no source-host extension or approved module
   was made during this follow-up.
6. [AufenthG §9](https://www.gesetze-im-internet.de/aufenthg_2004/__9.html).
   Paragraph 1 distinguishes the unlimited title. The spouse option in paragraph
   3a depends on the specified sponsor title, permit period, employment and other
   incorporated requirements; elapsed years alone cannot establish eligibility.
7. [ZKG §31](https://www.gesetze-im-internet.de/zkg/__31.html).
   Basic-account entitlement has a defined personal scope and application
   conditions. Its ten-business-day offer period is not an arrival deadline or a
   delivery promise for an ordinary commercial account. Refusal grounds and
   application requirements still need a complete cross-check before publication.
8. [Regulation (EU) 260/2012, Article 9](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:02012R0260-20240408)
   and [European Commission — IBAN discrimination](https://finance.ec.europa.eu/consumer-finance-and-payments/payment-services/payment-services/iban-discrimination_en).
   Article 9 concerns reachable payment accounts within the Union and covered
   credit transfers/direct debits. Do not turn it into an unrestricted claim
   about every SEPA country or payment method. The blanket German-account
   requirement in the federal [bank account guide](https://www.make-it-in-germany.com/en/living-in-germany/money-insurance/bank-account)
   needs reconciliation against this primary law, not blind reuse. The same guide
   describes typical documents, not universal basic-account prerequisites.
9. [SGB V §5](https://www.gesetze-im-internet.de/sgb_5/__5.html) and the federal
   [health insurance guide](https://www.make-it-in-germany.com/en/living-in-germany/money-insurance/health-insurance).
   Membership categories and exceptions matter. The guide calls for coverage
   from arrival and distinguishes interim travel cover before employment; it
   does not establish a generic thirty-day grace period or support provider
   rankings. Family insurance and EHIC cases need their own conditions. The
   ordinary §10 SGB V URL repeatedly timed out; the successfully accessed version
   is recorded in item 11 below. Access failures never established unavailability
   of the legal route.
10. [ZKG §33](https://www.gesetze-im-internet.de/zkg/__33.html),
    [§34](https://www.gesetze-im-internet.de/zkg/__34.html),
    [§35](https://www.gesetze-im-internet.de/zkg/__35.html), and
    [§37](https://www.gesetze-im-internet.de/zkg/__37.html).
    The basic-account application includes required information and existing
    usable-account disclosure. The statutory form has a completeness safeguard.
    An existing usable domestic account can be a refusal ground, with closure
    exceptions; a previous qualifying termination has its own conditions.
    Refusal is not unrestricted and has a notification/review procedure. The
    official indexed text of [§36](https://www.gesetze-im-internet.de/zkg/__36.html)
    identifies conduct, prior termination and statutory-compliance grounds;
    direct and full-law fetches timed out. Reconcile its incorporated provisions
    before any exhaustive entitlement/refusal decision. The
    [BaFin basic-account glossary](https://kontenvergleich.bafin.de/de/glossar/basiskonto-nach-dem-zkg)
    corroborates the bounded product scope, not an unrestricted commercial-account
    entitlement. Do not collect criminal-history details merely to offer general
    account preparation; unusual refusals need a reviewed verification path.
11. [SGB V §10 — successfully accessed official page](https://www.gesetze-im-internet.de/sgb_5/__10.html?level=1)
    and [BMG — insured groups](https://www.bundesgesundheitsministerium.de/gesetzlich-versicherte)
    (page dated 3 September 2026). The primary provision distinguishes the ordinary
    income formula from the marginal-employment limit; the ministry’s short
    numeric summary must not become a universal threshold. It also separates
    child age/training, other-parent insurance/income, and other statutory
    exceptions. Membership and a family relationship are not sufficient facts
    by themselves. Do not collect disability/health detail for a generic module;
    route unmodelled exceptions explicitly to the insurer.
12. [BMG — contribution-stabilisation FAQ](https://www.bundesgesundheitsministerium.de/service/gesetze-und-verordnungen/guv-21-lp/gkv-beitragssatzstabilisierungsgesetz-faq)
    explicitly labels its subject a draft. A proposed future reform must not
    replace current rules merely because it appears on an official website.
    Promulgation, effective date and scope need their own evidence before any
    future-policy activation.

## Remaining local claims to resolve, not silently approve

| Claim group | Records / local source leads | Next evidence check |
|---|---|---|
| KSK contribution split | `fre.health_insurance`; kuenstlersozialkasse.de | Statutory membership scope and contribution components. |
| First/renewal/self-employed permit fees | `nee.residence_permit`, `bc.blue_card`, `fam.residence_permits`, `fre.residence_permit`, `gw.residence_permit`; Köln 01083/20321/20335 | Current fee basis, age/nationality/exemption variations; no blanket fee. |
| Trade registration and IHK | `gw.gewerbeanmeldung`, `fre.gewerbe_check`; Köln 00268, IHK Köln, §18 EStG | Membership law and exceptions; tax classification is not safely inferred from job title alone. |
| Tax registration consequences | `fre.fragebogen`; ELSTER and AO | Statutory notification date, which consequences actually follow which failure. |
| Small-business VAT | `fre.kleinunternehmer`; §19 UStG | Current threshold transition and invoice requirements, including new-business cases. |
| Health coverage | All insurance copies; §5 SGB V, BMG, federal migration guidance | Public/private/family/EHIC distinctions; remove blanket 30-day deadlines and unsupported provider rankings. |
| Banking | All bank-account copies; §31 ZKG and federal guidance | Basic-account entitlement/conditions, existing SEPA use, documents; remove arbitrary 30-day due dates. |
| Broadcasting home office/exemptions | `fre.rundfunkbeitrag`, core and branch copies | Current treaty plus contribution-service implementation; household and business scopes differ. |
| Church tax | Registration copy and shared information; NRW tax ministry/KiStG | Tax base, membership and rate, avoiding form-selection advice that misstates membership. |
| SCHUFA | `shared.schufa`; local note links only to vendor | Separate statutory access right from optional commercial products; no unverified fee/timing promises. |
| Pending sponsor title | Family first-permit rules; §30 and Köln 20335 | Pending application is not an issued title; preserve conditional authority review. |
| Settlement waiting times | `bc.ne_fast_track`; Köln settlement pages | Leave duration unknown unless authority publishes an applicable current estimate. |
| Semester ticket | `stu.enrolment`; local log only links university homepage | Current university/ticket-specific eligibility and validity source. |

No record is approved by this document alone. Each correction must carry its
actual source/version metadata, pass the importer and deterministic regression
matrix, and be included in an atomic reviewed catalogue release.

## Executable audience and question gaps — 8 September

These are findings from the actual imported catalogue and canonical HTTP paths,
not additional legal interpretations or approval of replacement text.

1. **Blue Card preparation still needs the old `permit_track` selector.**
   `ImportTasksCommand::compileAppliesIf()` expands the file's
   `non_eu_employee_blue_card` header into citizenship, employment purpose and
   `permit_track = blue_card`, in addition to the authored current-title, entry
   and goal conditions. Actual onboarding with a national D visa and a Blue Card
   goal therefore leaves both first-application units at `needs_information`.
   `CanonicalImportedJourneyTest` pins the exact missing key and proves neither
   the desired title nor the selector is invented as a confirmed fact. The six
   historical journey tests now disclose explicit selector answers where needed.
   **Required correction:** review the legacy routing predicate's meaning against
   the canonical facts and each affected preparation/option, then version the
   corrected criteria. Do not infer an issued title from a goal, erase conflicting
   explicit answers, or remove all branch/legal conditions as a blanket fix.

2. **The approved registration unit does not reach employee audiences.**
   `core.yaml` still declares `situation: core`; its compiled predicate is
   `purpose in [digital_nomad, other]`. A person who explicitly answers employment
   therefore does not receive `core.anmeldung`, including a permanent resident
   who is employed and needs address preparation. The branch-specific employee
   copy remains unreviewed; the partial process mapping does not approve it.
   `SkippableAnswersTest` now exercises the canonical account API and exposes the
   missing dated/undated action instead of depending on the old checklist;
   `SettledStatusTest` exposes the permanent-resident variant.
   **Required correction:** finish the reviewed shared-registration audience and
   exception mapping together with the registration wording issues already listed
   above. Broadening the old blanket claim without reviewing those exceptions is
   not a complete fix. Do not pass these tests by approving an unrelated branch
   copy or guessing a different employment purpose.

Neither gap is closed by a passing synthetic clock test, by the structural
15-partial-unit inventory, or by the fact that explicit follow-up answers unlock
some of the historical cases. They remain release blockers.

## Owner clarification and source corrections — 8 September

The owner clarified: “we should rely on official rules, ai should judge based on
those rules only.” Together with the earlier explicit source-review authorisation,
this permits supported catalogue corrections, not invented law or independent
human/legal approval. Runtime AI remains a schema-bound input interpreter; the
deterministic backend evaluates the reviewed catalogue after user confirmation.
An unsupported case stays unsupported. No live provider was enabled.

Rechecked primary texts on 8 September: BMG §§17 and 27 and AufenthG §§18g and
18c, using the official links above. They still require a distinction between
registration and its exceptions, and between a requested Blue Card and an issued
title. This check is evidence for resolving the identified gaps, not approval of
the existing blanket wording or a completed coverage review.

Implemented locally in the uncommitted backend worktree, not imported to a live
environment. Corrected published-source records carry version `2026-09-08.1`,
verification date `2026-09-08`, and `owner_authorised_source_review` provenance:

| Unit | Correction and exact limit |
|---|---|
| `core.anmeldung` | Explicit actual-arrival and not-yet-registered facts replace the legacy core-purpose restriction. General §17 preparation explains §27 exceptions and §19(2) refused/delayed confirmation. The move-in target remains **preparation**, not a fully assessed personal legal deadline. No appointment extension, church-tax advice or universal document/dependency assumptions. |
| `case.family.register_address` | Retired, not deleted or reapproved. Its reviewed replacement is `core.anmeldung`; one registration process no longer emits competing copies. Existing history remains untouched. |
| `case.bc.first_application.prepare/submit` | Explicit non-EU/employment/current-D-visa/entry/goal conditions remove the inherited `permit_track` question. A separate immutable intent setting controls whether an optional application becomes an action; it does not change legal criteria. Missing/disputed intent produces a reachable question/review; a confirmed different goal does not. §81 is included for the submission timing context. No approval or continuation effect is inferred from a self-reported application. |
| `case.bc.settlement.track_21_months` | Current Blue Card, recorded qualifying months and language replace the legacy selector. Wording matches the existing 12–20-month range and retains contributions/other statutory checks. The missing full 21/27-month eligibility modules are **not** claimed complete. |
| `case.bc.verify_status_source` | Universal context no longer requires a Blue Card selector. The action points to the general Cologne Ausländeramt contact, not a Blue Card application page for an unknown title. |
| `core.steuer_id` | Existing-number availability is asked without collecting the number. Removed the 2–4-week promise and 28-day arrival clock; lifetime continuity and BZSt's three-month non-receipt procedure replace them. Removed registration-task completion as a prerequisite for recovering an existing number. Existing explicit single-source approval mode is retained; the readable BZSt service is linked, without changing the source-host allowlist. |
| `core.health_insurance` | Ask whether the person has confirmed cover with their insurer; do not infer another person's cover or select a regime. Removed the 30-day clock, registration prerequisite, generic document bundle and insurer rankings. This remains verification preparation, not a complete insurance-membership assessment. |
| `core.bank_account` | Ask whether help is needed; no automatic instruction to replace an existing account. Removed the 30-day clock, 1–3-day opening promise, commercial rankings and universal registration/document prerequisites. Basic-account entitlement is not decided by this partial card. |

Three minimal registered answers (`tax_id_available`,
`health_coverage_confirmed`, `bank_account_help_needed`) are per-person facts,
not account numbers, insurance identifiers or medical details. Registration uses
the existing `registration_status`; housing-provider availability is not silently
converted into “not registered.” They are optional purposeful follow-ups, not new
mandatory onboarding fields.

Official sources rechecked for these corrections include the BZSt service in item
1, BMG §§17/19/27 and Cologne 00415, AufenthG §§18g/18c/81 and Cologne 20321,
SGB V §5 and the federal health guide, ZKG §31 and the federal bank guide. Official
indexed §6 SGB V and §193 VVG text corroborate differing insurance regimes and
exceptions; direct fetches of those two pages timed out. No detailed entitlement
or numeric threshold was introduced from those incomplete fetches. General
immigration contact: https://www.stadt-koeln.de/service/adressen/auslaenderamt.

The current inventory has 95 units: 14 available partial units, one explicitly
retired unit and 80 review-required units. Zero units claim complete legal
coverage. The numbered audience findings above describe the pre-correction
reproductions; their selector/employee-audience defects are now corrected, but
the remaining exception/complete-criteria work is not waived.

The canonical case regression also exposed separation guidance in a continuing
household. The process mapping now treats the existing
`marital_household_continues: false` condition as a relevance discriminator,
alongside the existing title/purpose/sponsor conditions. This does not alter §31
copy or imply eligibility after separation. A confirmed continuing household
excludes that variant; unknown/conflicted household status remains unresolved.
The separate intent gate preserves missing dependencies for the chosen viable
alternative without treating the choice itself as a legal requirement.
