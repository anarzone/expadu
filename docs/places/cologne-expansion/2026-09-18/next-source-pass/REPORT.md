> **Later update:** Mapillary access is now operational and all catalogue locations have been checked for nearby cameras. Camera coverage is 83.1% within 50m, not usable-photo coverage. A 38-image diagnostic review did not establish ready hero photos. See [Mapillary results](mapillary/REPORT.md). The source-pass report below is the earlier checkpoint.

# Cologne free-photo expansion — 18 September 2026

**EXP-70 remains in progress. This pass has not established free-photo coverage for most Cologne places.** It produced additional source inventories and individually reviewed candidates, not a production import or a completed catalogue. No application code, staging data or production data changed in this pass.

## What the numbers mean

The starting offline catalogue has **12,857 provisional place groups**, of which **1,076 have linked photo candidates (8.37%)**. These are neither a verified census of Cologne nor 1,076 publication-ready places. The historical 4,075 database count is not used. The catalogue remains unchanged; its checksum and category counts are in `baseline-coverage.json`.

The greatest gaps are shopping (6,644 groups, 24 with candidates) and dining (3,418 groups, 117 with candidates). Category counts overlap. Resolving duplicate buildings, businesses and activities may change the denominator; excluding inconvenient categories would not solve coverage.

`source-pass-summary.json` and `reviewed-candidate-manifest.jsonl` give the reconciled results of this pass. A selected candidate means an actual image was inspected and associated with a specific place. It does not mean imported, approved, current in appearance, or counted as live coverage.

The consolidated selection is **43 additional candidates**: 32 Commons shop/hotel exteriors, five other Commons venue photos, five Schildergasse exteriors and one Germania club aerial. All 43 correspond to different baseline records without linked photos; inspected file hashes are distinct. Known building/component duplicates are excluded, but this does not resolve all catalogue identity or cross-provider photographic-work duplicates. Thirty-three still need current-operator checks. **Zero new publication approvals, zero imports and zero change to live coverage** occurred in this pass.

Validation confirmed all selected IDs exist in the unchanged catalogue, all have source/author/licence evidence, all reviewed files exist and their checksums match, and no selection is marked approved. Eight retail thumbnails exceeded the requested research size cap because returned image dimensions differed from API metadata; actual dimensions are recorded separately from visual suitability. This is an acquisition discrepancy, not evidence that those photos are unsuitable for the product.

## Sources actually investigated

| Source | Observed result | Practical limit |
| --- | --- | --- |
| Wikimedia Commons, wider categories plus shops/hotels | 2,658 distinct file records inspected through metadata; 2,445 not present in four earlier metadata inventories. All 936 general and 1,802 retail name associations reviewed. | File counts are not place counts. Name matching produced many wrong branches, people and nearby buildings. Category exploration was bounded, not exhaustive. |
| schildergasse.koeln | 278 media records; 62 individually credited and 37 explicitly gallery-credited own photos under the site's free licence. Five inspected exteriors matched to specific branches. | External credits and unmarked images withheld. Narrow geographic coverage. Four fresh official branch listings and one indexed official listing support current identity, not unchanged appearance. |
| Marco Verch / foto.wuestenigel.com / ccnull | 764 cards retrieved from a tag claiming 768; 84 individual detail pages examined. Two author-site aerials inspected. | One photographer across two sites, not independent sources. Most matches already illustrated; moved businesses withheld. ccnull image retrieval was not bypassed. |
| KartaView | 74 distinct public active frames in bounded searches. Four actual frames inspected; zero confirmed venue photos. | Camera proximity and direction do not establish that a storefront is visible. Fourteen purposively selected missing-photo locations are not a citywide sample. |
| NRW nature media API | Public access and five collections confirmed. Cologne geographic query returned no geotagged assets; five sampled records lacked usable rights/place metadata. | No reusable Cologne imagery established. Zero geotagged results do not establish that no unlocated Cologne photos exist. |
| Mapillary | Free service and attribution terms checked; existing application integration found. | An owner-controlled application token is needed before measuring coverage. No token available and no citywide coverage established. |
| Data Hub NRW / Open Data Germany | Free access route and licence filtering confirmed. | Registration/feed access still needed. Regional provider totals do not establish Cologne coverage. |

## Errors prevented

