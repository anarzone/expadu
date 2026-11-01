# Expadu — project rules

Keep the root `AGENTS.md` and `CLAUDE.md` synchronized.

## Product direction

Expadu is a daily companion for expats across cities, starting with Cologne.
Composer connects a person's request to places, events, journeys, reviewed
bureaucracy information and services. Keep city-specific assumptions in data and
configuration rather than hardcoding them into the design.

Laravel 13, Inertia v2, React 19, Filament 5, PostgreSQL/PostGIS and Redis.
Check `composer.json`, `package.json` and the CI workflows for current requirements.
Use relevant instructions in `.agents/skills/` or `.claude/skills/` when needed.

## Trust rules

**Bureaucracy requires verified sources.**

- Never invent fees, deadlines or legal references. Figures need an official source
  on an allow-listed host (`config/bureaucracy_sources.php`) and a `verified_at` date.
  Adding a source host is a policy decision: record who decided it next to the host.
- Preserve `Task::authoritative()` and `RuleSourcePolicy`. Approved content must
  satisfy their publication, jurisdiction, version, reviewer, source-verification,
  source-host and validity-window checks. Never bypass these gates.
- Cards are checked by machine, not signed off by a person (owner decision,
  7 Oct 2026). A `quote_checked` card (`reviewed_by: automated_source_check`)
  carries `claims`: each ties a piece of card text to the exact sentence on its
  official page. The import rejects a card if any figure on it is unquoted or differs
  from its quote. `bureaucracy:verify-sources` re-reads the pages daily and at
  deploy; only a passing check gives the card its `verified_at` and `review_due_at`,
  and a failed check withdraws it at once (users see "check the official page").
  Never author those dates on a `quote_checked` card, and never weaken `ClaimCheck`.
  Cards still stamped by a person (`expadu_content_owner`) keep the human path.
- AI may write and update cards only in this checked form. Prose without figures is
  not machine-verifiable, so keep it to what the quoted sources say.
- People are contacted only through `bureaucracy_escalations`: a failed check on a
  high-stakes card (residence, dated deadlines, money, urgent) or a plan the app
  cannot answer. High severity reaches the owner through Sentry, once per opening.
  Escalations never carry personal data.
- Authoritative catalogue data lives in `database/seeders/data/bureaucracy/*.yaml`
  and is compiled by `php artisan bureaucracy:import-tasks`. Volatile figures live
  in `config/bureaucracy_figures.php`.
- Missing or partial coverage must remain explicit. Showing nothing beats guessing.

**Media requires approved rights.**

- `PublishedMediaSelector` serves assets only when `rights_status = approved` and
  `health_status = active`.
- Stadt Köln images remain pending without documented permission. Only licences
  allowed by `config/media.php` can auto-approve. Do not approve a provider without
  a documented rights basis.

## Development and verification

- Verify the active PHP and Node versions before running project commands. Current
  CI uses PHP 8.4 and Node 22. Local PHP is provided by Herd; do not assume a fresh
  shell selects the correct runtime or extensions.
- `.husky/pre-commit` runs a staged secret scan when gitleaks is available, then
  `lint-staged` formatting. Staged PHP changes also trigger the fast Pest suite.
  Do not bypass hooks with `--no-verify`.
- `.lintstagedrc.json` defines formatting coverage: Pint for PHP, ESLint/Prettier for
  `resources/js` TS/TSX, and Prettier for `resources/css`. Check files outside those
  patterns explicitly, including `resources/sw.ts`.
- Add focused regression tests for behavior changes. Use filtered
  `php artisan test --compact` runs for PHP work and relevant frontend checks for
  frontend work. Validate visual changes in the browser; documentation-only changes
  need accuracy and consistency checks rather than unrelated application tests.
- Run `npm run build` before pushing TSX import/export changes. Commit hooks do not
  replace a frontend build.
- Preserve unrelated working-tree changes. Stage only files belonging to the task.

## UI work

- Start with `prototype/dev/design/connected/design-decisions.md` for the current
  cross-page decisions, approved references and shared components.
- Read the approved prototype and relevant product reports before implementing UI.
  Later user decisions take precedence over older proposals.
- Build alternative design concepts separately; preserve the accepted baseline
  unless the user asks to change it.
- Keep one page header. Utility pages do not carry a widgets rail.
- Prefer clean, friendly edits within the page for simple choices such as filters
  and starting points. Do not default to large blocking modals, blurred backdrops
  or oversized confirmation buttons. Keep controls compact and touch-friendly,
  preserve browsing context, and use restrained motion.
- Design mobile-first, including motion. Touch actions need immediate, subtle
  feedback; sheets, tabs, disclosures and confirmations should transition smoothly
  with short, interruptible motion. Preserve context and focus, avoid layout jumps
  and repeated decorative effects, and respect reduced-motion preferences. Do not
  depend on hover for feedback.
- Mobile screens prioritize useful content over chrome. Keep headers, navigation
  and discovery controls compact; reveal optional preferences on demand. Avoid
  repeated headings, labels and large gaps. Check the initial viewport with real
  content: the first useful item and its action should be visible without
  scrolling on a typical phone, while keeping readable text and touch targets.
- Keep search area, starting point/travel mode and sorting as separate compact
  contextual controls, not a single expanding filter form. Share starting point
  and mode with Directions. Only offer distance or availability choices backed
  by data. Prefer aligned choices over irregular groups of large buttons.
- Use the shared design tokens in `prototype/dev/design/connected/tokens.css`.
  Every page uses Travel’s warm cream palette (accepted 6 October 2026): cream
  canvas, warm off-white surfaces, dark warm text, cyan selections and orange
  actions, one main action per section. Give each colour one role (see “Colour
  roles” in `design-decisions.md`); do not hard-code colours or introduce a
  separate palette for a page. Main controls must not become uniformly grey. Use
  existing controls as interaction references, but improve layout and hierarchy
  rather than copying bulky chrome.
- Keep uncertain data visible as uncertain. Mockups must identify sample content
  and must not imply that scripted interactions are connected to live services.

## Deployment

`staging` deploys to staging and `main` to production through GitHub Actions on the
Hetzner host. Both deployment jobs depend on the image build, application tests and
browser tests; see `.github/workflows/ci.yml`.

When investigating a deployment, check workflow status before assuming an
infrastructure problem. After an authorized deployment, verify the running app
with relevant data or a screenshot. A successful push alone does not prove it is live.

## Project-specific pitfalls

- Cache scalars in Redis and hydrate PHP objects afterward. Cached objects have
  caused failures on warm reads when deserialization precedes autoloading.
- The app is light only at launch (no dark mode). The mock's tokens live in
  `resources/css/app.css`; breakpoints are Tailwind's `sm` 360, `md` 701 (tablet),
  `lg` 1000 (desktop) and `xl` 1500, so `max-md:` is a phone.
- QA previews are read-only (`ScenarioAssessmentPreview`). The old reset/become
  endpoints and `ResetPersonaState` return 410 without changing account or family
  data. Do not restore destructive switching; test persistent workflows in
  isolated services.
- Local and production datasets differ. Check the affected environment's data when
  investigating a data issue; local counts do not establish production behavior.
