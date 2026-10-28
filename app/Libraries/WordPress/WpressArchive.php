<?php

namespace App\Libraries\WordPress;

use RuntimeException;

/**
 * Reads All-in-One WP Migration (.wpress) backups.
 *
 * The format is a flat list of entries, each a 4377-byte header
 * (name 255, size 14, mtime 12, path 4096, all NUL-padded) followed by the file bytes.
 */
class WpressArchive
{
    private const HEADER = 255 + 14 + 12 + 4096;

    public function __construct(private readonly string $file)
    {
        if (! is_file($file)) {
            throw new RuntimeException("No such archive: {$file}");
        }
    }

    /**
     * Extract entries whose path starts with one of $prefixes (all entries when empty).
     *
     * @param list<string> $prefixes
     *
     * @return int Number of files written.
     */
    public function extract(string $dest, array $prefixes = []): int
    {
        $root = rtrim($dest, '/');
        $count = 0;
        $in   = fopen($this->file, 'rb');

        try {
            while (($entry = $this->nextHeader($in)) !== null) {
                [$name, $size] = $entry;

                if (! $this->wanted($name, $prefixes)) {
                    fseek($in, $size, SEEK_CUR);

                    continue;
                }

                if (str_contains('/' . $name . '/', '/../')) {
                    throw new RuntimeException("Unsafe path in archive: {$name}");
                }

                $target = $root . '/' . $name;

                if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0775, true) && ! is_dir(dirname($target))) {
                    throw new RuntimeException('Cannot create ' . dirname($target));
                }

                $out = fopen($target, 'wb');

                for ($left = $size; $left > 0; $left -= strlen($chunk)) {
                    $chunk = fread($in, min($left, 1 << 20));

                    if ($chunk === false || $chunk === '') {
                        throw new RuntimeException("Archive ended inside {$name}");
                    }

                    fwrite($out, $chunk);
                }

                fclose($out);
                $count++;
            }
        } finally {
            fclose($in);
        }

        return $count;
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private function nextHeader($in): ?array
    {
        $header = fread($in, self::HEADER);

        if ($header === false || strlen($header) < self::HEADER || trim($header, "\0") === '') {
            return null;
        }

        $field = static fn (int $offset, int $length) => strstr(substr($header, $offset, $length) . "\0", "\0", true);

        $name = $field(0, 255);
        $size = (int) $field(255, 14);
        $path = $field(281, 4096);

        return [($path === '' || $path === '.') ? $name : $path . '/' . $name, $size];
    }

    private function wanted(string $name, array $prefixes): bool
    {
        if ($prefixes === []) {
            return true;
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
