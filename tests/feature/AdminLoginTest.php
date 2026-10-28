<?php

use App\Libraries\Totp;
use App\Models\UserModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Admin;

/**
 * @internal
 */
final class AdminLoginTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private const PASSWORD = 'correct horse battery';

    protected function setUp(): void
    {
        parent::setUp();
        // CSRF is covered by the framework; these tests are about the sign-in rules.
        config('Filters')->filters = [];
    }

    private function requireTotp(bool $required): void
    {
        $config              = new Admin();
        $config->requireTotp = $required;
        Factories::injectMock('config', 'Admin', $config);
    }

    private function user(?string $secret): void
    {
        model(UserModel::class)->insert([
            'email' => 'me@example.com', 'name' => 'Me',
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'totp_secret' => $secret,
        ]);
    }

    private function signIn(string $code = '')
    {
        return $this->post('/admin/login', ['email' => 'me@example.com', 'password' => self::PASSWORD, 'code' => $code]);
    }

    public function testWithoutTwoFactorSignInIsRefusedWhenRequired(): void
    {
        $this->requireTotp(true);
        $this->user(null);

        $this->signIn()->assertRedirectTo('/admin/login');
        $this->assertNull(session('user_id'));
    }

    public function testWithTwoFactorSignInNeedsTheCurrentCode(): void
    {
        $this->requireTotp(true);
        $secret = Totp::newSecret();
        $this->user($secret);

        $this->signIn('000000' === Totp::code($secret, intdiv(time(), 30)) ? '111111' : '000000')->assertRedirectTo('/admin/login');
        $this->signIn(Totp::code($secret, intdiv(time(), 30)))->assertRedirectTo('/admin/');
    }

    public function testPasswordAloneWorksWhenNotRequired(): void
    {
        $this->requireTotp(false);
        $this->user(null);

        $this->signIn()->assertRedirectTo('/admin/');
    }
}
