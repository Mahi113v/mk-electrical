<?php

namespace Database\Seeders;

use App\Models\AccountYear;
use App\Models\Company;
use App\Models\Customer;
use App\Models\OpeningStock;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\WorkStatus;
use App\Services\DocNumber;
use App\Services\OrderService;
use App\Services\ProductCodeGenerator;
use App\Support\Bootstrapper;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Sample data for EVERY page, so the whole app can be tried straight away.
 *
 *  3 companies  MK Electrical, Sai Raj Kumar Industry, V Agencies
 *  logins       see README ("Sample logins"): product admin, company admins, manager, stock keeper, sales, staff, viewer
 *  masters      14 products (2 with automatic PRD- codes), 8 customers          (shared by every company)
 *  last year    opening stock typed by hand, purchase, sales, orders (one cancelled), adjustments, work status
 *  this year    purchase, sales (with Reference), orders in every status (+ one web order), adjustments, work status
 *               -> this year's opening stock is left EMPTY on purpose: press "Fetch / Refresh stock" on the Opening Stock page
 *
 * Safe to run again: a company + year that already has purchases is skipped.
 * Stock never goes negative in the sample data.
 */
class SampleDataSeeder extends Seeder
{
    private array $products = [];   // code => id
    private array $users = [];      // email => User
    private OrderService $orders;

    public function run(): void
    {
        $this->orders = app(OrderService::class);
        Bootstrapper::roles();
        Bootstrapper::years();
        $cur = Bootstrapper::year(Bootstrapper::fyStart());
        $prev = Bootstrapper::year(Bootstrapper::fyStart() - 1);

        DB::transaction(function () use ($cur, $prev) {
            [$mk, $sai, $va] = $this->companies();
            $this->users($mk, $sai, $va);
            // $this->masters();

            // $this->mkPreviousYear($mk, $prev);
            // $this->mkCurrentYear($mk, $cur);
            // $this->saiData($sai, $prev, $cur);
            // $this->vaData($va, $cur);
        });
    }

    // ------------------------------------------------------------------ companies, users, masters

    private function companies(): array
    {
        $mk = Company::updateOrCreate(['code' => 'MK'], [
            'name' => 'MK Electrical', 'gstin' => '33AAAPM1234C1Z5', 'phone' => '9585009824', 'email' => 'mk@mkelectrical-app.in',
            'address' => '12, Main Bazaar', 'city' => 'Sivakasi', 'state' => 'Tamil Nadu', 'pincode' => '626123', 'status' => 'active', 'allow_negative_stock' => false,
        ]);
        $sai = Company::updateOrCreate(['code' => 'SAI'], [
            'name' => 'Sai Raj Kumar Industry', 'phone' => '9944000111', 'email' => 'sairaj@mkelectrical-app.in',
            'address' => '45, Industrial Estate', 'city' => 'Madurai', 'state' => 'Tamil Nadu', 'pincode' => '625001', 'status' => 'active', 'allow_negative_stock' => false,
        ]);
        $va = Company::updateOrCreate(['code' => 'VA'], [
            'name' => 'V Agencies', 'gstin' => '33DCRPS0261P1ZY', 'phone' => '9944000222',
            'address' => '8, West Masi Street', 'city' => 'Madurai', 'state' => 'Tamil Nadu', 'pincode' => '625002', 'status' => 'active', 'allow_negative_stock' => true,
        ]);

        return [$mk, $sai, $va];
    }

