# Operation decisions

1. The owner's “continue” answers the concrete staging-quarantine permission
   request. It does not authorize a production change or an application release.
2. Reuse the exact reviewed package and operation UUID. Refuse cohort drift under
   locks instead of silently preparing a different request.
3. Keep the tested source-hold helper unchanged. Use a narrowly guarded named
   connection to staging inside the isolated application; never boot the live
   application's credentials as the application database.
4. Persist only scoped recovery metadata on the server. The earlier private raw
   archive rejection is respected; no archive or private content is downloaded.
5. The existing scheduler controls are absent on the running version. Check for
   active catalogue writers and use the helper's bounded transaction/table locks;
   keep the global scheduler and unrelated services running. This is sufficient
   coordination for this short approved operation, not release acceptance for
   unattended refreshes or restart behavior.
6. Check the actual running source separately from the candidate. Its isolated
   API-kernel results are not represented as authenticated external HTTP results.
7. Keep the 90 facility qualifications and 21 source-update differences outside
   this operation. Do not repeat already applied identity links or count them as
   new places.
8. Recount photos after the hold and retain the rights/provider gates. An active
   stored URL status is not a newly executed URL-health check.
9. Preserve completed preparation/review evidence as historical checkpoints.
   Publish this later live-operation result separately, with current aggregate
   counts and explicit remaining gaps.
