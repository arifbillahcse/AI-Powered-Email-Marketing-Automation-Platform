<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateSuperAdmin extends Command
{
    protected $signature = 'app:create-super-admin
                            {--name= : Full name}
                            {--email= : Login email}
                            {--password= : Password (prompted when omitted)}';

    protected $description = 'Create a platform super admin who can access /admin and Horizon';

    public function handle(): int
    {
        $data = [
            'name' => $this->option('name') ?? text('Name', required: true),
            'email' => $this->option('email') ?? text('Email', required: true),
            'password' => $this->option('password') ?? password('Password', required: true),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = new User($data);
        $user->is_super_admin = true;
        $user->email_verified_at = now();
        $user->save();

        $this->components->info("Super admin [{$user->email}] created. Sign in at ".url('/admin'));

        return self::SUCCESS;
    }
}
