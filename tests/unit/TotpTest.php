<?php

use App\Libraries\Totp;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TotpTest extends CIUnitTestCase
{
    // RFC 6238 appendix B test secret ("12345678901234567890") in base32.
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testMatchesRfcVectors(): void
    {
        $this->assertSame('287082', Totp::code(self::SECRET, intdiv(59, 30)));
        $this->assertSame('081804', Totp::code(self::SECRET, intdiv(1111111109, 30)));
        $this->assertSame('005924', Totp::code(self::SECRET, intdiv(1234567890, 30)));
    }

    public function testAcceptsNeighbouringStepsOnly(): void
    {
        $now = 1_800_000_000;
        $code = Totp::code(self::SECRET, intdiv($now, 30));

        $this->assertTrue(Totp::verify(self::SECRET, $code, $now));
        $this->assertTrue(Totp::verify(self::SECRET, $code, $now + 30));
        $this->assertFalse(Totp::verify(self::SECRET, $code, $now + 90));
        $this->assertFalse(Totp::verify(self::SECRET, '12345', $now));
    }

    public function testNewSecretsAreBase32(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{20}$/', Totp::newSecret());
    }
}
