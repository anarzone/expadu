---
description: File a new Expadu ticket in Plane with a linked stub doc in BookStack
argument-hint: "<title>" [--area transit] [--priority high] [--owner]
---

Load the `ticket-workflow` skill first — it holds the project id, state ids, book ids and
conventions. Then file this: **$ARGUMENTS**

1. **Check it isn't already filed.** Search existing work items (`workitem` + `action: "search"`)
   for the same idea. If something close already exists, show it and stop rather than duplicating.
2. **Resolve the area label.** Use `--area` if given; otherwise infer it from the title and say
   which you picked. Resolve the label id with `label` + `action: "list"`.
3. **Create the work item** in project EXP: raw-HTML `description_html`, priority from
   `--priority` (default `medium`), the area label, plus `blocked-on-owner` if `--owner` was passed
   or the work plainly needs a human decision, credential or approval.
4. **Create the stub doc** in the **Work Log** book (id `6`), in the chapter for that area —
   create the chapter if it doesn't exist. Use this skeleton:

   ```markdown
   Ticket: EXP-<n> · status: not started

   ## Problem
   <what the ticket says, in your own words>

   ## Approach
   _to be filled when work starts_

   ## Result
   _to be filled when the ticket closes_
   ```

5. **Link them both ways** — `workitem_link` on the item pointing at the page URL, and the ticket
   id already in the page text.
6. Report the ticket id, its URL, and the doc URL.

If the item is `blocked-on-owner`, say plainly what you need from the user and leave it in Backlog.
