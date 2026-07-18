# Gap note — <short title>

- **Date:** YYYY-MM-DD
- **Status:** draft | verified | merged
- **Surfaced by:** model eval | user question | support | content audit
- **Paths affected:** <situation keys, e.g. non_eu_employee_blue_card>
- **Resolved by:** <commit sha once merged>

## The question that exposed it

<Anonymized user question or eval scenario. No names, no account data.>

## What the catalogue lacks

<Precisely what a grounded model could not answer from the YAML — a fee, a
month count, a §, a missing task, a note scoped to the wrong path.>

## Draft addition (UNVERIFIED — never import from here)

<The model's (or author's) proposed YAML patch. This block is a starting
point for a human, not content. Every number and claim in it must be
re-verified against an official source before it enters the catalogue —
then the change is made in database/seeders/data/bureaucracy/ with a
verified_at date and a source link, and this note's status flips to merged.>

## Candidate official sources

- <stadt-koeln.de / BAMF / make-it-in-germany.com / gesetze-im-internet.de URL>

## Verification checklist

- [ ] Fact confirmed on an official page (quote + URL + date)
- [ ] Figures added to config/bureaucracy_figures.php if volatile
- [ ] YAML updated with verified_at + source link
- [ ] `php artisan bureaucracy:import-tasks` + `bureaucracy:coverage` clean
- [ ] Bureaucracy test suites green
