<?php

namespace Database\Seeders;

use App\Enums\HeadquarterType;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\User;
use App\Services\OfferService;
use App\Support\TradeAccess;
use App\Support\UnitRef;
use Illuminate\Database\Seeder;

/**
 * Products of the warehouses: every warehouse (headquarters and branch) of the DAKAR and Społem Jasło organizations gets
 * one published offer with 20 products (prices differ a little from warehouse to warehouse). The offers are made through
 * the real service, as the owner of the organization, and are open to the units of the organization (shops order from them).
 *
 * Run after DakarSeeder and SpolemSeeder. It skips a warehouse that already has the offer, so it can be run again.
 */
class WarehouseOffersSeeder extends Seeder
{
    private const TITLE = 'Oferta podstawowa – asortyment spożywczy';

    private const ORGANIZATIONS = [
        '6851047393' => 'dakar_organization@test.pl',
        '6852335266' => 'spolem_organization@test.pl',
    ];

    /** name, sku, unit, net price, VAT %, minimum quantity, step, maximum quantity */
    private const PRODUCTS = [
        ['Chleb pszenno-żytni 500 g', 'PIE-001', 'szt.', '3.20', '5', '10', '10', '500'],
        ['Bułka kajzerka 50 g', 'PIE-002', 'szt.', '0.45', '5', '50', '50', '2000'],
        ['Mleko UHT 3,2% 1 l', 'NAB-001', 'szt.', '2.85', '5', '12', '12', '1200'],
        ['Jogurt naturalny 400 g', 'NAB-002', 'szt.', '2.60', '5', '12', '12', '600'],
        ['Masło extra 200 g', 'NAB-003', 'szt.', '7.40', '5', '10', '10', '400'],
        ['Ser żółty gouda plastry 150 g', 'NAB-004', 'szt.', '5.90', '5', '10', '10', '300'],
        ['Jaja kl. M 10 szt.', 'NAB-005', 'op.', '8.20', '5', '6', '6', '300'],
        ['Mąka pszenna typ 500 1 kg', 'SYP-001', 'szt.', '2.90', '5', '10', '10', '800'],
        ['Cukier biały 1 kg', 'SYP-002', 'szt.', '3.70', '5', '10', '10', '800'],
        ['Ryż biały 1 kg', 'SYP-003', 'szt.', '4.80', '5', '10', '10', '500'],
        ['Makaron świderki 400 g', 'SYP-004', 'szt.', '3.10', '5', '20', '20', '1000'],
        ['Olej rzepakowy 1 l', 'TLU-001', 'szt.', '7.60', '5', '12', '12', '600'],
        ['Kawa mielona 250 g', 'NAP-001', 'szt.', '12.90', '23', '10', '10', '400'],
        ['Herbata czarna 100 torebek', 'NAP-002', 'op.', '9.40', '23', '10', '10', '400'],
        ['Woda mineralna niegazowana 1,5 l', 'NAP-003', 'szt.', '1.55', '23', '6', '6', '3000'],
        ['Sok jabłkowy 1 l', 'NAP-004', 'szt.', '3.30', '5', '12', '12', '1200'],
        ['Kiełbasa śląska 1 kg', 'MIE-001', 'kg', '21.50', '5', '5', '1', '200'],
        ['Szynka drobiowa plastry 100 g', 'MIE-002', 'szt.', '4.90', '5', '10', '10', '500'],
        ['Płyn do mycia naczyń 500 ml', 'CHE-001', 'szt.', '4.10', '23', '12', '12', '600'],
        ['Papier toaletowy 8 rolek', 'CHE-002', 'op.', '11.80', '23', '10', '10', '400'],
    ];

    public function run(): void
    {
        $service = app(OfferService::class);
        $access = app(TradeAccess::class);

        foreach (self::ORGANIZATIONS as $nip => $ownerEmail) {
            $organization = Organization::where('NIP', $nip)->first();
            $owner = User::where('email', $ownerEmail)->first();

            if ($organization === null || $owner === null) {
                $this->command?->warn("WarehouseOffersSeeder: brak organizacji $nip lub jej właściciela – najpierw DakarSeeder i SpolemSeeder.");

                continue;
            }

            foreach ($access->allUnits($organization->id, HeadquarterType::Warehouse) as $index => $unit) {
                $this->offer($service, $owner, $unit, $index);
            }
        }
    }

    private function offer(OfferService $service, User $owner, UnitRef $unit, int $index): void
    {
        $exists = Offer::query()->where('organization_id', $owner->organization_id)->where('unit_ref', $unit->ref())
            ->whereHas('versions', fn ($versions) => $versions->where('title', self::TITLE))->exists();

        if ($exists) {
            return;
        }

        // the price of a warehouse is a little different from another (0 %, +2 %, +4 % ...)
        $factor = 1 + 0.02 * $index;

        $offer = $service->create($owner, [
            'unit_ref' => $unit->ref(),
            'title' => self::TITLE,
            'description' => 'Podstawowy asortyment hurtowni: '.$unit->find((int) $owner->organization_id)?->name.'.',
            'currency' => 'PLN',
            'valid_to' => now()->addYear()->format('Y-m-d H:i:s'),
            'delivery_terms' => 'Dostawa do 2 dni roboczych od złożenia zamówienia. Płatność przelewem 14 dni.',
            'items' => collect(self::PRODUCTS)->map(fn (array $product, int $number) => [
                'name' => $product[0],
                'sku' => $product[1],
                'barcode' => $this->ean($unit, $number),
                'unit_of_measure' => $product[2],
                'unit_price' => number_format((float) $product[3] * $factor, 2, '.', ''),
                'vat_rate' => $product[4],
                'min_qty' => $product[5],
                'qty_step' => $product[6],
                'max_qty' => $product[7],
            ])->all(),
        ]);

        // published and open to the units of the organization (shops order from the warehouses of their organization)
        $service->publish($owner, $offer, true);
    }

    /** A valid EAN-13 (prefix 590) that is the same on every run: warehouse and position decide it. */
    private function ean(UnitRef $unit, int $number): string
    {
        $body = '590'.str_pad((string) (($unit->id * 100 + ($unit->isBranch() ? 50 : 0) + $number) % 1_000_000_000), 9, '0', STR_PAD_LEFT);
        $sum = 0;

        foreach (str_split($body) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 === 0 ? 1 : 3);
        }

        return $body.((10 - $sum % 10) % 10);
    }
}
