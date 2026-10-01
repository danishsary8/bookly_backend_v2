<?php

namespace Tests\Concerns;

use App\Models\StaffUser;

trait ActsAsStaff
{
    protected function staffToken(bool $admin = false): string
    {
        $factory = StaffUser::factory()->withTwoFactor();
        $staff = ($admin ? $factory->admin() : $factory)->create();

        return $staff->createToken('test', $staff->tokenAbilities())->plainTextToken;
    }

    /** Laravel keeps the resolved user between requests in one test; reset it when switching tokens. */
    protected function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
