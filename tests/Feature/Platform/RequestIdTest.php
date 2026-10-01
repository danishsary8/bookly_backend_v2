<?php

namespace Tests\Feature\Platform;

use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

class RequestIdTest extends TestCase
{
    public function test_every_response_gets_a_request_id(): void
    {
        $id = $this->getJson('/api/v1/ping')->assertOk()->headers->get('X-Request-Id');

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
        $this->assertNotSame($id, $this->getJson('/api/v1/ping')->headers->get('X-Request-Id'));
    }

    public function test_a_safe_incoming_id_is_reused_and_an_unsafe_one_is_replaced(): void
    {
        $this->withHeader('X-Request-Id', 'lb-abc123-xyz')->getJson('/api/v1/ping')->assertHeader('X-Request-Id', 'lb-abc123-xyz');

        $unsafe = $this->withHeader('X-Request-Id', "bad\nid")->getJson('/api/v1/ping')->headers->get('X-Request-Id');
        $this->assertNotSame("bad\nid", $unsafe);
    }

    public function test_request_log_line_carries_the_request_id_as_json(): void
    {
        config(['logging.log_requests' => true]);
        $handler = new TestHandler;
        $handler->setFormatter(new JsonFormatter);
        Log::getLogger()->setHandlers([$handler]);

        $id = $this->getJson('/api/v1/ping')->headers->get('X-Request-Id');

        $record = collect($handler->getRecords())->firstWhere('message', 'http.request');
        $this->assertNotNull($record);
        $this->assertSame($id, $record->context['request_id'] ?? $record->extra['request_id'] ?? null);
        $this->assertSame(200, $record->context['status']);
        $this->assertSame('/api/v1/ping', $record->context['path']);
        $this->assertJson($handler->getFormatter()->format($record));
    }
}
