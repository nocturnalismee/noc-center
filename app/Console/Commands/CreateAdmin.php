<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'noc:create-admin
        {--name= : The administrator name}
        {--email= : The administrator email address}
        {--password= : The administrator password}
        {--force : Create the administrator even when users already exist}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create the first administrator account';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (User::query()->exists() && ! $this->option('force')) {
            $this->error('A user already exists. Use --force to create another administrator.');

            return self::FAILURE;
        }

        $email = strtolower(trim((string) $this->option('email')));

        $validator = Validator::make([
            'name' => $this->option('name'),
            'email' => $email,
            'password' => $this->option('password'),
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(12)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $data = $validator->validated();

        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $user->forceFill([
            'role' => UserRole::Admin,
            'is_active' => true,
            'email_verified_at' => now(),
        ])->save();

        $this->info("Administrator {$user->email} created.");

        return self::SUCCESS;
    }
}
