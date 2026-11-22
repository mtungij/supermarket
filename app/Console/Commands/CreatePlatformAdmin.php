<?php

namespace App\Console\Commands;

use App\Enums\SystemRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * The ONLY supported way to create a Platform Super Admin.
 * Public registration can never produce one.
 */
class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin {--name=} {--email=}';

    protected $description = 'Create a Platform Super Admin account';

    public function handle(): int
    {
        $name = $this->option('name') ?: text('Name', required: true);
        $email = Str::lower($this->option('email') ?: text('Email', required: true));
        $secret = password('Password', required: true);

        $validator = Validator::make(compact('name', 'email') + ['password' => $secret], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(10)->letters()->mixedCase()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $role = Role::system(SystemRole::PlatformSuperAdmin->value);

        $user = new User(['name' => $name, 'email' => $email, 'password' => $secret]);
        $user->forceFill([
            'is_active' => true,
            'platform_role_id' => $role->id,
            'email_verified_at' => now(),
        ])->save();

        $this->info("Platform Super Admin {$user->email} created.");

        return self::SUCCESS;
    }
}
