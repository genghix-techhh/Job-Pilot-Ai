<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateOwner extends Command
{
    protected $signature = 'app:create-owner
        {--name= : Display name}
        {--email= : Login e-mail address}
        {--timezone= : IANA time zone (default from JOBPILOT_TIMEZONE)}';

    protected $description = 'Create the single owner account (only possible while no user exists)';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('An owner already exists. JobPilot AI is a single-owner app; use app:reset-owner-password to change the password.');

            return self::FAILURE;
        }

        $interactive = $this->input->isInteractive();
        $name = $this->option('name') ?: ($interactive ? text('Your name', required: true) : null);
        $email = $this->option('email') ?: ($interactive ? text('Login e-mail', required: true) : null);
        $timezone = $this->option('timezone') ?: config('jobpilot.timezone');

        // The password is never accepted as a command-line argument (it would land in shell history).
        // Non-interactive installs may provide it through the JOBPILOT_OWNER_PASSWORD environment variable.
        $pw = getenv('JOBPILOT_OWNER_PASSWORD') ?: null;
        if ($pw === null && $interactive) {
            $pw = password('Password (min. 12 characters)', required: true);
            $confirm = password('Repeat the password', required: true);
            if ($pw !== $confirm) {
                $this->error('The passwords do not match.');

                return self::FAILURE;
            }
        }

        $v = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $pw, 'timezone' => $timezone],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email:rfc', 'max:190'],
                'password' => ['required', 'string', 'min:12', 'max:200'],
                'timezone' => ['required', 'timezone:all'],
            ],
            ['password.required' => 'No password supplied. Run interactively, or set JOBPILOT_OWNER_PASSWORD.']
        );
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        User::create(['name' => $name, 'email' => $email, 'password' => $pw, 'timezone' => $timezone]);
        $this->info("Owner {$email} created. Sign in at ".url('/login').'.');

        return self::SUCCESS;
    }
}