    private function users(Company $mk, Company $sai, Company $va): void
    {
        $password = env('SEED_USER_PASSWORD') ?: 'ChangeMe@123';
        $role = fn (string $code) => Role::where('code', $code)->whereNull('company_id')->value('id');

        // a role made by the MK company admin for his own company
        $counter = Role::firstOrCreate(['code' => 'counter_billing_mk'], [
            'company_id' => $mk->id, 'name' => 'Counter Billing', 'description' => 'Billing counter: sales and customers only', 'is_system' => false,
            'permissions' => ['dashboard.view', 'products.view', 'customers.view', 'customers.manage', 'sales.view', 'sales.create', 'stock.view'],
        ]);

        // email => [name, phone, [company_id => role_id], default company]
        $people = [
            'mani@mkelectrical-app.in'      => ['Mani',            '7639610150', [$mk->id => $role('company_admin'), $sai->id => $role('manager')], $mk->id],
            'dinesh@mkelectrical-app.in'    => ['Dinesh',          '7010523982', [$mk->id => $role('staff')], $mk->id],
            'manager@mkelectrical-app.in'   => ['Mahesh Manager',  '9000000011', [$mk->id => $role('manager')], $mk->id],
            'stock@mkelectrical-app.in'     => ['Suresh Stock',    '9000000012', [$mk->id => $role('stock_keeper')], $mk->id],
            'sales@mkelectrical-app.in'     => ['Deepan Sales',    '9000000013', [$mk->id => $role('sales_executive')], $mk->id],
            'viewer@mkelectrical-app.in'    => ['Priya Viewer',    '9000000014', [$mk->id => $role('viewer')], $mk->id],
            'counter@mkelectrical-app.in'   => ['Monica Counter',  '9000000015', [$mk->id => $counter->id], $mk->id],
            'sairaj@mkelectrical-app.in'    => ['Sai Raj',         '9000000021', [$sai->id => $role('company_admin')], $sai->id],
            'vagencies@mkelectrical-app.in' => ['Vel',             '9000000031', [$va->id => $role('company_admin')], $va->id],
        ];

        foreach ($people as $email => [$name, $phone, $companies, $default]) {
            $user = User::firstOrNew(['email' => $email]);
            if (!$user->exists) {
                $user->fill(['name' => $name, 'phone' => $phone, 'is_user' => true, 'is_active' => true, 'password' => $password]);
                $user->email_verified_at = now();
                $user->save();
            }
            // add the companies the user does not have yet (an existing role is never changed)
            foreach ($companies as $cid => $rid) {
                if (!$user->companies()->where('companies.id', $cid)->exists()) {
                    $user->companies()->attach($cid, ['role_id' => $rid, 'is_default' => $cid === $default && !$user->companies()->wherePivot('is_default', true)->exists()]);
                }
            }
            $user->years()->syncWithoutDetaching(AccountYear::pluck('id'));
            $this->users[$email] = $user;
        }
    }

    // private function masters(): void
    // {
    //     $by = $this->users['mani@mkelectrical-app.in'];
    //     $products = [
    //         ['CAM-01', 'CCTV Dome Camera 2MP', 1850], ['CAM-02', 'CCTV Bullet Camera 2MP', 2100], ['DVR-04', 'DVR 4 Channel', 3200],
    //         ['DVR-08', 'DVR 8 Channel', 5200], ['HDD-1T', 'Hard Disk 1TB (CCTV)', 3400], ['WIR-90', 'Copper Wire 1.5 sq mm 90m', 2400],
    //         ['MCB-32', 'MCB 32A', 280], ['SW-6A', 'Modular Switch 6A', 45], ['LED-9W', 'LED Bulb 9W', 60], ['FAN-48', 'Ceiling Fan 48 inch', 1650],
    //         ['RO-MEM', 'RO Membrane 75 GPD', 950], ['RO-PMP', 'RO Booster Pump', 1750], ['SMPS-5A', 'SMPS 12V 5A', 520], ['CBL-RG6', 'RG6 Coaxial Cable 100m', 1450],
    //     ];
    //     foreach ($products as [$code, $name, $rate]) {
    //         Product::firstOrCreate(['product_code' => $code], ['product_name' => $name, 'rate' => $rate, 'user_id' => $by->id]);
    //     }

    //     // two products with an AUTOMATIC code (PRD-00001 ...), like the Purchase page makes them
    //     $generator = app(ProductCodeGenerator::class);
    //     foreach ([['LED Tube Light 20W', 190], ['Extension Board 6 Socket', 380]] as [$name, $rate]) {
    //         if (!Product::where('product_name', $name)->exists()) {
    //             $generator->create($name, $rate, $by->id);
    //         }
    //     }
    //     $this->products = Product::pluck('id', 'product_code')->all();
    //     foreach (Product::whereIn('product_name', ['LED Tube Light 20W', 'Extension Board 6 Socket'])->get() as $p) {
    //         $this->products[$p->product_name] = $p->id;
    //     }

