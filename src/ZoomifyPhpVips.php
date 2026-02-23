<?php
namespace DanielKm\Zoomify;

// Check the autoload.
if (!class_exists('DanielKm\Zoomify\Zoomify', false)) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'Zoomify.php';
}

/**
 * Copyright 2005 Adam Smith (asmith@agile-software.com)
 * Copyright Wes Wright (http://greengaloshes.cc)
 * Copyright Justin Henry (http://greengaloshes.cc)
 * Copyright 2014-2026 Daniel Berthereau (Daniel.git@Berthereau.net)
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
class ZoomifyPhpVips extends Zoomify
{
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
        if ($this->_imageFilename === false) {
            throw new \Exception('File does not exist: ' . $filepath);
        }

        $this->filepath = $this->_imageFilename;
        $this->destinationDir = $destinationDir;
        $result = $this->createDataContainer();
        if (!$result) {
            trigger_error('Output directory already exists.', E_USER_WARNING);
            return false;
        }

        $image = \Jcupitt\Vips\Image::newFromFile($this->filepath);
        $image->dzsave($this->_saveToLocation, [
            'layout' => 'zoomify',
            'suffix' => '.' . $this->tileFormat . '[Q=' . (int) $this->tileQuality . ']',
            'overlap' => (int) $this->tileOverlap,
            'tile_size' => (int) $this->tileSize,
            'background' => [0, 0, 0],
            'properties' => true,
        ]);

        // For an undetermined reason, the vips xml may be saved in a sub-folder
        // on some servers.
        $filevips = $this->_saveToLocation . '/' . basename($this->_saveToLocation) . '/vips-properties.xml';
        if (file_exists($filevips)) {
            rename($filevips, $this->_saveToLocation . '/vips-properties.xml');
            rmdir($this->_saveToLocation . '/' . basename($this->_saveToLocation));
        }
        return true;
    }
}
