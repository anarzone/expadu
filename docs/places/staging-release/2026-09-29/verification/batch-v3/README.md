# V3 performance verifier

The application copies and hashes are unchanged from V2. Use the grouping and
controller copies in the parent verification folder, the PlaceFacts copy from
`batch-v2/`, and the frozen package inputs. Copy them into one explicit private
input directory; the helper rejects different hashes.

V3 releases each successfully completed import-child savepoint into its still-open
caller savepoint. It keeps both caller rollback boundaries and rejects a top-level
commit during import. Exactly 4,643 child releases and an unchanged Laravel nesting
level are required. No application file, access rule, JIT setting or source record
is changed by this verifier adjustment.

The local mode validates listener/binding/API/rollback control flow. The local
application already contains the candidate; its inherited `deployed_baseline` key
must not be interpreted as the original deployed application. Staging mode uses
the actual deployed original classes, so its baseline and parity have that meaning.

V2 and V3 differ in transaction resource handling: do not attribute their latency
difference solely to application-query changes. See the independent committed
public-fixture query comparison for same-data original-versus-candidate evidence.

Recorded executed SHA-256: `0a880b9f7a1254f15dfb78291aa1d05216f7ed5f48a4c09198dcc127182ae3d8`.
