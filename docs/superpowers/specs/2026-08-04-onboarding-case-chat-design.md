# Onboarding-to-Case and Bounded Chat Design

**Date:** 2026-08-04
**Status:** Approved by the product owner
**Depends on:** `2026-08-03-bureaucracy-case-worker-design.md`

## Outcome

Real onboarding answers must produce the same durable facts and verified plan as QA scenarios. The Bureaucracy page must always expose a small “Check my situation” assistant. Structured questions remain the complete deterministic path; optional DeepSeek extraction only converts one free-text answer into one unconfirmed, server-authorized fact.

## Minimal onboarding flow

Keep the existing five screens and visual language.

1. Welcome: describe the plan as a first draft and use accurate privacy wording.
2. Situation: collect citizenship where needed, entry mode for every non-EU/family route, current title, current-title expiry, immediate goal, and sponsor title for family cases. Permit track remains unknown unless explicitly selected or implied by the chosen goal.
3. Cologne details: keep arrival date separate from dwelling move-in date; ask whether the address is registrable instead of assuming every temporary address is not. Store self-assessed German separately from documented German proof.
4. Interests: optional and skippable.
5. Confirmation: render a preview from the same deterministic current-case-plan pipeline as `/bureaucracy`; call it a first plan when decisive facts remain unknown.

Dependent values are cleared when their parent choice changes. D-visa expiry and residence-title expiry remain distinct.

## Canonical persistence

A single transactional action locks the user and case, updates profile fields, and synchronizes onboarding-owned legal facts. A changed onboarding answer supersedes the previous onboarding/legacy fact and increments `fact_version`; it never leaves stale confirmed facts overriding the new answer. Facts from another source that disagree create a normal conflict for user resolution instead of being silently overwritten.

The case-fact store is the legal recommendation source of truth. Profile attributes remain a compatibility projection and must not silently manufacture confirmed `standard`, `visa_free`, or non-EU-sponsor facts from missing answers.

## Bounded fact interpreter

The assistant is always visible on `/bureaucracy`.

- Without AI configuration or consent, it displays the next backend-authored structured question and its choices.
- With consent, the user may describe the answer in one text box.
- The request contains the persisted active question ID; callers cannot select a fact key.
- DeepSeek receives the one registered target, its schema, authored question, minimum dependency facts, and the current message.
- DeepSeek returns only `candidate`, `unknown`, or `off_topic` through a forced structured tool call.
- The server validates the value through `FactRegistry` and presents a fixed-copy confirmation UI.
- Only explicit user confirmation writes an authoritative fact and triggers reassessment.
- DeepSeek cannot return tasks, rules, legal explanations, dates calculated by the model, documents, fees, sources, or eligibility conclusions.

The endpoint is authenticated, ownership checked, per-user/IP throttled, and limited to 20 AI-assisted messages per rolling 24 hours. Raw messages are encrypted and expire within 30 days. When unavailable, invalid, off-topic, or limited, the deterministic controls remain usable.

## User-visible safety

Before the first model call, a compact consent sheet names the configured processor, links its privacy policy, explains what is sent and retained, and offers “Not now.” If processor disclosure, endpoint, model, or key is absent, AI input is disabled but the structured assistant remains available.

All plan output continues to come from manually verified rules and exposes official sources. The existing informational/legal limitation remains visible.

## Verification

- Feature tests post real onboarding payloads and assert durable case facts, conflicts, clearing, and same-engine preview behavior.
- The six investigated scenarios are asserted independently so one failure does not prevent the remaining cases from running.
- AI tests fake every HTTP request and cover schema injection, invalid values, off-topic input, timeouts, 429/5xx, consent, quotas, ownership, encryption, and deterministic fallback.
- Playwright covers the five onboarding screens, the always-visible assistant, consent, candidate confirmation, disabled-provider fallback, and all six QA personas.
- Final browser smoke review visits every persona after the automated suite is green.

## Non-goals

- No general-purpose chat.
- No model-authored legal answer or legal research.
- No automatic fact confirmation.
- No document upload or OCR.
- No new UI framework or icon library.
