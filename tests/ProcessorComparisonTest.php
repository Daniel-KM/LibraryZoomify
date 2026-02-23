<?php

declare(strict_types=1);

namespace DanielKm\Zoomify\Test;

use DanielKm\Zoomify\Zoomify;
use PHPUnit\Framework\TestCase;

/**
 * Cross-processor comparison tests for Zoomify.
 *
 * Two families of processors exist:
 * - PHP family (GD, Imagick, ImageMagick): same Python-ported
 *   algorithm, identical structure.
 * - Vips family (Vips CLI, PhpVips): native vips algorithm, different
 *   tile count/layout.
 *
 * Intra-family comparisons check identical structure. Cross-family
 * tests only check common invariants.
 */
class ProcessorComparisonTest extends TestCase
{
    use TestHelpersTrait;

    /**
     * Cached tiling results per processor.
     *
     * @var array<string, array{dest: string}>
     */
    private static array $results = [];

    /**
     * @var string[]
     */
    private static array $staticTempDirs = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$staticTempDirs as $dir) {
            if (is_dir($dir)) {
                (new self('cleanup'))->removeTempDir($dir);
            }
        }
        self::$results = [];
        self::$staticTempDirs = [];
    }

    /**
     * Tile the fixture image with the given processor and cache it.
     */
    private function ensureTiled(string $processor): array
    {
        if (isset(self::$results[$processor])) {
            return self::$results[$processor];
        }

        $this->skipIfProcessorUnavailable($processor);

        $dest = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'zoomify_cmp_' . strtolower($processor) . '_' . uniqid();
        self::$staticTempDirs[] = $dest;

        $z = new Zoomify($this->getProcessorConfig($processor));
        $z->process($this->fixtureImagePath(), $dest);

        self::$results[$processor] = ['dest' => $dest];
        return self::$results[$processor];
    }

    // ------------------------------------------------------------------
    // Per-processor structural checks
    // ------------------------------------------------------------------

    /**
     * @dataProvider processorProvider
     */
    public function testImagePropertiesXmlIsCorrect(
        string $processor
    ): void {
        $r = $this->ensureTiled($processor);
        $xmlPath = $r['dest'] . DIRECTORY_SEPARATOR
            . 'ImageProperties.xml';
        $props = $this->parseImageProperties($xmlPath);
        $this->assertSame(1217, $props['width']);
        $this->assertSame(797, $props['height']);
        $this->assertSame(256, $props['tileSize']);
        $this->assertGreaterThan(0, $props['numTiles']);
    }

    /**
     * @dataProvider processorProvider
     */
    public function testAllTilesAreValidJpeg(string $processor): void
    {
        $r = $this->ensureTiled($processor);
        $tiles = $this->getTileFiles($r['dest']);
        $this->assertNotEmpty($tiles);
        foreach ($tiles as $relPath) {
            $abs = $r['dest'] . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
            $this->assertFileIsValidJpeg($abs);
        }
    }

    /**
     * @dataProvider processorProvider
     */
    public function testAtLeastOneTileGroup(string $processor): void
    {
        $r = $this->ensureTiled($processor);
        $groups = glob($r['dest'] . '/TileGroup*');
        $this->assertNotEmpty(
            $groups,
            "No TileGroup found for $processor"
        );
    }

    public static function processorProvider(): array
    {
        return [
            'GD' => ['GD'],
            'Imagick' => ['Imagick'],
            'ImageMagick' => ['ImageMagick'],
            'Vips' => ['Vips'],
            'PhpVips' => ['PhpVips'],
        ];
    }

    // ------------------------------------------------------------------
    // PHP family intra-comparison (GD, Imagick, ImageMagick)
    // ------------------------------------------------------------------

    public function testPhpFamilySameTileFiles(): void
    {
        $procs = $this->getAvailablePhpFamilyProcessors();
        if (count($procs) < 2) {
            $this->markTestSkipped(
                'Need at least 2 PHP-family processors.'
            );
        }

        $tileSets = [];
        foreach ($procs as $proc) {
            $r = $this->ensureTiled($proc);
            $tileSets[$proc] = $this->getTileFiles($r['dest']);
        }

        $reference = reset($tileSets);
        $refProc = key($tileSets);
        foreach ($tileSets as $proc => $tiles) {
            $this->assertSame(
                $reference,
                $tiles,
                "Tile list differs between $refProc and $proc"
            );
        }
    }

    public function testPhpFamilySameTileDimensions(): void
    {
        $procs = $this->getAvailablePhpFamilyProcessors();
        if (count($procs) < 2) {
            $this->markTestSkipped(
                'Need at least 2 PHP-family processors.'
            );
        }

        $dimSets = [];
        foreach ($procs as $proc) {
            $r = $this->ensureTiled($proc);
            $dimSets[$proc] = $this->getTileDimensions($r['dest']);
        }

        $reference = reset($dimSets);
        $refProc = key($dimSets);
        foreach ($dimSets as $proc => $dims) {
            $this->assertSame(
                $reference,
                $dims,
                "Tile dimensions differ between $refProc and $proc"
            );
        }
    }

    public function testPhpFamilySameImageProperties(): void
    {
        $procs = $this->getAvailablePhpFamilyProcessors();
        if (count($procs) < 2) {
            $this->markTestSkipped(
                'Need at least 2 PHP-family processors.'
            );
        }

        $propSets = [];
        foreach ($procs as $proc) {
            $r = $this->ensureTiled($proc);
            $propSets[$proc] = $this->parseImageProperties(
                $r['dest'] . '/ImageProperties.xml'
            );
        }

        $reference = reset($propSets);
        $refProc = key($propSets);
        foreach ($propSets as $proc => $props) {
            $this->assertSame(
                $reference,
                $props,
                "ImageProperties differ between $refProc and $proc"
            );
        }
    }

    // ------------------------------------------------------------------
    // Vips family intra-comparison (Vips CLI, PhpVips)
    // ------------------------------------------------------------------

    public function testVipsFamilySameTileFiles(): void
    {
        $procs = $this->getAvailableVipsFamilyProcessors();
        if (count($procs) < 2) {
            $this->markTestSkipped(
                'Need both Vips and PhpVips for comparison.'
            );
        }

        $tileSets = [];
        foreach ($procs as $proc) {
            $r = $this->ensureTiled($proc);
            $tileSets[$proc] = $this->getTileFiles($r['dest']);
        }

        $reference = reset($tileSets);
        $refProc = key($tileSets);
        foreach ($tileSets as $proc => $tiles) {
            $this->assertSame(
                $reference,
                $tiles,
                "Tile list differs between $refProc and $proc"
            );
        }
    }

    public function testVipsFamilySameTileDimensions(): void
    {
        $procs = $this->getAvailableVipsFamilyProcessors();
        if (count($procs) < 2) {
            $this->markTestSkipped(
                'Need both Vips and PhpVips for comparison.'
            );
        }

        $dimSets = [];
        foreach ($procs as $proc) {
            $r = $this->ensureTiled($proc);
            $dimSets[$proc] = $this->getTileDimensions($r['dest']);
        }

        $reference = reset($dimSets);
        $refProc = key($dimSets);
        foreach ($dimSets as $proc => $dims) {
            $this->assertSame(
                $reference,
                $dims,
                "Tile dimensions differ between $refProc and $proc"
            );
        }
    }

    // ------------------------------------------------------------------
    // Cross-family invariants (all processors)
    // ------------------------------------------------------------------

    public function testAllProcessorsAgreeOnSourceDimensions(): void
    {
        $available = $this->getAvailableProcessors();
        if (count($available) < 2) {
            $this->markTestSkipped(
                'Need at least 2 processors for comparison.'
            );
        }

        foreach ($available as $proc) {
            $r = $this->ensureTiled($proc);
            $props = $this->parseImageProperties(
                $r['dest'] . '/ImageProperties.xml'
            );
            $this->assertSame(
                1217,
                $props['width'],
                "Width mismatch for $proc"
            );
            $this->assertSame(
                797,
                $props['height'],
                "Height mismatch for $proc"
            );
            $this->assertSame(
                256,
                $props['tileSize'],
                "TileSize mismatch for $proc"
            );
        }
    }
}
