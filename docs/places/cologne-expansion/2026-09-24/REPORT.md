# Places delivery checkpoint — 24 September 2026

**Required outcome: at least 75% approved, relevant, active photo coverage across the expanded catalogue, including cafés and restaurants. The outcome remains unmet.**

## Latest staging delivery

The owner authorized publication and staging deployment. [PR #55](https://github.com/anarzone/expadu/pull/55) merged as `c87b2622454fcec65dac7ab8de76a2320414b0af`; [deployment run 36027806631](https://github.com/anarzone/expadu/actions/runs/36027806631) succeeded after lint, application tests, browser tests and the image build passed. The four checked deployed backend files match the reviewed source hashes. Public HTTPS login returned 200.

Food & drink discovery is live. Read-only requests through the deployed HTTP kernel returned 200 for the coarse filter and each supported food subtype. There are currently **350 cafés**; restaurant, fast-food, bar and bakery subtype totals remain zero because their broader catalogue data has not been imported. The café first page's 20 identities also resolved through Composer. These checks used a non-persisted synthetic user and did not create account/session data. The initial verification probe incorrectly expected Composer's internal category to be coarse; it was corrected to check the actual fine-category contract and its coarse mapping. This was a probe correction, not an application change.

All **ten reviewed facility memberships across seven destinations** are now applied on staging. The operator verified fresh previews, exact rollback, replay and append-only audit behavior before committing. Coordinates and unrelated spot records remained unchanged. The post-deployment check verified **791 legacy links**, **784 eligible Composer identities** and all ten facility group mappings. This is a reviewed batch, not proof all citywide grouping is resolved.

The fresh selector audit reports **34 covered / 3,277 general-eligible imported records (1.0375%)**. The denominator dropped by ten because those facilities now belong to their destinations; the number of photos did not increase. **The 75% gate fails**. Cafés have 4 selected photos among 350 eligible records. Category and neighbourhood breakdowns are saved in [photo-coverage.json](staging-verification/photo-coverage.json). The expanded external catalogue is not this denominator and remains unimported/unresolved. No excluded municipal-origin asset was selected; production was unchanged.

Before the data operation, a fresh private staging backup was captured, parsed and restored successfully into a new disposable local database. The local PostgreSQL version needed only the newer `transaction_timeout` session setting removed from the restore stream; all other commands completed atomically. The private backup is not part of a public report payload.

## Completed in this turn

- Added the expanded scope and 75% requirement to the existing EXP-70 and EXP-72 task comments. Both tasks remain open.
- Prepared all 12,857 saved provisional place groups for review, preserving their source IDs and coordinates. Attached all 3,136 existing candidate records to 1,122 groups; retained all 11,735 groups without photo leads. Repeated generation produces identical hashes. No candidate was automatically approved.
- Implemented Food & drink discovery for the five existing application categories: cafés, restaurants, fast food, bars and bakeries. Connected the Places filter and Composer input handling. These changes are now deployed to staging as described above.
- Expanded the guarded OSM importer to full-city nodes, ways and relations for those categories. Tested typed identities, outside-boundary exclusion and repeated imports against fixtures, with no live source import.
- Fixed nearby independently identified food venues collapsing solely because of a shared name and rounded coordinates. Geographic containment does not merge an independent café with its park.
- Fixed classification and refresh ownership for overlapping café/bakery tags.
- Checked the separate tourism media library: its Food & Drink collection has nine files, largely repeated venue views. The inspected Kebapland asset has tourism-specific rights; it is not approved under the separate general CC footer.

## Fresh dining rehearsal and identity findings

Two fresh OSM responses contain 2,555 bounding-rectangle elements. The importer's Cologne boundary and naming checks exclude 527 outside-city elements and 33 unnamed ones; 306 match existing typed source records. A disposable staging restore rehearsal proposed **1,689 new rows, 317 updates and five retirements**, with **1,707 new observations**. Replay preserved identities and observation count, and rollback restored the exact spot/observation data. These are proposed rows, not 1,689 verified unique ready-to-serve venues. **No dining source import has changed staging.**

Seven nearby same-name pairs were reviewed. The restaurant and attraction named Tünnes und Schäl must remain separate. META's own site distinguishes its Caféthek and restaurant, so name/proximity does not justify merging them. One Starbucks node is explicitly a member of the same tagged cafe way. Shukran has an address conflict. Other probable duplicates remain unapplied. Four proposed retired source records are now marked disused by OSM; Café Hibiskus is deleted in OSM while its official site still advertises hours. Existing legacy rows also need reconciliation. See [review decisions](food-source-snapshot/identity-review/decisions.json) for source URLs and precise remaining checks.

## Photo acquisition decision

No new photo approvals were produced. A stricter Mapillary direction/quality pass found camera leads for 7,003 provisional groups, but all 22 diagnostic images inspected were withheld for venue relevance. That sample rules out automatic approval by camera direction; it does not establish that every remaining frame is unusable. Geograph's 1,062 nearby-image search results are repeated image records, not 1,062 unique approved places. Neither result establishes the required photo supply. See [Mapillary review](mapillary-direction/REPORT.md) and [source follow-up](source-followup.md).

**The source-only approach tested so far cannot be presented as a solution to the owner's 75% requirement.** There is no verified free feed identified here that closes the gap. A direct, opt-in venue/photographer contribution programme is a possible next free acquisition route: collect original venue-specific photos, explicit reuse permission, creator credit and a stable venue identity, then apply the existing rights/relevance/health checks. It is not implemented or acquired coverage, and reaching 75% through it cannot be promised before participation is established. No outreach was sent. The task remains open; further bulk imports are held at the specific quality issues above.

## Food photo leads in the saved external inventory

These figures are provisional research groups and image leads, not current verified venue totals or approved-photo coverage. Rows can overlap; bakery is also represented in the shopping research category.

| Source category | Provisional places | Places with leads | Lead share |
|---|---:|---:|---:|
| cafe | 615 | 13 | 2.11% |
| restaurant | 1,378 | 47 | 3.41% |
| fast_food | 708 | 4 | 0.56% |
| bar | 179 | 10 | 5.59% |
| pub | 357 | 17 | 4.76% |
| biergarten | 31 | 4 | 12.9% |
| bakery | 394 | 1 | 0.25% |
| ice_cream | 80 | 0 | 0.0% |

## Remaining work and limits

- Complete identity/currentness and per-image rights, relevance and availability review. No new photo publication approvals occurred in this continuation; the ten reviewed facility links are the staging data change described above.
- Find a substantially larger free photo supply. On the broad provisional denominator, 75% would require 9,643 photographed places. Even if every existing lead passed, at least 8,521 additional places would still need photo leads. The provisional denominator still needs identity and scope review; missing-photo places must not be removed merely to improve the percentage.
- Existing public source evidence does not demonstrate a path to 75%. Venue/photographer contributions are a potential free acquisition route, not acquired coverage. No outreach has been sent.
- Finish source taxonomy gaps such as pubs, beer gardens and ice-cream venues; the current application has five food categories and the new importer implements those exact categories. The research queue retains the broader source categories.
- Existing access policy excludes customer-only access records. Review how to represent ordinary commercial dining access explicitly; this change does not silently relax access rules.
- Nearby-detail suggestions and Composer still have legacy name/proximity deduplication; the new same-name identity regression covers the Places listing and detail count only. Further shared identity cleanup remains open.
- Public push and staging rollout of the component-performance and dining commits are complete. EXP-72 has delivery updates and remains In Progress. Detailed wiki delivery disclosure remains separately pending. Production is unchanged.

## Evidence

Implementation commit: `d7f0e0eb` (`feat(places): include food and drink in discovery`), containing only the 12 intended implementation/test files. The normal commit hooks passed the secret scan, formatting and fast PHP suite: **1,634 tests passed, 6,826 assertions**. The frontend build and targeted ESLint/Prettier checks passed. Build warnings concerned existing unresolved font paths, large chunks and a deprecated bundler option. Independent review's café/bakery refresh finding was reproduced with a failing regression and fixed. The commit is now deployed to staging through PR #55.

- [Working checklist](WORK.md)
- [Coverage audit](coverage-audit.json)
- [Queue validation](validation.json)
- [Source follow-up](source-followup.md)

The historical September 23 staging result (34 selected photos among 3,287 general-eligible imported records) is superseded for current staging by the September 24 selector audit above. The 12,857 provisional external groups remain a separate research inventory, not the staging denominator.
