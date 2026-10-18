# Component activity query validation

The API query for a park with reviewed facilities was applying access policy to thousands of unrelated rows. PostgreSQL compiled 142 JIT functions even when the park had one facility. A raw candidate-ID lookup now bounds the existing query; membership, current-parent, access, canonical-identity and category checks remain unchanged.

Read-only comparisons across all seven reviewed destinations returned identical activity rows. Warm query times were 135–192 ms before and 3.9–5.6 ms after, plus approximately 3–8 ms for the candidate lookup. The resulting query no longer triggered JIT.

On the same restored database and frozen 100-place sample, every API payload remained identical. Detail p95 improved from 165.311 ms to 31.113 ms. Existing facts and grouping regressions passed: 54 tests, 327 assertions. These measurements establish this bounded query improvement, not citywide data or photo completeness.

Validation uses the actual HTTP kernel with a synthetic, non-persisted user. Public-network acceptance and staging deployment are separate checks recorded in the delivery report.
