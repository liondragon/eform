# Self-Improvement Log

- When implementation-plan tasks are too abstract, require a per-task “task card” (Artifacts/Interfaces/Tests/Done When) instead of relying on the executor agent to infer missing details.
- Keep planning guides focused on plan outputs; put executor prompt templates under `agent_docs/prompts/` and reference them lightly from the guide when needed.
- Prefer functional role headers (planner/executor) over seniority personas; they reinforce authority boundaries without increasing improvisation.
- When reacting to review feedback, triage items (Category/Decision/Evidence) and default to skipping perf/DRY refactors unless they reduce net complexity or are backed by a bounded usage model or measurement.
- For parser/validator/registry work, add at least one test that runs shipped fixtures/examples through preflight and one test that verifies all declared IDs/enums resolve; this catches “schema accepts X but registry rejects X” drift early.
- When asked to “implement the next unchecked task”, don’t eyeball: programmatically list unchecked checkboxes, pick the first in file order, and quote that exact line before making code changes (IDE selection is not a task selector unless the user says so).
- For gotcha-heavy tasks (ledger/email/cache/atomic IO), mark the task card with `Handoff Required: yes — <must-write notes>` so the executor records sharp edges in `docs/state_handoff.md` before continuing.
- When using `apply_patch`, never paste patch markers (e.g., "*** End Patch") or escaped newline sequences (like `\n+`) into Markdown content; write literal newlines and re-open the file immediately if the output looks “patch-like”.
