<?php
namespace DanielKm\Zoomify;

/**
 * Zoomify big images into tiles supported by "OpenSeadragon", "OpenLayers" and
 * many other viewers.
 *
 * The Zoomify class is a port of the ZoomifyImage python script to a PHP class.
 * The original python script was written by Adam Smith, and was ported to PHP
 * (in the form of ZoomifyFileProcessor) by Wes Wright. The port to Imagick was
 * done by Daniel Berthereau for the Bibliothèque patrimoniale of Mines ParisTech.
 *
 * Copyright 2005 Adam Smith (asmith@agile-software.com)
 * Copyright Wes Wright (http://greengaloshes.cc)
 * Copyright Justin Henry (http://greengaloshes.cc)
 * Copyright 2014-2020 Daniel Berthereau (Daniel.git@Berthereau.net)
 *
 * Ported from Python to PHP by Wes Wright
 * Cleanup for Drupal by Karim Ratib (kratib@open-craft.com)
 * Cleanup for Omeka Classic by Daniel Berthereau (daniel.git@berthereau.net)
 * Conversion to ImageMagick by Daniel Berthereau
 * Set as a stand-alone library in Packagist and integrated in Omeka S.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

/**
 * Provides an interface to perform tiling of images.
 *
 * @package DanielKm\Zoomify
 */
class Zoomify
{
    /**
     * Store the config.
     *
     * @var array
     */
    protected $config;

    /**
     * The processor to use.
     *
     * @var string
     */
    protected $processor;

    /**
     * The path to the image.
     *
     * @var string
     */
    protected $filepath;

    /**
     * The path to the destination dir.
     *
     * @var string
     */
    protected $destinationDir;

    /**
     * If an existing destination should be removed.
     *
     * @var bool
     */
    protected $destinationRemove = false;

    /**
     * The file system mode of the directories.
     *
     * @var int
     */
    protected $dirMode = 0755;

    /**
     * The size of tiles.
     *
     * @var int
     */
    protected $tileSize = 256;

    /**
     * The overlap of tiles.
     *
     * @var int
     */
    protected $tileOverlap = 0;

    /**
     * The format of tiles.
     *
     * @var string
     */
    protected $tileFormat = 'jpg';

    /**
     * The quality of the tile.
     *
     * @var int
     */
    protected $tileQuality = 85;

    /**
     * Various metadata of the source and tiles.
     *
     * @var array
     */
    protected $data = [];

    protected $_tileExt = 'jpg';
    protected $_imageFilename = '';
    protected $_originalWidth = 0;
    protected $_originalHeight = 0;
    protected $_originalFormat = 0;
    protected $_saveToLocation;
    protected $_scaleInfo = [];
    protected $_tileGroupMappings = [];
    protected $_numberOfTiles = 0;

