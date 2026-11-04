<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Notifications\AccountSecurityNotice;
use App\Services\Auth\KnownDevices;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NewDeviceEmailTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function signIn(string $userAgent, string $ip = '203.0.113.7', string $email = 'dara@example.com', string $password = 'Secret123'): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['User-Agent' => $userAgent])->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->assertOk();
    }

    private function newDeviceEmails(Customer $customer): int
    {
        return Notification::sent($customer, AccountSecurityNotice::class, fn ($n) => $n->key === 'new_device')->count();
    }

    public function test_the_first_device_is_only_recorded_and_a_new_one_sends_an_email(): void
    {
        $dara = Customer::factory()->create(['email' => 'dara@example.com', 'password_hash' => 'Secret123', 'email_verified_at' => now()]);

        $this->signIn(self::CHROME_WINDOWS);
        $this->signIn(self::CHROME_WINDOWS); // same device again
        $this->assertSame(0, $this->newDeviceEmails($dara));

        $this->signIn(self::SAFARI_IPHONE, '198.51.100.9');
        $this->assertSame(1, $this->newDeviceEmails($dara));
        Notification::assertSentTo($dara, AccountSecurityNotice::class, function (AccountSecurityNotice $n) use ($dara) {
            $mail = $n->toMail($dara);

            return $n->key === 'new_device'
                && $mail->subject === 'New sign-in to your Bookly account'
                && str_contains($mail->introLines[0], 'Safari on iPhone')
                && str_contains($mail->introLines[0], '198.51.100.9');
        });

        $this->signIn(self::SAFARI_IPHONE, '198.51.100.10'); // now known
        $this->assertSame(1, $this->newDeviceEmails($dara));
        $this->assertSame(['Chrome on Windows', 'Safari on iPhone'], DB::table('customer_known_devices')->where('customer_id', $dara->id)->orderBy('device')->pluck('device')->all());
    }

    public function test_a_wrong_password_records_nothing_and_sends_nothing(): void
    {
        $dara = Customer::factory()->create(['email' => 'dara@example.com', 'password_hash' => 'Secret123', 'email_verified_at' => now()]);
        $this->signIn(self::CHROME_WINDOWS);

        $this->withHeaders(['User-Agent' => self::SAFARI_IPHONE])->postJson('/api/v1/auth/login', ['email' => 'dara@example.com', 'password' => 'Wrong1234'])->assertUnauthorized();

        $this->assertSame(0, $this->newDeviceEmails($dara));
        $this->assertSame(1, DB::table('customer_known_devices')->where('customer_id', $dara->id)->count());
    }

    public function test_the_shared_demo_account_never_gets_these_emails(): void
    {
        $this->seed(DemoSeeder::class);
        $demo = Customer::where('email', DemoSeeder::EMAIL)->firstOrFail();

        $this->signIn(self::CHROME_WINDOWS, email: DemoSeeder::EMAIL, password: DemoSeeder::PASSWORD);
        $this->signIn(self::SAFARI_IPHONE, email: DemoSeeder::EMAIL, password: DemoSeeder::PASSWORD);

        $this->assertSame(0, $this->newDeviceEmails($demo));
        $this->assertSame(0, DB::table('customer_known_devices')->where('customer_id', $demo->id)->count());
    }

    public function test_sign_ins_that_email_on_their_own_only_record_the_device(): void
    {
        $dara = Customer::factory()->create(['email' => 'dara@example.com', 'email_verified_at' => now()]);
        $devices = app(KnownDevices::class);

        $devices->signedIn($dara, self::CHROME_WINDOWS, '203.0.113.7');
        $devices->signedIn($dara, self::SAFARI_IPHONE, '198.51.100.9', email: false); // e.g. Telegram

        $this->assertSame(0, $this->newDeviceEmails($dara));
        $this->assertSame(2, DB::table('customer_known_devices')->where('customer_id', $dara->id)->count());
    }

    public function test_only_the_30_most_recent_devices_are_kept(): void
    {
        $dara = Customer::factory()->create(['email_verified_at' => now()]);
        foreach (range(1, KnownDevices::MAX_PER_CUSTOMER + 4) as $i) {
            DB::table('customer_known_devices')->insert(['customer_id' => $dara->id, 'device' => "Old device {$i}", 'first_seen_at' => now()->subDays(100 - $i), 'last_seen_at' => now()->subDays(100 - $i)]);
        }

        app(KnownDevices::class)->signedIn($dara, self::CHROME_WINDOWS, null, email: false);

        $kept = DB::table('customer_known_devices')->where('customer_id', $dara->id)->pluck('device');
        $this->assertCount(KnownDevices::MAX_PER_CUSTOMER, $kept);
        $this->assertContains('Chrome on Windows', $kept);
        $this->assertNotContains('Old device 1', $kept); // the oldest went
    }
}
