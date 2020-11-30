<?php declare(strict_types=1);
namespace DanielKm\Zoomify;

// Check the autoload.
if (!class_exists('DanielKm\Zoomify\Zoomify')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'Zoomify.php';
}

/**
 * Copyright 2005 Adam Smith (asmith@agile-software.com)
 * Copyright Wes Wright (http://greengaloshes.cc)
 * Copyright Justin Henry (http://greengaloshes.cc)
 * Copyright 2014-2020 Daniel Berthereau (Daniel.git@Berthereau.net)
 *
 * Ported from Python to PHP by Wes Wright
 * Cleanup for Drupal by Karim Ratib (kratib@open-craft.com)
 * Cleanup for Omeka Classic by Daniel Berthereau (daniel.gitlabberthereau.net)
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
class ZoomifyVips extends Zoomify
{
    use ZoomifyCommandTrait;

    /**
     * The path to command line vips.
     *
     * @var string
     */
    protected $vipsPath;

    /**
     * The strategy to use by php to process a command ("exec" or "proc_open").
     *
     * @var string
     */
    protected $executeStrategy;

    /**
     * Constructor.
     *
     * @param array $config
     */
    public function __construct(array $config = null)
    {
        if (is_null($config)) {
            $config = [];
        }

        $this->config = $config;
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
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
        $this->_imageFilename = realpath($filepath);
        $this->filepath = realpath($filepath);
        $this->destinationDir = $destinationDir;
        $result = $this->createDataContainer();
        if (!$result) {
            trigger_error('Output directory already exists.', E_USER_WARNING);
            return false;
        }

        $command = sprintf(
            '%s dzsave %s %s --layout zoomify --suffix %s --overlap %s --tile-size %s --background "0 0 0" --properties',
            $this->vipsPath,
            escapeshellarg($this->filepath),
            escapeshellarg($this->_saveToLocation),
            escapeshellarg('.' . $this->tileFormat . '[Q=' . (int) $this->tileQuality . ']'),
            (int) $this->tileOverlap,
            (int) $this->tileSize
        );
        $result = $this->execute($command);
        if ($result === false) {
            return false;
        }

        // For an undetermined reason, the vips xml may be saved in a sub-folder
        // on some servers.
        $filevips = $this->_saveToLocation . '/' . basename($this->_saveToLocation) . '/vips-properties.xml';
        if (file_exists($filevips)) {
            rename($filevips, $this->_saveToLocation . '/vips-properties.xml');
            rmdir($this->_saveToLocation . '/' . basename($this->_saveToLocation));
        }
        return true;
    }

    /**
     * Helper to get the command line tool vips.
     *
     * @return string
     */
    public function getVipsPath()
    {
        if (is_null($this->vipsPath)) {
            $command = 'whereis -b vips';
            $result = $this->execute($command);
            if (empty($result)) {
                $this->vipsPath = '';
            } else {
                strtok($result, ' ');
                $this->vipsPath = strtok(' ');
            }
        }
        return $this->vipsPath;
    }
}
