<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProductResource\Widgets\ProductUrlStats;
use App\Filament\Resources\ProductResource\Widgets\UrlsTableWidget;
use App\Filament\Resources\StoreResource\Pages\EditStore;
use App\Filament\Resources\StoreResource\Pages\ListStores;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class AdditionalCostFormTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_store_form_saves_the_additional_cost(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        $this->fillStoreForm($store, ['additional_cost' => 8.99, 'additional_cost_reduced_from' => 150, 'additional_cost_reduced' => 4.99])
            ->assertHasNoFormErrors();

        $store->refresh();
        $this->assertSame(8.99, $store->additional_cost);
        $this->assertSame(150.0, $store->additional_cost_reduced_from);
        $this->assertSame(4.99, $store->additional_cost_reduced);
    }

    public function test_store_form_saves_an_empty_additional_cost_as_none(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http'], 'additional_cost' => 5, 'additional_cost_reduced_from' => 50, 'additional_cost_reduced' => 1]);

        $this->fillStoreForm($store, ['additional_cost' => null, 'additional_cost_reduced_from' => null, 'additional_cost_reduced' => null])
            ->assertHasNoFormErrors();

        $store->refresh();
        $this->assertNull($store->additional_cost);
        $this->assertNull($store->additional_cost_reduced_from);
        $this->assertNull($store->additional_cost_reduced);
    }

    public function test_store_form_rejects_a_negative_additional_cost(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        $this->fillStoreForm($store, ['additional_cost' => -1, 'additional_cost_reduced_from' => -1, 'additional_cost_reduced' => -1])
            ->assertHasFormErrors(['additional_cost', 'additional_cost_reduced_from', 'additional_cost_reduced']);
    }

    public function test_store_form_rejects_a_reduction_that_does_not_add_up(): void
    {
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        // A reduced cost needs the price it applies from.
        $this->fillStoreForm($store, ['additional_cost' => 8.99, 'additional_cost_reduced' => 4.99])
            ->assertHasFormErrors(['additional_cost_reduced_from']);

        // A reduction needs a cost to reduce.
        $this->fillStoreForm($store, ['additional_cost_reduced_from' => 150])
            ->assertHasFormErrors(['additional_cost']);

        // A reduced cost is no more than the cost.
        $this->fillStoreForm($store, ['additional_cost' => 8.99, 'additional_cost_reduced_from' => 150, 'additional_cost_reduced' => 9.99])
            ->assertHasFormErrors(['additional_cost_reduced']);
    }

    public function test_store_form_rejects_a_reduction_from_zero(): void
    {
        // A reduction from 0 would apply to every price, the opposite of "no reduction".
        $store = Store::factory()->create(['settings' => ['scraper_service' => 'http']]);

        $this->fillStoreForm($store, ['additional_cost' => 15, 'additional_cost_reduced_from' => 0])
            ->assertHasFormErrors(['additional_cost_reduced_from']);
    }

    public function test_link_form_saves_its_own_additional_cost(): void
    {
        $url = $this->makeUrl();

        $this->editUrl($url, ['additional_cost' => 0])->assertHasNoTableActionErrors();
        $this->assertSame(0.0, $url->refresh()->additional_cost);

        $this->editUrl($url, ['additional_cost' => null])->assertHasNoTableActionErrors();
        $this->assertNull($url->refresh()->additional_cost);
    }

    public function test_link_form_drops_its_own_cost_when_the_link_moves_to_another_store(): void
    {
        $url = $this->makeUrl();
        $url->update(['additional_cost' => 5]);
        Store::factory()->createOne(['domains' => [['domain' => 'example2.com']]]);

        $this->editUrl($url, ['url' => 'https://example2.com/product', 'additional_cost' => 5])->assertHasNoTableActionErrors();

        $this->assertSame('https://example2.com/product', $url->refresh()->url);
        $this->assertNull($url->additional_cost);
    }

    public function test_product_page_link_form_saves_its_own_additional_cost(): void
    {
        $url = $this->makeUrl();

        Livewire::test(ProductUrlStats::class, ['record' => $url->product])
            ->callAction('edit', data: ['url' => $url->url, 'price_factor' => 1, 'additional_cost' => 4.5], arguments: ['url' => $url->getKey()])
            ->assertHasNoActionErrors();

        $this->assertSame(4.5, $url->refresh()->additional_cost);
    }

    public function test_link_form_keeps_a_cost_typed_for_the_new_store(): void
    {
        $url = $this->makeUrl();
        $url->update(['additional_cost' => 5]);
        Store::factory()->createOne(['domains' => [['domain' => 'example2.com']]]);

        $this->editUrl($url, ['url' => 'https://example2.com/product', 'additional_cost' => 7])->assertHasNoTableActionErrors();

        $this->assertSame(7.0, $url->refresh()->additional_cost);
    }

    public function test_a_new_address_is_alerted_on_with_the_cost_set_with_it(): void
    {
        $url = $this->makeUrl(scrapedPrice: 95);
        $url->update(['additional_cost' => 0]);
        $url->product->update(['notify_price' => 100, 'notify_percent' => null]);
        Notification::fake();

        // 95 is below the target, but not with the 10 shipping set in the same edit.
        $this->editUrl($url, ['url' => 'https://example.com/other', 'additional_cost' => 10])->assertHasNoTableActionErrors();

        Notification::assertNothingSent();
        $this->assertEqualsWithDelta(105.0, $url->product->refresh()->current_price, 0.001);
    }

    public function test_a_link_added_with_its_own_cost_is_alerted_on_with_it(): void
    {
        $url = $this->makeUrl(scrapedPrice: 95);
        $url->product->update(['notify_price' => 100, 'notify_percent' => null]);
        Notification::fake();

        $added = Url::createFromUrl('https://example.com/seller-listing', $url->product_id, additionalCost: 10);

        $this->assertSame(10.0, $added->additional_cost);
        Notification::assertNothingSent();
    }

    public function test_link_form_rejects_a_negative_additional_cost(): void
    {
        $this->editUrl($this->makeUrl(), ['additional_cost' => -1])
            ->assertHasTableActionErrors(['additional_cost']);
    }

    public function test_store_import_takes_only_what_a_store_export_has(): void
    {
        $other = User::factory()->createOne();
        $json = json_encode([
            'name' => 'Imported',
            'domains' => [['domain' => 'imported.test']],
            'scrape_strategy' => collect(['title', 'price', 'image'])->mapWithKeys(fn ($s) => [$s => ['type' => 'selector', 'value' => 'h1']])->all(),
            'settings' => ['scraper_service' => 'http'],
            'additional_cost' => -5,
            'additional_cost_reduced_from' => 0,
            'user_id' => $other->id,
            'cookies' => 'session=stolen',
        ]);

        Livewire::test(ListStores::class)
            ->callAction('import_store', data: ['json' => $json])
            ->assertHasNoActionErrors();

        $store = Store::query()->where('name', 'Imported')->firstOrFail();
        $this->assertNull($store->additional_cost);
        $this->assertNull($store->additional_cost_reduced_from);
        $this->assertNotSame($other->id, $store->user_id);
        $this->assertNull($store->cookies);
    }

    public function test_store_export_leaves_out_the_additional_cost(): void
    {
        $store = Store::factory()->create(['additional_cost' => 8.99, 'additional_cost_reduced_from' => 150, 'additional_cost_reduced' => 4.99]);

        $this->assertStringNotContainsString(
            'additional_cost',
            view('filament.resources.store-resource.actions.share-store', ['record' => $store])->render(),
        );
    }

    protected function fillStoreForm(Store $store, array $data)
    {
        return Livewire::test(EditStore::class, ['record' => $store->getKey()])
            ->set('data.domains', null)
            ->fillForm(array_merge([
                'domains' => [['domain' => 'example.com']],
                'settings.locale_settings.locale' => 'en_US',
                'settings.locale_settings.currency' => 'USD',
            ], $data))
            ->call('save');
    }

    protected function makeUrl(float $scrapedPrice = 10): Url
    {
        // Saving the link form validates the URL by scraping it.
        $this->mockScrape($scrapedPrice, 'Example Product');

        $product = Product::factory()
            ->for($this->user)
            ->addUrlWithPrices('https://example.com/product', [10])
            ->createOne();

        return $product->urls()->firstOrFail();
    }

    protected function editUrl(Url $url, array $data)
    {
        return Livewire::test(UrlsTableWidget::class, ['record' => $url->product])
            ->callTableAction('edit', $url, data: array_merge(['url' => $url->url, 'price_factor' => 1], $data));
    }
}
