<?php

use App\Enums\UserRole;
use App\Http\Controllers\ProductController;
use App\Models\Organization;
use App\Models\ShopBranch;
use App\Models\ShopHeadquarter;
use App\Models\User;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\EloquentProductRepository;
use App\Services\Contracts\ProductServiceInterface;
use App\Services\ProductService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The catalogue columns that the products migration of the project will add (the tests create them themselves). */
function productSchema(): void
{
    Schema::table('products', function ($table) {
        $table->unsignedBigInteger('organization_id')->nullable();
        $table->unsignedBigInteger('category_id')->nullable();
        $table->text('description')->nullable();
        $table->decimal('price', 10, 2)->nullable();
    });

    Schema::create('product_categories', function ($table) {
        $table->id();
        $table->unsignedBigInteger('organization_id');
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->string('name');
        $table->timestamps();
    });
}

function productFixture(): array
{
    $org = Organization::create(['name' => 'Org']);
    $other = Organization::create(['name' => 'Inna']);
    $hq = ShopHeadquarter::create(['organization_id' => $org->id, 'name' => 'Sklep']);
    $branch = ShopBranch::create(['organization_id' => $org->id, 'shop_headquarter_id' => $hq->id, 'name' => 'Placówka']);

    $make = fn (UserRole $role, array $extra = [], ?int $orgId = null) => User::factory()->create(['role' => $role, 'organization_id' => $orgId ?? $org->id, ...$extra]);

    return [
        'org' => $org, 'other' => $other,
        'owner' => $make(UserRole::ORGANIZATION_OWNER),
        'manager' => $make(UserRole::SHOP_OWNER, ['shop_headquarter_id' => $hq->id]),
        'leader' => $make(UserRole::SHOP_LEADER, ['shop_headquarter_id' => $hq->id, 'shop_branch_id' => $branch->id]),
        'worker' => $make(UserRole::SHOP_WORKER, ['shop_headquarter_id' => $hq->id, 'shop_branch_id' => $branch->id]),
        'otherOwner' => $make(UserRole::ORGANIZATION_OWNER, [], $other->id),
        'admin' => User::factory()->create(['role' => UserRole::Admin]),
        'platformManager' => User::factory()->create(['role' => UserRole::Manager]),
    ];
}

function addProduct(int $orgId, string $name, ?int $categoryId = null, array $extra = []): int
{
    static $barcode = 5900000000000;

    return DB::table('products')->insertGetId([
        'name' => $name, 'barcode' => (string) $barcode++, 'organization_id' => $orgId, 'category_id' => $categoryId,
        'created_at' => now(), 'updated_at' => now(), ...$extra,
    ]);
}

