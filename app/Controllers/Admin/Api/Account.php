<?php

namespace App\Controllers\Admin\Api;

use App\Filters\AdminAuth;
use App\Libraries\SecretBox;
use App\Libraries\Totp;
use App\Models\UserModel;

/**
 * The signed-in user's own account: password, login email, and signing out other sessions.
 *
 * Each change asks for the current password (and the authenticator code when two-factor
 * is on), so a stolen session alone can't take the account over. Each one also raises
 * session_version, which signs out every other session.
 */
class Account extends ApiController
{
    public function show()
    {
        $user = AdminAuth::currentUser();

        return $this->ok([
            'name'        => $user['name'],
            'email'       => $user['email'],
            'twoFactor'   => $user['totp_secret'] !== null,
            'lastLoginAt' => $user['last_login_at'],
        ]);
    }

    public function password()
    {
        $data = $this->input();
        $user = AdminAuth::currentUser();

        if (($error = $this->confirm($user, $data)) !== null) {
            return $error;
        }

        $new = (string) ($data['new_password'] ?? '');

        if (strlen($new) < 12) {
            return $this->error('Use at least 12 characters.', 422, ['new_password' => 'Too short']);
        }

        if ($new !== (string) ($data['confirm_password'] ?? '')) {
            return $this->error('The new passwords do not match.', 422, ['confirm_password' => 'Mismatch']);
        }

        if (password_verify($new, $user['password_hash'])) {
            return $this->error('That is your current password.', 422, ['new_password' => 'Unchanged']);
        }

        $this->saveAndSignOutOthers($user, ['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);
        log_message('info', "Admin password changed for user {$user['id']} from {$this->request->getIPAddress()}");

        return $this->ok(['ok' => true, 'message' => 'Password changed. Other sessions were signed out.']);
    }

    public function email()
    {
        $data  = $this->input();
        $user  = AdminAuth::currentUser();
        $email = strtolower(trim((string) ($data['email'] ?? '')));

        if (($error = $this->confirm($user, $data)) !== null) {
            return $error;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
            return $this->error('That is not an email address.', 422, ['email' => 'Invalid']);
        }

        if (model(UserModel::class)->where('email', $email)->where('id !=', $user['id'])->first() !== null) {
            return $this->error('Another user has that email.', 422, ['email' => 'Taken']);
        }

        $this->saveAndSignOutOthers($user, ['email' => $email]);
        log_message('info', "Admin sign-in email changed for user {$user['id']} from {$this->request->getIPAddress()}");

        return $this->ok(['ok' => true, 'email' => $email, 'message' => 'Sign-in email changed. Other sessions were signed out.']);
    }

    public function signOutOthers()
    {
        $data = $this->input();
        $user = AdminAuth::currentUser();

        if (($error = $this->confirm($user, $data)) !== null) {
            return $error;
        }

        $this->saveAndSignOutOthers($user, []);

        return $this->ok(['ok' => true, 'message' => 'Every other session was signed out.']);
    }

    /**
     * Check the current password (and code), with its own rate limit.
     */
    private function confirm(array $user, array $data)
    {
        if (! service('throttler')->check('reauth-' . $user['id'], 5, 15 * MINUTE)) {
            return $this->error('Too many attempts. Wait a few minutes and try again.', 429);
        }

        if (! password_verify((string) ($data['current_password'] ?? ''), $user['password_hash'])) {
            log_message('warning', "Admin re-authentication failed for {$this->request->getIPAddress()}");

            return $this->error('Your current password is wrong.', 422, ['current_password' => 'Wrong']);
        }

        if ($user['totp_secret'] !== null) {
            $step = Totp::matchStep(SecretBox::open($user['totp_secret']), (string) ($data['code'] ?? ''));

            if ($step === null || ($user['totp_last_step'] !== null && $step <= (int) $user['totp_last_step'])) {
                return $this->error('That authenticator code is wrong or already used.', 422, ['code' => 'Wrong']);
            }

            model(UserModel::class)->update($user['id'], ['totp_last_step' => $step]);
        }

        return null;
    }

    /**
     * Save the change and raise session_version; this session moves to the new version.
     */
    private function saveAndSignOutOthers(array $user, array $changes): void
    {
        $version = (int) $user['session_version'] + 1;
        model(UserModel::class)->update($user['id'], $changes + ['session_version' => $version]);

        session()->regenerate(true);
        session()->set(['session_version' => $version]);
    }
}
