## 2026-09-30 — Football evidence correction (21:09 UTC)

The first football probe used the unsupported internal activity label `football`. That probe did not verify Composer’s football request path. Both the running staging source and the pinned candidate were rerun at **20:58:18 UTC** with the canonical **`activities=[soccer]`, `categories=[pitch]`**, a 3 km radius and the same labeled sample Cologne-centre origin. Both returned **0 candidates with the free budget and 0 without that budget filter**. This identifies a gap in this sample, not a citywide absence of football places. Historical unsupported-label summaries are retained separately; the corrected summaries and verifier require the canonical activity. The committed source hold, retained records, API contract counts and photo count are unaffected.

The work remains In Progress. Preparing a scoped staging code port is the next step; no staging code deployment or production change has occurred.

---
