<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Lets signed-in admins through. The JSON API answers 401; pages redirect to the sign-in form.
 */
class AdminAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (session('user_id') !== null) {
            return null;
        }

        if (str_starts_with($request->getUri()->getPath(), '/admin/api') || str_starts_with(ltrim($request->getUri()->getPath(), '/'), 'admin/api')) {
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
}
