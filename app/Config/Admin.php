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
}
