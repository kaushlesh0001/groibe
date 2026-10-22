# Operations runbook

Status: a procedure, not policy. It says how to run Groibe day to day. Setup and deploys are in `docs/DEPLOY.md`. The rules behind these steps are in the master spec (17.2, 17.4) and in SCR-028, SCR-029, SCR-032 and SCR-034.

**Contents**

1. [Who does what](#1-who-does-what)
2. [Data requests (export and erasure)](#2-data-requests-export-and-erasure)
3. [Reset an admin's sign-in from the server](#3-reset-an-admins-sign-in-from-the-server)
4. [The sign-in code email does not arrive](#4-the-sign-in-code-email-does-not-arrive)
5. [Routine checks](#5-routine-checks)
6. [Change how long data is kept](#6-change-how-long-data-is-kept)

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

**Reminder for requests older than 7 days.** Every day at 08:45 (Indian time) the app sends every super admin one bell notice and one email while any request that is still `open` or `in_progress` was made more than 7 days ago (SCR-039). It says how many are waiting and how long the oldest has waited. It carries no name. Open **System, Data requests** and deal with them. The System status panel on the dashboard also shows how many have waited more than 7 days. The weekly check (section 5) is the backup if the notice is missed.

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

## 3. Reset an admin's sign-in from the server

Admin sign-in has a second step: a 6-digit code emailed to the admin (SCR-040). A super admin always has it. Every other admin has it while the `two_factor_admin` flag is on. The code is valid for 10 minutes, works once, and 5 wrong codes lock that person out for 15 minutes. An admin may also have an authenticator app and recovery codes from before.

Use this procedure when an admin lost their authenticator app and recovery codes, or when you need to clear a code lockout at once instead of waiting 15 minutes. It does **not** turn the emailed code off: that is decided by role and flag. It does not help if the admin cannot reach their mailbox (see section 4).

1. **Prove who is asking.** Speak to the person on a call you start yourself, or in person. An email request alone is not enough.
2. Sign in to the host by SSH:

   ```bash
   cd ~/groibe
   /opt/ecp-php83/bin/php artisan groibe:two-factor-reset person@example.com
   ```

   Replace the PHP path with the one in your `.env` notes (DEPLOY section 7), and the email with the admin's. If the person has an authenticator app or recovery codes it asks you to confirm. Add `--force` only in a script.
3. The command removes that one person's authenticator secret and recovery codes, and clears their code lockout, wrong-code count and resend wait, so a fresh code can be sent at once. A reset that removed an app is written to the audit log (event `two_factor_reset`, "by server command"). It does not tell the person.
4. Tell the person to sign in. They get a new emailed code. Nothing needs to be set up. If they want the authenticator app again they can add it on their profile page, which makes new recovery codes: **store them safely**.
5. If the person asked for it because a phone was lost or stolen, ask them to change their password too, and check the audit log for activity they did not do.

If the **only** super admin is locked out and nobody has SSH, the host's support can run the command only if they have access to the account. Keep a second super admin to avoid this.

## 4. The sign-in code email does not arrive

The code is sent at once, not through the queue, so it does not depend on cron. It is sent to the email address on the admin's own account, once a minute at most (the one sent at sign-in counts). There is no way to skip the code, and nobody can read it from the server on production.

Go through these in order:

1. **Wait a minute, then use "Resend" on the sign-in page.** A second email within a minute is refused on purpose. After 5 wrong codes the page says "Too many wrong codes" and waits 15 minutes; no email is sent during that time.
2. **Check the address and the spam folder.** The email is from the Groibe sender address, subject "Your Groibe admin sign-in code". The address is the one on the admin's account (Admin, People, Admin Users). A typo there means the code goes to someone else: another super admin corrects it.
3. **Look at the email log.** Another super admin opens **System, Email Log** and finds a row with template `AdminSignInCode` for that address.
   - **No row:** the app did not send. The person is probably locked out, or asked less than a minute ago. Wait, or run the reset command (section 3) to clear the lockout.
   - **Row with status `sent`:** the mail server accepted it. The problem is delivery: spam, a mailbox rule, or the sender domain (SPF, DKIM and DMARC, DEPLOY section 10).
   - **Row with status `failed`:** the mail server refused it. Check the mail settings and the host's hourly sending limit (DEPLOY sections 10 and 17). The contact form is a quick test of the same settings.
4. **Another super admin can sign in?** If yes, they check the above and, if needed, correct the address. If **no admin** can receive the code, fix the mail settings on the server (`.env`, then rebuild the caches the way `scripts/deploy.sh` does) and try again.
5. **If the admin has lost access to their mailbox for good,** a super admin changes the email address on the account (Admin, People, Admin Users). With no other super admin, change it on the server with the app's own model so the change is recorded:

   ```bash
   cd ~/groibe
   /opt/ecp-php83/bin/php artisan tinker --execute="App\Models\User::where('email', 'old@example.com')->firstOrFail()->update(['email' => 'new@example.com']);"
   ```

   Do this only after proving who is asking (section 3, step 1). Do **not** switch production to the `log` mailer to read a code: that writes every code into a file.

On a local computer, `MAIL_MAILER=log` writes the email, code included, to the newest file in `storage/logs` (`laravel-DATE.log`, because the log channel is `daily`). That is the only local shortcut, and it is not a setting to turn the code off (README).

## 5. Routine checks

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

## 6. Change how long data is kept

The days kept for four kinds of data are settings (SCR-039). The defaults need no action. Change one only on advice (for example from the lawyer for the audit trail). The table of what is kept and for how long is in `docs/DEPLOY.md`, section 18.

| Setting key | Default | Lowest allowed |
|---|---|---|
| `retention.failed_jobs_days` | 30 | 7 |
| `retention.job_batches_days` | 30 | 7 |
| `retention.notifications_read_days` | 180 | 30 |
| `retention.activity_log_days` (the audit trail) | 365 | 90 |

1. Open **System, Settings**. Look for the key in the list. If it is there, open it with its edit button. If it is not (the default is in use), press **New setting**.
2. Fill the form: **Key** is the setting key from the table, exactly. **Value** is a whole number of days, for example `400`. **Type** is **Integer**. Save.
3. The change applies at the next nightly run (03:40 for failed jobs and job batches, 03:45 for read notifications, 03:50 for the audit trail). The settings are cached for up to 15 minutes.
4. **A value below the lowest allowed is ignored, with no error, and the default is used.** So `3` for the audit trail does not delete anything older than 3 days: the 365-day default stays. The text under **Value** says the same. A value that is not a whole number (for example `45 days`) is ignored too. After saving, check the next run's audit log entry (event `retention`): it records the days actually used.
5. To go back to the default, delete the setting.

Data is deleted for good by these jobs and cannot be brought back, except from a dump (DEPLOY section 14). Raising a number never restores what an earlier run already removed.

## Commit messages: no assistant or vendor names

Commit messages and pull request text in this repository do not carry the coding assistant's name, its vendor's name, a co-author line for it, or a session line.

1. `.claude/settings.json` sets the assistant's commit and pull request attribution to empty.
2. A local `commit-msg` hook deletes any line that holds "anthropic", starts with "Co-Authored-By: Claude", "Claude-Session:" or "Generated with". The tracked copy is `scripts/git-hooks/commit-msg`. Git does not share hooks, so on a new clone install it once: `cp scripts/git-hooks/commit-msg .git/hooks/commit-msg`.
3. The hook only changes the message being written. It never rewrites commits that already exist, and history is never rewritten to remove old trailers.
4. Check a clone has it: `ls -l .git/hooks/commit-msg`.
