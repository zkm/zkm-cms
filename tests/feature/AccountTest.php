<?php

use App\Models\UserModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Admin;
use Config\Services;

/**
 * Changing the password or email, and ending other sessions.
 *
 * @internal
 */
final class AccountTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private const PASSWORD = 'correct horse battery';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('throttler');
        config('Filters')->filters = [];

        $admin              = new Admin();
        $admin->requireTotp = false;
        Factories::injectMock('config', 'Admin', $admin);

        $this->userId = (int) model(UserModel::class)->insert([
            'email' => 'me@example.com', 'name' => 'Me', 'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
        ]);
    }

    /**
     * A session as if signed in at $age seconds ago with the given session_version.
     */
    private function asSession(int $version = 1, int $age = 60): self
    {
        Services::resetSingle('filters');
        Services::resetSingle('response');

        return $this->withSession(['user_id' => $this->userId, 'user_name' => 'Me', 'session_version' => $version, 'signed_in_at' => time() - $age]);
    }

    private function code($result): int
    {
        return $result->response()->getStatusCode();
    }

    private function signIn(string $email, string $password)
    {
        Services::resetSingle('filters');
        $_SESSION = [];

        return $this->post('/admin/login', ['email' => $email, 'password' => $password]);
    }

    public function testChangingThePasswordNeedsTheCurrentOne(): void
    {
        $wrong = $this->asSession()->withBodyFormat('json')->put('/admin/api/account/password', ['current_password' => 'nope', 'new_password' => 'a brand new passphrase', 'confirm_password' => 'a brand new passphrase']);
        $this->assertSame(422, $this->code($wrong));

        $short = $this->asSession()->withBodyFormat('json')->put('/admin/api/account/password', ['current_password' => self::PASSWORD, 'new_password' => 'short', 'confirm_password' => 'short']);
        $this->assertSame(422, $this->code($short));

        $ok = $this->asSession()->withBodyFormat('json')->put('/admin/api/account/password', ['current_password' => self::PASSWORD, 'new_password' => 'a brand new passphrase', 'confirm_password' => 'a brand new passphrase']);
        $this->assertSame(200, $this->code($ok));

        $this->signIn('me@example.com', self::PASSWORD)->assertRedirectTo('/admin/login');
        $this->signIn('me@example.com', 'a brand new passphrase')->assertRedirectTo('/admin/');
    }

    public function testChangesSignOutOtherSessions(): void
    {
        $this->assertSame(200, $this->code($this->asSession(1)->get('/admin/api/me')));

        $this->asSession(1)->withBodyFormat('json')->post('/admin/api/account/sign-out-others', ['current_password' => self::PASSWORD]);
        $this->assertSame(2, (int) model(UserModel::class)->find($this->userId)['session_version']);

        // A session that signed in before the change is now signed out; one on the new version isn't.
        $this->assertSame(401, $this->code($this->asSession(1)->get('/admin/api/me')));
        $this->assertSame(200, $this->code($this->asSession(2)->get('/admin/api/me')));
    }

    public function testChangingTheSignInEmail(): void
    {
        model(UserModel::class)->insert(['email' => 'taken@example.com', 'name' => 'Other', 'password_hash' => 'x']);

        $taken = $this->asSession()->withBodyFormat('json')->put('/admin/api/account/email', ['current_password' => self::PASSWORD, 'email' => 'taken@example.com']);
        $this->assertSame(422, $this->code($taken));

        $ok = $this->asSession()->withBodyFormat('json')->put('/admin/api/account/email', ['current_password' => self::PASSWORD, 'email' => 'Private.Alias@example.com']);
        $this->assertSame(200, $this->code($ok));

        $this->signIn('me@example.com', self::PASSWORD)->assertRedirectTo('/admin/login');
        $this->signIn('private.alias@example.com', self::PASSWORD)->assertRedirectTo('/admin/');
    }

    public function testSessionsOfDeletedUsersOrPastTheMaximumAgeEnd(): void
    {
        $this->assertSame(401, $this->code($this->asSession(1, config('Admin')->maxSessionAge + 60)->get('/admin/api/me')));

        model(UserModel::class)->delete($this->userId);
        $this->assertSame(401, $this->code($this->asSession()->get('/admin/api/me')));
    }
}
