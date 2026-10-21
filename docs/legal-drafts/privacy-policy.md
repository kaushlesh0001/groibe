# Privacy Policy

**DRAFT FOR LAWYER REVIEW, NOT LEGAL ADVICE**

Notes for the reviewer (delete before publishing):

- Paste the final text into Admin, System, Agreement Templates, type "privacy". The text must never be put in code (CLAUDE.md).
- Every item in square brackets is a placeholder or an open question. No fact in this draft is invented: each statement describes what the platform does today (see `docs/PRIVACY_AUDIT.md`), and anything that does not match is marked **[CONFIRM]**.
- The Digital Personal Data Protection Act, 2023 and the Digital Personal Data Protection Rules, 2025 are named below. The founder's note is that most duties start on 13 May 2027 under the phase-in. The lawyer must confirm which duties apply today and which apply from that date.
- The public copy rule in `CLAUDE.md` bans em dashes and AI-related wording. This draft follows it.

Version: [VERSION NUMBER]. Effective from: [EFFECTIVE DATE].

## 1. Who we are

This policy is from [COMPANY LEGAL NAME] ("Groibe", "we", "us"), of [REGISTERED ADDRESS]. Groibe runs a platform where skilled professionals ("contributors") deliver work for businesses ("clients"), managed by Groibe.

Under the Digital Personal Data Protection Act, 2023 ("the Act") and the Digital Personal Data Protection Rules, 2025 ("the Rules"), we decide why and how your personal data is used. [LAWYER: confirm that Groibe is the "Data Fiduciary" for every kind of data below, and whether it is a "Significant Data Fiduciary".]

## 2. When this policy applies

This policy covers the Groibe website, the contributor area, the client area and the support and contact routes. Parts of the Act and the Rules take effect in stages, with most duties starting on 13 May 2027. [LAWYER: say in plain words which promises below apply now and which apply from that date, or state that we follow them all now.]

## 3. What we collect

We collect only what the platform needs. We do not ask for government ID numbers, bank account numbers or card numbers.

