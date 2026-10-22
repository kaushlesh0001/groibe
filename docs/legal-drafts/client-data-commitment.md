# Client Data Commitment

**DRAFT FOR LAWYER REVIEW, NOT LEGAL ADVICE**

Notes for the reviewer (delete before publishing):

- This is a plain-language commitment to clients. [LAWYER: decide whether it is a public page, an annex to the Client MSA, or both. If it is an annex, say how it ranks against the MSA.]
- It is written against `docs/PRIVACY_AUDIT.md`. Each promise below is tagged **[OK]** (the platform does it today; sections 2 and 5 are built and tested since 2026-10-22), **[CHANGED]** (worded to match what the platform does today, not the shorter promise first planned) or **[DO NOT PUBLISH UNTIL BUILT]**.
- Square brackets are placeholders or open questions. No fact is invented.
- The public copy rule in the project rules file bans em dashes and AI-related wording. The AI promise in section 7 conflicts with that rule: see the two options there. The founder decides.
- The Digital Personal Data Protection Act, 2023 and the Digital Personal Data Protection Rules, 2025 apply to personal data in what you share. Most duties start on 13 May 2027 under the phase-in. [LAWYER: confirm what applies today, and whether Groibe is a processor for the client's personal data inside files.]

Version: [VERSION NUMBER]. Effective from: [EFFECTIVE DATE].

[COMPANY LEGAL NAME] ("Groibe"), of [REGISTERED ADDRESS], makes these commitments to every client that uses the Groibe platform.

## 1. Your files stay private

**[CHANGED]** Files you attach to a brief or a project are stored in private storage. They can be opened only by:

- people in your organization who have an account on your organization, and
- Groibe staff whose role lets them see briefs and projects.

**[CHANGED]** Contributors on your project cannot open your brief files or project files. A contributor can open a file only if it was attached to a message in the project conversation they are part of.

**[DO NOT PUBLISH UNTIL BUILT OR ACCEPTED: "only the team assigned to your project".]** Today, every Groibe delivery manager, community manager and finance user can open every client's files, whether or not they are assigned to the project. Either the platform changes to limit staff to the projects they run, or this commitment says so. [FOUNDER DECISION.]

## 2. Who sees your name and what you pay

**[OK]** Contributors do not see what you pay Groibe, your contract value, or your budget. They see their own agreed amount and payouts only.

**[OK, built 2026-10-22, SCR-044]** Contributors do not see your organization's name or the name of any person in your organization. On a project they see the project title, the project code and their own role. In project conversations your people appear to them only as "Client". The same holds on task pages, in notifications and in emails sent to contributors. Contributor identity is not shown to you before you accept the statement of work, and you see contributors by first name, role and headline, unless the project hides its team.

Groibe cannot filter what people type. Please do not put your company's name or secrets in a project title, scope, task text, message or file name if you want them kept from contributors, and Groibe staff follow the same rule when they write those. [LAWYER: wording.]

## 3. Files are opened only through short links

**[OK]** Every download uses a private link that lasts 10 minutes. The link works only for a person who is signed in and who has permission to open that file. Files are not on any public web address.

## 4. Access ends when work ends

**[CHANGED]** When Groibe removes a contributor from your project, they lose the project conversation and their open tasks at once. [CONFIRM before publishing: a removed contributor may still be able to open tasks they had already finished, and to download their own submitted files. See `docs/PRIVACY_AUDIT.md`, row 4. Either fix the platform or add this to the commitment.]

When a project is completed, contributors keep read-only access to their own work on it. [LAWYER: say how long, or whether access should end.]

Contributors who leave a project must delete your files from their own devices. This is in the Contributor Code of Ethical Remote Work and in the Member Agreement. Groibe cannot check what is on a contributor's device. [LAWYER: wording.]

## 5. A record of who opened your files

**[OK, built 2026-10-22, SCR-045]** We keep a record of every download of your files: who opened which file and when. The record never holds the contents of the file. It covers brief files, project files, submitted work and message attachments, and the statement of work as a PDF or print page. A request that is refused leaves no entry, because nothing was opened. The record is kept for [PERIOD SET BY LAWYER; the platform default is 365 days, then it is deleted with the rest of the admin log]. [FOUNDER DECISION: whether clients may ask to see the record for their own files, and how.] The record cannot show what a person did with a file after they downloaded it.

## 6. Your data, your requests

**[OK]** You can ask for a copy of the personal data we hold about you, and ask us to erase it, from Settings, then Privacy. We reply within [RESPONSE TIME SET BY LAWYER]. For data about your organization as a business, write to contact@groibe.com. [LAWYER: no screen exists for company data requests.]

Some records are kept after erasure, for example payment records, the log of admin actions, project messages and agreements you accepted. The Privacy Policy lists them and says why.

## 7. No use of your data to build automated systems

**[OK today, cannot be proved by software alone.]** The platform has no automated or machine-learning features and does not send your files or data to any outside service to learn from. Groibe will not use your files or data to train, tune or improve any automated model, and will not let anyone else do so.

- **Option A (clear, breaks the public copy rule in the project rules file):** "We do not use your data to train AI."
- **Option B (follows the rule, less direct):** the sentence above, without the word "AI". [FOUNDER DECISION.]

Contributors and staff are bound by the same rule in the Member Agreement and the Contributor Code. [CONFIRM that both documents contain it. The Member Agreement text is not in this repo.]

## 8. How long we keep your files

**[DO NOT PUBLISH UNTIL SET.]** Briefs, project files, submitted work and messages: kept for [PERIOD SET BY LAWYER] after the project closes, then [DELETED / ARCHIVED]. Today no automatic deletion exists for these. Backup copies keep older data until they age out: weekly copies (newest 4), copies taken before updates (newest 5), the host's daily backups, and encrypted off-site copies (last 8 weekly and 3 monthly). [LAWYER: confirm and say how to describe this.]

## 9. Companies that handle data for us

| Company | Why | Where [CONFIRM] |
|---|---|---|
| MilesWeb | Hosting, database, files, backups | [COUNTRY] |
| Cloudflare | Web traffic and DNS | [LOCATION] |
| [EMAIL PROVIDER] | Sending platform emails | [LOCATION] |
| Zoho Books | Our staff make invoices there. Names and tax numbers on an invoice are held by Zoho. | [LOCATION] |
| [OTHERS: code hosting, backup storage, uptime monitor, staff mailboxes] | [PURPOSE] | [LOCATION] |

[LAWYER: confirm written agreements and any transfer rules. Say whether the client may object to a new one and how we tell them.]

## 10. If something goes wrong

If your data is lost, shown to the wrong person or taken, we will tell you [WITHIN PERIOD SET BY LAWYER] and tell you what we know and what we are doing. [LAWYER: link to the duties in the Act and the Rules.]

## 11. Questions and complaints

Kaushlesh Kumar [CONFIRM WITH LAWYER], contact@groibe.com, [REGISTERED ADDRESS]. Governing law: [GOVERNING LAW]. [LAWYER: courts or arbitration.]