    //     $customers = [
    //         ['Anand Stores', '9876500001', 'Main Bazaar', 'Sivakasi'], ['Priya Textiles', '9876500002', 'KK Nagar', 'Virudhunagar'],
    //         ['Mohan Hotel', '9876500003', 'Bus Stand Road', 'Sivakasi'], ['Kumar Textiles', '9876500004', 'Sattur Road', 'Sivakasi'],
    //         ['Selvi Fashions', '9876500005', 'Palace Road', 'Rajapalayam'], ['Raja Godown', '9876500006', 'Railway Feeder Road', 'Sattur'],
    //         ['Pandi Traders', '9876500007', 'West Masi Street', 'Madurai'], ['Walk-in Customer', '9876500008', null, 'Sivakasi'],
    //     ];
    //     foreach ($customers as [$name, $phone, $area, $city]) {
    //         Customer::firstOrCreate(['customer_phone' => $phone], [
    //             'customer_name' => $name, 'whatsapp' => $phone, 'area' => $area, 'city' => $city, 'address' => trim("$area, $city", ', '), 'user_id' => $by->id,
    //         ]);
    //     }
    // }

    // // --------------------- MK Electrical ---------------------
    // private function mkPreviousYear(Company $c, AccountYear $y): void
    // {
    //     if ($this->done($c, $y)) {
    //         return;
    //     }
    //     $mani = $this->users['mani@mkelectrical-app.in'];
    //     $stock = $this->users['stock@mkelectrical-app.in'];
    //     $sales = $this->users['sales@mkelectrical-app.in'];

    //     // opening stock of the FIRST year is typed by hand
    //     $this->opening($c, $y, ['CAM-01' => 10, 'CAM-02' => 6, 'DVR-04' => 6, 'DVR-08' => 4, 'HDD-1T' => 8, 'WIR-90' => 15, 'MCB-32' => 40, 'SW-6A' => 100,
    //         'LED-9W' => 60, 'FAN-48' => 8, 'RO-MEM' => 12, 'RO-PMP' => 5, 'SMPS-5A' => 15, 'CBL-RG6' => 10], $mani);

    //     $this->purchase($c, $y, 20, 'Sri Balaji Traders', 'INV-101', ['CAM-01' => [20, 1500], 'DVR-04' => [10, 2600], 'HDD-1T' => [10, 2800]], $stock);
    //     $this->purchase($c, $y, 75, 'Shiva Sakthi Electricals', 'INV-245', ['WIR-90' => [25, 1950], 'MCB-32' => [60, 200], 'SW-6A' => [200, 28], 'LED-9W' => [150, 38]], $stock);
    //     $this->purchase($c, $y, 140, 'Sri Balaji Traders', 'INV-310', ['CAM-02' => [15, 1750], 'DVR-08' => [8, 4300], 'SMPS-5A' => [20, 410], 'CBL-RG6' => [15, 1150]], $stock);
    //     $this->purchase($c, $y, 230, 'AquaTech Spares', 'INV-77', ['RO-MEM' => [20, 720], 'RO-PMP' => [10, 1350]], $stock);
    //     $this->purchase($c, $y, 300, 'Shiva Sakthi Electricals', 'INV-512', ['FAN-48' => [12, 1250], 'LED-9W' => [100, 36]], $stock);

    //     $this->sale($c, $y, 40, 'Anand Stores', 'Ravi - Rajapalayam shop', 'cash', ['CAM-01' => [6, 1850], 'SW-6A' => [40, 45]], $sales);
    //     $this->sale($c, $y, 95, null, 'Mani anna', 'upi', ['WIR-90' => [8, 2400], 'MCB-32' => [20, 280]], $sales);
    //     $this->sale($c, $y, 160, 'Priya Textiles', 'Selvam - Virudhunagar', 'credit', ['DVR-04' => [4, 3200], 'HDD-1T' => [4, 3400]], $sales);
    //     $this->sale($c, $y, 210, 'Anand Stores', 'Ravi - Rajapalayam shop', 'cash', ['LED-9W' => [80, 60], 'FAN-48' => [5, 1650]], $sales);
    //     $this->sale($c, $y, 280, null, 'Counter sale', 'cash', ['RO-MEM' => [10, 950], 'RO-PMP' => [4, 1750]], $sales);

    //     $this->order($c, $y, 50, 'completed', 'CCTV installation', 'Anand Stores', ['CAM-01' => 4, 'DVR-04' => 1], 1500, $mani);
    //     $this->order($c, $y, 120, 'completed', 'Home wiring', 'Priya Textiles', ['WIR-90' => 3, 'MCB-32' => 10, 'SW-6A' => 30], 3000, $sales);
    //     $this->order($c, $y, 190, 'cancelled', 'Shop CCTV (cancelled by customer)', 'Mohan Hotel', ['CAM-02' => 3], 800, $sales);
    //     $this->order($c, $y, 260, 'completed', 'RO service', 'Mohan Hotel', ['RO-MEM' => 2], 500, $mani);

