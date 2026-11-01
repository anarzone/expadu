# Composer actions, slice 1: what the server sends

23 Oct 2026 · AI session · the contract session 2 proposed, which the server now sends, plus a few additions.
Canvas: https://claude.ai/artifact/Aooo2WrGvVoeX83qbhLiyT.

## Settings

Two booleans in `user_settings`, written through `PUT /user-settings` and read from the shared `userSettings`
prop. The page lives at Settings › Composer.

| Key | Default | Covers |
|---|---|---|
| `composer_ask_first_everyday` | `false` | saved places, My day, Home/Work |
| `composer_ask_first_paperwork` | `true` | Paperwork steps and reports; a date typed into a record row |

A Paperwork **answer read from the person's words by the AI** is always a proposal, whatever the setting.
Paperwork accepts it only once they confirm it (`ai_extracted_user_confirmed`). A date typed into a record row is
their own answer (`manual`), so it follows the switch.

## Blocks in an answer

Every block has `id` and `area` (`everyday` | `paperwork`). A change keeps one `id` (its token) in every state;
its plan changes use `"{id}:plan"`.

```
proposal: { type, id, token, area, action, title, from?, to?, sub?, fact_key?, edit?: {kind:'date', value:'YYYY-MM-DD'}, until, text }
done:     { type, id, area, action, text, sub?, undo: {token, until}, link?: {label, href?|nav?},
            day?: [{time, what, new}],                     // My day as it is now, the new item marked
            ask?: {text, options: [{label, prompt?|draft?}]} }   // after Home: "When did you move in?"
undone:   { type, id, area, action, text }
plan_changes: { type, id: "{token}:plan", area:'paperwork', changes: PaperworkPlanDiff rows, link: {label:'Open Paperwork', href:'/bureaucracy'} }
record:   { type, domain:'paperwork', title, rows: [{key, label, value, edit?: {kind:'date', value:'YYYY-MM-DD'|null}}], link }
handoff:  { type, what, text, place: {crumb, actions: string[], here, label, href?|nav?}, note }
permission: { type, purpose:'paperwork_answer', title, sub, disclosure }
```

Notes:

- `text` on a block is Composer's own words. The answer's lead says them already when the AI wrote one.
- On a proposal, `until` is when it can no longer be applied (24 h). On done, `undo.until` is when it can no longer be undone.
- Undone and plan changes are drawn in place of the done card (see Endpoints).
- On `ask` options: `prompt` sends that message. `draft` puts the words in the box for them to finish ("I moved in on ").
- `link.nav`: `saved` is Composer's Saved panel (saved places and events); `plans` is My day / Plans.
- `handoff.place.here` is the label to ring. `place.href` is one of:
  - `/bureaucracy?record=delete`
  - `/bureaucracy?record=stop`
  - `/settings/profile`
- `withdraw_ai_permission` and `withdraw_paperwork_permission` send `place.nav: 'ai_notice'`; the latter also sends
  `place.purpose: 'composer_paperwork'`. The notice under Composer's box should open with Withdraw for that purpose.
  - Withdrawing `composer_assist` withdraws `composer_paperwork` too.
  - `DELETE /privacy/processing/composer_paperwork` withdraws only the Paperwork one.
- `permission` comes when a Paperwork change arrives without their permission and they have a record in use.
  - Its purpose is `composer_paperwork`: Composer's own Paperwork notice.
  - `disclosure.notice` is the notice in words (a list of sentences): draw it as given. Its `payload_categories`
    and `never_sent` are what the words come from. `GET /privacy/processing/composer_paperwork` serves the same.
  - On Allow, ask again: `POST /composer/ask` for the same last message, with `processing` as usual plus
    `paperwork_processing: { consent: true, request_id: <new uuid>, notice_version, provider_version }`, both
    versions taken from `disclosure`.
  - The permission is bound to their own record and lasts for that one message. That exchange runs under it.
- `notice_version` is now `2026-10-23.subject-bound-request.5`. Pages already read it from the disclosure, so
  nothing to change there.

## Endpoints

All three answer with the workspace. A refusal is a 409 `{ message, workspace }`, and its message goes to the bar.

| | |
|---|---|
| `POST /composer/apply {token}` | the proposal becomes done, and its plan changes when Paperwork's plan moved |
| `POST /composer/undo {token}` | done becomes undone, and its plan changes go |
| `POST /composer/act {action:'paperwork_fact', key, value}` | a record row's new date: its proposal or done card, in a reply of Composer's own; no message from them |

- Another person's token gets a 404.
- Applying or undoing twice changes nothing and returns the workspace.
- Wayfinder: `applyAction`, `undoAction` and `act` on ComposerController.
