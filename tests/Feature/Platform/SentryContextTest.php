<?php

namespace Tests\Feature\Platform;

use App\Models\Customer;
use App\Support\SentryBeforeSend;
use Illuminate\Http\Request;
use Sentry\Event;
use Tests\TestCase;

class SentryContextTest extends TestCase
{
    public function test_adds_request_id_and_user_id_only_and_strips_personal_data(): void
    {
        $customer = new Customer(['name' => 'Sok Dara', 'email' => 'dara@example.com']);
        $customer->id = 42;
        $request = Request::create('/api/v1/checkout', 'POST');
        $request->attributes->set('request_id', 'req-12345678');
        $this->app->instance('request', $request);
        // Binding the request resets its user resolver, so set ours afterwards.
        $request->setUserResolver(fn () => $customer);

        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://api.test/api/v1/checkout',
            'headers' => ['authorization' => 'Bearer secret', 'accept' => 'application/json'],
            'cookies' => ['session' => 'x'],
            'data' => ['password' => 'secret'],
        ]);

        $result = SentryBeforeSend::handle($event);

        $this->assertSame('req-12345678', $result->getTags()['request_id']);
        $this->assertSame('customer', $result->getTags()['user_type']);
        $this->assertSame('42', $result->getUser()->getId());
        $this->assertNull($result->getUser()->getEmail());
        $this->assertNull($result->getUser()->getIpAddress());
        $this->assertSame(['accept' => 'application/json'], $result->getRequest()['headers']);
        $this->assertArrayNotHasKey('cookies', $result->getRequest());
        $this->assertArrayNotHasKey('data', $result->getRequest());
    }

    public function test_sentry_is_off_without_a_dsn(): void
    {
        $this->assertEmpty(config('sentry.dsn'));
        $this->getJson('/api/v1/ping')->assertOk();
    }
}
