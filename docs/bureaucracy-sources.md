# Bureaucracy content — research log

Research for `eu_employee.yaml`, `freelancer.yaml`, `core.yaml` and the `student.yaml`
EU-filter edit. All sources fetched on **2026-06-11**. `verified_at` was deliberately
NOT set anywhere — every row below needs a human (Anar) to confirm against the source
before stamping it.

Legend: ✅ = claim read directly on the official source · ⚠️ = plausible but NOT
confirmed from an official source (listed again under "NEEDS OWNER VERIFICATION").

## Branch: eu_employee

| Task key | Claim(s) verified | Official source | Checked | Notes / uncertainties |
|---|---|---|---|---|
| eue.anmeldung | ✅ 14-day deadline; ✅ free of charge (gebührenfrei); ✅ Wohnungsgeberbestätigung mandatory, cannot be submitted later, landlord has 2 weeks to issue; ✅ walk-in Mon/Wed, appointment Tue/Thu/Fri; ✅ electronic Wohnsitzanmeldung available (sticker mailed) | https://www.stadt-koeln.de/service/produkt/anmeldung-ihres-wohnsitzes-1 | 2026-06-11 | NEW canonical URL — the old `produkt/anmeldung-einer-wohnung` used in the existing YAML files now returns **404**. Consequence of late registration (Bußgeld amount) not stated on the page → not claimed. |
| eue.anmeldung | ⚠️ termine.stadt-koeln.de booking portal | https://termine.stadt-koeln.de/ | 2026-06-11 | Returns HTTP 400 to automated fetch (bot protection) — almost certainly fine in a browser (current stadt-koeln.de pages link to it), but I could not render it. |
| eue.steuer_id | ✅ IdNr is sent by post only (data protection); ✅ re-request via BZSt input form or letter, sent to registered address | https://www.bzst.de/EN/Private_individuals/Tax_identification_number/tax_identification_number_node.html | 2026-06-11 | ⚠️ "2–4 weeks after Anmeldung" timing is NOT stated on the BZSt page (carried over from existing Expadu files / common knowledge). |
| eue.health_insurance | ✅ EHIC covers only unexpected illness during *temporary* stays; not valid as cover when moving to live/work | https://europa.eu/youreurope/citizens/health/unplanned-healthcare/temporary-stays/index_en.htm | 2026-06-11 | "TK has best English support" is community/practical advice, phrased as such. Employee enrolment mechanics mirror the existing non_eu_employee task. |
| eue.bank_account | ✅ IBAN discrimination prohibited — Reg. (EU) 260/2012 Art. 9: payer/payee "shall not specify the Member State in which that payment account is to be located" | https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32012R0260 | 2026-06-11 | "Payroll systems still choke on foreign IBANs" = practical advice, labelled as such. Bank links are commercial (N26/ING/Sparkasse), not factual claims. |
| eue.rundfunkbeitrag | ✅ €18.36/month; ✅ one fee per dwelling regardless of occupants/devices; ✅ Beitragsservice auto-notified via registration office; ✅ RF-code reduction €6.12/month; ✅ BAföG/Bürgergeld exemption | https://www.rundfunkbeitrag.de/ and https://www.rundfunkbeitrag.de/welcome/english | 2026-06-11 | Amount unchanged as of today. Note: `rundfunkbeitrag.de/en/` (used in existing files) now 301-redirects; new files use `/welcome/english` directly. |
| eue.liability_insurance | — no official claims (product is voluntary) | — | 2026-06-11 | €30–50/yr and €10M coverage figure = practical/market advice, same framing as existing non_eu file. Urgency set to medium per spec (existing files use low). |

## Branch: freelancer

