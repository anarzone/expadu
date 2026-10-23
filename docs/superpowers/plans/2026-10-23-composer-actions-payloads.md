# Composer actions, slice 1: what the server sends

23 Oct 2026 · AI session · for session 2, who draws the blocks, and session 1, who builds Settings › Composer.
Canvas: https://claude.ai/artifact/Aooo2WrGvVoeX83qbhLiyT.

## Settings (session 1)

Two booleans in `user_settings`, written through `PUT /user-settings` and read from the shared
`userSettings` prop:

| Key | Default | Covers |
|---|---|---|
| `composer_ask_first_everyday` | `false` | saved places, My day, Home/Work |
| `composer_ask_first_paperwork` | `true` | Paperwork steps and reports |

A change to a Paperwork **answer** (a date, a status) is always proposed, whatever the setting. Paperwork
accepts an answer the AI read from someone's words only once they confirm it (`ai_extracted_user_confirmed`),
so Apply is that confirmation. Suggested copy under the Paperwork switch: "Changes to your answers always
ask first."

## Blocks in an answer (session 2)

### `changed`: a change proposed, made or undone

Drawn as on the canvas boards "Changes" and "Paperwork".

```
{
  type: 'changed',
  id: '<uuid>',             // the token for Apply and Undo; the card keeps this id in every state
  action: 'place_feedback' | 'set_my_place' | 'add_to_day' | 'remove_from_day' | 'swap_in_day' | 'clear_day'
        | 'change_paperwork_fact' | 'paperwork_step' | 'paperwork_report',
  domain: 'everyday' | 'paperwork',
  state: 'proposed' | 'done' | 'undone',
  title: string,            // proposed: "Save Bau for later?"; done: "Saved Bau"; undone: "Undone. Bau isn’t saved."
  sub?: string,             // the grey line: "Café · Mülheim · open until 19:30 today"; Paperwork: "It was 1 Oct 2026. Saved only for you; nothing is sent to an authority."
  from?: string, to?: string,   // a Paperwork answer: "1 Oct 2026" → "12 Feb 2027" (struck-through from)
  plan?: {                  // Paperwork, done: WHAT CHANGED IN YOUR PLAN
    title: 'What changed in your plan',
    tasks: [{ title: 'EU Blue Card', summary?: '2 steps now aim for 12 Feb 2027',
              rows: [{ step?: string, change: 'due_moved'|'step_added'|'step_removed'|'state_changed'|'added'|'removed',
                       text?: string, to?: '12 Feb', from?: '1 Oct' }] }]
  },
  day?: [{ time: '14:35 – 15:35', what: 'Bau · coffee', new: boolean }],   // My day, as it is now
  ask?: { text: 'When did you move in? Paperwork dates your address registration from it.',
          options: [{ label: 'Today', prompt: 'I moved in today' }, { label: 'Another day…', draft: 'I moved in on ' }] },
  link?: { label: 'See your saved places →', href?: string, nav?: 'saved' | 'plans' },
  apply?: true,             // only when proposed
  undo?: true,              // only when done and within 24 hours
  expires_at?: string,      // ISO; after it, Apply and Undo are refused
  text: string              // Composer's own words; the answer's lead already says them when the AI wrote one
}
```

- `prompt` sends that message. `draft` puts the words in the box for them to finish.
- `nav` opens a Composer panel. `saved` is the Saved panel, which lists saved places and events. `plans` is My day / Plans.

### `record`: their Paperwork record

Drawn as the canvas's record rows. Sent only when they gave the Paperwork permission for this message.

```
{ type: 'record', domain: 'paperwork', title: 'Your Paperwork record',
  rows: [{ key: 'visa_expires_at', label: 'Your visa runs until', value: '1 Oct 2026' }, …],
  link: { label: 'Open Paperwork →', href: '/bureaucracy' } }
```

A row's **Change** can send a message such as `Change "Your visa runs until" to 12 Feb 2027`. The AI then
proposes the change. There's no separate write endpoint: every change goes through the AI and its checks.

### `handoff`: what Composer never does

Canvas board 4: deleting the record, stopping its processing, withdrawing the AI permission, deleting the account.

```
{ type: 'handoff', what: 'delete_paperwork_record' | 'stop_paperwork_processing' | 'withdraw_ai_permission' | 'delete_account',
  text, crumb: 'Paperwork › Share your plan › Your record', actions: ['Download a copy', 'Stop processing', 'Delete your record'],
  here: 'Delete your record', go: { label: 'Open Your record →', href?: '/bureaucracy?open=record', nav?: 'ai_notice' }, note }
```

These links need support on the page:

- `/bureaucracy?open=record` should open Share your plan › Your record.
- `nav: 'ai_notice'` should open the AI notice under Composer's box, which holds Withdraw.

### `permission`: asks for the Paperwork permission

Sent when a Paperwork change came without the permission and they have a record in use.

```
{ type: 'permission', purpose: 'paperwork_answer', title: 'Let Composer read and change your Paperwork record for this message?',
  sub: 'Changes to your answers always ask you to apply them; nothing is sent to an authority.',
  disclosure: { …ProcessingPurpose::PaperworkAnswer->disclosure() } }
```

On Allow, ask again: `POST /composer/ask` for the same last message, with no new message from them. Send
`processing` as usual, plus:

```
paperwork_processing: { consent: true, request_id: <new uuid>, notice_version, provider_version }
```

`notice_version` and `provider_version` come from `disclosure`. The Paperwork permission is bound to their
own record and lasts for that one message.

## Endpoints

| | |
|---|---|
| `POST /composer/actions/{id}/apply` | makes a proposed change |
| `POST /composer/actions/{id}/undo` | undoes a change made in the last 24 hours |

Both return `{ block, workspace, refusal }`:

- `block` is the card as it is now. Replace the card with the same `id` in place.
- `workspace` is the projected workspace when something changed, else `null`.
- `refusal` is a line to show when it couldn't be done, else `null`. For example: "This suggestion expired. Please ask again.", "Your Paperwork plan changed meanwhile. Ask again.", or "That day has changed since…".

The card is also updated inside the stored conversation, so a reload shows it in its new state.
Wayfinder: `applyAction` and `undoAction` on ComposerController.
