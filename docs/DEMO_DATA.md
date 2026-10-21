# Demo data

Fake accounts and records for local and staging only. The seeder refuses to run in production or any other environment.

```
php artisan db:seed --class=DemoSeeder
```

For a clean local database use `php artisan migrate:fresh --seed --seeder=DemoSeeder`. On staging the plain `db:seed` command above is the safe one, because it never wipes.

**Totals:** 12 contributors, 4 organizations with 7 client users, 6 briefs, 8 projects, 4 open roles with 4 applications, 5 reviews and 3 proof-of-work items. Spec 19 lists these figures.

**Agreement wording (SCR-036).** The Member Agreement, Client MSA and SOW template are seeded as placeholders, and a placeholder counts as not published. So the seeder publishes a version of each titled "(demo wording)" that says it is not legal text, only while the current version is still a placeholder. Real wording is never replaced. Terms and Privacy stay placeholders, so `/terms` and `/privacy` answer 404.

It adds data and never wipes. Running it again changes nothing: every record is matched on its email, slug or title and left alone if it exists. It sends no email or notifications. The Roles, Taxonomy, Feature Flags and Agreement Templates seeders run first, because the demo data needs them.

## Logins

All demo accounts use the password `DemoPass#2026`. Emails end in `@demo.groibe.test`, a reserved domain that cannot receive mail. Your real admin login (from `.env`) is untouched.

### Contributors (sign in at `/login`, lands on `/app/contributor`)

| Name | Email | State |
|---|---|---|
| Aditi Sharma | aditi.sharma@demo.groibe.test | Active, public |
| Rohan Mehta | rohan.mehta@demo.groibe.test | Active, public |
| Sneha Iyer | sneha.iyer@demo.groibe.test | Active, public |
| Vikram Singh | vikram.singh@demo.groibe.test | Active, public |
| Priya Nair | priya.nair@demo.groibe.test | Active, private (invited to a project) |
| Arjun Das | arjun.das@demo.groibe.test | Active, private (on the active project) |
| Meera Kulkarni | meera.kulkarni@demo.groibe.test | Active, private |
| Kabir Joshi | kabir.joshi@demo.groibe.test | Pending review |
| Ananya Bose | ananya.bose@demo.groibe.test | Pending review |
| Dev Malhotra | dev.malhotra@demo.groibe.test | Pending review |
| Ishita Rao | ishita.rao@demo.groibe.test | Suspended |
| Nikhil Verma | nikhil.verma@demo.groibe.test | Suspended |

### Clients (land on `/app/client`)

| Organization | Name | Email | Org role |
|---|---|---|---|
| Kestrel Foods (Demo), verified, public | Rhea Kapoor | rhea.kapoor@demo.groibe.test | Owner |
| | Sameer Ghosh | sameer.ghosh@demo.groibe.test | Manager |
| Bluepeak Logistics (Demo), verified | Tanvi Desai | tanvi.desai@demo.groibe.test | Owner |
| | Harsh Vora | harsh.vora@demo.groibe.test | Manager |
| | Leela Menon | leela.menon@demo.groibe.test | Viewer |
| Northwind Health (Demo), verified | Dr Farhan Qureshi | dr.farhan.qureshi@demo.groibe.test | Owner |
| Lumen Retail (Demo), pending verification, Client MSA not accepted | Nisha Arora | nisha.arora@demo.groibe.test | Owner |

## What else is in it

- Briefs: draft, submitted, needs info, scoped and two converted.
- Projects: one active (accepted SOW, assignment for Arjun Das, two milestones, three tasks), one with the SOW sent, one in staffing (Priya Nair invited), one draft.
- Showcase projects (Pass 6): three accepted projects, one per showcase consent value, and one closed project. All have an accepted SOW, a finished milestone and a completed seat per contributor.

| Project | Client | Status | Showcase consent | Contributors |
|---|---|---|---|---|
| Warehouse KPI dashboard | Bluepeak Logistics (Demo) | Accepted | none | Rohan Mehta |
| Patient survey report | Northwind Health (Demo) | Accepted | anonymous | Aditi Sharma |
| Pricing page redesign | Kestrel Foods (Demo) | Accepted | named | Sneha Iyer |
| Distributor pricing model | Kestrel Foods (Demo) | Closed | named | Vikram Singh, Aditi Sharma, Sneha Iyer |

- Proof of work (all on the closed project, because drafts are created at close): Vikram Singh published and public, Aditi Sharma published but members-only, Sneha Iyer draft. Only Vikram's item is visible to the public, at `/work/demo-distributor-pricing-model-vikram`, and it names Kestrel Foods because the consent is `named`. To see the anonymous label, set the project's consent to `anonymous` from the client project page.
- Reviews: four published (Rhea Kapoor on Vikram Singh and Aditi Sharma, Dr Farhan Qureshi on Aditi Sharma, and Vikram Singh on Kestrel Foods, which stays admin-only) and one waiting in moderation (Rhea Kapoor on Sneha Iyer). Reputation figures are computed for Vikram, Aditi, Sneha and Rohan, and Kestrel Foods has its organization stats.
- No new logins. Every person in this data was already in the lists above, so use the same password.
- Open roles: four. Survey analyst (public, featured, two positions), Dashboard support analyst (members only, linked to the active project), Brand refresh designer (invite only, Sneha Iyer is on the list) and a draft Content editor. Applications: Aditi Sharma (submitted) and Rohan Mehta (shortlisted) for the survey role, Meera Kulkarni (offer waiting) for the dashboard role, Sneha Iyer (submitted) for the brand role.
- Homepage and website (Pass 8): the eleven homepage sections, 22 FAQs (10 general, 6 contributor, 6 client) and an About page come from the production-safe `WebsiteContentSeeder`. Every FAQ and the About page are hidden drafts marked "needs review", so none of that wording shows on the site until an admin approves it in Admin under Website. The demo adds seven featured picks (the Survey analyst role, Kestrel Foods, Vikram Singh's proof of work and four public contributors) and two clearly fake testimonials ("Demo Client One" and "Demo Client Two") so the homepage has something to show. No testimonials are seeded outside the demo.
- Admin: use your own login. No demo admin accounts are created.
