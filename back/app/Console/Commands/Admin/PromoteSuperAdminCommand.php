<?php

namespace App\Console\Commands\Admin;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * is_super_admin is deliberately not mass-assignable / not exposed over
 * HTTP -- this command is the only way to grant it.
 */
class PromoteSuperAdminCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'admin:promote {email}';

    /**
     * @var string
     */
    protected $description = 'Grant super admin access to an existing user by email';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No user with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $user->forceFill(['is_super_admin' => true])->save();
        $this->info("{$user->email} is now a super admin.");

        return self::SUCCESS;
    }
}
