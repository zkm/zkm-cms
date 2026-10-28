<?php

namespace App\Commands;

use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class UserCreate extends BaseCommand
{
    protected $group       = 'Users';
    protected $name        = 'user:create';
    protected $description = 'Create an admin user, or reset the password of an existing one.';
    protected $usage       = 'user:create <email> [name]';
    protected $options     = ['--password-stdin' => 'Read the password from standard input instead of prompting'];

    public function run(array $params)
    {
        $email = strtolower(trim($params[0] ?? CLI::prompt('Email')));
        $name  = $params[1] ?? null;

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            CLI::error('That is not an email address.');

            return EXIT_USER_INPUT;
        }

        $password = CLI::getOption('password-stdin') ? trim((string) fgets(STDIN)) : $this->askPassword();

        if (strlen($password) < 12) {
            CLI::error('Use at least 12 characters.');

            return EXIT_USER_INPUT;
        }

        $users    = model(UserModel::class);
        $existing = $users->where('email', $email)->first();
        $hash     = password_hash($password, PASSWORD_DEFAULT);

        if ($existing !== null) {
            $users->update($existing['id'], ['password_hash' => $hash] + ($name ? ['name' => $name] : []));
            CLI::write("Password updated for {$email}.", 'green');
        } else {
            $users->insert(['email' => $email, 'name' => $name ?? strstr($email, '@', true), 'password_hash' => $hash]);
            CLI::write("Created {$email}.", 'green');
            CLI::write(config('Admin')->requireTotp
                ? "Two-factor sign-in is required here. Set it up before signing in: php spark user:totp {$email}"
                : "Turn on two-factor sign-in with: php spark user:totp {$email}");
        }

        return EXIT_SUCCESS;
    }

    private function askPassword(): string
    {
        $tty = stream_isatty(STDIN);

        if ($tty) {
            shell_exec('stty -echo');
        }

        try {
            CLI::print('Password: ');
            $first = trim((string) fgets(STDIN));
            CLI::newLine();
            CLI::print('Again: ');
            $second = trim((string) fgets(STDIN));
            CLI::newLine();
        } finally {
            if ($tty) {
                shell_exec('stty echo');
            }
        }

        if ($first !== $second) {
            CLI::error('The passwords do not match.');
            exit(EXIT_USER_INPUT);
        }

        return $first;
    }
}
