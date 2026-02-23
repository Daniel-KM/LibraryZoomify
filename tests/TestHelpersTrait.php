<?php

declare(strict_types=1);

namespace DanielKm\Zoomify\Test;

use DanielKm\Zoomify\Zoomify;

trait TestHelpersTrait
{
    /**
     * @var string[]
     */
    protected array $tempDirs = [];

    /**
     * Create a temp directory (for general use).
     */
    protected function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'zoomify_test_' . uniqid();
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    /**
     * Return a non-existent temp path and register it for cleanup.
     *
     * Zoomify creates the destination itself and fails if it already
     * exists, so we must not mkdir() beforehand.
     */
    protected function getTempPath(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'zoomify_test_' . uniqid();
        $this->tempDirs[] = $dir;
        return $dir;
    }

    protected function removeTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path)
                ? $this->removeTempDir($path)
                : unlink($path);
        }
        rmdir($dir);
    }

    protected function cleanupTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTempDir($dir);
        }
        $this->tempDirs = [];
    }

    protected function fixtureImagePath(): string
    {
        return __DIR__ . '/fixtures/test-image.jpg';
    }

    /**
     * Skip test if the processor is not available.
     */
    protected function skipIfProcessorUnavailable(string $processor): void
    {
        try {
            new Zoomify($this->getProcessorConfig($processor));
        } catch (\Exception $e) {
            $this->markTestSkipped(
                "Processor $processor is not available: "
                . $e->getMessage()
            );
        }
    }

    /**
     * Return the config array for a given processor.
     *
     * CLI processors need explicit paths because the Zoomify facade
     * detects them during auto-detection but does not propagate them
     * to the sub-processor instances.
     */
    protected function getProcessorConfig(string $processor): array
    {
        $config = ['processor' => $processor];
        if ($processor === 'ImageMagick') {
            $path = trim((string) shell_exec('command -v magick 2>/dev/null'))
                ?: trim((string) shell_exec('command -v convert 2>/dev/null'));
            if ($path !== '') {
                $config['convertPath'] = $path;
            }
        } elseif ($processor === 'Vips') {
            $path = trim((string) shell_exec('command -v vips 2>/dev/null'));
            if ($path !== '') {
                $config['vipsPath'] = $path;
            }
        }
        return $config;
    }

    /**
     * Assert that a file is a valid JPEG image.
     */
    protected function assertFileIsValidJpeg(string $path): void
    {
        $this->assertFileExists($path);
        $info = @getimagesize($path);
        $this->assertNotFalse($info, "Not a valid image: $path");
        $this->assertSame(
            IMAGETYPE_JPEG,
            $info[2],
            "File is not JPEG: $path"
        );
    }

    /**
     * Return sorted relative paths of tile files across TileGroups.
     */
    protected function getTileFiles(string $destDir): array
    {
        $result = [];
        $entries = array_diff(scandir($destDir), ['.', '..']);
        $groups = [];
        foreach ($entries as $entry) {
            $path = $destDir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && str_starts_with($entry, 'TileGroup')) {
                $groups[] = $entry;
            }
        }
        sort($groups);
        foreach ($groups as $group) {
            $groupDir = $destDir . DIRECTORY_SEPARATOR . $group;
            $tiles = array_diff(scandir($groupDir), ['.', '..']);
            sort($tiles);
            foreach ($tiles as $tile) {
                $result[] = $group . '/' . $tile;
            }
        }
        return $result;
    }

    /**
     * Return tile dimensions: [relative_path => [width, height]].
     */
    protected function getTileDimensions(string $destDir): array
    {
        $result = [];
        foreach ($this->getTileFiles($destDir) as $relPath) {
            $absPath = $destDir . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
            $info = @getimagesize($absPath);
            if ($info !== false) {
                $result[$relPath] = [$info[0], $info[1]];
            }
        }
        return $result;
    }

    /**
     * Parse ImageProperties.xml and return metadata.
     *
     * @return array{width: int, height: int, numTiles: int,
     *     tileSize: int}
     */
    protected function parseImageProperties(string $path): array
    {
        $this->assertFileExists($path);
        $xml = simplexml_load_file($path);
        $this->assertNotFalse($xml, "Cannot parse XML: $path");
        $attrs = $xml->attributes();
        return [
            'width' => (int) $attrs['WIDTH'],
            'height' => (int) $attrs['HEIGHT'],
            'numTiles' => (int) $attrs['NUMTILES'],
            'tileSize' => (int) $attrs['TILESIZE'],
        ];
    }

    /**
     * List available processors that can be instantiated.
     *
     * @return string[]
     */
    protected function getAvailableProcessors(): array
    {
        $all = ['GD', 'Imagick', 'ImageMagick', 'Vips', 'PhpVips'];
        $available = [];
        foreach ($all as $proc) {
            try {
                new Zoomify($this->getProcessorConfig($proc));
                $available[] = $proc;
            } catch (\Exception $e) {
                // Not available.
            }
        }
        return $available;
    }

    /**
     * List available PHP-family processors (GD, Imagick, ImageMagick).
     *
     * @return string[]
     */
    protected function getAvailablePhpFamilyProcessors(): array
    {
        return array_intersect(
            $this->getAvailableProcessors(),
            ['GD', 'Imagick', 'ImageMagick']
        );
    }

    /**
     * List available Vips-family processors (Vips, PhpVips).
     *
     * @return string[]
     */
    protected function getAvailableVipsFamilyProcessors(): array
    {
        return array_intersect(
            $this->getAvailableProcessors(),
            ['Vips', 'PhpVips']
        );
    }
}
