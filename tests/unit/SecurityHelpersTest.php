<?php

use App\Controllers\Site;
use App\Filters\SecurityHeaders;
use App\Libraries\SecretBox;
use App\Libraries\Totp;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SecurityHelpersTest extends CIUnitTestCase
{
    public function testOneLineStripsHeaderInjection(): void
    {
        $this->assertSame('Eve Bcc: victim@example.com', Site::oneLine("Eve\r\nBcc: victim@example.com"));
        $this->assertSame('Zoë', Site::oneLine("  Zoë\0 "));
    }

    public function testCspHashesOnlyHeadScripts(): void
    {
        $html = '<html><head><script>var a=1;</script><script src="/x.js"></script></head><body><script>alert(1)</script></body></html>';

        $this->assertSame(["'sha256-" . base64_encode(hash('sha256', 'var a=1;', true)) . "'"], SecurityHeaders::headScriptHashes($html));
    }

    public function testTotpMatchStepReturnsTheCodesStep(): void
    {
        $secret = Totp::newSecret();
        $now    = 1_800_000_000;
        $step   = intdiv($now, 30);

        $this->assertSame($step, Totp::matchStep($secret, Totp::code($secret, $step), $now));
        $this->assertSame($step - 1, Totp::matchStep($secret, Totp::code($secret, $step - 1), $now));
        $this->assertNull(Totp::matchStep($secret, Totp::code($secret, $step - 3), $now));
    }

    public function testSecretBoxEncryptsAndRejectsTampering(): void
    {
        $sealed = SecretBox::seal('JBSWY3DPEHPK3PXP');

        $this->assertStringStartsWith('v1:', $sealed);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $sealed);
        $this->assertSame('JBSWY3DPEHPK3PXP', SecretBox::open($sealed));
        // Secrets stored before encryption was added still read back.
        $this->assertSame('JBSWY3DPEHPK3PXP', SecretBox::open('JBSWY3DPEHPK3PXP'));

        $raw = base64_decode(substr($sealed, 3), true);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);

        $this->expectException(\CodeIgniter\Encryption\Exceptions\EncryptionException::class);
        SecretBox::open('v1:' . base64_encode($raw));
    }
}
