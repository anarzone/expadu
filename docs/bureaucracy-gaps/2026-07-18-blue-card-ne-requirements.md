# Gap note — Blue Card NE track: pension months, §, wait time

- **Date:** 2026-07-18
- **Status:** draft
- **Surfaced by:** model eval (grounded Sonnet, permanent-residency couple scenario)
- **Paths affected:** non_eu_employee_blue_card
- **Resolved by:** —

## The question that exposed it

"I've had my EU Blue Card for a while; my wife holds a 4-year family permit.
We both want permanent residency — exact steps, requirements, fees?"

## What the catalogue lacks

`bc.ne_fast_track` now has documents, the €113 fee and the digital-submission
flow, but — unlike `nee.ne_check` (36/24 months, §18c) and `fam.ne_apply`
(60 months, §9) — it states:

1. **No pension-contribution month count** for the Blue Card track. Cologne's
   Fachkräfte NE page does not mention the Blue Card track at all, so nothing
   citable was available at authoring time.
2. **No AufenthG § for the NE step itself** (only §18g for the original card).
3. **No expected wait** between digital submission and the Termineinladung
   (the 6–12 week figure in the catalogue belongs to the first-permit step).

## Draft addition (UNVERIFIED — never import from here)

Likely §18c Abs. 2 AufenthG; pension contributions likely must cover the same
21 (B1) / 27 (A1) months as the holding period. Both plausible from federal
law, neither yet confirmed on an official page. Proposed description addition
once verified: "Legal basis §18c Abs. 2; pension contributions must cover the
full 21/27 months."

## Candidate official sources

- https://www.make-it-in-germany.com/en/visa-residence/types/eu-blue-card
  (federal portal — settlement-permit section for Blue Card holders)
- https://www.gesetze-im-internet.de/aufenthg_2004/__18c.html (statute text)
- https://www.stadt-koeln.de/service/produkt/niederlassungserlaubnis-fuer-fachkraefte
  (re-check for a Blue Card section after the city's URL migration settles)

## Verification checklist

- [ ] Fact confirmed on an official page (quote + URL + date)
- [ ] YAML updated with verified_at + source link
- [ ] `php artisan bureaucracy:import-tasks` + `bureaucracy:coverage` clean
- [ ] Bureaucracy test suites green
