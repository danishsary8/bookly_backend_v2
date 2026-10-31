---
inclusion: always
---

# Bookly: working rules (always on)

You work for the owner, a software development student. Explain in simple English, step by step, with real examples.

## The two repos (one workspace)
- `bookly_backend_v2/`: Laravel 13 + PostgreSQL (Neon) REST API `/api/v1/`, deployed on Render.
- `bookly_frontend/`: React + Vite + TypeScript, deployed on Vercel.
- Read first, in this order: each repo's `CLAUDE.md`; backend `docs/PROJECT_CONTEXT.md`, `docs/WORKLOG.md`,
  `docs/PRODUCTION_CHECKLIST.md`, `docs/OWNER_TODO.md`, `docs/API.md`; frontend `docs/WORKLOG.md`, `docs/ROADMAP.md`,
  `docs/V2_PLAN.md`, `design-system/bookly/MASTER.md`.

## Hard rules
1. **Step by step.** Do one small step, summarise it in 3 to 6 lines, then STOP and wait for the owner to type
   `confirm`. Never chain several steps without a `confirm`.
2. **Ask before design decisions.** Give 2 or 3 options with your recommendation. Never pick silently.
3. **Migrations:** never change existing ones. A new migration or table needs the owner's OK first.
4. **Git:** one branch per task, made from a fresh `origin/main` (`git fetch origin` then
   `git switch -c <branch> origin/main`). Prefixes `feature/`, `fix/`, `chore/`, `docs/`. Human-style names and
   commit messages. **Never** put kiro, claude, codex, gemini, AI or any tool name in a branch name or commit message,
   and add no "Co-authored-by" lines. Push with `git push -u origin <branch>`. **Never merge**, never push to `main`,
   never force-push.
5. **Each branch works alone:** it must pass and make sense even if the owner has not merged any other branch or done
   any owner step yet (feature off or a friendly "not available yet" until then).
6. **Backend done =** `php artisan test` all green + `vendor/bin/pint --test` clean + `docs/API.md` and
   `public/openapi.yaml` updated for any endpoint change.
7. **Frontend done =** `npm run lint`, `npm run typecheck`, `npm test`, `npm run build` all pass, and a browser check
   of every changed page (`npm run dev` on :5173 against the local API on :8000 or the live API). Say exactly what you
   clicked and saw. If something was not checked, write "not yet verified".
8. **Secrets:** never paste keys, passwords or connection strings in code, docs, commits or chat.
9. Do not run `composer require laravel/boost`.

## Notes you must leave (every task)
- **Work log:** add a finished entry to that repo's `docs/WORKLOG.md` on the task branch (what, branch, tests, what was
  verified).
- **Hand-over note for the reviewer:** keep ONE running file, `docs/HANDOFF.md`, on the backend branch
  `docs/handoff-notes` (create it from `origin/main` the first time; later just switch to it, add, commit, push).
  For each task write: date, task, branches + links `https://github.com/danishsary8/<repo>/pull/new/<branch>`,
  status (done / partly done / blocked), tests run and results, what was NOT verified, open questions, decisions the
  owner made, and **"Where I stopped / next step"**.
- **Owner tasks:** anything only the owner can do (merge, keys, settings, decisions, manual checks) goes into
  `docs/OWNER_TODO.md` on that same `docs/handoff-notes` branch, with links and click-by-click steps.
- **Before the chat or credits run out:** commit and push whatever is safe, update `docs/HANDOFF.md`, and tell the
  owner where you stopped.
