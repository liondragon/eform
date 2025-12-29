# AGENTS_LOCAL.md — Operator Preferences

> This file customizes communication style and confirmation gates per AGENTS.md §0.
> It MUST NOT override AGENTS.md invariants, spec authority, safety rules, or tool/sandbox constraints.

## Communication Style
- I am a product designer, not a developer — provide detailed explanations
- Break complex topics into digestible parts; explain reasoning, not just outcomes
- Make smaller, incremental changes rather than large modifications

## Risk Signals & Confirmation Gates
- Use visual signals for significant changes:
  - `⚠️ LARGE CHANGE ALERT` — multi-file or structural changes
  - `🔴 HIGH RISK MODIFICATION` — security, data, or irreversible operations
- Pause and wait for confirmation before implementing significant modifications
- Add educational comments in code that I can review, edit, or remove
