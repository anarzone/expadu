# Composer: evidence audit (16 Nov 2026)

Read-only. No evals or paid calls were run. Code paths are relative to the `wt-ios` worktree (branch `docs/smart-app-plan` @ `18d16924c`, 14 Nov). Other roots:
- **[W]** = `/Users/anar/Projects/Own/Startups/expadu-app/.claude/worktrees` (eval reports, not in git)
- **[M]** = `/Users/anar/Projects/Own/Startups/expadu-app` (main checkout; `reports/` and `research_notes/` are untracked)
- **[MEM]** = `/Users/anar/.claude/projects/-Users-anar-Projects-Own-Startups-expadu-app/memory`

## 1. Capability, end to end

**How it works.** Each message runs through one Haiku tool loop (`app/Composer/Assistant/Assistant.php:31-46`). It gets 3 rounds (`:51`), plus one more if an answer is sent back, inside a 14 s budget (`:78`), with `max_tokens` 1200 (`:365`) and a tool call forced every round (`:369`). The code planner picks the places for a plan, not the AI (`app/Composer/Assistant/PlanDay.php:6-11`). Every sentence is checked against the tool results and dropped if they don't back it.

**Tools.** 51 plus `answer`: 20 lookups (`AssistantTools.php:264-362`, `PlanDay.php:68`) and 31 changes (registry `app/Composer/Actions/ComposerActions.php:21-52`, plus `open_in_app` at `AssistantTools.php:1855`).

| Job | Lookups | Changes it actually makes |
|---|---|---|
| Day planning | plan_day, suggest_for_day, my_day*, my_next (card only, model never sees it) | add_to_day, remove_from_day, add_appointment, mark_done, swap_in_day, move_in_day, shift_day, clear_day |
| Events / places | search_events, search_places, find_place, compare_places, weather, where_am_i, who_to_ask | save_event, remind_me, place_feedback, laptop_answer (proposal only) |
| Travel | departures, plan_trip, notices, my_trip (card only, `:350`) | plan_trip_for_later (keeps the way and sets a leave reminder), way_reminder, remove_way, save_stop, set_travel_prefs, mute_alerts |
| Paperwork | guidance (up to 3 guide titles, by keyword substring match, `:1672-1687`), paperwork (card only without permission, `:348-349`) | change_paperwork_fact (always a proposal, `ComposerActions.php:59`), paperwork_step, paperwork_report; all three need a separate Paperwork permission |
| Own data / settings | my_places, my_saved, my_taste* | set_my_place, add_my_place, remove_my_place, set_place_times, set_notifications, set_needs, update_profile, dismiss_alert, read_alerts, open_in_app (a hand-off, not a change) |

\* = only with the personalisation opt-in (`AssistantTools.php:357-361`).

**What it completes.** The changes are real writes, each with a 24 h Undo (`ComposerActions.php:62`).

**What it only answers or hands off:**
- It can't book, buy, order or send anything, and won't answer general-knowledge, legal or visa questions (`Assistant.php:93`).
- Paperwork questions are answered "only by these guides, never by you" (`AssistantTools.php:345`). In practice that means guide titles.
- It can't start a live trip; there is no tool for it.
- It is reactive only. The server already computes leave times, plan risks and briefs, but "None of it reaches the iPhone" (`docs/personalisation/2026-11-14-smart-app-plan.md:7`).

## 2. Measured quality

**The eval set.** `tests/Fixtures/composer-eval/requests.json`: version 2, created 21 Oct, **490 cases** in 14 groups. The largest are regression 58, solo_newcomer 48 and actions 42; new_person and opted_in have 40 each.

**How it runs.** The runner is `app/Console/Commands/EvaluateComposer.php`: synthetic users, the real Haiku model, gates plus expectations. 381 reports sit in [W]`/*/storage/app/composer-eval/`. The eval is not in CI: `.github/workflows/` has no `composer:eval`.

### A. Automated self-checks (most flattering)

- **Last full run** (483 cases), 30 Oct, "c3-v3-final": **467/483 fully passing (96.7%)**, 1 gate (fact_mismatch). File: [W]`/today-layout/storage/app/composer-eval/c3-v3-final-20261030-021106.json`.
  - Misses: `categories_any` 33/40, `around` 31/35.
  - `cheap_food` failed in 8 personas. The planner returned an airport viewpoint ("Besucherterrasse in Grengel") instead of food.
- **Latest runs** (4 Nov), in [W]`/release-staging/storage/app/composer-eval/`:
  - regression: **58/58**, 0 gates (`final2-reg-20261104-165340.json`)
  - fresh 40-question audit set: **38/40**, 1 gate (`final2-fresh-20261104-164844.json`); fails are `g_apotheke` and `c_day_after`
- **No eval has run since 4 Nov.**

### B. Blind Opus 5.5 grading of the same 483 answers (`composer:grade`, scores 1–5)

File: [W]`/today-layout/storage/app/composer-eval/grades-b2-c3-20261030-021245.json`.

