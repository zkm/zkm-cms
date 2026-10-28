<?php

namespace App\Filters;

use App\Models\UserModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Lets signed-in admins through. The JSON API answers 401; pages redirect to the sign-in form.
 *
 * A session counts only while its user still exists, its session_version matches the
 * user's (raised on a password or email change, or "sign out everywhere"), and it is
 * younger than Config\Admin::$maxSessionAge.
 */
class AdminAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (self::currentUser() !== null) {
            return null;
        }

        if (str_starts_with(ltrim($request->getUri()->getPath(), '/'), 'admin/api')) {
            return service('response')->setStatusCode(401)->setJSON(['error' => 'Signed out. Sign in again.']);
        }

        return redirect()->to('/admin/login');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control', 'no-store');
        $response->setHeader('X-Robots-Tag', 'noindex');

        return $response;
    }

    /**
     * The signed-in user, or null (ending the session) if it is no longer valid.
     */
    public static function currentUser(): ?array
    {
        $session = session();
        $userId  = $session->get('user_id');

        if ($userId === null) {
            return null;
        }

        $user    = model(UserModel::class)->find($userId);
        $expired = time() - (int) $session->get('signed_in_at') > config('Admin')->maxSessionAge;

        if ($user === null || (int) $session->get('session_version') !== (int) $user['session_version'] || $expired) {
            $session->destroy();

            return null;
        }

        return $user;
    }
}
