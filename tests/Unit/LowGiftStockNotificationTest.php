<?php

namespace Tests\Unit;

use App\Models\Gift;
use App\Notifications\LowGiftStockNotification;
use Tests\TestCase;

class LowGiftStockNotificationTest extends TestCase
{
    public function test_notification_uses_both_languages_and_keeps_real_stock(): void
    {
        $gift = new Gift(['name_en' => 'Pen', 'name_km' => 'ប៊ិច', 'stock' => 3]);
        $notification = new LowGiftStockNotification($gift);
        app()->setLocale('en');
        $this->assertSame('Low gift stock: Pen has 3 left.', $notification->toArray(new \stdClass)['message']);
        app()->setLocale('km');
        $this->assertSame('ស្តុកកាដូនៅសល់តិច៖ ប៊ិច នៅសល់ 3។', $notification->toArray(new \stdClass)['message']);
    }
}
