<?php

namespace Tests\Feature\Admin;

use App\Enums\BookFormat;
use App\Models\BookVariant;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BulkPriceChangeTest extends TestCase
{
    use RefreshDatabase;

    private StaffUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = StaffUser::factory()->admin()->withTwoFactor()->create();
    }

    private function as(StaffUser $user): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('t', $user->tokenAbilities())->plainTextToken);
    }

    private function variant(string $price, ?string $cost): BookVariant
    {
        return BookVariant::factory()->create(['price_usd' => $price, 'cost_price_usd' => $cost]);
    }

    public function test_preview_changes_nothing_and_apply_saves_exactly_the_preview(): void
    {
        $a = $this->variant('6.99', '2.80');
        $b = $this->variant('8.00', '3.50');
        $body = ['variant_ids' => [$a->id, $b->id], 'sell_mode' => 'markup', 'sell_value' => 1.6, 'cost_mode' => 'keep', 'rounding' => '90'];

        $preview = $this->as($this->admin)->postJson('/api/v1/staff/variants/prices/preview', $body)->assertOk()->json('data');
        $this->assertSame(['4.90', '5.90'], array_column($preview['items'], 'new_price_usd'));
        $this->assertSame('6.99', $a->fresh()->price_usd);

        $this->as($this->admin)->postJson('/api/v1/staff/variants/prices', $body)->assertOk()->assertJsonPath('data.updated_count', 2);
        $this->assertSame('4.90', $a->fresh()->price_usd);
        $this->assertSame('5.90', $b->fresh()->price_usd);
        $this->assertSame(2, DB::table('admin_audit_logs')->where('action', 'book_variant.prices_changed')->count());
    }

    public function test_cost_runs_first_and_rounding_modes(): void
    {
        $v = $this->variant('10.00', '2.00');
        $this->as($this->admin)->postJson('/api/v1/staff/variants/prices', [
            'variant_ids' => [$v->id], 'cost_mode' => 'percent', 'cost_value' => 10, 'sell_mode' => 'markup', 'sell_value' => 2, 'rounding' => '50',
        ])->assertOk();
        $this->assertSame('2.20', $v->fresh()->cost_price_usd);
        $this->assertSame('4.50', $v->fresh()->price_usd); // 4.40 → nearest .50

        $this->as($this->admin)->postJson('/api/v1/staff/variants/prices', [
            'variant_ids' => [$v->id], 'cost_mode' => 'keep', 'sell_mode' => 'percent', 'sell_value' => -20, 'rounding' => 'whole',
        ])->assertOk();
        $this->assertSame('4.00', $v->fresh()->price_usd); // 3.60 → 4
    }

    public function test_never_below_cost_keeps_the_old_price_and_says_why(): void
    {
        $v = $this->variant('6.90', '5.00');
        $item = $this->as($this->admin)->postJson('/api/v1/staff/variants/prices/preview', [
            'variant_ids' => [$v->id], 'cost_mode' => 'keep', 'sell_mode' => 'set', 'sell_value' => 4.9,
        ])->assertOk()->json('data.items.0');
        $this->assertSame('6.90', $item['new_price_usd']);
        $this->assertSame('below_cost', $item['note']);
        $this->assertFalse($item['changed']);

        $this->as($this->admin)->postJson('/api/v1/staff/variants/prices', [
            'variant_ids' => [$v->id], 'cost_mode' => 'keep', 'sell_mode' => 'set', 'sell_value' => 4.9, 'floor_at_cost' => false,
        ])->assertOk();
        $this->assertSame('4.90', $v->fresh()->price_usd);
    }

    public function test_markup_without_a_cost_is_skipped(): void
    {
        $v = $this->variant('6.90', null);
        $item = $this->as($this->admin)->postJson('/api/v1/staff/variants/prices/preview', [
            'variant_ids' => [$v->id], 'cost_mode' => 'keep', 'sell_mode' => 'markup', 'sell_value' => 1.6,
        ])->assertOk()->json('data.items.0');
        $this->assertSame('no_cost', $item['note']);
        $this->assertSame('6.90', $item['new_price_usd']);
    }

    public function test_only_admins_and_validation(): void
    {
        $v = $this->variant('6.90', '2.00');
        $staff = StaffUser::factory()->withTwoFactor()->create();
        $this->as($staff)->postJson('/api/v1/staff/variants/prices', ['variant_ids' => [$v->id], 'sell_mode' => 'set', 'sell_value' => 1, 'cost_mode' => 'keep'])->assertForbidden();
        $this->as($this->admin)->postJson('/api/v1/staff/variants/prices', ['variant_ids' => [$v->id], 'sell_mode' => 'keep', 'cost_mode' => 'keep'])->assertUnprocessable();
        $this->as($this->admin)->postJson('/api/v1/staff/variants/prices', ['variant_ids' => [$v->id], 'sell_mode' => 'percent', 'sell_value' => -100, 'cost_mode' => 'keep'])->assertUnprocessable();
        $this->assertSame('6.90', $v->fresh()->price_usd);
    }

    public function test_a_cost_rule_leaves_ebooks_alone(): void
    {
        $ebook = BookVariant::factory()->format(BookFormat::Ebook)->create(['price_usd' => '3.00', 'cost_price_usd' => null]);
        $item = $this->as($this->admin)->postJson('/api/v1/staff/variants/prices/preview', [
            'variant_ids' => [$ebook->id], 'cost_mode' => 'set', 'cost_value' => 2.8, 'sell_mode' => 'keep',
        ])->assertOk()->json('data.items.0');
        $this->assertNull($item['new_cost_usd']);
        $this->assertSame('digital', $item['note']);
        $this->assertFalse($item['changed']);
    }

    public function test_undo_puts_the_old_prices_back(): void
    {
        $v = $this->variant('6.99', '2.80');
        $rows = $this->as($this->admin)->postJson('/api/v1/staff/variants/prices', [
            'variant_ids' => [$v->id], 'cost_mode' => 'set', 'cost_value' => 3, 'sell_mode' => 'set', 'sell_value' => 4.9, 'rounding' => 'none',
        ])->assertOk()->json('data.items');
        $this->assertSame(['4.90', '3.00'], [$v->fresh()->price_usd, $v->fresh()->cost_price_usd]);

        $items = array_map(fn ($r) => ['variant_id' => $r['variant_id'], 'price_usd' => $r['old_price_usd'], 'cost_price_usd' => $r['old_cost_usd']], $rows);
        $this->as($this->admin)->postJson('/api/v1/staff/variants/prices/restore', ['items' => $items])->assertOk()->assertJsonPath('data.restored_count', 1);
        $this->assertSame(['6.99', '2.80'], [$v->fresh()->price_usd, $v->fresh()->cost_price_usd]);
        $this->assertSame(1, DB::table('admin_audit_logs')->where('action', 'book_variant.prices_restored')->count());

        $staff = StaffUser::factory()->withTwoFactor()->create();
        $this->as($staff)->postJson('/api/v1/staff/variants/prices/restore', ['items' => $items])->assertForbidden();
    }

    public function test_books_list_filters_by_margin(): void
    {
        $loss = $this->variant('4.00', '5.00');
        $low = $this->variant('5.00', '4.50');   // 10% margin
        $fine = $this->variant('8.00', '3.00');
        $none = $this->variant('6.00', null);
        $ids = fn (string $m) => collect($this->as($this->admin)->getJson("/api/v1/staff/books?margin={$m}&per_page=100")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$loss->book_id], $ids('loss'));
        $this->assertSame(collect([$loss->book_id, $low->book_id])->sort()->values()->all(), $ids('low'));
        $this->assertSame([$none->book_id], $ids('no_cost'));
        $this->assertNotContains($fine->book_id, $ids('low'));
    }
}