| Task key | Claim(s) verified | Official source | Checked | Notes / uncertainties |
|---|---|---|---|---|
| fre.anmeldung | Same claims as eue.anmeldung | https://www.stadt-koeln.de/service/produkt/anmeldung-ihres-wohnsitzes-1 | 2026-06-11 | — |
| fre.steuer_id | Same claims as eue.steuer_id | BZSt (see above) | 2026-06-11 | Same 2–4 week caveat. |
| fre.residence_permit | ✅ §21 AufenthG governs self-employment permits; ✅ Cologne document list (CV, qualifications, business concept **in German**, financing plan + capital proof, customer contacts, revenue projections); ✅ German language generally expected; ✅ application via Arbeitsmigration unit, Dillenburger Str. 56–66, personal appointment with invitation letter, contact by email/form/post; ✅ §21(5) covers freie Berufe | https://www.stadt-koeln.de/service/produkte/00947/index.html · https://www.gesetze-im-internet.de/aufenthg_2004/__21.html | 2026-06-11 | ⚠️ **Fee not stated** on the Cologne page → no fee claimed in the YAML (the ~€100 in the existing non_eu file is also unsourced). 90-day deadline mirrors the existing non_eu pattern (entry-visa validity), not stated on this product page. |
| fre.fragebogen | ✅ must be submitted within **one month** of starting activity (§138 AO); ✅ electronic via ELSTER mandatory since 2021-01-01; ✅ Steuernummer arrives by post after review; ✅ ELSTER account required | https://www.elster.de/elsterweb/infoseite/unternehmensgruendung · https://www.elster.de/eportal/formulare-leistungen/alleformulare/fseeun | 2026-06-11 | "Fines + estimated income if missed" — §138 AO consequence reported by IHK/Handelskammer pages in search results, not read on ELSTER itself → phrased once, flag below. Deadline_type left `none` (clock runs from activity start, not arrival — the schema can't model that). |
| fre.kleinunternehmer | ✅ §19 UStG thresholds: **€25,000** previous calendar year / **€100,000** current year (post-Jan-2025 wording read directly in the statute) | https://www.gesetze-im-internet.de/ustg_1980/__19.html | 2026-06-11 | "Exemption ends mid-year when crossing €100k" follows from the current-year wording; invoice-clause requirement is standard practice (§34a UStDV) — not separately fetched. |
| fre.health_insurance | ✅ GKV general rate 14.6%; ✅ average Zusatzbeitrag 2.9% (2026); ✅ self-employed assessed on income up to the ceiling; ✅ minimum assessment base €1,318.33 → min contribution €230.71 (with Krankengeld) / €222.80 (without) per BMG page | https://www.bundesgesundheitsministerium.de/beitraege | 2026-06-11 | Exact minimum-€ figures kept OUT of the YAML (described as "legal minimum floor") since they change yearly. PKV warning = practical advice, labelled. |
| fre.health_insurance (KSK) | ✅ KSK gives self-employed artists/publicists protection "similar to employees" (official wording); requirement: not-only-temporary self-employed artistic/publicist activity | https://www.kuenstlersozialkasse.de/ · https://www.kuenstlersozialkasse.de/kuenstler-und-publizisten | 2026-06-11 | ⚠️ The "you pay roughly the employee half" mechanic (KSVG §§14ff.) was confirmed only via secondary sources — the KSK homepage states employee-equivalent protection but I did not fetch the exact cost-split page. See below. |
| fre.gewerbe_check | ✅ §18 EStG Katalogberufe list (scientific/artistic/writing/teaching + doctors, lawyers, engineers, architects, journalists, interpreters, translators, …); ✅ Cologne Gewerbeanmeldung fee **€26** for sole proprietorship (deregistration free); ✅ online via Service.Wirtschaft.NRW portal with immediate PDF Gewerbeschein; ✅ non-EU citizens need residence permit allowing self-employment | https://www.gesetze-im-internet.de/estg/__18.html · https://www.stadt-koeln.de/service/produkte/00268/index.html | 2026-06-11 | ⚠️ "Gewerbeamt forwards registration to IHK; IHK membership mandatory (§2 IHKG), freie Berufe exempt" — confirmed only from secondary sources (gruenderplattform, sevdesk); ihk.de/koeln linked but the membership page itself was not fetched. |
| fre.bank_account | — no official claims | — | 2026-06-11 | "Private accounts prohibit business use" = practical advice (bank T&Cs), labelled as such. No legal claim that a business account is required (correct: it isn't, for sole proprietors). |
| fre.rundfunkbeitrag | ✅ €18.36/month, one per dwelling (as above) | rundfunkbeitrag.de (see above) | 2026-06-11 | ⚠️ "Home office in own flat covered by household fee; separate premises may owe company fee" — directionally correct per the companies section, but the companies page itself was not fetched; YAML phrases it as "check the rules" with link rather than asserting. |

## Branch: core

| Task key | Claim(s) verified | Official source | Checked | Notes / uncertainties |
|---|---|---|---|---|
| core.anmeldung | Same as eue.anmeldung | stadt-koeln.de (see above) | 2026-06-11 | — |
| core.steuer_id | Same as eue.steuer_id | BZSt (see above) | 2026-06-11 | Same 2–4 week caveat. |
| core.health_insurance | Generic only: insurance mandatory for residents | — (general law: §193 VVG / SGB V — not fetched) | 2026-06-11 | ⚠️ Kept deliberately generic; "retroactive premiums for gaps" = practical warning, labelled. |
| core.bank_account | — no factual claims | — | 2026-06-11 | — |
| core.rundfunkbeitrag | ✅ €18.36/month, one per dwelling, €6.12 RF reduction, BAföG/Bürgergeld exemption | rundfunkbeitrag.de (see above) | 2026-06-11 | — |
| core.liability_insurance | — no official claims | — | 2026-06-11 | Market-price advice only. |

## Branch: student (edit only)

| Task key | Change | Source | Checked | Notes |
|---|---|---|---|---|
| stu.residence_permit | Added `eu_filter: non_eu_only` — EU citizens have freedom of movement and need no residence permit | (Freedom of movement, FreizügG/EU — the task itself already says "Non-EU students must convert their entry visa") | 2026-06-11 | No other content touched, per scope. |

## NEEDS OWNER VERIFICATION

Facts used (or deliberately softened) that I could NOT confirm on an official source.
Check these before setting `verified_at` on the affected tasks:

1. **KSK cost split (fre.health_insurance)** — "you pay roughly the employee half of pension/health/care, KSK covers the rest." Official KSK homepage confirms employee-equivalent protection; the exact 50% split (KSVG) was only confirmed via secondary sources. Verify at kuenstlersozialkasse.de → Künstler und Publizisten → Beitrag.
2. **§21 AufenthG permit fee (fre.residence_permit)** — no fee stated on stadt-koeln.de produkt 00947; no fee claimed in YAML. The "~€100" in the existing `non_eu_employee.yaml` (nee.residence_permit) is likewise unverified — check AufenthV fee schedule or ask at the Ausländeramt.
3. **IHK compulsory membership wording (fre.gewerbe_check)** — §2 IHKG Pflichtmitgliedschaft + freie-Berufe exemption confirmed only via secondary sources; verify on ihk.de/koeln before stamping.
4. **"Steuer-ID arrives 2–4 weeks after Anmeldung" (eue./fre./core.steuer_id)** — BZSt does not publish a timeframe; this is inherited from the existing Expadu files. Either confirm with BZSt/Finanzamt guidance or accept as practical estimate.
5. **§138 AO consequences (fre.fragebogen)** — "fines + estimated income if missed" reported by IHK/Handelskammer pages found in search, not read on ELSTER/AO directly.
6. **termine.stadt-koeln.de** — HTTP 400 to automated fetch (bot protection); confirm in a browser that the portal and the `anmeldung`/`auslaenderbehoerde` deep-link UIDs in `BuergeramtService::SERVICES` still resolve.
7. **Rundfunkbeitrag home-office rule (fre.rundfunkbeitrag)** — companies page not fetched; YAML only says "check the rules", but verify before strengthening the claim.

## Outdated facts found in EXISTING YAML files (not fixed — out of scope per task rules)

| File / task | Problem | Evidence (2026-06-11) |
|---|---|---|
| non_eu_employee.yaml → nee.anmeldung, student.yaml → stu.anmeldung, family_reunification.yaml (anmeldung task) | Link `https://www.stadt-koeln.de/service/produkt/anmeldung-einer-wohnung` returns **404** | Current page: `https://www.stadt-koeln.de/service/produkt/anmeldung-ihres-wohnsitzes-1` (200) |
| non_eu_employee.yaml → nee.residence_permit | Link `…/service/produkt/aufenthaltstitel-erteilung` returns **404** | Working equivalent: `https://www.stadt-koeln.de/service/produkte/01083/index.html` (Aufenthaltserlaubnis für die nichtselbständige Erwerbstätigkeit, 200) |
| student.yaml → stu.residence_permit | Link `…/service/produkt/aufenthaltstitel-zum-zweck-des-studiums` returns **404**; also "Proof of finances (€11,208/year)" is the **2024** Sperrkonto figure — current requirement is **€11,904/year (€992/month)** | Working page: `https://www.stadt-koeln.de/service/produkte/00973/index.html` (200); Sperrkonto amount per Auswärtiges Amt guidance (auswaertiges-amt.de/de/sperrkonto/375488 — found via search, page itself not fetched: verify) |
| student.yaml → stu.bafoeg | "Up to €934/month" is outdated — BAföG Höchstsatz is now **€992/month** | Multiple 2026 sources in search (bafoeg-rechner.de etc.); official bmbf/bafög page not fetched: verify exact current Höchstsatz |
| non_eu_employee.yaml → nee.drivers_licence | Link `…/service/produkt/umschreibung-eines-fuehrerscheins` returns **404** | Find current product URL on stadt-koeln.de |
| non_eu_employee.yaml, student.yaml, family_reunification.yaml → rundfunkbeitrag tasks | `https://www.rundfunkbeitrag.de/en/` now **301-redirects**; €18.36/month itself is still correct ✅ | Direct URL: `https://www.rundfunkbeitrag.de/welcome/english` |
| family_reunification.yaml (residence permit task) | Link `…/service/produkt/aufenthaltserlaubnis-zum-zweck-des-familiennachzugs` returns **404** | Find current product URL on stadt-koeln.de |
| student.yaml → stu.enrolment | "Semesterticket … free public transit in NRW" — since 2024 students get the **Deutschlandsemesterticket** (Germany-wide) | Not fetched from an official source — verify with Uni Köln / KSTW before correcting |

---

# 2026-06-12 — Full transposition of the consolidated checklist (Cases 0–23)

The owner's consolidated "Bureaucracy Document Checklists (FINAL)" doc (compiled
2026-06-11 against stadt-koeln.de product pages) was transposed into the catalogue.
**verified_at policy applied:** tasks whose facts carry the doc's ✅ legend (read
directly on Stadt Köln pages during the 2026-06-11 compile) are stamped
`verified_at: 2026-06-11`. ◐ items (official federal/other sources) ship published
WITHOUT the badge. ⚠ items ship with "typical requirements" framing.