    //     $this->adjustment($c, $y, 100, 'damage', 'Water damage in godown', ['LED-9W' => -5], $stock);
    //     $this->adjustment($c, $y, 250, 'lost', 'Lost while shifting shop', ['SW-6A' => -3], $stock);

    //     $dinesh = $this->users['dinesh@mkelectrical-app.in'];
    //     $this->work($c, $y, 60, [['Sivakasi - Anand Stores', 'Mani, Ranjith'], ['Virudhunagar - Priya Textiles', 'Mathan, Dinesh']], $dinesh);
    //     $this->work($c, $y, 130, [['Sattur - Raja Godown', 'Mani, Ranjith']], $dinesh);
    //     $this->work($c, $y, 270, [['Sivakasi - Mohan Hotel', 'Mathan, Dinesh'], ['Rajapalayam - Selvi Fashions', 'Mani, Ranjith']], $dinesh);
    // }

    // private function mkCurrentYear(Company $c, AccountYear $y): void
    // {
    //     if ($this->done($c, $y)) {
    //         return;
    //     }
    //     $mani = $this->users['mani@mkelectrical-app.in'];
    //     $stock = $this->users['stock@mkelectrical-app.in'];
    //     $sales = $this->users['sales@mkelectrical-app.in'];
    //     $counter = $this->users['counter@mkelectrical-app.in'];
    //     $dinesh = $this->users['dinesh@mkelectrical-app.in'];
    //     $tube = 'LED Tube Light 20W';
    //     $board = 'Extension Board 6 Socket';

    //     $this->purchase($c, $y, 10, 'Sri Balaji Traders', 'INV-401', ['CAM-01' => [15, 1520], 'CAM-02' => [10, 1760], 'DVR-04' => [8, 2650], 'SMPS-5A' => [15, 415]], $stock);
    //     $this->purchase($c, $y, 40, 'Shiva Sakthi Electricals', 'INV-588', ['WIR-90' => [20, 1980], 'MCB-32' => [50, 205], 'SW-6A' => [120, 29], 'LED-9W' => [100, 39]], $stock);
    //     $this->purchase($c, $y, 80, 'AquaTech Spares', 'INV-91', ['RO-MEM' => [15, 730], 'RO-PMP' => [8, 1360], 'CBL-RG6' => [10, 1160]], $stock);
    //     $this->purchase($c, $y, 120, 'Sri Balaji Traders', 'INV-433', ['HDD-1T' => [8, 2850], 'DVR-08' => [6, 4350], 'FAN-48' => [10, 1270]], $stock);
    //     $this->purchase($c, $y, 160, 'Shiva Sakthi Electricals', 'INV-640', ['LED-9W' => [80, 40], 'SW-6A' => [60, 30], $tube => [30, 120], $board => [25, 260]], $stock);

    //     $this->sale($c, $y, 15, 'Anand Stores', 'Ravi - Rajapalayam shop', 'cash', ['CAM-01' => [5, 1850], 'SMPS-5A' => [4, 520]], $sales);
    //     $this->sale($c, $y, 30, null, 'Mani anna', 'upi', ['DVR-04' => [2, 3200], 'CAM-02' => [3, 2100]], $sales);
    //     $this->sale($c, $y, 55, 'Priya Textiles', 'Selvam - Virudhunagar', 'credit', ['WIR-90' => [6, 2400], 'MCB-32' => [15, 280]], $sales);
    //     $this->sale($c, $y, 90, null, 'Counter sale', 'cash', ['LED-9W' => [30, 60], 'SW-6A' => [25, 45]], $counter);
    //     $this->sale($c, $y, 125, 'Anand Stores', 'Ravi - Rajapalayam shop', 'cash', ['RO-MEM' => [6, 950], 'RO-PMP' => [3, 1750]], $sales);
    //     $this->sale($c, $y, 150, 'Kumar Textiles', 'Kumar - Sivakasi', 'card', ['HDD-1T' => [3, 3400], 'FAN-48' => [4, 1650]], $sales);
    //     $this->sale($c, $y, 175, null, 'Counter sale', 'cash', [$tube => [6, 190], $board => [5, 380]], $counter);

