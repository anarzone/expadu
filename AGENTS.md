## DeepSeek Delegation

- Treat DeepSeek Flash v4 through the installed Cline CLI as the external assistant/subagent for this project, with the primary agent acting as orchestrator.
- Proactively delegate suitable bounded, routine work such as tests, factories, seeders, scaffolding, repetitive CRUD, straightforward UI changes, mechanical refactors, lint/type fixes, translations, documentation upkeep, codebase investigation, and first-pass implementations.
- Give the subagent a narrow scope and allow it to inspect files, edit files, and run relevant commands within that scope. Avoid concurrent edits to the same files.
- Keep architecture, security-sensitive work, ambiguous product decisions, integration, and final verification with the primary agent.
- Review every delegated diff, correct mistakes, preserve existing user changes, and run the relevant project checks before claiming completion.
- Continue this workflow until the user explicitly asks to stop. If Cline or DeepSeek becomes unavailable, report that briefly and complete the work locally when practical.


---

# Project rules

The project-specific rules below are shared with Claude Code (`CLAUDE.md`). Keep the two in sync —
both assistants must work to the same standard.


A situation-aware companion for expats in Cologne. Laravel 13 + Inertia/React 19 + Filament 5,
PostgreSQL/PostGIS, Redis. Deployed to a single Hetzner box (prod + staging side by side).

Generic Laravel guidance lives in `.claude/skills/` and loads on demand — this file is only the
things that are specific to this codebase and expensive to rediscover.

## Trust rules (non-negotiable)

**Bureaucracy is legal guidance. Being wrong costs someone their residence status.**

- Never invent a fee, deadline, or `§`. Every figure comes from an official source
  (stadt-koeln.de, BAMF, make-it-in-germany.com, gesetze-im-internet.de) with a `verified_at` date.
- A rule only reaches users when `review_status = approved` **and** it passes `RuleSourcePolicy`:
  jurisdiction, reviewer, verified date, an unexpired `review_due_at`, and ≥1 legal source on an
  allow-listed HTTPS host. `Task::authoritative()` is the gate — never bypass it.
- The catalogue is authored in `database/seeders/data/bureaucracy/*.yaml` and compiled by
  `php artisan bureaucracy:import-tasks`. Volatile figures live in `config/bureaucracy_figures.php`.
- **AI never authors user-facing bureaucracy content.** It may extract facts against a tool schema
  (user confirms) or draft gap notes for a human. `docs/bureaucracy-gaps/` drafts are never imported
  as-is.
- Showing nothing beats guessing. Partial coverage is a designed state, not a bug.

**Media is rights-gated the same way.** `PublishedMediaSelector` serves only
`rights_status = approved` + `health_status = active`. stadt-koeln images are captured as `pending`
deliberately — their terms forbid commercial reuse without written permission. Only open licences
(`config/media.php`) auto-approve. Never flip a provider to approved without a grant on file.

## Toolchain (fresh shells resolve the wrong binaries)

A new shell finds brew PHP 8.5 (no phpredis) and node 14 — both fail confusingly. Prefix every
test/build/commit command:

```bash
export PATH="/Users/anar/Library/Application Support/Herd/bin:$HOME/.nvm/versions/node/v22.14.0/bin:$PATH"
```

## Gates

- `.husky/pre-commit` runs Pint (`--dirty`) and the fast Pest suite. **Never `--no-verify`.**
- Prettier does not run automatically on `sw.ts` — run it by hand when touching that file.
- Run `npm run build` before pushing TSX import/export changes; the hook misses those.
- Every change needs a test. `php artisan test --compact` with a filter, not the whole suite.

## Deploying

`staging` → staging box, `main` → production, both via GitHub Actions on green CI. Deploy is
**gated on tests** — a red `main` silently ships nothing, which looks like "the deploy is stuck".
Check `gh run list` before assuming an infra problem. Verify prod with real data or a screenshot
after pushing; "pushed" is not "live".

## Gotchas worth remembering

- **Never cache PHP objects via the redis store.** phpredis unserializes without the autoloader, so
  it 500s only on a *warm* hit. Cache scalars, hydrate after.
- **Dark mode must toggle `html.dark`** — `--color-*` indirection resolves at `:root`, so a nested
  `.dark` wrapper won't flip tokens.
- **QA persona switching is a clean slate** (`ResetPersonaState`) — a persona means exactly that
  persona, never a layer over the previous one.
- Local and prod hold different data (prod runs the cron, local doesn't). Reproduce data bugs
  against prod counts, not local ones.

## Working style

- Read the prototype HTML/CSS in `prototype/` and match it before implementing UI.
- One header per page; utility pages carry no widgets rail.
- Don't commit the user's unrelated working-tree changes — stage only the files you touched.
