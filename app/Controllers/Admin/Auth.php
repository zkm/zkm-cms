<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
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
        $ip = $this->request->getIPAddress();

        // Five tries a minute per address; fail2ban can watch the log line below.
        if (! service('throttler')->check('login-' . md5($ip), 5, MINUTE)) {
            log_message('warning', "Admin sign-in throttled for {$ip}");

            return $this->fail('Too many attempts. Wait a minute and try again.');
        }

        $email = strtolower(trim((string) $this->request->getPost('email')));
        $user  = model(UserModel::class)->where('email', $email)->first();
        $ok    = $user !== null && password_verify((string) $this->request->getPost('password'), $user['password_hash']);

        if ($ok && $user['totp_secret'] && ! Totp::verify($user['totp_secret'], (string) $this->request->getPost('code'))) {
            $ok = false;
        }

        // Same message as a wrong password, so this doesn't confirm the password was right.
        if ($ok && ! $user['totp_secret'] && config('Admin')->requireTotp) {
            log_message('warning', "Admin sign-in refused for {$ip}: two-factor is required and not set up for user {$user['id']}");
            $ok = false;
        }

        if (! $ok) {
            log_message('warning', "Admin sign-in failed for {$ip}");

            return $this->fail('That email, password or code is wrong.');
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            model(UserModel::class)->update($user['id'], ['password_hash' => password_hash((string) $this->request->getPost('password'), PASSWORD_DEFAULT)]);
        }

        session()->regenerate(true);
        // A fresh CSRF token for the signed-in session.
        session()->remove(config('Security')->tokenName);
        session()->set(['user_id' => (int) $user['id'], 'user_name' => $user['name']]);
        model(UserModel::class)->update($user['id'], ['last_login_at' => date('Y-m-d H:i:s')]);

        return redirect()->to('/admin/');
    }

    public function logout()
    {
        session()->destroy();

        return redirect()->to('/admin/login');
    }

    private function fail(string $message)
    {
        return redirect()->to('/admin/login')->withInput()->with('login_error', $message);
    }
}