function addCategory(int $orgId, string $name, ?int $parentId = null): int
{
    return DB::table('product_categories')->insertGetId(['organization_id' => $orgId, 'parent_id' => $parentId, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
}

dataset('rolesWithProducts', [
    'właściciel organizacji' => ['owner', 'platform/organization/products/index', '/platform/organization/products'],
    'kierownik siedziby' => ['manager', 'platform/shop/owner/products/index', '/shop/owner/products'],
    'kierownik placówki' => ['leader', 'platform/shop/leader/products/index', '/shop/leader/products'],
    'pracownik' => ['worker', 'platform/shop/worker/products/index', '/shop/worker/products'],
]);

test('każda rola ze struktury ma widok produktów w swoim folderze, także zanim istnieje katalog', function (string $who, string $component, string $url) {
    $f = productFixture();

    $this->actingAs($f[$who])->get($url)->assertOk()
        ->assertInertia(fn ($page) => $page->component($component)->where('ready', false)->where('products.total', 0)->where('categories', [])->has('filters'));

    expect(file_exists(resource_path('js/pages/'.$component.'.tsx')))->toBeTrue();
})->with('rolesWithProducts');

test('widoki produktów magazynów istnieją dla kierownika siedziby, placówki i pracownika', function () {
    foreach (['owner', 'leader', 'worker'] as $slug) {
        expect(file_exists(resource_path("js/pages/platform/warehouses/{$slug}/products/index.tsx")))->toBeTrue();
        expect(route("warehouse.{$slug}.products.index", absolute: false))->toBe("/warehouse/{$slug}/products");
    }
});

test('katalog jest zamknięty dla kont platformy, innych ról i gości', function () {
    $f = productFixture();

    foreach (['/shop/owner/products', '/warehouse/worker/products'] as $url) {
        $this->actingAs($f['owner'])->get($url)->assertForbidden();
    }
    $this->actingAs($f['worker'])->get('/platform/organization/products')->assertForbidden();
    $this->actingAs($f['admin'])->get('/platform/organization/products')->assertForbidden();
    $this->actingAs($f['platformManager'])->get('/shop/leader/products')->assertForbidden();

    $this->app['auth']->forgetGuards();
    $this->get('/shop/worker/products')->assertRedirect(route('login'));
});

test('konto bez organizacji nie czyta katalogu, nawet gdy rola pasuje (polityka)', function () {
    productSchema();
    $f = productFixture();
    $stray = User::factory()->create(['role' => UserRole::ORGANIZATION_OWNER, 'organization_id' => null]);

    expect($stray->can('viewAny', App\Models\Product::class))->toBeFalse()
        ->and($f['owner']->can('viewAny', App\Models\Product::class))->toBeTrue()
        ->and($f['worker']->can('viewAny', App\Models\Product::class))->toBeTrue()
        ->and($f['admin']->can('viewAny', App\Models\Product::class))->toBeFalse()
        ->and($f['platformManager']->can('viewAny', App\Models\Product::class))->toBeFalse();

    expect(fn () => app(ProductServiceInterface::class)->catalog($stray))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

test('produkty i kategorie są izolowane między organizacjami', function () {
    productSchema();
    $f = productFixture();

    $mine = addCategory($f['org']->id, 'Napoje');
    $theirs = addCategory($f['other']->id, 'Obca kategoria');
    addProduct($f['org']->id, 'Cola', $mine);
    addProduct($f['other']->id, 'Obcy produkt', $theirs);
    addProduct($f['other']->id, 'Obcy bez kategorii');

    foreach (['owner', 'manager', 'leader', 'worker'] as $who) {
        $url = ['owner' => '/platform/organization', 'manager' => '/shop/owner', 'leader' => '/shop/leader', 'worker' => '/shop/worker'][$who].'/products';

        $this->actingAs($f[$who])->get($url)->assertOk()->assertInertia(fn ($page) => $page
            ->where('ready', true)->where('total_products', 1)->where('products.total', 1)->where('products.data.0.name', 'Cola')
            ->has('categories', 1)->where('categories.0.name', 'Napoje'));
    }

    // a category of another organization does not exist for the person: the filter is ignored and nothing leaks
    $this->actingAs($f['owner'])->get('/platform/organization/products?category='.$theirs)->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.category', null)->where('products.total', 1)->where('products.data.0.name', 'Cola'));

    $this->actingAs($f['otherOwner'])->get('/platform/organization/products')->assertOk()
        ->assertInertia(fn ($page) => $page->where('products.total', 2)->where('categories.0.name', 'Obca kategoria'));
});

test('filtrowanie po kategorii obejmuje podkategorie, a menu pokazuje liczby produktów', function () {
    productSchema();
    $f = productFixture();

    $drinks = addCategory($f['org']->id, 'Napoje');
    $water = addCategory($f['org']->id, 'Woda', $drinks);
    $food = addCategory($f['org']->id, 'Jedzenie');
    addProduct($f['org']->id, 'Cola', $drinks);
    addProduct($f['org']->id, 'Woda gazowana', $water);
    addProduct($f['org']->id, 'Chleb', $food);
    addProduct($f['org']->id, 'Bez kategorii');

    $this->actingAs($f['owner']);

    $this->get('/platform/organization/products?category='.$drinks)->assertInertia(fn ($page) => $page
        ->where('filters.category', $drinks)->where('products.total', 2)
        ->where('categories', fn ($categories) => collect($categories)->firstWhere('id', $drinks)['count'] === 1 && collect($categories)->firstWhere('id', $drinks)['total'] === 2));

    $this->get('/platform/organization/products?category='.$water)->assertInertia(fn ($page) => $page->where('products.total', 1)->where('products.data.0.name', 'Woda gazowana')->where('products.data.0.category', 'Woda'));
    $this->get('/platform/organization/products')->assertInertia(fn ($page) => $page->where('products.total', 4)->where('total_products', 4));
});

test('wyszukiwanie po nazwie i kodzie, sortowanie i paginacja po 12', function () {
    productSchema();
    $f = productFixture();

    foreach (range(1, 14) as $number) {
        addProduct($f['org']->id, 'Produkt '.str_pad((string) $number, 2, '0', STR_PAD_LEFT));
    }
    $code = DB::table('products')->where('name', 'Produkt 05')->value('barcode');

    $this->actingAs($f['owner']);

    $this->get('/platform/organization/products')->assertInertia(fn ($page) => $page
        ->where('products.total', 14)->where('products.last_page', 2)->has('products.data', 12)->where('products.data.0.name', 'Produkt 01'));
    $this->get('/platform/organization/products?page=2')->assertInertia(fn ($page) => $page->has('products.data', 2)->where('products.current_page', 2));
    $this->get('/platform/organization/products?sort=name_desc')->assertInertia(fn ($page) => $page->where('products.data.0.name', 'Produkt 14'));
    $this->get('/platform/organization/products?sort=newest')->assertInertia(fn ($page) => $page->where('products.data.0.name', 'Produkt 14'));
    $this->get('/platform/organization/products?q=05')->assertInertia(fn ($page) => $page->where('products.total', 1)->where('products.data.0.name', 'Produkt 05'));
    $this->get('/platform/organization/products?q='.$code)->assertInertia(fn ($page) => $page->where('products.total', 1)->where('products.data.0.barcode', $code));
    // wildcards are plain characters
    $this->get('/platform/organization/products?q=%25')->assertInertia(fn ($page) => $page->where('products.total', 0));

    $this->get('/platform/organization/products?sort=drop')->assertSessionHasErrors('sort');
    $this->get('/platform/organization/products?page=0')->assertSessionHasErrors('page');
});

test('kontroler, serwis i repozytorium produktów są powiązane konstruktorami', function () {
    expect(app(ProductServiceInterface::class))->toBeInstanceOf(ProductService::class)
        ->and(app(ProductRepositoryInterface::class))->toBeInstanceOf(EloquentProductRepository::class);

    expect((new ReflectionClass(ProductController::class))->getConstructor()->getParameters()[0]->getType()->getName())->toBe(ProductServiceInterface::class)
        ->and((new ReflectionClass(ProductService::class))->getConstructor()->getParameters()[0]->getType()->getName())->toBe(ProductRepositoryInterface::class);
});
