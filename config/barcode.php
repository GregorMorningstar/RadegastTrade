<?php

/*
|--------------------------------------------------------------------------
| EAN-13 barcode prefixes
|--------------------------------------------------------------------------
|
| Each entity type has its own 4-digit prefix. The code has the form:
| prefix (4) + zero-padded ID (8) + check digit (1) = 13 digits.
|
| Because every type has a different prefix, codes of different entities never
| collide. Prefixes MUST be within 2000-2999: GS1 reserves
| prefixes 20-29 for stores' internal use and never assigns them
| to manufacturers, so codes of goods bought from manufacturers (real EANs)
| cannot collide with them.
|
*/

return [

    'prefixes' => [
        'user' => env('BARCODE_PREFIX_USER', '2001'),
        'organization' => env('BARCODE_PREFIX_ORGANIZATION', '2002'),
        'warehouse_headquarter' => env('BARCODE_PREFIX_WAREHOUSE_HEADQUARTER', '2003'),
        'shop_headquarter' => env('BARCODE_PREFIX_SHOP_HEADQUARTER', '2004'),
        'shop_branch' => env('BARCODE_PREFIX_SHOP_BRANCH', '2006'),
        'warehouse_branch' => env('BARCODE_PREFIX_WAREHOUSE_BRANCH', '2007'),

        // Shop's own products without a manufacturer EAN
        'product' => env('BARCODE_PREFIX_PRODUCT', '2005'),
    ],

];