    //     $this->order($c, $y, 20, 'completed', 'Warehouse CCTV', 'Anand Stores', ['CAM-01' => 4, 'DVR-04' => 1], 1500, $mani);
    //     $this->order($c, $y, 45, 'completed', 'Home wiring', 'Priya Textiles', ['WIR-90' => 3, 'MCB-32' => 10, 'SW-6A' => 30], 3000, $sales);
    //     $this->order($c, $y, 70, 'active', 'Shop RO installation', 'Mohan Hotel', ['RO-MEM' => 2, 'RO-PMP' => 1], 800, $mani);
    //     $this->order($c, $y, 100, 'pending', 'Office CCTV (4 cameras)', 'Kumar Textiles', ['CAM-02' => 4, 'DVR-04' => 1, 'SMPS-5A' => 2], 2500, $sales);
    //     $this->order($c, $y, 130, 'upcoming', 'Showroom lighting', 'Selvi Fashions', ['LED-9W' => 20, 'SW-6A' => 10, $tube => 8], 1200, $mani);
    //     $this->order($c, $y, 140, 'cancelled', 'Godown wiring (customer cancelled)', 'Raja Godown', ['WIR-90' => 5, 'MCB-32' => 8], 1000, $sales);
    //     $this->webOrder($c, $y, 'Walk-in Customer', ['LED-9W' => 5]);

    //     $this->adjustment($c, $y, 35, 'damage', 'Box dropped while unloading', ['SW-6A' => -2, 'LED-9W' => -3], $stock);
    //     $this->adjustment($c, $y, 85, 'found', 'Found extra pieces in the godown count', ['MCB-32' => 2], $stock);
    //     $this->adjustment($c, $y, 130, 'correction', 'Stock count correction', ['CAM-01' => -1], $mani);

    //     foreach ([
    //         [5, [['Sivakasi - Anand Stores', 'Mani, Ranjith'], ['Sattur - Raja Godown', 'Mathan, Dinesh']]],
    //         [22, [['Virudhunagar - Priya Textiles', 'Mani, Ranjith']]],
    //         [47, [['Sivakasi - Mohan Hotel', 'Mathan, Dinesh'], ['Sivakasi - Main Bazaar shop', 'Mani, Ranjith']]],
    //         [72, [['Rajapalayam - Selvi Fashions', 'Mathan, Mani, Dinesh']]],
    //         [101, [['Sivakasi - Kumar Textiles', 'Mathan, Dinesh'], ['Sivakasi - Anand Stores', 'Mani, Ranjith']]],
    //         [128, [['Madurai - Pandi Traders', 'Mathan, Mahesh, Mani, Dinesh, Ranjith']]],
    //         [141, [['Sattur - Raja Godown', 'Mathan, Dinesh']]],
    //         [165, [['Sivakasi - Mohan Hotel', 'Mani, Ranjith'], ['Virudhunagar - Priya Textiles', 'Mathan, Dinesh']]],
    //     ] as [$day, $items]) {
    //         $this->work($c, $y, $day, $items, $dinesh);
    //     }
    // }

    // // ------------------------------------------------------------------ Sai Raj Kumar Industry, V Agencies

    // private function saiData(Company $c, AccountYear $prev, AccountYear $cur): void
    // {
    //     $admin = $this->users['sairaj@mkelectrical-app.in'];
    //     $mani = $this->users['mani@mkelectrical-app.in'];