- Rejected stadium photographs suggested for the shop “Stadion,” speaker portraits suggested for Müller stores, and incorrect Apple/Fotolambertin branches.
- Kept old HEMA and Zalando storefront photos on hold after address conflicts; did not present them as current branch images.
- Rejected Benetton's official map point displaced about 181 metres from the matched branch. Existing catalogue coordinates were preserved; they remain reference points, not verified entrances.
- Counted the Steigenberger aerial as an additional illustration of the already illustrated Habsburgerring 9–13 building, **zero new destination coverage**.
- Zoo houses and the Rheinpark playground add **zero new root destinations**. Two Theodor-Heuss-Park geometries represent one destination.
- Held Reserved's proposed photo because Kämpgen dominates the storefront image, and Opera's proposed hotel photo because a different hotel name appears on the entrance.

These are research decisions in an offline package. They do not establish that all duplicate-name or coordinate bugs in the application are fixed.

## Rights and image quality

No Stadt Köln photo asset or municipal dataset is approved or imported by this pass. Commons hosting is not permission by itself: municipal photographic provenance remains excluded. Independent photographs of a municipal venue are considered separately from municipal ownership of the venue. Official text used to verify a site's identity does not grant image rights.

Per-file source, author, exact licence, attribution evidence, image dimensions and review decisions are retained in the source directories. Historical photos may be useful when labelled accurately; they do not prove today's facade, tenant, interior or opening conditions. Images of a road, square, sign, individual activity or adjacent business are not substituted for the intended venue.

Publication remains pending the application's rights, health and identity checks. Current-operation checks remain open for the retail batch. No artificial/generic photo is used to fill a missing venue image.

## What remains and the next decision

1. Obtain owner-controlled Mapillary access and tourism feed access, then measure **unique matched destinations** against the same city boundary and category breakdown. Keep wrong-angle images, duplicates, component photos and uncertain rights out of the numerator.
2. Finish currentness and publication checks for the selected offline photos; import only accepted assets through the existing media pipeline with attribution.
3. Complete catalogue identity/coordinate decisions and the remaining pilot grouping, Composer and rollback checks before widening an import. Verify the affected environment through APIs.
4. Recompute ready coverage from approved, active media attached to distinct publishable destinations. Keep the tickets open until those checks and the agreed coverage outcome are met.

**The scalable solution is still unproven.** Further small anonymous-source batches are unlikely on current evidence to close the shopping/dining gap quickly. Account-backed free sources are the next evaluation, not a guarantee. If they also lack coverage, the product needs an explicit choice about launching places without photos, narrowing the initial catalogue, or obtaining owner-contributed images. None of those changes to the user's requested scope has been assumed.

## Primary references

- [Commons shops](https://commons.wikimedia.org/wiki/Category:Shops_in_Cologne), [hotels](https://commons.wikimedia.org/wiki/Category:Hotels_in_Cologne), and [API etiquette](https://www.mediawiki.org/wiki/API:Etiquette).
- [Schildergasse image licence policy](https://www.schildergasse.koeln/lizenzen/).
- [Photographer's Cologne index](https://foto.wuestenigel.com/tags/cologne) and [author-site licence](https://foto.wuestenigel.com/licenses/by/2.0/deed.de). ccnull's per-file CC BY 2.0 DE is distinct from the author site's generic CC BY 2.0.
- [KartaView terms](https://kartaview.org/terms) and [FAQ](https://kartaview.org/doc/faq).
- [NRW nature API](https://mediathek.api.naturschutzinformationen.nrw.de/mediathek/api/).
- [Mapillary FAQ](https://help.mapillary.com/hc/en-us/articles/8348198426396-Mapillary-FAQ), [licence](https://help.mapillary.com/hc/en-us/articles/115001770409-CC-BY-SA-license-for-open-data), and [application token setup](https://mapillary.github.io/mapillary-js/docs/intro/try/).
- [Data Hub NRW terms](https://tourismusverband.nrw/allgemeine-geschaeftsbedingungen-data-hub-nrw) and [Open Data Germany access](https://open-data-germany.org/en/retrieve-open-data/).

Detailed evidence: `storefront/`, `photographer/`, `photographer-visual-review/`, `commons-review/`, `retail-review/`, `kartaview/`, `nrw-nature/`. Original exploratory counts remain historical in their own summaries; the consolidated manifest controls this pass's candidate count.
