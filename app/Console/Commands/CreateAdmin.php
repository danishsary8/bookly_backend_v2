<?php

namespace App\Console\Commands;

use App\Enums\StaffRole;
use App\Models\StaffUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'staff:create-admin {--name= : Full name} {--email= : Login email}';

    protected $description = 'Create an admin staff account (password is asked for, never passed as an option)';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = strtolower((string) ($this->option('email') ?: $this->ask('Email')));
        $password = $this->secret('Password (min 8 characters, letters and numbers)');
        $confirm = $this->secret('Confirm password');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirm],
            [
                'name' => ['required', 'string', 'max:150'],
                'email' => ['required', 'email', 'max:190', 'unique:staff_users,email'],
                'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        StaffUser::create([
            'name' => $name,
            'email' => $email,
            'password_hash' => $password,
            'role' => StaffRole::Admin,
        ]);

        $this->info("Admin {$email} created. Log in at POST /api/v1/staff/auth/login and set up 2FA before using admin features.");

        return self::SUCCESS;
    }
}
