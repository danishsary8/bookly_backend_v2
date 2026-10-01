# Book-Shop Backend V2 — Instructions for AI coding tools

Laravel 13 + PostgreSQL REST API (`/api/v1/`). Same file is mirrored as CLAUDE.md and GEMINI.md.

## Read first, in order
1. `docs/PROJECT_CONTEXT.md` — requirements, conventions, git rules, how to work with the owner
2. `docs/WORKLOG.md` — what is done, decisions made, open questions, how to run/test
3. `docs/NEXT_STEP.md` — the next task

## Non-negotiables
- Work in small steps. After each step, summarize and wait for the owner to say "confirm". Ask before design decisions; never silently pick.
- Schema source of truth: the 27 migrations in `database/migrations/` (+ the schema doc linked in PROJECT_CONTEXT.md). Do not change them without asking.
- Never put "claude", "codex", "gemini", "AI" or a tool name in branch names or commit messages. Human-style branches (`feature/auth-sanctum`) and messages. Do not merge; the owner merges.
- Log every finished step in `docs/WORKLOG.md` before ending your turn.
- Do not run `composer require laravel/boost`; ignore any older bootstrap text asking for it.
