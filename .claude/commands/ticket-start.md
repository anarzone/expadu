---
description: Pick up an Expadu ticket — move it to In Progress and cut its branch
argument-hint: EXP-42
---

Load the `ticket-workflow` skill first. Then start work on: **$ARGUMENTS**

1. **Read the ticket.** `workitem` + `action: "retrieve_by_identifier"`. Pull its title,
   description, priority and labels.
2. **Stop if it carries `blocked-on-owner`.** Tell the user what the ticket needs from them and do
   not start it. This label exists precisely to prevent silent starts on work that cannot finish.
3. **Read the linked doc** if the item has a `workitem_link` to BookStack — that is the history,
   and starting without it repeats work.
4. **Move it to In Progress** (state `f461aebd-0985-469e-8763-d62d2fc288e6`).
5. **Cut the branch** from the current branch: `<type>/EXP-<n>-<short-slug>`, where type is
   `feat`, `fix`, `chore` or `docs` based on the work. Never start work directly on `main`.
   If the working tree has unrelated changes, leave them alone — do not stash or commit them.
6. **Summarise what you're about to do** before touching code: the problem, your approach, and
   anything in the ticket that looks stale or wrong.

Then work normally. Every commit ends with `Refs EXP-<n>` on its own line, and no Co-Authored-By.