    /**
     * Constructor.
     *
     * @param array $config
     * @throws \Exception
     */
    public function __construct(array $config = null)
    {
        if (is_null($config)) {
            $config = [];
        }

        $this->config = $config;
        if (isset($config['processor'])) {
            $this->processor = $config['processor'];
        }

        // Check the processor.
        // Automatic.
        if (empty($this->processor)) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'ZoomifyVips.php';
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'ZoomifyImageMagick.php';
            $processorVips = new ZoomifyVips();
            $processorImageMagick = new ZoomifyImageMagick();
            if ($processorVips->getVipsPath()) {
                $this->processor = 'Vips';
            } elseif ((extension_loaded('vips') || extension_loaded('ffi'))
                && class_exists('Jcupitt\Vips\Image')
            ) {
                $this->processor = 'PhpVips';
            } elseif ($processorImageMagick->getConvertPath()) {
                $this->processor = 'ImageMagick';
            } elseif (extension_loaded('imagick')) {
                $this->processor = 'Imagick';
            } elseif (extension_loaded('gd')) {
                $this->processor = 'GD';
            } else {
                throw new \Exception('No graphic library available.');
            }
        }
        // GD.
        elseif ($this->processor === 'GD') {
            if (!extension_loaded('gd')) {
                throw new \Exception('GD library is not available.');
            }
        }
        // Imagick.
        elseif ($this->processor === 'Imagick') {
            if (!extension_loaded('imagick')) {
                throw new \Exception('Imagick library is not available.');
            }
        }
        // CLI ImageMagick.
        elseif ($this->processor === 'ImageMagick') {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'ZoomifyImageMagick.php';
            $processor = new ZoomifyImageMagick();
            if (!$processor->getConvertPath()) {
                throw new \Exception('Convert path is not available.');
            }
        }
        // CLI Vips.
        elseif ($this->processor === 'Vips') {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'ZoomifyVips.php';
            $processor = new ZoomifyVips();
            if (!$processor->getVipsPath()) {
                throw new \Exception('Vips path is not available.');
            }
        }
        // PHP Vips.
        elseif ($this->processor === 'PhpVips') {
            if ((!extension_loaded('vips') && !extension_loaded('ffi'))
                || !class_exists('Jcupitt\Vips\Image')
            ) {
                throw new \Exception('php-vips library is not available.');
            }
        }
        // Error.
        else {
            throw new \Exception('No graphic library available.');
        }
    }

    /**
     * Zoomify the specified image and store it in the destination dir.
     *
     * Check to be sure the file hasn't been converted already.
     *
     * @param string $filepath The path to the image.
     * @param string $destinationDir The directory where to store the tiles.
     * @return bool
     */
    public function process($filepath, $destinationDir = '')
    {
        switch ($this->processor) {
            case 'Imagick':
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'ZoomifyImagick.php';
                $processor = new ZoomifyImagick($this->config);
                break;
            case 'GD':
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'ZoomifyGD.php';
                $processor = new ZoomifyGD($this->config);
                break;
            case 'ImageMagick':
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'ZoomifyImageMagick.php';
                $processor = new ZoomifyImageMagick($this->config);
                break;
            case 'Vips':
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'ZoomifyVips.php';
                $processor = new ZoomifyVips($this->config);
                break;
            case 'PhpVips':
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'ZoomifyPhpVips.php';
                $processor = new ZoomifyPhpVips($this->config);
                break;
            default:
                throw new \Exception('No graphic library available.');
        }
        $result = $processor->process($filepath, $destinationDir);
        return $result;
    }

    /**
     * Zoomify the specified image and store it in the destination dir.
     *
     * Check to be sure the file hasn't been converted already.
     *
     * @param string $filepath The path to the image.
     * @param string $destinationDir The directory where to store the tiles.
     */
    protected function zoomifyImage($filepath, $destinationDir)
    {
        $this->_imageFilename = realpath($filepath);
        if ($this->_imageFilename === false) {
            throw new \Exception('File does not exist: ' . $filepath);
        }

        $this->filepath = $this->_imageFilename;
        $this->destinationDir = $destinationDir;
        $result = $this->createDataContainer();
        if ($result === false) {
            trigger_error('Output directory already exists.', E_USER_WARNING);
            return false;
        }

        $this->getImageMetadata();
        $this->processImage();
        $result = $this->saveXMLOutput();
        return $result;
    }

    /**
     * Given an image name, load it and extract metadata.
     */
    protected function getImageMetadata()
    {
        list($this->_originalWidth, $this->_originalHeight, $this->_originalFormat) = getimagesize($this->_imageFilename);

        // Get scaling information.
        $width = $this->_originalWidth;
        $height = $this->_originalHeight;
        $width_height = [$width, $height];
        array_unshift($this->_scaleInfo, $width_height);
        while (($width > $this->tileSize) || ($height > $this->tileSize)) {
            $width = floor($width / 2);
            $height = floor($height / 2);
            $width_height = [$width, $height];
            array_unshift($this->_scaleInfo, $width_height);
        }

        // Tile and tile group information.
        $this->preProcess();
    }

    /**
     * Create a container (a folder) for tiles and tile metadata if not set.
     *
     * @return bool
     */
    protected function createDataContainer()
    {
        if ($this->destinationDir) {
            $location = $this->destinationDir;
        }
        // Determine the path to the directory from the filepath.
        else {
            list($root) = $this->getRootAndDotExtension($this->_imageFilename);
            $directory = dirname($root);
            $filename = basename($root);
            $root = $filename . '_zdata';
            $location = $directory . DIRECTORY_SEPARATOR . $root;
            $this->destinationDir = $location;
        }

        $this->_saveToLocation = $location;

        // If the paths already exist, an image is being re-processed, clean up
        // for it.
        if ($this->destinationRemove) {
            if (is_dir($this->_saveToLocation)) {
                $this->rmDir($this->_saveToLocation);
            }
        } elseif (is_dir($this->_saveToLocation)) {
            return false;
        }

        if (!is_dir($this->_saveToLocation)) {
            mkdir($this->_saveToLocation, $this->dirMode, true);
        }

        return true;
    }

    /**
     * Create a container for the next group of tiles within the data container.
     *
     * @param string $tileContainerName
     */
    protected function createTileContainer($tileContainerName = '')
    {
        $tileContainerPath = $this->_saveToLocation . DIRECTORY_SEPARATOR . $tileContainerName;
        if (!is_dir($tileContainerPath)) {
            mkdir($tileContainerPath);
        }
    }

    /**
     * Plan for the arrangement of the tile groups.
     */
    protected function preProcess()
    {
        $tier = 0;
        $tileGroupNumber = 0;
        $numberOfTiles = 0;

        foreach ($this->_scaleInfo as $width_height) {
            list($width, $height) = $width_height;

            // Cycle through columns, then rows.
            $row = 0;
            $column = 0;
            $ul_x = 0;
            $ul_y = 0;
            $lr_x = 0;
            $lr_y = 0;
            while (!(($lr_x === $width) && ($lr_y === $height))) {
                $tileFilename = $this->getTileFilename($tier, $column, $row);
                $tileContainerName = $this->getNewTileContainerName($tileGroupNumber);

                if ($numberOfTiles === 0) {
                    $this->createTileContainer($tileContainerName);
                } elseif ($numberOfTiles % $this->tileSize === 0) {
                    ++$tileGroupNumber;
                    $tileContainerName = $this->getNewTileContainerName($tileGroupNumber);
                    $this->createTileContainer($tileContainerName);
                }
                $this->_tileGroupMappings[$tileFilename] = $tileContainerName;
                ++$numberOfTiles;

                // for the next tile, set lower right cropping point
                $lr_x = ($ul_x + $this->tileSize < $width) ? $ul_x + $this->tileSize : $width;
                $lr_y = ($ul_y + $this->tileSize < $height) ? $ul_y + $this->tileSize : $height;

                // for the next tile, set upper left cropping point
                if ($lr_x === $width) {
                    $ul_x = 0;
                    $ul_y = $lr_y;
                    $column = 0;
                    ++$row;
                } else {
                    $ul_x = $lr_x;
                    ++$column;
                }
            }
            ++$tier;
        }
    }

    /**
     * Explode a filepath in a root and an extension, i.e. "/path/file.ext" to
     * "/path/file" and ".ext".
     *
     * @param string $filepath
     * @return array
     */
    protected function getRootAndDotExtension($filepath)
    {
        $extension = pathinfo($filepath, PATHINFO_EXTENSION);
        $root = $extension ? substr($filepath, 0, strrpos($filepath, '.')) : $filepath;
        return [$root, $extension];
    }

    /**
     * Get the name of the file for the tile.
     *
     * @param int $scaleNumber
     * @param int $columnNumber
     * @param int $rowNumber
     * @return string
     */
    protected function getTileFilename($scaleNumber, $columnNumber, $rowNumber)
    {
        return $scaleNumber . '-' . $columnNumber . '-' . $rowNumber . '.' . $this->_tileExt;
    }

    /**
     * Return the name of the next tile group container.
     *
     * @param int $tileGroupNumber
     * @return string
     */
    protected function getNewTileContainerName($tileGroupNumber = 0)
    {
        return 'TileGroup' . $tileGroupNumber;
    }

    /**
     * Get the full path of the file the tile will be saved as.
     *
     * @param int $scaleNumber
     * @param int $columnNumber
     * @param int $rowNumber
     * @return string
     */
    protected function getFileReference($scaleNumber, $columnNumber, $rowNumber)
    {
        $tileFilename = $this->getTileFilename($scaleNumber, $columnNumber, $rowNumber);
        $tileContainerName = $this->getAssignedTileContainerName($tileFilename);
        return $this->_saveToLocation . DIRECTORY_SEPARATOR . $tileContainerName . DIRECTORY_SEPARATOR . $tileFilename;
    }

    /**
     * Return the name of the tile group for the indicated tile.
     *
     * @param string $tileFilename
     * @return string
     */
    protected function getAssignedTileContainerName($tileFilename)
    {
        if ($tileFilename) {
            if (isset($this->_tileGroupMappings) && $this->_tileGroupMappings) {
                if (isset($this->_tileGroupMappings[$tileFilename])) {
                    $containerName = $this->_tileGroupMappings[$tileFilename];
                    if ($containerName) {
                        return $containerName;
                    }
                }
            }
        }
        $containerName = $this->getNewTileContainerName();

        return $containerName;
    }

    /**
     * Save xml metadata about the tiles.
     *
     * @return bool
     */
    protected function saveXMLOutput()
    {
        $xmlFile = fopen($this->_saveToLocation . DIRECTORY_SEPARATOR . 'ImageProperties.xml', 'w');
        if ($xmlFile === false) {
            return false;
        }
        fwrite($xmlFile, $this->getXMLOutput());
        $result = fclose($xmlFile);
        return $result;
    }

    /**
     * Create xml metadata about the tiles
     *
     * @return string
     */
    protected function getXMLOutput()
    {
        $xmlOutput = sprintf('<IMAGE_PROPERTIES WIDTH="%s" HEIGHT="%s" NUMTILES="%s" NUMIMAGES="1" TILESIZE="%s" VERSION="1.8" />',
            $this->_originalWidth, $this->_originalHeight, $this->_numberOfTiles, $this->tileSize) . PHP_EOL;
        return $xmlOutput;
    }

    /**
     * Remove a dir from filesystem.
     *
     * @param string $dirPath
     * @return bool
     */
    protected function rmDir($dirPath)
    {
        $files = array_diff(scandir($dirPath), ['.', '..']);
        foreach ($files as $file) {
            $path = $dirPath . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                unlink($path);
            }
        }
        return rmdir($dirPath);
    }
}
