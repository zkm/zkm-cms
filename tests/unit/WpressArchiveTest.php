<?php

use App\Libraries\WordPress\WpressArchive;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class WpressArchiveTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/wpress-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function entry(string $name, string $path, string $body): string
    {
        return str_pad($name, 255, "\0") . str_pad((string) strlen($body), 14, "\0") . str_pad('0', 12, "\0") . str_pad($path, 4096, "\0") . $body;
    }

    public function testExtractsMatchingEntries(): void
    {
        $archive = $this->dir . '/site.wpress';
        file_put_contents($archive, $this->entry('database.sql', '.', 'SELECT 1;')
            . $this->entry('a.png', 'uploads/2026/10', 'PNG')
            . $this->entry('x.php', 'plugins/evil', '<?php')
            . str_repeat("\0", 4377));

        $count = (new WpressArchive($archive))->extract($this->dir . '/out', ['database.sql', 'uploads/']);

        $this->assertSame(2, $count);
        $this->assertSame('SELECT 1;', file_get_contents($this->dir . '/out/database.sql'));
        $this->assertSame('PNG', file_get_contents($this->dir . '/out/uploads/2026/10/a.png'));
        $this->assertFileDoesNotExist($this->dir . '/out/plugins/evil/x.php');
    }

    public function testRejectsPathTraversal(): void
    {
        $archive = $this->dir . '/bad.wpress';
        file_put_contents($archive, $this->entry('passwd', 'uploads/../../etc', 'x'));

        $this->expectException(RuntimeException::class);
        (new WpressArchive($archive))->extract($this->dir . '/out', ['uploads/']);
    }
}
