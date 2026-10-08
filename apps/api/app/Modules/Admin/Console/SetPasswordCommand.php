<?php

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;

/**
 * Sets a user's password: the way back in for someone who lost theirs, since the mail that
 * would reset it may not be set up. The password comes from an environment variable or from
 * the prompt, never from the command line, so it never lands in a shell history or a log.
 */
final class SetPasswordCommand extends Command
{
    protected $signature = 'admin:password
        {email : The user\'s email address}
        {--password-env= : Name of an environment variable holding the new password}';

    protected $description = 'Set a user\'s password, from an environment variable or the prompt';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("No user {$email}.");

            return self::FAILURE;
        }

        if (filled($variable = $this->option('password-env'))) {
            // getenv, not env(): with the config cached, env() returns null outside config files.
            $secret = (string) getenv((string) $variable);
        } elseif ($this->input->isInteractive()) {
            $secret = password(
                label: 'New password',
                validate: fn (string $v) => mb_strlen($v) >= User::MIN_PASSWORD_LENGTH ? null : 'At least '.User::MIN_PASSWORD_LENGTH.' characters.',
            );
        } else {
            $this->components->error('Give the password in an environment variable: --password-env=NAME.');

            return self::FAILURE;
        }

        if (mb_strlen($secret) < User::MIN_PASSWORD_LENGTH) {
            $this->components->error('The password must be at least '.User::MIN_PASSWORD_LENGTH.' characters.');

            return self::FAILURE;
        }

        $user->forceFill(['password' => $secret])->save();
        $this->components->info("The password of {$email} is set.");

        return self::SUCCESS;
    }
}
