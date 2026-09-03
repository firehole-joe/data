<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminUserCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'user:create-admin
        {email : The email address of the administrator}
        {--name=Admin : Display name to use when creating a new user}';

    /**
     * @var string
     */
    protected $description = 'Create a new feed administrator, or elevate an existing user to admin.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $name = trim((string) $this->option('name')) ?: 'Admin';

        $validator = Validator::make(
            ['email' => $email, 'name' => $name],
            ['email' => ['required', 'email'], 'name' => ['required', 'string', 'max:255']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            if ($existing->is_admin && ! $this->confirm("{$email} is already an admin. Reset their password anyway?", false)) {
                $this->components->info('No changes made.');

                return self::SUCCESS;
            }

            $existing->forceFill([
                'is_admin' => true,
                'password' => Hash::make($this->promptForPassword()),
            ])->save();

            $this->components->info("Elevated {$email} to feed administrator.");

            return self::SUCCESS;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($this->promptForPassword()),
            'is_admin' => true,
        ]);

        $this->components->info("Created feed administrator {$email}.");

        return self::SUCCESS;
    }

    /**
     * Read the password twice from hidden input and require a match.
     */
    private function promptForPassword(): string
    {
        while (true) {
            $password = (string) $this->secret('Password (min 8 characters)');
            $confirm = (string) $this->secret('Confirm password');

            if (strlen($password) < 8) {
                $this->components->error('Password must be at least 8 characters.');

                continue;
            }

            if ($password !== $confirm) {
                $this->components->error('The passwords did not match. Try again.');

                continue;
            }

            return $password;
        }
    }
}
