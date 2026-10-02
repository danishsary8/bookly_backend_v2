<?php

namespace Tests\Feature\Platform;

use App\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The frontend generates its TypeScript types from public/openapi.yaml, so every field a response
 * returns must be in the spec and every field the spec promises must be returned.
 */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    private array $schemas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schemas = Yaml::parseFile(public_path('openapi.yaml'))['components']['schemas'];
        $this->seed(DemoSeeder::class);
    }

    /** Property names of a schema, following allOf. */
    private function fields(string $schema): array
    {
        $definition = $this->schemas[$schema];
        $fields = array_keys($definition['properties'] ?? []);
        foreach ($definition['allOf'] ?? [] as $part) {
            $fields = [...$fields, ...(isset($part['$ref']) ? $this->fields(basename($part['$ref'])) : array_keys($part['properties'] ?? []))];
        }
        sort($fields);

        return array_values(array_unique($fields));
    }

    private function assertShape(string $schema, array $actual, array $notReturnedHere = []): void
    {
        $keys = array_keys($actual);
        sort($keys);

        $this->assertSame(array_values(array_diff($this->fields($schema), $notReturnedHere)), $keys, "{$schema} does not match the response");
    }

    public function test_public_catalog_responses_match_the_spec(): void
    {
        $books = $this->getJson('/api/v1/books?per_page=1')->assertOk()->json();
        $this->assertShape('BookCard', $books['data'][0]);
        $this->assertShape('PageLinks', $books['links']);
        $this->assertSame([], array_values(array_diff($this->fields('PageMeta'), array_keys($books['meta']))), 'PageMeta fields missing');

        $book = $this->getJson('/api/v1/books/'.$books['data'][0]['id'])->json('data');
        $this->assertShape('BookDetail', $book);
        $this->assertShape('Variant', $book['variants'][0]);

        $this->assertShape('Author', $this->getJson('/api/v1/authors/'.$this->getJson('/api/v1/authors')->json('data.0.id'))->json('data'));
        $this->assertShape('Category', $this->getJson('/api/v1/categories')->json('data.0'));
        $this->assertShape('Publisher', $this->getJson('/api/v1/publishers')->json('data.0'));
        $this->assertShape('Series', $this->getJson('/api/v1/series')->json('data.0'));
        $this->assertShape('SeriesDetail', $this->getJson('/api/v1/series/'.$this->getJson('/api/v1/series')->json('data.0.id'))->json('data'));

        $reviewed = $this->getJson('/api/v1/books?sort=rating&per_page=1')->json('data.0.id');
        $reviews = $this->getJson("/api/v1/books/{$reviewed}/reviews")->json();
        $this->assertShape('Review', $reviews['data'][0]);
        $this->assertShape('RatingSummary', $reviews['meta']['rating_summary']);
    }

    public function test_customer_responses_match_the_spec(): void
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'demo@bookly.test', 'password' => DemoSeeder::PASSWORD])->assertOk()->json();
        $this->assertShape('Customer', $token['customer']);
        $this->assertShape('TokenResponse', Arr::except($token, 'customer'));
        $auth = ['Authorization' => 'Bearer '.$token['token']];

        $this->assertShape('Customer', $this->getJson('/api/v1/me', $auth)->json('data'));
        $this->assertShape('Address', $this->getJson('/api/v1/addresses', $auth)->json('data.0'));

        $variant = $this->getJson('/api/v1/books/'.$this->getJson('/api/v1/books?in_stock=1&format=paperback&per_page=1')->json('data.0.id'))->json('data.variants.0.id');
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant, 'quantity' => 2], $auth)->assertCreated();
        $this->assertShape('Cart', $this->getJson('/api/v1/cart', $auth)->json('data'));
        $this->assertShape('CheckoutPreview', $this->postJson('/api/v1/checkout/preview', [], $auth)->assertOk()->json('data'));

        $order = $this->getJson('/api/v1/orders', $auth)->json('data.0');
        $this->assertShape('Order', $order, ['status_history']);
        $this->assertShape('Order', $this->getJson("/api/v1/orders/{$order['id']}", $auth)->json('data'));
        $this->assertShape('OwnReview', $this->getJson('/api/v1/reviews', $auth)->json('data.0'));

        // Malis has the demo return; forget the demo customer the guard remembered from earlier requests.
        $this->app['auth']->forgetGuards();
        $malis = Customer::where('email', 'malis@bookly.test')->firstOrFail();
        $this->assertShape('Return', $this->getJson('/api/v1/returns', ['Authorization' => 'Bearer '.$malis->createToken('t', ['customer'])->plainTextToken])->json('data.0'));
    }

    public function test_coupon_check_matches_the_spec(): void
    {
        $customer = Customer::factory()->create();
        $auth = ['Authorization' => 'Bearer '.$customer->createToken('t', ['customer'])->plainTextToken];
        $variant = $this->getJson('/api/v1/books/'.$this->getJson('/api/v1/books?in_stock=1&format=hardcover&per_page=1')->json('data.0.id'))->json('data.variants');
        $hardcover = collect($variant)->firstWhere('format', 'hardcover');
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $hardcover['id'], 'quantity' => 2], $auth)->assertCreated();

        $this->assertShape('CouponCheck', $this->postJson('/api/v1/cart/coupon/check', ['code' => 'WELCOME10'], $auth)->assertOk()->json('data'));
    }
}
