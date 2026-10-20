# Read-only review and resolutions

Three material findings were accepted before the final batch was frozen.

1. The source record for Raph's BBQ Deli had an `end_date` in 2019 despite still
   carrying a food category. The builder now holds records with end/closing
   dates for lifecycle review. This is contradictory evidence, not an assertion
   that the present business was independently confirmed closed.
2. Additional Overture records for Arena, Vetrina and Kaukasia shared category,
   street/number and distinctive name tokens with nearby OSM identities. The
   builder now uses that corroboration to flag ambiguity without merging. The
   broader check also caught Landmann/Treppchen and the two Düxer records.
   Eight additional records were held in total. Existing OSM records were
   retained where only an additional cross-provider identity needed review.
3. PHP loose comparisons can treat changed numeric-looking names or telephone
   strings as equal. Expected-state checks now use recursively key-sorted JSON
   fingerprints, preserving string distinctions and list order while treating
   numeric `51` and `51.0` as equivalent. Five focused checks cover this guard.

The final batch is 4,643 records: 3,908 destinations and 735 facilities, with
1,874 proposed creates and 2,769 refreshes. The final native rehearsal is run
against this batch, after these changes. Its success is recorded separately in
`rehearsal.json`; this review note itself does not certify a passing run.

The replay assertion remaps every payload to its post-import ID and expected
row. Reusing the original pre-import expected state is deliberately rejected.

The reviewer made no edits and accessed no database or live service. Full
Composer requests, unnamed activity retrieval, additional booking/fee semantics,
photo coverage, live promotion and independent visits to every venue remain
explicitly outside this preparation's completed scope.

The follow-up review confirmed all three resolutions, the final checksum and
counts, and independently passed the 15 preparation tests and five fingerprint
checks. It found no remaining material defect within that follow-up scope.
Handoff remains conditional on the recorded final native rehearsal succeeding.

The final acquisition-boundary fix also received read-only review. It derives
Overpass bounds from the same official polygons used for acceptance, fails
before fetching or retiring records when the extent is missing, and retains
polygon containment as the acceptance check. The regression includes an accepted
airport viewpoint east of the old rectangle. The complete focused application
suite passed after this fix: 67 tests and 442 assertions. This acquisition-only
change does not alter the frozen package payloads or rehearsal implementation.
