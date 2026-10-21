# MK Electrical – multi company business suite

Laravel 10 API (`/api`) + Vue 3 frontend.

## What is inside

| Area | Who | What |
|---|---|---|
| **Admin console** `/admin` | product admin, company admin | Companies, Users (company + role + account years), Roles & access (permission matrix), Account years (create next year, lock a year per company) - see "Two separate logins" |
| **Company app** | users, by role | Dashboard, Orders, Sales, Purchase, Opening Stock, Stock Adjustment, Stock Summary, Customers, Products, Work Status |
| **Public order form** | customers | `/order-data?company=MK` (no login) |

### How data is kept
* **Company**: every transaction (orders, purchase, sales, adjustments, opening stock, work status) has `company_id`.
  Products and customers are shared masters. A user sees only the companies the admin gave to them.
* **Account year** (1 April – 31 March): every transaction also has `account_year_id`. The year is chosen on the login page and can be switched in the top bar.
  A year can be **locked** per company (view only).
* The browser sends `X-Company-Id` and `X-Year-Id`; the server checks them against the user's access **on every request**.
* **Stock is never stored**, it is calculated: `opening + purchases − sales − orders ± adjustments` (per company + year).
  Selling / ordering more than the stock is refused unless the company has "allow negative stock" switched on.

### The four stock pages
1. **Opening Stock** – button *Fetch / Refresh stock* asks the server to add up last year's purchase, sales, orders and adjustments and saves the closing as this year's opening. Numbers typed by hand are kept unless "Overwrite manual" is ticked.
2. **Sales** – direct sale, no order needed, with a **Reference** (who it was given to).
3. **Stock Adjustment** – manual corrections (damage, lost, found …). **Cancelling an order creates an automatic adjustment** that returns its stock; re-opening the order removes it again.
4. **Purchase** – *Add products*: a product that already exists (same name) gets its stock increased, a new name creates the product with an automatic unique code `PRD-00001…`.

### Registers, date range and printing
Every list that is a register has a **From / To** date range. The default is **account year start date → today** (never past the end of the year); *Reset* brings it back.
Registers with a date range and **Print register**: Orders, Sales, Purchase, Stock Adjustment, Stock (opening at the From date, closing at the To date), Work Status.
Bills / vouchers with a print button per row: Order invoice, Sales bill, Purchase voucher, Stock adjustment voucher.
Plain lists with **Print**: Opening Stock, Customers, Products.
Printing opens the browser print dialog (choose *Save as PDF* for a file). API: `GET /api/<orders|sales|purchases|stock-adjustments|works|stock>?from=YYYY-MM-DD&to=YYYY-MM-DD`.
Time zone is `Asia/Kolkata` (`APP_TIMEZONE`), so a document made after midnight gets today's date.

### Two separate logins
| Login | Address | Who can sign in | What they get |
|---|---|---|---|
| **User login** | `/login` | company admin, manager, stock keeper, sales executive, staff, viewer, any custom role | the company app (pages allowed by their role). Needs the account year chosen on the page. **The product admin cannot sign in here.** |
| **Admin login** | `/admin` | **product admin** and **company admin** only | the admin console, the two logins, company admin scope, seeded sample data. Nothing on the normal pages links to it - you have to type `/admin`. Manager / staff / viewer ... cannot sign in here. |

* **Product admin** (`users.is_admin`): sees the whole console - Companies, Users (any company, can make other product admins), Roles & Access (shared roles + any company's roles), Account Years (create next year, lock a year per company).
* **Company admin** (Company Admin role in a company): sees a smaller console for **his own company only** - Users of his company (role + account years; a user who also works for another company is "shared": only the role in this company can be changed), Roles (shared roles are read only, he makes his own roles for his company), Account Years (lock / open his company's years), Company (profile and the negative-stock setting). He cannot make companies, product admins or new years.
* A token from one login does not work on the other side (user token = company screens only, admin token = admin console only). Sign-in is limited to 8 tries a minute per account.
* Companies keep working as before: the browser sends `X-Company-Id` / `X-Year-Id` and the server checks access on every request.

### Roles
Shared roles: Company Admin (everything), Manager, Stock Keeper, Sales Executive, Staff, Viewer. A company admin can add roles for his own company (e.g. *Counter Billing*). A role is given to a user **per company**.

## Sample logins and sample data
`php artisan migrate:fresh --seed` (or `db:seed`) loads sample data for **every page**: 3 companies (MK, SAI, VA), 16 products (2 with automatic `PRD-` codes), 8 customers, and for MK last year + this year: purchase, sales (with Reference), orders in every status (one cancelled with its automatic stock adjustment, one web order), manual adjustments, work status and last year's opening stock. This year's opening stock is left empty so you can press **Fetch / Refresh stock**.
Local / testing: on by default. Production (Railway): only when `SEED_SAMPLE_DATA=true`. Running it again adds nothing twice.

| Login at | Email | Password | Role |
|---|---|---|---|
| `/admin` | mahesh@mkelectrical-app.in, mathan@mkelectrical-app.in | `SEED_ADMIN_PASSWORD` | product admin |
| `/admin` and `/login` | mani@mkelectrical-app.in | `SEED_USER_PASSWORD` | company admin of MK (manager of SAI) |
| `/admin` and `/login` | sairaj@mkelectrical-app.in | `SEED_USER_PASSWORD` | company admin of SAI |
| `/admin` and `/login` | vagencies@mkelectrical-app.in | `SEED_USER_PASSWORD` | company admin of VA |
| `/login` | manager@ , stock@ , sales@ , viewer@ , counter@ , dhinesh@ `mkelectrical-app.in` | `SEED_USER_PASSWORD` | manager, stock keeper, sales executive, viewer, counter billing (company role), staff |

(Without `SEED_*_PASSWORD` in `.env` the password is `ChangeMe@123`. Change every password after the first login.)

## Run (Docker)
```
cp .env.example .env     # DB_HOST=db, DB_PASSWORD=root, set SEED_ADMIN_PASSWORD / SEED_USER_PASSWORD (quote them if they contain #)
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```
Open http://localhost:8000 – default logins are in `database/seeders/AdminUserSeeder.php` (change the passwords after the first login).

### Upgrading an existing database
**Important:** a product admin (`users.is_admin`) can no longer sign in at `/login`. If you used an admin account (Mahesh, Mathan) to work in the company app, sign in at `/admin` and make a company-admin user for your daily work (Users → New User → company + Company Admin).
`php artisan migrate` is safe: old orders and work status move into a default company **MK** and the right account year (by their date), every old user gets access to MK (old admins stay product admins), and the old single `products.stock` number becomes this year's opening stock.

## Frontend on Netlify, backend on Railway
* Netlify: build command `npm install && npm run build:spa`, publish `dist`, env `VITE_API_URL=https://<backend>.up.railway.app/api` (`netlify.toml` is included).
* Railway: set `APP_KEY`, `DB_*`, `CORS_ALLOWED_ORIGINS=https://<your-site>.netlify.app`, `SEED_*`, then run `php artisan migrate --force` (and `db:seed` once).
* Delete `public/hot` on the server (it only exists while `npm run dev` runs).

## Tests
`php artisan test` – 36 tests: company isolation, permissions, purchase / sales / order / cancel / adjustment stock maths, opening stock carry forward, date range registers, stock register period maths, locked years, public order form, admin console, the two logins, company admin scope, seeded sample data.