    //     if (!$this->done($c, $prev)) {
    //         $this->purchase($c, $prev, 30, 'Madurai Wholesale', 'INV-S1', ['CAM-01' => [8, 1520], 'SW-6A' => [50, 29]], $admin);
    //         $this->sale($c, $prev, 80, 'Pandi Traders', 'Pandi - Madurai', 'cash', ['CAM-01' => [3, 1850], 'SW-6A' => [20, 45]], $admin);
    //     }
    //     if (!$this->done($c, $cur)) {
    //         $this->purchase($c, $cur, 12, 'Madurai Wholesale', 'INV-S2', ['DVR-04' => [4, 2650], 'LED-9W' => [40, 39], 'MCB-32' => [30, 205]], $admin);
    //         $this->purchase($c, $cur, 60, 'Madurai Wholesale', 'INV-S3', ['WIR-90' => [10, 1990], 'CBL-RG6' => [6, 1160]], $admin);
    //         $this->sale($c, $cur, 20, 'Pandi Traders', 'Pandi - Madurai', 'cash', ['DVR-04' => [1, 3200], 'LED-9W' => [15, 60]], $admin);
    //         $this->sale($c, $cur, 70, null, 'Kannan', 'upi', ['WIR-90' => [3, 2400]], $mani);
    //         $this->order($c, $cur, 25, 'completed', 'Warehouse lighting', 'Pandi Traders', ['LED-9W' => 10, 'MCB-32' => 5], 600, $admin);
    //         $this->order($c, $cur, 90, 'pending', 'CCTV 4 camera', 'Pandi Traders', ['DVR-04' => 1], 900, $admin);
    //         $this->adjustment($c, $cur, 40, 'lost', 'Lost in transport', ['LED-9W' => -2], $admin);
    //         $this->work($c, $cur, 28, [['Madurai - Pandi Traders', 'Mathan, Mahesh, Mani, Dinesh, Ranjith']], $admin);
    //         $this->work($c, $cur, 95, [['Madurai - West Masi Street', 'Mathan, Mahesh, Mani, Dinesh, Ranjith'], ['Melur - site', 'Mathan, Mahesh, Mani, Dinesh, Ranjith']], $admin);
    //     }
    // }

    // private function vaData(Company $c, AccountYear $cur): void
    // {
    //     $admin = $this->users['vagencies@mkelectrical-app.in'];

    //     if (!$this->done($c, $cur)) {
    //         $this->opening($c, $cur, ['CAM-02' => 3], $admin);   // typed by hand (first year of this company)
    //         $this->purchase($c, $cur, 15, 'Sri Balaji Traders', 'INV-V1', ['CAM-02' => [5, 1760]], $admin);
    //         $this->sale($c, $cur, 30, 'Pandi Traders', 'Ravi', 'cash', ['CAM-02' => [2, 2100]], $admin);
    //         $this->work($c, $cur, 31, [['Madurai - Pandi Traders', 'Mathan, Mani, Mahesh, Dinesh, Ranjith']], $admin);
    //     }
    // }

    // // ------------------------------------------------------------------ helpers

    // /** a company + year that already has purchases was seeded before */
    // private function done(Company $c, AccountYear $y): bool
    // {
    //     return Purchase::where('company_id', $c->id)->where('account_year_id', $y->id)->exists();
    // }

    // /** start of the year + N days, but never later than today / the end of the year */
    // private function on(AccountYear $y, int $offset): string
    // {
    //     $date = $y->start_date->copy()->addDays($offset);
    //     $cap = Carbon::now()->startOfDay()->min($y->end_date->copy());

    //     return ($date->gt($cap) ? $cap : $date)->toDateString();
    // }

    // private function id(string $code): int
    // {
    //     return $this->products[$code] ?? throw new \RuntimeException("Sample product $code is missing");
    // }

    // private function opening(Company $c, AccountYear $y, array $quantities, User $by): void
    // {
    //     foreach ($quantities as $code => $qty) {
    //         OpeningStock::updateOrCreate(
    //             ['company_id' => $c->id, 'account_year_id' => $y->id, 'product_id' => $this->id($code)],
    //             ['quantity' => $qty, 'source' => 'manual', 'updated_by' => $by->id]
    //         );
    //     }
    // }

    // /** @param array<string, array{0: float, 1: float}> $items code => [quantity, rate] */
    // private function purchase(Company $c, AccountYear $y, int $day, string $supplier, string $invoice, array $items, User $by): void
    // {
    //     $lines = collect($items)->map(fn ($v, $code) => ['product_id' => $this->id($code), 'quantity' => $v[0], 'rate' => $v[1], 'amount' => round($v[0] * $v[1], 2)])->values();
    //     $p = Purchase::create([
    //         'company_id' => $c->id, 'account_year_id' => $y->id,
    //         'purchase_no' => DocNumber::next(Purchase::class, 'purchase_no', 'PUR', $c->id, $y->id, $y->code),
    //         'purchase_date' => $this->on($y, $day), 'supplier_name' => $supplier, 'supplier_phone' => '9' . str_pad((string) abs(crc32($supplier)) % 1000000000, 9, '0', STR_PAD_LEFT),
    //         'invoice_no' => $invoice, 'notes' => null, 'total' => $lines->sum('amount'), 'created_by' => $by->id,
    //     ]);
    //     $p->items()->createMany($lines->all());
    // }

