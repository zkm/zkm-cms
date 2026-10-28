<?php

namespace App\Commands;

use App\Libraries\SecretBox;
use App\Libraries\Totp;
use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class UserTotp extends BaseCommand
{
    protected $group       = 'Users';
    protected $name        = 'user:totp';
    protected $description = 'Turn on two-factor sign-in (an authenticator app code) for a user.';
    protected $usage       = 'user:totp <email> [--disable]';
    protected $options     = ['--disable' => 'Turn it off instead'];

    public function run(array $params)
    {
        $users = model(UserModel::class);
        $user  = $users->where('email', strtolower(trim($params[0] ?? '')))->first();

        if ($user === null) {
            CLI::error('No user with that email.');

            return EXIT_USER_INPUT;
        }

        if (CLI::getOption('disable')) {
            $users->update($user['id'], ['totp_secret' => null, 'totp_last_step' => null]);
            CLI::write('Two-factor sign-in is off.', 'yellow');

            return EXIT_SUCCESS;
        }

        $secret = Totp::newSecret();
        CLI::write('Add this to your authenticator app (most can read the otpauth link as a QR code):');
        CLI::write('  Secret: ' . $secret);
        CLI::write('  Link:   ' . Totp::uri($secret, $user['email']));

        $code = CLI::prompt('Enter the 6-digit code it shows to confirm');

        if (! Totp::verify($secret, $code)) {
            CLI::error('That code did not match. Nothing was changed.');

            return EXIT_ERROR;
        }

        $users->update($user['id'], ['totp_secret' => SecretBox::seal($secret), 'totp_last_step' => Totp::matchStep($secret, $code)]);
        CLI::write('Two-factor sign-in is on.', 'green');

        return EXIT_SUCCESS;
    }
}
