# Task prompts (owner's guide)

Ready-to-paste prompts so the coding assistant in Kiro continues Bookly step by step. Written 2026-10-31.

## One-time setup (5 minutes)
1. Merge this branch (`docs/work-prompts`) so the files are on `main`, then `git pull` in `bookly_backend_v2`.
2. Kiro opens the parent folder `E:\xampp\htdocs\book_project\bookly_v2`, so copy the rules file there once
   (PowerShell, in that parent folder):
   ```powershell
   New-Item -ItemType Directory -Force .kiro\steering | Out-Null
   Copy-Item bookly_backend_v2\.kiro\steering\bookly.md .kiro\steering\bookly.md
   ```
   Check: Kiro panel → **Agent Steering** lists `bookly.md`.

## Order for today
| # | File | Task | Size |
|---|------|------|------|
| 0 | `00-start-every-chat.md` | Paste first in **every** new chat | - |
| 1 | `01-phone-code-hint.md` | F: phone-code hint | small |
| 2 | `02-you-may-also-like.md` | A: similar books (audit + gaps; most of it exists) | small |
| 3 | `03-offers-and-reports.md` | B+D: offers + reports (needs your OK for a new table) | large |
| 4 | `04-bi-dashboard.md` | E: BI admin dashboard | large |

Use a **new chat per task** (prompt 0, then the task prompt). Long chats get confused and cost more.

## How to talk to it
- After each step it stops. Read the summary, then type `confirm` (or say what to change).
- When it asks a design question, answer with the letter or your own words. If unsure, say
  "explain simpler with an example".
- If it chains many steps without stopping, say: "Stop. Follow the steering rules: one step, then wait."
- If the chat gets long or credits run low, type: `Wrap up: push safe work, update HANDOFF.md and OWNER_TODO.md on
  docs/handoff-notes, tell me where you stopped.`

## Reviewer checklist (before you merge anything)
- [ ] Branch name: `feature/`, `fix/`, `chore/` or `docs/` + plain words. No tool or AI name. Same for commits.
- [ ] The PR shows green CI.
- [ ] Only files that belong to the task changed (look at "Files changed").
- [ ] No existing file in `database/migrations/` was edited; any new migration was approved by you.
- [ ] Backend: it says how many tests passed and that pint is clean; `docs/API.md` updated for endpoint changes.
- [ ] Frontend: it lists lint, typecheck, test, build results **and** what it clicked in the browser.
- [ ] `WORKLOG.md` has an entry; `docs/HANDOFF.md` on `docs/handoff-notes` says what is done and what is not.
- [ ] No passwords, keys or connection strings anywhere.

## Where the notes are
- `docs/HANDOFF.md` (branch `docs/handoff-notes`): what each chat did, where it stopped, open questions. Reviewed
  later in the main project chat.
- `docs/OWNER_TODO.md`: everything only you can do (merges, keys, settings, checks), with links.
