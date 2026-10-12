<?php

/*
|--------------------------------------------------------------------------
| Prefiksy kodów kreskowych EAN-13
|--------------------------------------------------------------------------
|
| Każdy rodzaj encji ma własny, 4-cyfrowy prefiks. Kod ma postać:
| prefiks (4) + ID dopełnione zerami (8) + cyfra kontrolna (1) = 13 cyfr.
|
| Ponieważ każdy rodzaj ma inny prefiks, kody różnych encji nigdy się nie
| powtórzą. Prefiksy MUSZĄ mieścić się w zakresie 2000-2999: GS1 rezerwuje
| przedrostki 20-29 na użytek wewnętrzny sklepów i nigdy nie przydziela ich
| producentom, więc kody towarów kupowanych od producentów (prawdziwe EAN)
| nie mogą się z nimi zderzyć.
|
*/

return [

    'prefixes' => [
        'user' => env('BARCODE_PREFIX_USER', '2001'),
        'organization' => env('BARCODE_PREFIX_ORGANIZATION', '2002'),
        'warehouse_headquarter' => env('BARCODE_PREFIX_WAREHOUSE_HEADQUARTER', '2003'),
        'shop_headquarter' => env('BARCODE_PREFIX_SHOP_HEADQUARTER', '2004'),

        // Własne produkty sklepu bez EAN od producenta
        'product' => env('BARCODE_PREFIX_PRODUCT', '2005'),
    ],

];