- **c3-v3-final:** overall **3.32**, fit 3.56, useful **3.29**, honest 4.06, clear 3.87.
  - Poor (overall ≤ 2): **90/483 (18.6%)**.
  - Score spread: 1:8, 2:82, 3:175, 4:183, 5:35.
  - Flags: ignores_constraint 70, odd_wording 64, contradiction 29, repetitive 17.
- **Production setting (Haiku, low effort)**, `grades-full-20261029-234224.json`: overall 3.27, poor **115/483 (23.8%)**, ignores_constraint 83.
- **Sonnet, medium effort:** overall 3.43, but 22/483 calls failed.

By group (c3, overall score):

| Group | Score | Group | Score |
|---|---|---|---|
| actions | **4.17** | toddler_family | **2.79** |
| regression | 3.65 | wheelchair | 2.93 |
| places_matrix | 3.57 | school_family | 2.98 |
| | | student_budget | 3.03 |

Worst themes: cheap_food 1.50, impossible 2.38, meet_people 2.38, german_evening 2.62, rainy_indoors 2.75, step_free_day 2.75. The memory notes say "ignores constraint ≈ 3/4 of low scores" ([MEM]`/project_ai_model_check_2026_10_30.md:21`).

### C. Hand grade, 3 Nov accuracy audit (40 fresh questions)

From [M]`/research_notes/Expadu accuracy audit/composer.md:8-10,39-45`:
- **69% (313 of 454 points)**: 12 good, 5 in between, 23 poor.
- "Mostly understands people (89%)… fails at giving them something they can use (39%)."
- The eval passed 37/40, and **21 of those 37 were graded poor**.
- Other-day hours 0/4; comparisons 0/3; corrections 2/5.
- The sentence check dropped 25 sentences, and only 2 of them were wrong. Meanwhile 5/40 answers kept a false statement.

Also in [M]`/reports/Expadu accuracy audit.md:18-19,38-39`.

**After the audit.** Fixes shipped 3–4 Nov: `8e5c59414` (other-day hours), `c01a277da` (comparisons), `31302d776` (sentence check). They were re-measured only by the self-checks (38/40), never re-graded by hand or by Opus.

## 3. Knowing the user

**Sent with every request, without the opt-in:**
- the message
- the last **6 turns of this conversation**, 1,000 characters each at most (`Assistant.php:75,224-239`)
- the time, the page and the **home Veedel** (`:82,253-255`)
- plans this conversation made

**Available through tools:** nearest stop and Veedel (never coordinates), saved places' names, saved events and stops (`AssistantTools.php:110-125`). The system prompt says: "You know nothing about the person beyond their words and what the tools return" (`Assistant.php:120`).

**Consent gates:**
- Each request needs a `composer_assist` permit (`app/Composer/Assistant/Ask.php:76-79`). That purpose exists only when `LLM_DRIVER=anthropic` (`app/Privacy/ProcessingPurpose.php:102`); the default is `heuristic` (`config/services.php:145`, `.env.example:137`).
- The personalisation opt-in (`app/Models/User.php:248-251`, off by default per `config/personalisation.php:8-9`) adds only `my_taste` and `my_day`.
- Paperwork contents need a separate permission. Otherwise trip, Paperwork and next-plan are cards the model never reads (`AssistantTools.php:348-352`; `Assistant.php:187`).

**Memory across conversations: none.**
- There is no `user_facts` table and no remember tool (grep of `app/` and `database/migrations` found none).
- The 14 Nov code audit says: "Composer forgets. 'I'm vegetarian' is lost when the chat ends… never the taste profile, past trips or the profile (situation, German level)" (`docs/personalisation/2026-11-14-smart-app-plan.md:9`).
- Finished trips are deleted (`:8`), and the iPhone "barely feeds the taste" (`:11`).

**How the taste is built:**
1. Signals go into a ledger.
2. `signals:aggregate` runs at 04:30 (`routes/console.php:157`) through `AffinityAggregator`, with a 60-day half-life (`config/personalisation.php:31-33`).
3. `TasteFacts` turns the result into strong/some/avoid categories and Veedels, dayparts and a weekend lean, with at least 5 signals (`:76-78`).
4. Composer reads those as `my_taste` lines (`AssistantTools.php:2063-2084`).
5. Separately, `TasteProfileWriter` (Haiku writes the wording) runs at 04:45 (`routes/console.php:159`), but only if `TASTE_PROFILE_ENABLED` is set (default `false`, `config/services.php:102`, `.env.example:124`; checked at `TasteProfileWriter.php:64-67`). **Composer never reads that profile**: no reference to it in `app/Composer`.

