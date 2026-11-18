<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class OperatorAccess extends Command
{
    protected $signature = 'operator:access {user_id : Existing user ID} {--revoke : Remove reporting access}';

    protected $description = 'Grant or revoke operator reports for an existing account; never creates an account';

    public function handle(): int
    {
        $id = (string) $this->argument('user_id');
        if (! ctype_digit($id) || ! ($user = User::find($id))) {
            $this->error('Existing numeric user ID required.');

            return self::FAILURE;
        }
        $user->forceFill(['is_operator' => ! $this->option('revoke')])->save();
        $this->info($this->option('revoke') ? 'Operator access revoked.' : 'Operator access granted.');

        return self::SUCCESS;
    }
}
