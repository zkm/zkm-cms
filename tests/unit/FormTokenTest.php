<?php

use App\Libraries\FormToken;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class FormTokenTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    public function testATokenIsGoodOnlyBetweenItsMinimumAndMaximumAge(): void
    {
        Time::setTestNow('2026-10-31 12:00:00');
        $token = FormToken::issue();

        $this->assertSame('too-fast', FormToken::problem($token));

        Time::setTestNow('2026-10-31 12:00:03');
        $this->assertNull(FormToken::problem($token));

        Time::setTestNow('2026-11-01 12:00:01');
        $this->assertSame('expired', FormToken::problem($token));
    }

    public function testMissingOrTamperedTokensAreRejected(): void
    {
        Time::setTestNow('2026-10-31 12:00:00');
        [$issued, $sig] = explode('.', FormToken::issue());

        $this->assertSame('missing', FormToken::problem(''));
        $this->assertSame('invalid', FormToken::problem('garbage'));
        $this->assertSame('invalid', FormToken::problem(($issued - 60) . '.' . $sig));
        $this->assertSame('invalid', FormToken::problem($issued . '.' . str_repeat('0', 64)));
    }
}
