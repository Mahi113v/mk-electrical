# Multi-company guide

## What changed
* **company_id on every business table**: products, customers, orders, order_items, purchases, purchase_items, sales, sale_items,
  opening_stocks, stock_adjustments, stock_adjustment_items, work_status, work_list. Every query is limited to the current company automatically (`BelongsToCompany`).
* Products and customers belong to ONE company. Product code is unique inside the company. The same phone number in two companies = two separate customers.
* Public order page: the customer types the company code, sees only that company's products, customers and prices.
* Company: logo, image, UPI id, bank details. They show on the menu, dashboard banner, login company list, public order page (+ UPI QR), invoice print.
  No logo -> the default MK logo.
* Opening the site (`/`) always goes to the USER login. The admin login is only at `/admin`.

## Own database per company (product admin only)
Admin -> Companies -> New Company -> Database tab: tick "Create a separate database", give a name, choose the tables.
* All tables of the main database are listed. Shared tables (users, roles, companies ...) always stay in the main database.
* Choosing a table also chooses what it cannot work without (orders -> customers, order_items, products ...).
* A module whose tables are not chosen disappears from that company's menu and is refused by the server.
* Edit company -> Database: give an existing company its own database (its current rows are copied), add more tables later, make an extra table of your own.
* "Download database" (top of the Companies page): choose company + database -> .sql file.
* MySQL / MariaDB only. The DB user in `.env` needs CREATE / DROP privilege.
* A company with data cannot be deleted. An empty one is deleted together with its own database.

## Upgrade
```
php artisan migrate        # adds company_id (old shared products/customers are copied per company), branding, payment and database columns
php artisan storage:link   # for logo / image files
npm install && npm run build
```
Tests: `php artisan test` (sqlite). Own-database tests need MySQL:
`DB_CONNECTION=mysql DB_DATABASE=mk_test php artisan test`
