<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use App\Support\Auth\TwoFactor;
use Illuminate\Console\Command;

/** For a locked-out administrator with no phone and no recovery codes: run on the server. */
class TwoFactorReset extends Command
{
    protected $signature = 'hotel:2fa-reset {login : E-mail address or username}';

    protected $description = 'Turn off two-step sign-in for one account (lost phone and recovery codes)';

    public function handle(): int
    {
        $login = trim($this->argument('login'));
        $user = User::where('email', $login)->orWhere('username', $login)->first();
        if (! $user) {
            $this->error("No account found for {$login}.");

            return self::FAILURE;
        }
        if (! TwoFactor::enabled($user)) {
            $this->info("{$user->name} does not use two-step sign-in.");

            return self::SUCCESS;
        }
        TwoFactor::disable($user);
        Audit::record('auth.2fa_reset', "Two-step sign-in reset for {$user->name} from the server command line", $user, reason: 'hotel:2fa-reset', flagged: true);
        $this->info("Done. {$user->name} can sign in with their password" . (TwoFactor::required($user) ? ' and will be asked to set up two-step sign-in again.' : '.'));

        return self::SUCCESS;
    }
}