## Catalogue structure after the transposition

| File | situation header | Content |
|---|---|---|
| core.yaml | core | Civilian spine for digital nomads/other (Case 0/1) |
| eu_employee.yaml | eu_employee | Case 1 — spine only |
| non_eu_employee.yaml | non_eu_employee + _blue_card + _chancenkarte | Shared spine for all employee sub-paths |
| non_eu_employee_standard.yaml | non_eu_employee | Case 2 — §18a/b permit (produkt 01083 ✅) + NE-check info |
| non_eu_employee_blue_card.yaml | non_eu_employee_blue_card | Case 3 — §18g (produkt 20321 ✅, incl. Addendum-2 tail) + fast-track info |
| non_eu_employee_chancenkarte.yaml | non_eu_employee_chancenkarte | Case 8 ◐ — work limits info + conversion task |
| student.yaml | student | Case 4 — §16b permit upgraded (produkt 00973 ✅: €11,904 Sperrkonto 2025/26, all-pages copies, parents' declaration), 140-day work account, post-graduation §20(3) info ◐ |
| freelancer.yaml | freelancer + freelancer_gewerbe | Shared spine incl. tax track (ELSTER/Fragebogen/§19 UStG) |
| freelancer_liberal.yaml | freelancer | Case 5 ◐/⚠ — §21(5) "typical requirements", Servicestelle routing, Gewerbe-check task |
| freelancer_gewerbe.yaml | freelancer_gewerbe | Case 13 ◐ — §21(1) permit, Gewerbeanmeldung €26 (produkt 00268 ✅) |
| family_reunification.yaml | family_reunification + _of_german + _of_eu_citizen | Shared family spine (Anmeldung family variant ✅) |
| family_reunification_standard.yaml | family_reunification | Case 7 — §§29–32 (produkt 20335 ✅: A1 Goethe/TestDaF/telc only, rent-level proof, livelihood block) |
| family_reunification_of_german.yaml | family_reunification_of_german | Case 7 variant — §28 (produkt 20334 ✅: Personalausweis only, no livelihood block, custody proof) + 3-year NE info |
| family_reunification_of_eu_citizen.yaml | family_reunification_of_eu_citizen | Case 10 ◐ — Aufenthaltskarte, typical-requirements framing |
| shared_info.yaml | ALL branches | Case 6 driving licence (articles 06292/60839 + produkte 00834/00836 ✅, 3 branches, 180-day deadline), church tax ◐, Schufa ⚠-phrased, Verpflichtungserklärung ✅-adjacent, Fiktionsbescheinigung ◐, passport Übertrag ✅, long-game NE/citizenship (✅ Köln NE pages + ◐ StAG incl. Oct-2025 fast-track abolition), recognition §16d ◐, ARB 1/80 ◐ (+ ✅ Köln reduced fees €22.80/€37), child born §33 ✅/◐, other-permits routing card (Cases 11/12/14/17–22) |

## € figures and time-sensitive values now in the catalogue (re-check yearly)

| Figure | Value | Where | Source tier |
|---|---|---|---|
| Anmeldung fine ceiling | up to €1,000 (§54 BMG), tolerated in practice | core/nee anmeldung | ◐ statute |
| eAT first issue / renewal | ~€100 / €93–96 | standard + blue card | ✅ Köln (renewal), ⚠ first-issue federal standard |
| Blue Card salary thresholds | €50,700 / €45,934.20 (2026) | bc.blue_card, ck.convert | ✅ Köln 20321 |
| Sperrkonto students | €11,904 (2025/26) | stu.residence_permit | ✅ Köln 00973 |
| Chancenkarte finance | €13,092/12mo (~€1,091/mo, 2026) | ck.work_limits | ◐ federal |
| Gewerbeanmeldung | €26 | gw.gewerbeanmeldung | ✅ Köln 00268 |
| Driving licence fees | €35 EU exchange / €36.30 Anlage-11 | shared.driving_licence | ✅ Köln |
| Rundfunkbeitrag | €18.36/mo (€6.12 reduced) | all spines | ✅ rundfunkbeitrag.de |
| Kindergeld | €250/mo/child | fam.kindergeld | ◐ |
| Verpflichtungserklärung | ~€29 | shared.verpflichtungserklaerung | ◐ |
| Citizenship fee | €255; 3-yr fast track abolished 2025-10-30 | shared.long_game | ◐ |
| ARB 1/80 reduced fees | €22.80 (<24) / €37 (24+) | bc.blue_card, shared.arb_turkish | ✅ Köln 20321 |
| Child permit fee | from ~€25 | shared.child_born | ◐ federal |
| Turkish/§21 NE fees | €113 ⚠ / €124 self-employed ✅ | shared.long_game (not asserted numerically) | mixed |

## Conscious exclusions (unchanged)
- Case 23 returnees (§37/38), humanitarian/asylum §§22–26, §104c — excluded with reason.
- Pre-arrival mode — onboarding has no "not yet arrived" answer yet; revisit with that feature.
- Au pair/WHV/language-course/training-search/researchers/ICT personas get the routing
  card (shared.other_permits) only — no full paths until those personas exist in onboarding.

## Path-refinement model
`users.bureaucracy_path` + ProfileEngine::PATH_OPTIONS map sub-paths:
non_eu_employee → standard | blue_card | chancenkarte; family_reunification →
of a non-EU citizen (base) | of_german | of_eu_citizen; freelancer → liberal (base) | gewerbe.
Shared-spine tasks carry multi-branch situation headers so progress survives path switches.
