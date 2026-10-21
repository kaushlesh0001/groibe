# Operations runbook

Status: a procedure, not policy. It says how to run Groibe day to day. Setup and deploys are in `docs/DEPLOY.md`. The rules behind these steps are in the master spec (17.2, 17.4) and in SCR-028, SCR-029, SCR-032 and SCR-034.

**Contents**

1. [Who does what](#1-who-does-what)
2. [Data requests (export and erasure)](#2-data-requests-export-and-erasure)
3. [Reset two-factor authentication for a locked-out admin](#3-reset-two-factor-authentication-for-a-locked-out-admin)
4. [Routine checks](#4-routine-checks)

---

## 1. Who does what

| Task | Owner |
|---|---|
| Data requests | The founder, as super admin. Only a super admin can process them. |
| Replies to people about data requests | Sent from `contact@groibe.com` |
| 2FA resets | The founder (needs SSH access to the host) |
| Weekly off-server backup copy | The founder (DEPLOY section 19) |

If the founder is away for more than a few days, name a second super admin in writing and keep it with this file. Do this before launch.

## 2. Data requests (export and erasure)

A contributor or client can ask, at `/settings/privacy`, for a copy of their data or for their data to be erased. Every super admin gets a notice in the bell and by email. Only a super admin sees the list: **Admin, System, Data requests**.

**Legal deadline for answering: to be confirmed by the lawyer before launch.** Do not quote a number to anyone until then. Until it is confirmed, answer as fast as you can.

**Reminder for requests older than 7 days.** Every day at 08:45 (Indian time) the app sends every super admin one bell notice and one email while any request that is still `open` or `in_progress` was made more than 7 days ago (SCR-039). It says how many are waiting and how long the oldest has waited. It carries no name. Open **System, Data requests** and deal with them. The System status panel on the dashboard also shows how many have waited more than 7 days. The weekly check (section 4) is the backup if the notice is missed.

### Steps for every request

1. Open **System, Data requests**. The list shows when it was requested, the person, the kind, the status and who handled it. Read the request.
2. Check that the request really comes from the account holder. It was made while signed in, so the account email is the proof. Always reply to the **email address on the account**, never to another address someone gives you.
3. Press **Take it** (status `in_progress`). This stops another admin working on it. The next buttons only appear after this.
4. Follow the steps for the kind of request below.
5. Write the note for the person in plain words. The note is not written to the audit log, so put no secret in it.

### Export (a copy of their data)

1. Press **Download the data**. A JSON file is made on the spot and is never stored. Each download is logged (who and which request, never what the file held).
2. Check the file opens. It holds the account, profile, skills, agreements accepted, briefs, applications, project seats, tasks, submissions, their own messages, proof of work, reviews, payouts from approval on, notification choices and the kinds of email sent. It holds nothing staff-only, nothing about other people, and no secrets.
3. Put the file in a **password-protected zip**. Send the zip from `contact@groibe.com` to the account email. Send the password by a **different route**, for example a phone call or SMS to the number on the profile (SCR-039). Never put the password in the same email. Delete the file and the zip from your computer afterwards.
4. Press **Mark as sent**. A box asks "How the copy was delivered (the person can read this)". Say how you sent it, for example "Sent as a zip to your account email. The password was sent to you by SMS." Do not write the password. The person is told on both channels and can read the note.

### Erasure (delete their data)

1. Press **Erase their data**. A box asks "Erase this person's data?". If work or money is still open, the box says "This cannot be done yet" and names what to settle first:
   - a contributor with an invited or active project seat, or a payout that is planned or approved;
   - a client who is the only owner of an organization with projects in progress.
   Close the box. Settle those (finish, remove the seat, pay or cancel the payout, or add another owner) and try again. Admin accounts are never erased here.
2. Erasure is one step and cannot be undone. The account is kept but anonymised and soft-deleted (shown as "Deleted user"). It removes the name, email, password, sign-in data, profile, photo, skills, written application answers, proof-of-work text, support conversations, notifications and the address on email log rows. It keeps payouts, the audit log, project messages and comments, submissions, published reviews, agreement acceptances and reputation figures (SCR-028, SUGGESTIONS 272). If the lawyer says any of those must go too, log it as a change request first.
3. Nothing is sent when erasure completes, because the account no longer exists, and the person cannot read the note after the account closes. If you want the person told, email them from `contact@groibe.com` **before** you press the button, using the address on the account.
4. In the box, the note is optional. Confirm. The screen says "Personal data erased and account closed".

### Reject a request

Press **Reject** and write the reason in the box ("Reason (the person can read this)"). The person is told. Use it when the request is not from the account holder, or the lawyer advises refusing. Get legal advice if you are unsure.

## 3. Reset two-factor authentication for a locked-out admin

Use this when an admin lost their phone and their recovery codes. Two-factor sign-in is always required for super admin, and for other admins while the `two_factor_admin` flag is on (SCR-029).

1. **Prove who is asking.** Speak to the person on a call you start yourself, or in person. An email request alone is not enough.
2. Sign in to the host by SSH:

   ```bash
   cd ~/groibe
   /opt/cpanel/ea-php83/root/usr/bin/php artisan groibe:two-factor-reset person@example.com
   ```

   Replace the PHP path with the one in your `.env` notes (DEPLOY section 7), and the email with the admin's. It asks you to confirm. Add `--force` only in a script.
3. The command turns off the secret and recovery codes for that one person. It writes an entry to the audit log (event `two_factor_reset`, "by server command"). It does not tell the person.
4. Tell the person to sign in. The panel sends them to the set-up page. They scan the code with an authenticator app and **store the new recovery codes safely**.
5. If the person asked for it because a phone was lost or stolen, ask them to change their password too, and check the audit log for activity they did not do.

If the **only** super admin is locked out and nobody has SSH, the host's support can run the command only if they have access to the account. Keep a second super admin to avoid this.

## 4. Routine checks

**Every week (the founder, 15 minutes)**

- [ ] **System, Data requests:** any request older than 7 days? Deal with it (section 2). The daily 08:45 notice should already have told you.
- [ ] **Dashboard, System status:** no red alert line (stopped cron, test wording live). Cron says "Running", database size is below 80% of the limit, failed jobs are not piling up.
- [ ] **System, Email Log:** any `failed` rows in the last week? Check the address and the mail settings (DEPLOY section 10).
- [ ] **Backup copy:** the weekly dump is encrypted and copied off the server (DEPLOY section 19).
- [ ] The uptime monitor has sent no unanswered alert (DEPLOY section 20).

**Every month**

- [ ] Download `storage/app/private` (encrypted, DEPLOY section 19).
- [ ] Look at the host's disk and database size against the plan.

**Every quarter**

- [ ] Do the restore drill (DEPLOY section 14).
- [ ] Check that the retention rules in DEPLOY section 18 still match what the app does.
