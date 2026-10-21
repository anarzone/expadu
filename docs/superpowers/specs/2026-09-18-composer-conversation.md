# Composer conversation design — approved in voice

Implement in the separate interactive prototype, keeping the accepted Expadu visual language. This is a scripted, browser-persisted demonstration, not a live AI or retrieval integration.

- One continuing conversation: user messages appear immediately, loading and replies follow inline, previous exchanges remain readable.
- One active plan card updates in place. Chat and manual edits operate on the same proposal and share validation. Preserve immutable versions; earlier plan cards are compact, read-only references. My Plans opens the confirmed plan.
- Default approval is Ask before changes; a compact setting offers Allow routine plan changes. Only reversible plan operations may be applied automatically. External transactions and bureaucracy submissions are not implemented and cannot be authorized by this setting.
- Plan change previews remain separate from the confirmed plan. Provide Undo for the most recent applied version with a stale-version guard.
- Saved/My Plans/browse replacements remain contextual panels. A few relevant replacement suggestions can appear in chat, with Browse more opening the picker.
- History preserves original messages and plan references. Start fresh archives the current section, resets conversational focus, and preserves saved items, plans and unfinished drafts. History can be reopened. A deterministic topic summary demonstrates compact context; it is explicitly not semantic AI memory.
- Switching topics removes implicit active-plan context. Ambiguous plan references ask for clarification. No inference that a one-time request is a permanent preference.
- Composer only demonstrates supported Expadu operations. Unrelated requests are declined; missing coverage and feasibility information stay explicit. Bureaucracy uses the existing verified-content gates; no generated legal guidance.
- Preserve scroll and typed input while updating an active component; use modest motion and reduced-motion support. On mobile, panels return to the same conversation position. A contextual plan shortcut provides a route back to the active component.

Acceptance flow: send a planning request → see user/loading/reply in history → edit time manually → request a later time in chat → handle a conflicting edit without changing the plan → apply → inspect previous version → undo → change approval setting → make a routine change → ask about bureaucracy → Start fresh → open History → resume → refresh and preserve all state.

Production follow-up: authenticated persistence, retention/deletion controls, real conversation compaction/retrieval, model/tool authorization boundaries, authoritative knowledge retrieval and freshness checks, backend approval enforcement, real travel/opening-hours verification and cross-device concurrency. Do not claim these are complete in the prototype.
