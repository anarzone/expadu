# Gap note — work-start rule missing on the Blue Card path

- **Date:** 2026-07-18
- **Status:** draft
- **Surfaced by:** model eval (grounded Sonnet, arriving-couple scenario)
- **Paths affected:** non_eu_employee_blue_card (possibly _chancenkarte)
- **Resolved by:** —

## The question that exposed it

"I entered on a work visa and I've already started working; I haven't applied
for the Blue Card yet."

## What the catalogue lacks

The warning "visa-free entrants may generally not start working before the
permit (or BA approval) — your D-visa colleagues can" exists ONLY on the
standard path (`nee.submit_application` how_to). The Blue Card tasks say
nothing about when work may legally start. The eval model correctly refused
to cross-apply the note and told the user to confirm with the ABH — honest,
but a visa-free Blue Card applicant reading only their own checklist gets no
warning at all, and that's the case where working early is illegal.

## Draft addition (UNVERIFIED — never import from here)

Mirror the same how_to warning onto `bc.submit_application` (and check
whether the Chancenkarte path needs its own variant — its work rules differ).
The rule text itself was verified for the standard path (Cologne product
01083); confirm the identical rule is stated for Blue Card applicants before
copying.

## Candidate official sources

- https://www.stadt-koeln.de/service/produkte/20321/index.html (Blue Card page)
- https://www.make-it-in-germany.com/en/visa-residence/types/eu-blue-card

## Verification checklist

- [ ] Fact confirmed on an official page (quote + URL + date)
- [ ] YAML updated with verified_at + source link
- [ ] `php artisan bureaucracy:import-tasks` + `bureaucracy:coverage` clean
- [ ] Bureaucracy test suites green
