<?php

namespace App\Commands;

use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class UserEmail extends BaseCommand
{
    protected $group       = 'Users';
    protected $name        = 'user:email';
    protected $description = 'Change the email an admin signs in with. Signs that user out everywhere.';
    protected $usage       = 'user:email <current-email> <new-email>';

    public function run(array $params)
    {
        $users = model(UserModel::class);
        $user  = $users->where('email', strtolower(trim($params[0] ?? '')))->first();
        $email = strtolower(trim($params[1] ?? ''));

        if ($user === null) {
            CLI::error('No user with that email.');

            return EXIT_USER_INPUT;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            CLI::error('The new email is not an email address.');

            return EXIT_USER_INPUT;
        }

        if ($users->where('email', $email)->first() !== null) {
            CLI::error('Another user already has that email.');

            return EXIT_USER_INPUT;
        }

        $users->update($user['id'], ['email' => $email, 'session_version' => (int) $user['session_version'] + 1]);
        CLI::write("{$user['email']} now signs in as {$email}.", 'green');

        return EXIT_SUCCESS;
    }
}
