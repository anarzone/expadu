# Final facility review dispositions

The independent reviewer inspected the complete candidate branch diff against
8f7eb597b073c025fb8ab0f0011eb1313657d6ac, including staged facility changes.
Verdict: With fixes. Critical: none. Important: three. Minor: one.
The reviewer did not execute tests, access the network or inspect private source
responses. The implementer performed one regression-backed fix pass, with no
second review round.

1. **Important — mixed broad/fine category leakage: fixed.** A park plus
   playground request enabled extra qualified picnic/BBQ records through broad
   park expansion. Composer now restricts qualification-only additions to the
   explicitly requested fine facility categories when no sport is requested.
   The mixed park/playground regression failed before and passed after the fix.
2. **Important — fresh proof/effective facts disagreement: fixed.** Matching
   fresh proof and stored tags/points could coexist with older effective public
   access or a different effective map point. The operator now compares proof
   with effective access, unknown fee and map coordinates and refuses a
   contradiction before any review. Three access/fee/point regressions failed
   before and passed after the fix; source facts are never repaired implicitly.
3. **Important — later access review retaining qualification: fixed.**
   Qualifications now bind complete access correction history, including
   withdrawals. A later access review requires a fresh qualification. Native
   apply also requires current known unconditional public access. Places, map,
   Composer discovery and saved-ID regressions failed before and passed after
   the fix. A combined public access and qualification review stays valid;
   combined unknown access is refused without leaving either correction.
4. **Minor, regraded Important — batch fixtures masked intended guards: fixed.**
   The two fixtures originally occupied the same point, causing the first
   record's overlap check to mask an invalid second record. Fixtures now have
   distinct coordinates and tests assert the exact proof, freshness, identity
   and duplicate refusal reasons. Regraded because misleading guard coverage
   affects confidence in the atomic operator protocol.

## Declined or bounded review work

- Independent verification of private evidence authenticity was not performed
  by the reviewer. The executor fetched current provider responses, compared
  tags and original geometry, and verified the installed application manifest.
  The guide explicitly states that supplied hashes and boolean attestations do
  not independently prove source authenticity. No stronger review claim is made.
- Live deployment and durable recovery for the separate legacy identity batch
  were not part of this facility protocol. Neither is silently claimed complete;
  both remain release boundaries, with no live alias operation performed.

## Verification

Nine new behavior regressions failed before the fixes. Four corrected invalid
batch cases passed while asserting their intended exact refusal reasons.
After fixes, the focused and related trust suite passed 201 tests / 1,035
assertions. Normal hooks and exact-head CI are recorded separately when complete.
