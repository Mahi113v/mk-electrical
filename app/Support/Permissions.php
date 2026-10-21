<?php

namespace App\Support;

/**
 * Every permission the admin can give to a role.
 * Key format: "<module>.<action>"  (checked with Tenant::can('sales.create')).
 */
class Permissions
{
    public const CATALOGUE = [
        'dashboard'         => ['label' => 'Dashboard',         'actions' => ['view' => 'View dashboard']],
        'products'          => ['label' => 'Products',          'actions' => ['view' => 'View', 'manage' => 'Add / edit / delete']],
        'customers'         => ['label' => 'Customers',         'actions' => ['view' => 'View', 'manage' => 'Add / edit', 'delete' => 'Delete']],
        'orders'            => ['label' => 'Orders',            'actions' => ['view' => 'View', 'create' => 'Create', 'update' => 'Edit / change status', 'cancel' => 'Cancel (stock goes back)', 'delete' => 'Delete']],
        'purchases'         => ['label' => 'Purchase',          'actions' => ['view' => 'View', 'create' => 'Add / edit', 'delete' => 'Delete']],
        'sales'             => ['label' => 'Sales',             'actions' => ['view' => 'View', 'create' => 'Add / edit', 'delete' => 'Delete']],
        'opening_stock'     => ['label' => 'Opening Stock',     'actions' => ['view' => 'View', 'manage' => 'Fetch / refresh / edit']],
        'stock_adjustments' => ['label' => 'Stock Adjustment',  'actions' => ['view' => 'View', 'create' => 'Add', 'delete' => 'Delete']],
        'stock'             => ['label' => 'Stock Summary',     'actions' => ['view' => 'View current stock']],
        'work_status'       => ['label' => 'Work Status',       'actions' => ['view' => 'View', 'manage' => 'Add / edit / delete']],
    ];

    /** @return string[] */
    public static function all(): array
    {
        $keys = [];
        foreach (self::CATALOGUE as $module => $def) {
            foreach (array_keys($def['actions']) as $action) {
                $keys[] = "$module.$action";
            }
        }

        return $keys;
    }

    public static function views(): array
    {
        return array_values(array_filter(self::all(), fn ($k) => str_ends_with($k, '.view')));
    }

    /** Remove anything that is not a real permission key */
    public static function clean(array $keys): array
    {
        return array_values(array_intersect(self::all(), array_unique($keys)));
    }

    /** System roles created on install. Admin can edit them later (company_admin is always "everything"). */
    public static function systemRoles(): array
    {
        $all = self::all();

        return [
            'company_admin' => ['Company Admin', 'Full access inside the company', $all],
            'manager' => ['Manager', 'Everything except deleting records', array_values(array_filter($all, fn ($k) => !str_ends_with($k, '.delete'))) ],
            'stock_keeper' => ['Stock Keeper', 'Purchase, opening stock, adjustments and stock reports', [
                'dashboard.view', 'products.view', 'products.manage', 'purchases.view', 'purchases.create', 'opening_stock.view',
                'opening_stock.manage', 'stock_adjustments.view', 'stock_adjustments.create', 'stock.view',
            ]],
            'sales_executive' => ['Sales Executive', 'Orders and sales, can see stock', [
                'dashboard.view', 'products.view', 'customers.view', 'customers.manage', 'orders.view', 'orders.create', 'orders.update',
                'sales.view', 'sales.create', 'stock.view', 'work_status.view',
            ]],
            'staff' => ['Staff', 'Orders, customers and work status', [
                'dashboard.view', 'products.view', 'customers.view', 'customers.manage', 'orders.view', 'orders.create',
                'orders.update', 'work_status.view', 'work_status.manage',
            ]],
            'viewer' => ['Viewer', 'Read only', self::views()],
        ];
    }
}
