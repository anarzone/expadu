---
name: ticket-workflow
description: "Use when working with Expadu tickets or project docs — anything involving Plane work items (EXP-n), the BookStack wiki, or the /ticket-new, /ticket-start, /ticket-done commands. Activate when the user mentions a ticket id like EXP-42, asks what's in progress or what's open, wants work filed, wants a doc written or updated in the wiki, or asks to close out finished work. Also activate before committing work that belongs to a ticket, so the commit carries its Refs line. Covers the Plane and BookStack MCP tools, the bin/wiki-image screenshot helper, and the branch/commit/doc conventions. Do not use for general Laravel, React, or test work that isn't tied to a ticket."
---

# Expadu ticket workflow

Plane is the tracker, BookStack is the wiki. Both are self-hosted on this laptop only
(OrbStack, bound to `127.0.0.1` — nothing on the Hetzner box). The loop is:
**capture → start → work → close**, and closing is what keeps the docs honest.

## Fixed IDs

Plane workspace `expadu`, project **EXP** `ec0e00d7-6c81-424b-bc9b-ea9243c8e892`.

| State | ID |
|---|---|
| Backlog | `506104ab-3a1b-4849-a34f-142e421b81b4` |
| Todo | `4903c6de-3656-4baa-9ea0-02e5a42fb9e9` |
| In Progress | `f461aebd-0985-469e-8763-d62d2fc288e6` |
| Done | `46553c35-d34b-4158-a0a3-b1811b195673` |
| Cancelled | `8f7c8622-22e1-42b4-9fc9-0804a648828b` |

BookStack books: **Runbooks** `3`, **Architecture** `4`, **Decisions** `5`, **Work Log** `6`.

Labels are the area grouping: `bureaucracy`, `transit`, `places`, `composer`, `ai`, `today`,
`events`, `onboarding`, `design`, `infra`, `security`, `platform`, `marketing`, plus the
cross-cutting `blocked-on-owner`. Resolve label ids with `label` + `action: "list"`.

## Starting work from Plane's board

`bin/plane-sync` turns a card into a branch. Move a card to **In Progress** in Plane, then:

```bash
bin/plane-sync              # act
bin/plane-sync --dry-run    # show what it would do
bin/plane-sync --watch=60   # keep syncing every 60s
```

For each in-progress ticket it creates the branch `<type>/EXP-<n>-<slug>` from `staging`, a stub
page in Work Log under a chapter for the ticket's area label, a link on the ticket to that page, and
one comment naming the branch. The branch prefix is inferred from the title's leading verb
(*Fix…* → `fix`, *Write…* → `docs`, *Clean up / Migrate / Rotate…* → `chore`, else `feat`).

Two properties to rely on:

- **It never checks out.** The branch ref is created and left alone, so it cannot disturb a dirty
  working tree or interrupt whatever you are mid-way through.
- **It skips `blocked-on-owner`.** Those tickets need a human decision and must not get a branch
  implying work has started.

It is fully idempotent — anything already done is reported with `=` and skipped, so `--watch` is
safe to leave running.

## Conventions

- **Branch:** `<type>/EXP-<n>-<short-slug>` — e.g. `fix/EXP-42-sbahn-absurd-itineraries`.
- **Commit:** ends with `Refs EXP-<n>` on its own line. Never add a Co-Authored-By line.
- **Doc:** one page per ticket in **Work Log**, chapter per area. Reference docs belong in
  Runbooks / Architecture / Decisions instead, not Work Log.
- **Link both ways:** the work item gets a `workitem_link` to the page URL; the page names the
  ticket in its text.
- **`blocked-on-owner` means stop.** Those items need a human decision, credential, or approval —
  never start one silently. Say what is needed and leave it in Backlog.

## Platform limits worth knowing

Plane runs **Community edition**, so a lot of the MCP toolset 404s. This is the edition, not a bug —
never debug it as auth.

- **No Epics.** `workitem_type` 404s and cannot be enabled. Use the area label plus `parent` nesting
  to break a job into steps.
- **Modules and cycles are off** and `project update_features` 404s — enabling them is a manual step
  in the Plane web UI.
- Also dead: workspace `page`, `collection`, `initiative`, `customer`, `template`.
- **Webhooks exist, but not on the API-key layer.** `/api/v1/…/webhooks/` 404s while
  `/api/workspaces/<slug>/webhooks/` returns 401 — they are real, session-authenticated, and managed
  in the Plane UI (HMAC-signed via `X-Plane-Signature`; events: project, issue, module, cycle,
  issue_comment). Before building on them, note Plane validates the target against
  `WEBHOOK_ALLOWED_IPS`, which is **unset here**, so a webhook aimed at this machine is refused as
  SSRF. Making it work means adding that CIDR to the Plane compose env and restarting the api and
  worker containers — a deliberate loosening of a security control, so ask first. `bin/plane-sync`
  polls instead and needs none of that.
- `description_html` must be **raw** HTML (`<p>text</p>`). Escaped entities get stored literally.
- Project names reject special characters (no parentheses).
- **Rate limit is 60 requests/minute.** When filing many items, sleep ~1.2s between calls and back
  off 65s on a 429.

BookStack:

- **The MCP server cannot upload images** — `create_attachment` only attaches a *link*. Use
  `bin/wiki-image <file> <page-id> [alt]`, which prints the Markdown to paste in.
- Image URLs come back on `https://wiki.localhost` while the API answers on `http://127.0.0.1:3000`.
  Browse the wiki by `wiki.localhost` or images look broken.
- `BOOKSTACK_ENABLE_WRITE=true`, so `delete_page`, `delete_book` and `permanently_delete` exist.
  The first two go to the recycle bin; **`permanently_delete` does not**. Never call it without an
  explicit instruction.

## Screenshots

Source them from the real UI, not by hand:

```bash
export PATH="/Users/anar/Library/Application Support/Herd/bin:$HOME/.nvm/versions/node/v22.14.0/bin:$PATH"
npm run test:e2e -- tests/Browser/<spec>.spec.ts
```

The dev server is `.claude/launch.json` → `expadu` on `:8011`. Several specs in `tests/Browser/`
already capture images. For a one-off, drive the Browser pane and screenshot it. Then:

```bash
bin/wiki-image path/to/shot.png <page-id> "what it shows"
```

## Searching before creating

Always check for an existing page before writing a new one — there is a lot of overlapping material
and duplicates are the main failure mode:

- `search_content` with `{type:page}` or `{book_id:6}` for the wiki.
- `workitem` + `action: "search"` for tickets.

**PQL does not work on this edition** — passing `pql` returns "PQL and structured filters are not
supported on this Plane edition" along with a long reference block. Don't retry it with corrected
syntax; the syntax was never the problem. List with `per_page=100` and filter client-side instead.
Label and state ids come from `label list` / `state list`.

## Closing a ticket honestly

The close step reports what actually happened. If the tests fail, say so and leave the ticket open.
Do not mark Done on a red suite, and do not write a doc claiming a result that was not observed.
