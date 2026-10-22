<?php

/*
|--------------------------------------------------------------------------
| EAN-13 barcode prefixes
|--------------------------------------------------------------------------
|
| The code has the form: prefix (4) + zero-padded ID (8) + check digit (1) = 13 digits. Every kind of record has its own
| prefix, so codes of different records never collide, and a scanner recognises what it has read from the first digits.
|
| The prefixes MUST be within 2000-2999: GS1 reserves the prefixes 20-29 for a shop's internal use and never assigns them to
| manufacturers, so the codes of goods bought from manufacturers (real EANs) cannot collide with ours.
|
| The scheme: the first two digits are the FAMILY, the last two the kind of record inside it.
|
|   20xx  people              2001 user
|   21xx  organizations       2101 organization
|   22xx  warehouses          2201 main headquarters      2202 branch (warehouse)
|   23xx  shops               2301 main headquarters      2302 branch (shop)
|   24xx  goods               2401 own product (no manufacturer EAN)      2402 product category
|   25xx  tasks               2501 task
|   26xx  trade               2601 offer                  2602 order
|   27xx  reserved for documents (delivery notes, receipts, invoices)
|   28xx, 29xx  free, for what comes next
|
| Change a prefix in .env (BARCODE_PREFIX_*) only before the first code is printed: a code already issued keeps the old
| prefix, and the scanner would no longer recognise it by the new one.
|
*/

return [

    'prefixes' => [
        // 20xx people
        'user' => env('BARCODE_PREFIX_USER', '2001'),

        // 21xx organizations
        'organization' => env('BARCODE_PREFIX_ORGANIZATION', '2101'),

        // 22xx warehouses
        'warehouse_headquarter' => env('BARCODE_PREFIX_WAREHOUSE_HEADQUARTER', '2201'),
        'warehouse_branch' => env('BARCODE_PREFIX_WAREHOUSE_BRANCH', '2202'),

        // 23xx shops
        'shop_headquarter' => env('BARCODE_PREFIX_SHOP_HEADQUARTER', '2301'),
        'shop_branch' => env('BARCODE_PREFIX_SHOP_BRANCH', '2302'),

        // 24xx goods: the shop's own products without a manufacturer EAN, and the categories of the catalogue
        'product' => env('BARCODE_PREFIX_PRODUCT', '2401'),
        'product_category' => env('BARCODE_PREFIX_PRODUCT_CATEGORY', '2402'),

        // 25xx tasks
        'task' => env('BARCODE_PREFIX_TASK', '2501'),

        // 26xx trade
        'offer' => env('BARCODE_PREFIX_OFFER', '2601'),
        'order' => env('BARCODE_PREFIX_ORDER', '2602'),
    ],

];
