# Prompt 1 (F): phone-code sign-in hint (frontend only, small)

```text
TASK F: Help customers who ask for a phone sign-in code but never get one.

Background (found on the live site on 2026-10-31): the backend only sends a code to an account whose phone number
is VERIFIED and linked to the Bookly Telegram bot (bookly_backend_v2 PhoneCodeController::reachable:
phone_e164 + phone_verified_at + telegram_chat_id). A number typed in Account > Profile is only a courier contact
number and is NOT verified. The "Telegram order updates" card does not verify it either. The only ways to verify:
"Continue with Telegram" sign-in, or Account > Sign-in & security > Phone row > Confirm (PhoneInTelegram in
src/features/account/SignInMethods.tsx). The owner got stuck exactly here.

Where: bookly_frontend/src/features/auth/PhoneCodeSignIn.tsx (text in src/i18n/en.ts phoneSignInLead and km.ts),
tests in src/pages/auth/phone-code-sign-in.test.tsx. Optional second place: the Profile page phone field hint
(src/pages/account/ProfilePage.tsx).

Branch: fix/phone-code-hint (frontend, from origin/main). No backend change.

Steps (stop after each for "confirm"):
1. Read the files above and show me the current texts. Propose 2 or 3 wordings (English + Khmer) for:
   (a) a short hint under the phone field / after "Send code", e.g. "No code? Your number must be confirmed first:
   Account > Sign-in & security > Phone > Confirm. Or use Continue with Telegram below.";
   (b) optionally a one-line hint on the Profile phone field: "This is for couriers. To sign in with it, confirm it
   in Sign-in & security." Recommend one. Wait for my choice.
2. Implement with the existing design system (design-system/bookly/MASTER.md), reuse existing components, keep it
   accessible (text, not colour only). Add/adjust tests.
3. Run lint, typecheck, test, build. Then a browser check at :5173: login page > Sign in with phone number? >
   see the hint (desktop and 390 px, English and Khmer). Report what you saw.
4. Commit ("Explain how to get a phone sign-in code"), push, add WORKLOG entry, then update HANDOFF.md and
   OWNER_TODO.md (merge link) on docs/handoff-notes. Tell me the PR link.
```
