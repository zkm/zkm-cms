<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Admin sign-in settings. Override in .env, e.g. `admin.requireTotp = false`.
 */
class Admin extends BaseConfig
{
    /**
     * Refuse sign-in for users without two-factor (an authenticator code) set up.
     * On by default in production, because the login page's address is public.
     */
    public bool $requireTotp = ENVIRONMENT === 'production';

    /**
     * Seconds a sign-in lasts, however active it is. (Idle sessions end sooner,
     * after Config\Session::$expiration.)
     */
    public int $maxSessionAge = 12 * HOUR;

    /**
     * Who may reach /admin at all (see App\Filters\AdminGate). Empty means no restriction.
     * Comma-separated addresses or CIDR ranges, e.g. "203.0.113.7, 2001:db8::/48".
     */
    public string $allowedIps = '';

    /**
     * Cloudflare Access in front of /admin: the team name (or <team>.cloudflareaccess.com)
     * and the application's Audience (AUD) tag. Both set means every admin request needs
     * a valid Access token.
     */
    public string $accessTeam = '';

    public string $accessAud = '';

    /**
     * Optionally, only these Access identities (comma-separated emails).
     */
    public string $accessEmails = '';
}
