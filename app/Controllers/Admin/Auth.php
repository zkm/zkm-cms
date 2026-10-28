<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\SecretBox;
use App\Libraries\Totp;
use App\Models\UserModel;

class Auth extends BaseController
{
    public function login()
    {
        if (session('user_id') !== null) {
            return redirect()->to('/admin/');
        }

        return $this->response->setHeader('Cache-Control', 'no-store')
            ->setBody(view('admin/login', [
                'error'       => session('login_error'),
                'email'       => old('email'),
                'requireTotp' => config('Admin')->requireTotp,
            ]));
    }

    public function attempt()
    {
        $ip       = $this->request->getIPAddress();
        $email    = strtolower(trim((string) $this->request->getPost('email')));
        $password = (string) $this->request->getPost('password');

        // Five tries a minute per address, and ten per 15 minutes per account so that
        // spreading guesses over many addresses doesn't help. fail2ban watches these log lines.
        $throttler = service('throttler');

        if (! $throttler->check('login-' . md5($ip), 5, MINUTE) || ! $throttler->check('login-user-' . md5($email), 10, 15 * MINUTE)) {
            log_message('warning', "Admin sign-in throttled for {$ip}");

            return $this->fail('Too many attempts. Wait a few minutes and try again.');
        }

        $users = model(UserModel::class);
        $user  = $email !== '' ? $users->where('email', $email)->first() : null;

        // Check a password even when the email is unknown, so the response time doesn't
        // reveal which emails have accounts.
        $ok = password_verify($password, $user['password_hash'] ?? self::dummyHash()) && $user !== null;

        $secret = $user !== null && $user['totp_secret'] ? SecretBox::open($user['totp_secret']) : null;
        $step   = null;

        if ($ok && $secret !== null) {
            $step = Totp::matchStep($secret, (string) $this->request->getPost('code'));

            // A code works once: refuse its time step, and earlier ones, after it's been used.
            if ($step === null || ($user['totp_last_step'] !== null && $step <= (int) $user['totp_last_step'])) {
                $ok = false;
            }
        }

        // Same message as a wrong password, so this doesn't confirm the password was right.
        if ($ok && $secret === null && config('Admin')->requireTotp) {
            log_message('warning', "Admin sign-in refused for {$ip}: two-factor is required and not set up for user {$user['id']}");
            $ok = false;
        }

        if (! $ok) {
            log_message('warning', "Admin sign-in failed for {$ip}");

            return $this->fail('That email, password or code is wrong.');
        }

        $changes = ['last_login_at' => date('Y-m-d H:i:s')];

        if ($step !== null) {
            $changes['totp_last_step'] = $step;
        }

        // Secrets saved before encryption was added get encrypted now.
        if ($secret !== null && ! SecretBox::isSealed($user['totp_secret'])) {
            $changes['totp_secret'] = SecretBox::seal($secret);
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $changes['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $users->update($user['id'], $changes);

        session()->regenerate(true);
        // A fresh CSRF token for the signed-in session.
        session()->remove(config('Security')->tokenName);
        session()->set(['user_id' => (int) $user['id'], 'user_name' => $user['name']]);
        log_message('info', "Admin sign-in for user {$user['id']} from {$ip}");

        return redirect()->to('/admin/');
    }

    public function logout()
    {
        session()->destroy();

        return redirect()->to('/admin/login');
    }

    /**
     * A hash with the same cost as real ones, for verifying against when no user matched.
     * It's cached, because making it on each request would itself make unknown emails slower.
     */
    private static function dummyHash(): string
    {
        $hash = cache('login_dummy_hash');

        if (! is_string($hash) || password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            cache()->save('login_dummy_hash', $hash, 0);
        }

        return $hash;
    }

    private function fail(string $message)
    {
        return redirect()->to('/admin/login')->withInput()->with('login_error', $message);
    }
}
