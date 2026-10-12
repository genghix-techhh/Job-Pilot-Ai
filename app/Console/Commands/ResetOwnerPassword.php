<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\password;

class ResetOwnerPassword extends Command
{
    protected $signature = 'app:reset-owner-password';

    protected $description = 'Set a new password for the owner (there is no e-mail reset flow in this private app)';

    public function handle(): int
    {
        $user = User::query()->first();
        if (! $user) {
            $this->error('No owner exists yet. Run app:create-owner first.');

            return self::FAILURE;
        }

        $pw = getenv('JOBPILOT_OWNER_PASSWORD') ?: null;
        if ($pw === null) {
            if (! $this->input->isInteractive()) {
                $this->error('Run interactively, or provide JOBPILOT_OWNER_PASSWORD.');

                return self::FAILURE;
            }
            $pw = password('New password (min. 12 characters)', required: true);
            if ($pw !== password('Repeat the new password', required: true)) {
                $this->error('The passwords do not match.');

                return self::FAILURE;
            }
        }
        if (mb_strlen($pw) < 12) {
            $this->error('The password must be at least 12 characters.');

            return self::FAILURE;
        }

        $user->forceFill(['password' => $pw])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $this->info("Password updated for {$user->email}. All sessions were signed out.");

        return self::SUCCESS;
    }
}
