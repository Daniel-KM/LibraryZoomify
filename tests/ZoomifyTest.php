<?php

declare(strict_types=1);

namespace DanielKm\Zoomify\Test;

use DanielKm\Zoomify\Zoomify;
use DanielKm\Zoomify\ZoomifyFactory;
use PHPUnit\Framework\TestCase;

class ZoomifyTest extends TestCase
{
    use TestHelpersTrait;

    protected function tearDown(): void
    {
        $this->cleanupTempDirs();
    }

    // ------------------------------------------------------------------
    // Constructor tests
    // ------------------------------------------------------------------

    public function testConstructorAutoDetectsProcessor(): void
    {
        $z = new Zoomify();
        $this->assertInstanceOf(Zoomify::class, $z);
    }

    /**
     * @dataProvider validProcessorProvider
     */
    public function testConstructorWithValidProcessor(
        string $processor
    ): void {
        $this->skipIfProcessorUnavailable($processor);
        $z = new Zoomify($this->getProcessorConfig($processor));
        $this->assertInstanceOf(Zoomify::class, $z);
    }

    public static function validProcessorProvider(): array
    {
        return [
            'GD' => ['GD'],
            'Imagick' => ['Imagick'],
            'ImageMagick' => ['ImageMagick'],
            'Vips' => ['Vips'],
            'PhpVips' => ['PhpVips'],
        ];
    }

    public function testConstructorWithInvalidProcessorThrows(): void
    {
        $this->expectException(\Exception::class);
        new Zoomify(['processor' => 'NonExistent']);
    }

    public function testFactoryReturnsInstance(): void
    {
        $factory = new ZoomifyFactory();
        $z = $factory();
        $this->assertInstanceOf(Zoomify::class, $z);
    }

    // ------------------------------------------------------------------
    // Process error tests
    // ------------------------------------------------------------------

    public function testProcessNonExistentFileThrows(): void
    {
        $z = new Zoomify($this->getProcessorConfig('GD'));
        $this->expectException(\Exception::class);
        $z->process('/non/existent/file.jpg');
    }

    public function testProcessExistingDestinationReturnsFalse(): void
    {
        $dest = $this->getTempPath();
        $config = $this->getProcessorConfig('GD');

        $z = new Zoomify($config);
        $result = $z->process($this->fixtureImagePath(), $dest);
        $this->assertTrue($result);

        // Second run with same destination: triggers warning, returns
        // false.
        $z2 = new Zoomify($config);
        $result = @$z2->process($this->fixtureImagePath(), $dest);
        $this->assertFalse($result);
    }

    public function testProcessWithDestinationRemoveOverwrites(): void
    {
        $dest = $this->getTempPath();
        $config = $this->getProcessorConfig('GD');

        $z = new Zoomify($config);
        $z->process($this->fixtureImagePath(), $dest);

        $config['destinationRemove'] = true;
        $z2 = new Zoomify($config);
        $result = $z2->process($this->fixtureImagePath(), $dest);
        $this->assertTrue($result);
    }

    // ------------------------------------------------------------------
    // Smoke tests per processor
    // ------------------------------------------------------------------

    /**
     * @dataProvider validProcessorProvider
     */
    public function testProcessProducesOutputWithProcessor(
        string $processor
    ): void {
        $this->skipIfProcessorUnavailable($processor);

        $dest = $this->getTempPath();
        $config = $this->getProcessorConfig($processor);
        $z = new Zoomify($config);
        $result = $z->process($this->fixtureImagePath(), $dest);
        $this->assertTrue($result, "process() failed for $processor");

        // ImageProperties.xml must exist.
        $xmlPath = $dest . DIRECTORY_SEPARATOR . 'ImageProperties.xml';
        $this->assertFileExists($xmlPath);
        $props = $this->parseImageProperties($xmlPath);
        $this->assertSame(1217, $props['width']);
        $this->assertSame(797, $props['height']);
        $this->assertSame(256, $props['tileSize']);
        $this->assertGreaterThan(0, $props['numTiles']);

        // At least one TileGroup must exist.
        $tiles = $this->getTileFiles($dest);
        $this->assertNotEmpty($tiles, "No tiles by $processor");

        // All tiles must be valid JPEG files.
        foreach ($tiles as $relPath) {
            $absPath = $dest . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
            $this->assertFileIsValidJpeg($absPath);
        }
    }
}