**Does it change anything? Measured:**
- **Production:** "0 opt-ins on production" ([MEM]`/project_accuracy_audit_fixes.md:10`). The audit found taste "never called in 96 searches" ([M]`/reports/Expadu accuracy audit.md:40`).
- **Eval, controlled comparison.** The same 40 messages were run as `opted_in` (with a seeded taste, `EvaluateComposer.php:536-543`) and as `new_person`:
  - Opus overall: **3.23 vs 3.23** (opted-in better 12 times, worse 10, tied 18)
  - the model called `my_taste` in **2 of 40** opted-in cases (c3 report)
- **Since then:** `e6160f3fb` (4 Nov) added code-side taste ranking to `search_places`. Before it, "36 of 36 lists were the same for opposite profiles." It has not been measured since.

## 4. Cost and speed

**Model.** Haiku is forced: any model id that isn't Haiku falls back to `claude-haiku-5-5` (`config/services.php:5,147`), at effort low (`:148`). Because a tool call is forced every round, the model never thinks ([MEM]`/project_ai_model_check_2026_10_30.md:23`).

**Tokens per message** (eval means, cache reads included, `Assistant.php:773-775`):

| Run | Input | Output | Calls |
|---|---|---|---|
| c3 full run | 43,950 | 336 | 2.07 |
| 4 Nov regression | 43,518 | 280 | 2.03 |

At the config price ($0.10 / $0.50 per Mtok, `config/services.php:132`) that is about **$0.0046 per message** before cache discounts.

**Latency** (time per ask in the eval):

| Run | Median | p90 | Max |
|---|---|---|---|
| c3 full run | 4.01 s | 6.16 s | 12.15 s |
| 4 Nov regression | 3.42 s | 4.99 s | 6.97 s |
| Sonnet, full run | 5.43 s | not checked | not checked |

The audit's fresh set: p50 4.0 s, p95 7.5 s, 6 of 40 over 6 s (`composer.md:38`). The target is "3–6 s" (`Assistant.php:50`).

**Production telemetry.** Every call is logged to `llm_calls` (`app/Ai/LlmCalls.php:20-41`). As of 3 Nov, production had **19 Composer calls all-time, 3 traced answers, from one person** (`composer.md:7,28`; `reports/…:68`). There is no live latency or cost figure.

## 5. Engineering health

**Size:**
- `app/Composer`: 59 PHP files, **12,990 lines**. Largest: `AssistantTools.php` 2,181, `WorkspaceStore.php` 1,001, `Assistant.php` 780.
- `app/Personalisation`: 2,055 lines.
- iOS: `ios/Expadu/Composer` 4,251 lines of Swift.

**Tests.** 32 Composer test files, 6,523 lines, **304** `it()`/`test()` declarations. The model is faked (`Http::fake`) in 4 of the 9 assistant test files, so the tests check the code, not the answers.

**Churn.**
- **192 non-merge commits** touched `app/Composer` since 1 Sep (Sep 9, Oct 159, Nov 24), for +14,945 / −4,915 lines.
- 123 of those came after the 22 Oct one-loop rewrite. That rewrite followed the owner saying answers got "more stupid after each change" ([MEM]`/project_composer_one_loop.md:11`).
- **Only 3 commits since 5 Nov** (the last on 12 Nov). The work moved to iOS.

**Known open issues:**
- There are no TODO/FIXME markers in `app/Composer`.
- The `first_names_place` check wobbles on wording, and `who_to_ask` has not been seen working with the real model ([MEM]`/project_accuracy_audit_fixes.md:19`).
- Place data that Composer relies on ([M]`/reports/Expadu accuracy audit.md:22-24,44`):
  - 28% of sampled places have a firm error
  - 38% of places have listed hours, 0% a photo
  - 1,727 of 8,101 places are in duplicate pairs
  - 74 of 110 kinds have nothing suggestible
- Memory, routines and notifications are planned but not built (`smart-app-plan.md:64-71`).

## 6. Honest assessment

- **Far from "knows me and runs my day."** Today Composer is a grounded city lookup and plan editor that answers in about 3–4 s for under half a cent. It sees only the current chat, the home Veedel and saved place names. Nothing it learns survives the conversation (`smart-app-plan.md:9`).
- **Gap 1: no memory or picture of the person.** There is no fact store and the taste profile is never read. With a seeded taste, quality didn't move (3.23 vs 3.23), the model looked at taste in 2/40 cases, and production has 0 opt-ins.
- **Gap 2: suggestions often aren't useful.** Opus scores usefulness 3.29/5, with 18.6–23.8% of answers poor. The hand grade puts usefulness at 39%. Failures cluster on constraints (families 2.79–2.98, wheelchair 2.93, cheap food 1.50) and on thin place data. Doing things for the person is the strong part (4.17).
- **Gap 3: no real use and no reach-out.** There have been 3 production answers from one person. The engine only answers when asked, and the server's moments never reach the phone.
- **The measurements flatter it.** Self-checks say 96.7–100%, while independent grading says 18–24% poor, and 21 of the 37 eval passes were graded poor by hand. Nothing has been re-graded since the 3–4 Nov fixes, and nothing at all has been measured since 4 Nov.
