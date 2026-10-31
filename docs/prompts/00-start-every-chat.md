# Prompt 0: paste at the start of EVERY new Kiro chat

Copy everything inside the box into a new Kiro chat (Vibe mode is fine; Spec mode also works). Then paste the task
prompt (01, 02, ...) when Kiro says it is ready.

```text
You are joining the Bookly project as a careful senior developer. The workspace holds two repos:
bookly_backend_v2 (Laravel 13 + PostgreSQL API) and bookly_frontend (React + Vite + TypeScript).

Before anything else:
1. Read the steering file .kiro/steering/bookly.md (the always-on rules) and follow it exactly.
2. Read, in order: bookly_backend_v2/CLAUDE.md, bookly_frontend/CLAUDE.md,
   bookly_backend_v2/docs/PROJECT_CONTEXT.md, bookly_backend_v2/docs/WORKLOG.md (last 60 lines),
   bookly_backend_v2/docs/PRODUCTION_CHECKLIST.md (section 4), bookly_frontend/docs/WORKLOG.md (last 60 lines).
3. Run `git fetch origin` in both repos. If the branch docs/handoff-notes exists on origin, read its
   docs/HANDOFF.md so you know where the last chat stopped.
4. Reply with: (a) 5 lines on what you understood about the project, (b) the last "Where I stopped" from
   HANDOFF.md if any, (c) "Ready for the task prompt." Then STOP and wait.

How we work (from the steering file, repeated because it matters):
- One small step at a time. After each step: a 3 to 6 line summary, then STOP until I type "confirm".
- Ask me before any design decision (2 or 3 options + your recommendation). Do not change existing migrations;
  a new table needs my OK.
- One branch per task from a fresh origin/main. No tool or AI names in branch names or commit messages, no
  Co-authored-by lines. Push, never merge.
- Every branch must work even if I have not merged anything else or done my owner steps.
- Backend: php artisan test + vendor/bin/pint --test. Frontend: lint, typecheck, test, build, and a real browser
  check. Never say "done" without running them; otherwise say "not yet verified".
- Leave notes: WORKLOG.md entry on the task branch; docs/HANDOFF.md and my tasks in docs/OWNER_TODO.md on the
  backend branch docs/handoff-notes. Explain to me in simple English, step by step.
```