| Who | What | Why |
|---|---|---|
| Everyone who signs in | Name, email address, a password stored only as a hash, the time of last sign-in, time zone | To run your account and keep it safe |
| Contributors | Profile (headline, bio, phone, city, state, country, photo, links), skills and levels, availability, experience, languages, work preferences, answers on applications, proof-of-work text, reviews, payout records (project, amount, status, a transaction reference and tax deducted) | To assess you, match you to work, run projects, show your work (with your choice and the client's consent) and pay you |
| Clients | Your name, work email, designation, organization details (name, GST number if given, website, size, address), briefs, files you upload, messages, reviews, agreements you accept | To scope and deliver projects and to invoice |
| Visitors who write to us | Name, email, subject, message, IP address and browser details | To answer you and to limit spam |
| Everyone | Records of agreements accepted (who, which version, when, IP address and browser details), a log of emails sent (address, kind of email, whether it was sent, never the text), a log of actions in the admin area, and errors | To prove what was agreed, to run the service and to keep it secure |

We use a session cookie and a security cookie that the site needs to work. We do not use advertising or analytics cookies, and our pages load no scripts or fonts from other companies. [CONFIRM at publication.]

## 4. Why we use it, and on what basis

- To create and run your account, run projects and pay contributors: [LAWYER: consent, or the "legitimate uses" in section 7 of the Act, for each purpose].
- To keep the platform safe, prevent misuse and meet legal duties: [LAWYER].
- To show proof of work and public profiles: only with your choice, and for client projects only with the client's consent (none, anonymous or named).
- To send you notices about your account and projects. Security notices cannot be switched off. You can switch other notices off in your settings.

We will not use your data for a new purpose without asking you again. [LAWYER: confirm.]

## 5. Who we share it with

We share personal data only with the people who need it and with the companies that help us run the platform.

**Inside a project.**

- Clients see the contributors on their project by first name, role and headline, once the statement of work is accepted, unless the project hides its team.
- Contributors see their own project work. In project conversations a contributor sees a client person's first name and organization name. **[CONFIRM: this does not match a promise that contributors never see client names. See `docs/PRIVACY_AUDIT.md`, row 2.]**
- Groibe staff see what their role needs.

**Companies that process data for us (our "processors").**

| Processor | What it does | Where [CONFIRM] |
|---|---|---|
| MilesWeb | Hosts the platform, the database, the files and the host's own backups | [COUNTRY OF SERVERS] |
| Cloudflare | Handles web traffic and DNS in front of the platform, so it sees visitor IP addresses | [LOCATION] |
| [EMAIL PROVIDER] | Sends the platform's emails | [LOCATION] |
| Zoho Books | Used by our staff to make invoices. The platform stores only the invoice number, amount and status. | [LOCATION] |
| [OTHER PROCESSORS: code hosting, backup storage, uptime monitor, staff mailboxes] | [PURPOSE] | [LOCATION] |

[LAWYER: confirm each has a written agreement with Groibe, and whether any transfer outside India needs notice or limits.]

We do not sell your personal data. We may have to share it when the law requires.

## 6. How long we keep it

These are the periods the platform is set to today.

| Data | Kept for |
|---|---|
| Log of emails sent | 90 days if sent, 180 days if it failed |
| Log of actions in the admin area | 365 days |
| Notifications you have read | 180 days (unread ones are kept until read) |
| Imported files and their reports | 30 days |
| Error logs | 14 days |
| Failed background jobs | 30 days |
| Database backups | The newest 4 weekly copies, the newest 5 copies taken before an update, the host's daily copies, and encrypted off-server copies (the last 8 weekly and 3 monthly) |
| Accounts, profiles, briefs, files, messages, applications, reviews, contact messages | **[PERIOD TO BE SET BY LAWYER. No automatic deletion exists today. See `docs/PRIVACY_AUDIT.md`, row 7.]** |

## 7. Your rights

You can ask us to:

- give you a copy of your personal data;
- erase your personal data;
- [CORRECT OR UPDATE your data. LAWYER: today you can edit your profile yourself, and there is no separate request screen];
- [WITHDRAW YOUR CONSENT. LAWYER: no separate screen exists];
- [NOMINATE another person to act for you if you die or cannot act. LAWYER: not built];
- raise a grievance (section 9).

**How.** Signed in, open Settings, then Privacy, and make a request. We answer within [RESPONSE TIME SET BY LAWYER]. We reply to the email address on your account, and we may ask you to prove it is you. If you cannot sign in, write to [GRIEVANCE EMAIL].

**What erasure does.** We remove your name, email, password, sign-in data, profile and photo, skills, written application answers, proof-of-work text, support conversations and notifications, and the address on email log rows. Your account stays as an anonymous record. **We keep** payout records, the log of admin actions, project messages and comments, submitted work, published reviews, records of agreements you accepted and reputation figures, because [LEGAL REASON, LAWYER TO CONFIRM FOR EACH]. We cannot refuse a request because of work in progress without telling you why, and we cannot erase an account while work or payments are still open. [LAWYER: confirm.]

**Backups.** Erasure is not applied to backup copies. They are overwritten as they age out, as the table in section 6 shows. [LAWYER: confirm that this is acceptable and how to say it.]

## 8. How we protect it

- Private files are stored where the public web cannot reach them. Each download needs a link that lasts 10 minutes, a signed-in account and permission for that file.
- Admin sign-in needs a password and a 6-digit code sent to the admin's email.
- Passwords are stored as hashes. Traffic uses HTTPS.
- Access follows roles: a client's files are open to that client's organization and to Groibe staff who run projects.
- **[CONFIRM before publishing: "We keep a record of who downloads a file." This is not built today. See `docs/PRIVACY_AUDIT.md`, row 8.]**

No system is perfectly secure. If a breach affects your personal data we will tell you and the authority as the Act and the Rules require. [LAWYER: set the wording and the timing.]

## 9. Grievances and contact

Grievance officer: [GRIEVANCE OFFICER NAME], [DESIGNATION].
Email: [GRIEVANCE EMAIL]. Address: [REGISTERED ADDRESS]. We reply within [RESPONSE TIME].
If you are not satisfied you may complain to the Data Protection Board of India. [LAWYER: confirm the route and the wording.]

## 10. Children

[LAWYER: our platform is for working adults. State the age limit and what we do if we learn that a person under 18 has registered.]

## 11. Changes

We will post changes here with a new version number and date. If a change matters to you, we will tell you before it starts. [LAWYER: confirm.]

## 12. Law

This policy is governed by [GOVERNING LAW]. Courts at [PLACE] have jurisdiction, subject to your rights under the Act. [LAWYER: confirm.]
