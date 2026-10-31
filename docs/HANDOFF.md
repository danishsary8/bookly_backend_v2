# Hand-over notes

## How to review
1. Read this file from the top. Each task below lists its branches, checks and open points.
2. For every branch listed, open its PR link and check it against the reviewer checklist in `docs/prompts/README.md`.
3. Write what you find under a **Review** heading in that task's section (pass, or the problem and the file/line).
4. Owner-only steps (merge, settings, live checks) are in `docs/OWNER_TODO.md` on this same branch.

---

## 2026-10-31 · Task F: phone sign-in code hint

**Why:** the API only sends a phone sign-in code to a number that is confirmed and linked to the Bookly Telegram bot
(backend `PhoneCodeController::reachable`: `phone_e164` + `phone_verified_at` + `telegram_chat_id`). A number typed in
Account → Profile is only a courier contact and is not confirmed, so people (the owner too) got no code.

**Branches**
- Frontend `fix/phone-code-hint`: https://github.com/danishsary8/bookly_frontend/pull/new/fix/phone-code-hint
  (commits `ccc4e65` "Explain how to get a phone sign-in code", `d78dc9d` "Log the phone sign-in code hint").
- Backend: no change.

**Status:** done (pushed, not merged).

**What changed**
- `src/features/auth/PhoneCodeSignIn.tsx`: hint under the phone field (`TextField` `hint`, so it is the field's
  accessible description). After the first "Send code", the number is re-checked on every key press, so a fixed number
  clears the error and brings the hint back without pressing Send again.
- `src/pages/account/ProfilePage.tsx`: hint under the phone field with a link to `/account/security`.
- `src/i18n/en.ts`, `src/i18n/km.ts`: `signin.phoneCodeHint`, `account.phoneHint`, `account.phoneHintLink`, `account.phoneHintEnd`.
  - EN login: "Codes only go to a confirmed number. Not confirmed yet? Use Continue with Telegram below, or confirm it in
    Account → Sign-in & security → Phone → Confirm."
  - KM login: "លេខកូដផ្ញើទៅតែលេខដែលបានបញ្ជាក់ប៉ុណ្ណោះ។ មិនទាន់បញ្ជាក់? សូមប្រើ «បន្តជាមួយ Telegram» ខាងក្រោម ឬបញ្ជាក់វានៅ គណនី → ការចូល និងសុវត្ថិភាព → លេខទូរស័ព្ទ → បញ្ជាក់។"
  - EN Profile: "Couriers call this number. To sign in with it, it must be confirmed in Sign-in & security."
  - KM Profile: "អ្នកដឹកជញ្ជូនទូរស័ព្ទមកលេខនេះ។ ដើម្បីចូលគណនីដោយលេខនេះ វាត្រូវតែបានបញ្ជាក់នៅក្នុង ការចូល និងសុវត្ថិភាព។"
- Tests: 4 new (`src/pages/auth/phone-code-sign-in.test.tsx`: hint EN, hint KM, error clears once the number is fixed
  with no request sent; `src/pages/account/account-pages.test.tsx`: Profile hint + link).

**Tests run**
- `npm run lint`: 0 errors · `npm run typecheck`: clean · `npm test`: 440 passed, 3 skipped, 0 failed · `npm run build`: ok.
- Browser (Playwright/Chromium, Vite :5173 + local API :8000, demo customer), EN and KM at 1280 px and 390 px, all pass:
  hint under the field; `123` → Send → error replaces the hint; typing `012 345 678` → error gone, hint back, no
  request sent; Profile hint shows and its link opens Sign-in & security; no page or hint overflow; no console errors.

**Not verified**
- The local API has no Telegram bot, which hides phone sign-in, so `/auth/options` was stubbed to `telegram: true` in the
  browser for the check. Not checked on the live site yet (owner step in `OWNER_TODO.md` section 0).
- A real code from the live bot (not part of this change).
- The Khmer wording has not been checked by a native speaker.

**Decisions the owner made**
- Wording A1 (hint under the field, always visible) + B1 (Profile hint with a link, always shown).
- B for the error: re-check the number on every key press after the first Send.
- No extra review file; this file starts with "How to review".

**Open questions:** none.

**Follow-ups found (not changed in this branch)**
- Profile, Khmer, 390 px: the account tab row is cut at the right edge ("អាសយដ្ឋា…"). It scrolls sideways, and it
  existed before this change.

**Review** (2026-10-31, project review; verdict: **pass, OK to merge**)
- Checklist: branch name and both commit messages are plain, no tool names, no Co-authored-by lines. Only the 7
  files the task needs changed (5 source/i18n, 2 tests) + `docs/WORKLOG.md`. No backend, no migrations, no secrets.
- Re-ran on the pushed branch (`d78dc9d`, clean install): `npm run lint` 0 errors, `npm run typecheck` clean,
  `npm test` 440 passed / 3 skipped, `npm run build` ok. Matches the report above.
- Code: reuses `TextField`'s `hint` prop, so the hint is the field's accessible description and the error replaces it
  like every other field. Re-check after the first Send is small and correct (`phoneChecked` + `checkPhone`); the test
  proves no request is sent while typing. Profile link copies the email hint's pattern. Comment updated.
- Nit (no change needed now): the new Khmer login hint writes "លេខទូរស័ព្ទ" while the field label above it writes
  "លេខទូរសព្ទ". The app already mixes both spellings; leave it for the native-speaker review (owner step) and fix all
  of them together then.
- Nit: the work log went into a second commit; fine, no need to squash.
- Not verifiable here: the live check (owner step, section 0) and a real bot code.


**Where I stopped / next step**
- Task F is finished and pushed. Next: the owner merges `fix/phone-code-hint`, does the live check in `OWNER_TODO.md`
  section 0, then confirms the phone in Sign-in & security (section 4c). Next task prompt: `docs/prompts/02-you-may-also-like.md`.
