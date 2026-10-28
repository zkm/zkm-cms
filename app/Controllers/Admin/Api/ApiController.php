<?php

namespace App\Controllers\Admin\Api;

use App\Controllers\BaseController;
use App\Libraries\SiteCache;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Base for the admin JSON API. Every route is behind the adminauth filter and CSRF.
 */
abstract class ApiController extends BaseController
{
    protected $helpers = ['site', 'url', 'text'];

    /**
     * The decoded JSON body.
     */
    protected function input(): array
    {
        $data = $this->request->getJSON(true);

        return is_array($data) ? $data : [];
    }

    protected function ok(mixed $data = ['ok' => true], int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON($data);
    }

    protected function error(string $message, int $status = 422, array $fields = []): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON(['error' => $message, 'fields' => (object) $fields]);
    }

    /**
     * Something on the public site changed: drop the cached pages.
     */
    protected function changed(): void
    {
        SiteCache::flush();
    }

    protected function userId(): ?int
    {
        return session('user_id');
    }
}
