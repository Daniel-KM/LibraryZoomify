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
 *
 * @internal
 * This adaptation of the original ZoomifyFileProcessor doesn't use ImageMagick
 * functions to create multiple tiles automagically. The process is stricly the
 * same than the original, so it can be improved.
 * @todo Use functions allowing to create multiple tiles in one time.
 */
class ZoomifyImageMagick extends Zoomify
{
    use ZoomifyCommandTrait;

    /**
     * The path to command line ImageMagick convert when the processor is "cli".
     *
     * @var string
     */
    protected $convertPath;

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
        return $this->zoomifyImage($filepath, $destinationDir);
    }

    /**
     * Starting with the original image, start processing each row.
     */
    protected function processImage()
    {
        // Start from the last scale (bigger image).
        $tier = (count($this->_scaleInfo) - 1);
        $row = 0;
        $ul_y = 0;
        $lr_y = 0;

        list($root, $ext) = $this->getRootAndDotExtension($this->_imageFilename);

        // Create a row from the original image and process it.
        while ($row * $this->tileSize < $this->_originalHeight) {
            $ul_y = $row * $this->tileSize;
            $lr_y = ($ul_y + $this->tileSize < $this->_originalHeight)
                ? $ul_y + $this->tileSize
                : $this->_originalHeight;
            $saveFilename = $root . '-' . $tier . '-' . $row . '.' . $ext;
            $width = $this->_originalWidth;
            $height = abs($lr_y - $ul_y);
            $crop = [];
            $crop['width'] = $width;
            $crop['height'] = $height;
            $crop['x'] = 0;
            $crop['y'] = $ul_y;
            $this->imageResizeCrop($this->_imageFilename, $saveFilename, [], $crop);

            $this->processRowImage($tier, $row);
            ++$row;
        }
    }

    /**
     * For a row image, create and save tiles.
     */
    protected function processRowImage($tier = 0, $row = 0)
    {
        list($tierWidth, $tierHeight) = $this->_scaleInfo[$tier];
        $rowsForTier = floor($tierHeight / $this->tileSize);
        if ($tierHeight % $this->tileSize > 0) {
            ++$rowsForTier;
        }

        list($root, $ext) = $this->getRootAndDotExtension($this->_imageFilename);

        $imageRow = null;
        $imageRowSize = [];
        $tempRow = null;
        $isThereFirstRowFile = false;
        $isThereSecondRowFile = false;

        // Create row for the current tier.
        // First tier.
        if ($tier === count($this->_scaleInfo) - 1) {
            $firstTierRowFile = $root . '-' . $tier . '-' . $row . '.' . $ext;
            if (is_file($firstTierRowFile)) {
                $imageRow = ' -format ' . $this->_tileExt;
                $imageRow .= ' -quality ' . (int) $this->tileQuality;
                $imageRow .= ' ' . escapeshellarg($firstTierRowFile);
                list($imageRowSize['width'], $imageRowSize['height']) = getimagesize($firstTierRowFile);
            }
        }

        // Instead of use of original image, the image for the current tier is
        // rebuild from the previous tier's row (first and eventual second
        // rows). It allows a quicker resize.
        // TODO Use an automagic tiling and check if it's quicker.
        else {
            // Create an empty file in case where there are no first row file.
            $imageRow = ' -format ' . $this->_tileExt;
            $imageRow .= ' -quality ' . (int) $this->tileQuality;
            $imageRow .= ' -size ' . escapeshellarg(sprintf('%dx%d', $tierWidth, $this->tileSize));
            $imageRowSize = [];
            $imageRowSize['width'] = $tierWidth;
            $imageRowSize['height'] = $this->tileSize;

            $t = $tier + 1;
            $r = $row * 2;

            $firstRowFile = $root . '-' . $t . '-' . $r . '.' . $ext;
            // $firstRowWidth = 0;
            $firstRowHeight = 0;

            $isThereFirstRowFile = is_file($firstRowFile);

            if ($isThereFirstRowFile) {
                // Take all the existing first row image and resize it to tier
                // width and image row half height.
                list(/* $firstRowWidth */, $firstRowHeight) = getimagesize($firstRowFile);
                $imageRow .= ' \( '
                    . escapeshellarg($firstRowFile)
                    . ' -resize ' . escapeshellarg(sprintf('%dx%d!', $tierWidth, $firstRowHeight))
                    . ' \)';
            }

            ++$r;
            $secondRowFile = $root . '-' . $t . '-' . $r . '.' . $ext;
            // $secondRowWidth = 0;
            $secondRowHeight = 0;

            $isThereSecondRowFile = is_file($secondRowFile);

            // There may not be a second row at the bottom of the image…
            // If any, copy this second row file at the bottom of the row image.
            if ($isThereSecondRowFile) {
                // As imageRow isn't empty, the second row file is resized, then
                // copied in the bottom of imageRow, then the second row file is
                // deleted.
                // $imageRowHalfHeight = floor($this->tileSize / 2);
                list(/* $secondRowWidth */, $secondRowHeight) = getimagesize($secondRowFile);
                $imageRow .= ' \( '
                    . escapeshellarg($secondRowFile)
                    . ' -resize ' . escapeshellarg(sprintf('%dx%d!', $tierWidth, $secondRowHeight))
                    . ' \)'
                    . ' -append';
            }

            // The last row may be less than $this->tileSize…
            $tileHeight = $this->tileSize * 2;
            $tierHeightCheck = $firstRowHeight + $secondRowHeight;
            if ($tierHeightCheck < $tileHeight) {
                $imageRow .= ' -crop ' . escapeshellarg(sprintf('%dx%d', $tierWidth, $tierHeightCheck));
            }

            if (!$isThereFirstRowFile) {
                $imageRow = null;
            } else {
                // Materialize the composed row to a temp file to avoid
                // re-executing the composition for each tile.
                $tempRow = tempnam(sys_get_temp_dir(), 'zm_');
                unlink($tempRow);
                $tempRow .= '.' . $this->_tileExt;
                $command = escapeshellarg($this->convertPath) . ' ' . $imageRow . ' +repage ' . escapeshellarg($tempRow);
                $this->execute($command);
                // Source row files are no longer needed.
                @unlink($firstRowFile);
                if ($isThereSecondRowFile) {
                    @unlink($secondRowFile);
                }
                $isThereFirstRowFile = false;
                $isThereSecondRowFile = false;
                // Use the materialized temp file for tiling and resize.
                $imageRow = ' -quality ' . (int) $this->tileQuality . ' ' . escapeshellarg($tempRow);
            }
        }

        // Create tiles for the current image row.
        if ($imageRow) {
            // Cycle through columns, then rows.
            $column = 0;
            $imageWidth = $imageRowSize['width'];
            $imageHeight = isset($tierHeightCheck) ? $tierHeightCheck : $imageRowSize['height'];
            $ul_x = 0;
            $ul_y = 0;
            $lr_x = 0;
            $lr_y = 0;
            // TODO Use an automatic tiling.
            while (!(($lr_x === $imageWidth) && ($lr_y === $imageHeight))) {
                // Set lower right cropping point.
                $lr_x = (($ul_x + $this->tileSize) < $imageWidth)
                    ? $ul_x + $this->tileSize
                    : $imageWidth;
                $lr_y = (($ul_y + $this->tileSize) < $imageHeight)
                    ? $ul_y + $this->tileSize
                    : $imageHeight;
                $width = abs($lr_x - $ul_x);
                $height = abs($lr_y - $ul_y);

                $tileFilename = $this->getFileReference($tier, $column, $row);
                $command = $imageRow
                    . ' -page 0x0+0+0 '
                    . ' -crop ' . escapeshellarg(sprintf('%dx%d+%d+%d', $width, $height, $ul_x, $ul_y));

                $command = escapeshellarg($this->convertPath)
                    . ' ' . $command
                    . ' ' . escapeshellarg($tileFilename);
                $this->execute($command);
                $this->_numberOfTiles++;

                // Set upper left cropping point.
                if ($lr_x === $imageWidth) {
                    $ul_x = 0;
                    $ul_y = $lr_y;
                    $column = 0;
                } else {
                    $ul_x = $lr_x;
                    ++$column;
                }
            }

            // Create a new sample for the current tier, then process next tiers
            // via a recursive call.
            if ($tier > 0) {
                $halfWidth = max(1, floor($imageWidth / 2));
                $halfHeight = max(1, floor($imageHeight / 2));
                // Warning: the name is the current tier, so the file for the
                //previous tier, if it exists, is removed.
                $rowFilename = $root . '-' . $tier . '-' . $row . '.' . $ext;

                $command = $imageRow;
                $command .= ' +repage -flatten'
                    . ' -resize ' . escapeshellarg(sprintf('%sx%s!', $halfWidth, $halfHeight));
                $command = escapeshellarg($this->convertPath)
                    . ' ' . $command
                    . ' ' . escapeshellarg($rowFilename);
                $this->execute($command);
            }

            if ($isThereFirstRowFile) {
                @unlink($firstRowFile);
                if ($isThereSecondRowFile) {
                    @unlink($secondRowFile);
                }
            }
            if ($tempRow) {
                @unlink($tempRow);
            }

            // Process next tiers via a recursive call.
            if ($tier > 0) {
                if ($row % 2 !== 0) {
                    $this->processRowImage($tier - 1, floor(($row - 1) / 2));
                } elseif ($row === $rowsForTier - 1) {
                    $this->processRowImage($tier - 1, floor($row / 2));
                }
            }
        }
    }

    /**
     * Resize and crop an image via convert.
     *
     * @internal For resize, the size is forced (option "!").
     *
     * @param string $source
     * @param string $destination
     * @param array $resize Array with width and height.
     * @param array $crop Array with width, height, upper left x and y.
     * @return bool
     */
    protected function imageResizeCrop($source, $destination, $resize = [], $crop = [])
    {
        $params = [];
        if ($resize) {
            // Clean the canvas only when processing from source.
            $params[] = '+repage';
            $params[] = '-flatten';
            $params[] = '-thumbnail ' . escapeshellarg(sprintf('%sx%s!', $resize['width'], $resize['height']));
        }
        if ($crop) {
            $params[] = '-crop ' . escapeshellarg(sprintf('%dx%d+%d+%d', $crop['width'], $crop['height'], $crop['x'], $crop['y']));
        }
        $params[] = '-quality ' . (int) $this->tileQuality;

        $result = $this->convert($source, $destination, $params);
        return $result;
    }

    /**
     * Helper to process a convert command.
     *
     * @param string $source
     * @param string $destination
     * @param array $params
     * @return bool
     */
    protected function convert($source, $destination, $params)
    {
        $command = sprintf(
            '%s %s %s %s',
            escapeshellarg($this->convertPath),
            escapeshellarg($source . '[0]'),
            implode(' ', $params),
            escapeshellarg($destination)
        );
        $result = $this->execute($command);
        return $result !== false;
    }

    /**
     * Helper to get the command line to convert.
     *
     * @return string
     */
    public function getConvertPath()
    {
        if (is_null($this->convertPath)) {
            $command = 'command -v magick';
            $result = $this->execute($command);
            if (empty($result)) {
                $command = 'command -v convert';
                $result = $this->execute($command);
            }
            $this->convertPath = empty($result) ? '' : trim($result);
        }
        return $this->convertPath;
    }
}
