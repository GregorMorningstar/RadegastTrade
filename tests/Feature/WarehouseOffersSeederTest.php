<?php

use App\Models\Offer;
use App\Models\OfferItem;
use Database\Seeders\DakarSeeder;
use Database\Seeders\SpolemSeeder;
use Database\Seeders\WarehouseOffersSeeder;

test('seeder ofert hurtowni: każda hurtownia Dakara i Społem ma opublikowaną ofertę z 20 produktami, bez dublowania', function () {
    $this->seed(DakarSeeder::class);
    $this->seed(SpolemSeeder::class);
    $this->seed(WarehouseOffersSeeder::class);
    $this->seed(WarehouseOffersSeeder::class);

    // DAKAR: the headquarters and the branch of the warehouse; Społem: the headquarters
    expect(Offer::count())->toBe(3);
    Offer::with('currentVersion.items')->get()->each(function (Offer $offer) {
        expect($offer->status->value)->toBe('published')->and($offer->shared_internally)->toBeTrue()->and($offer->currentVersion->items)->toHaveCount(20);
    });
    expect(OfferItem::count())->toBe(60);

    // every barcode is a valid EAN-13
    OfferItem::pluck('barcode')->each(function (string $ean) {
        $sum = collect(str_split(substr($ean, 0, 12)))->map(fn ($digit, $i) => (int) $digit * ($i % 2 === 0 ? 1 : 3))->sum();
        expect(strlen($ean))->toBe(13)->and((int) $ean[12])->toBe((10 - $sum % 10) % 10);
    });
});
