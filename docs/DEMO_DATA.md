# Demo data

Fake accounts and records for local and staging only. The seeder refuses to run in production or any other environment.

```
php artisan db:seed --class=DemoSeeder
```

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
- Admin: use your own login. No demo admin accounts are created.
