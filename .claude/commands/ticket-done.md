---
description: Close an Expadu ticket — verify, screenshot, update the doc, flip it to Done
argument-hint: EXP-42
---

Load the `ticket-workflow` skill first. Then close out: **$ARGUMENTS**

Work through this in order and **stop at the first step that fails** — a red suite means the ticket
stays open.

1. **Verify.** Run the tests that cover the change:

   ```bash
   export PATH="/Users/anar/Library/Application Support/Herd/bin:$HOME/.nvm/versions/node/v22.14.0/bin:$PATH"
   php artisan test --compact --filter=<relevant>
   ```

   For TSX changes also run `npm run lint:check` and `npm run build`. If anything is red, report the
   failure and stop — do not proceed to step 5.
2. **Capture what changed, if it's visible.** Start the `expadu` preview server and screenshot the
   affected screens, or run the Playwright spec that covers them. Skip this for work with no visible
   surface, and say that you skipped it.
3. **Upload the screenshots** to the ticket's doc page:
   `bin/wiki-image <file> <page-id> "<what it shows>"`.
4. **Rewrite the doc.** Fill in Approach and Result with what actually happened, embed the
   screenshots, and list the commits. Update the status line to `status: done`. If the work
   revealed something durable — a gotcha, an architectural fact, a decision — put that in
   **Runbooks**, **Architecture** or **Decisions** instead of burying it in the work log.
5. **Move the ticket to Done** (state `46553c35-d34b-4158-a0a3-b1811b195673`).
6. **Comment on the ticket** with the commit SHAs, the test result, and a link to the doc.
7. **Report** the ticket, the doc URL, and anything you could not verify.

Report outcomes faithfully. If a step was skipped, say which and why. Never write a result into the
doc that you did not actually observe.