    // /** @param array<string, array{0: float, 1: float}> $items code => [quantity, rate] */
    // private function sale(Company $c, AccountYear $y, int $day, ?string $customer, string $reference, string $payment, array $items, User $by): void
    // {
    //     $lines = collect($items)->map(fn ($v, $code) => ['product_id' => $this->id($code), 'quantity' => $v[0], 'rate' => $v[1], 'amount' => round($v[0] * $v[1], 2)])->values();
    //     $known = $customer ? Customer::where('customer_name', $customer)->first() : null;
    //     $s = Sale::create([
    //         'company_id' => $c->id, 'account_year_id' => $y->id,
    //         'sale_no' => DocNumber::next(Sale::class, 'sale_no', 'SAL', $c->id, $y->id, $y->code),
    //         'sale_date' => $this->on($y, $day), 'customer_id' => $known?->id, 'customer_name' => $customer ?? 'Walk-in',
    //         'reference' => $reference, 'payment_mode' => $payment, 'total' => $lines->sum('amount'), 'created_by' => $by->id,
    //     ]);
    //     $s->items()->createMany($lines->all());
    // }

    // /** goes through the real OrderService: totals, customer record and (for "cancelled") the automatic stock adjustment */
    // private function order(Company $c, AccountYear $y, int $day, string $status, string $name, string $customer, array $items, float $service, User $by): void
    // {
    //     $this->saveOrder($c, $y, $day, $status, $name, $customer, $items, $service, $by->id, true, 'admin');
    // }

    // private function webOrder(Company $c, AccountYear $y, string $customer, array $items): void
    // {
    //     $this->saveOrder($c, $y, 175, 'pending', 'Web order - ' . $customer, $customer, $items, 0, null, false, 'web');
    // }

    // private function saveOrder(Company $c, AccountYear $y, int $day, string $status, string $name, string $customerName, array $items, float $service, ?int $userId, bool $custom, string $source): void
    // {
    //     $customer = Customer::where('customer_name', $customerName)->firstOrFail();

    //     $order = $this->orders->save(null, [
    //         'order_name' => $name, 'order_date' => $this->on($y, $day), 'customer_name' => $customer->customer_name, 'customer_phone' => $customer->customer_phone,
    //         'whatsapp' => $customer->whatsapp, 'area' => $customer->area, 'city' => $customer->city, 'address' => $customer->address,
    //         'service_charge' => $service, 'referral' => 'Mathan', 'notes' => '', 'status' => $status === 'cancelled' ? 'pending' : $status,
    //         'items' => collect($items)->map(fn ($qty, $code) => ['product_id' => $this->id($code), 'quantity' => $qty])->values()->all(),
    //     ], $c, $y, $userId, $custom, $source);

    //     if ($status === 'cancelled') {
    //         $this->orders->changeStatus($order, 'cancelled', $c, $y, $userId);   // creates the automatic stock adjustment
    //     }
    // }

    // /** @param array<string, float> $changes code => signed quantity */
    // private function adjustment(Company $c, AccountYear $y, int $day, string $reason, string $notes, array $changes, User $by): void
    // {
    //     $a = StockAdjustment::create([
    //         'company_id' => $c->id, 'account_year_id' => $y->id,
    //         'adjustment_no' => DocNumber::next(StockAdjustment::class, 'adjustment_no', 'ADJ', $c->id, $y->id, $y->code),
    //         'adjustment_date' => $this->on($y, $day), 'reason' => $reason, 'notes' => $notes, 'is_auto' => false, 'created_by' => $by->id,
    //     ]);
    //     $a->items()->createMany(collect($changes)->map(fn ($q, $code) => ['product_id' => $this->id($code), 'quantity_change' => $q])->values()->all());
    // }

    // /** @param array<int, array{0: string, 1: string}> $items [[work place, worker], ...] */
    // private function work(Company $c, AccountYear $y, int $day, array $items, User $by): void
    // {
    //     $w = WorkStatus::create(['company_id' => $c->id, 'account_year_id' => $y->id, 'user_id' => $by->id, 'work_date' => $this->on($y, $day)]);
    //     $w->workLists()->createMany(collect($items)->map(fn ($i) => ['work_place' => $i[0], 'worker_name' => $i[1]])->all());
    // }
}
