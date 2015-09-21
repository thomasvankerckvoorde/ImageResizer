<?php
namespace Craft;

class ImageResizerService extends BaseApplicationComponent
{
    // Public Methods
    // =========================================================================

    public function getPlugin()
    {
        return craft()->plugins->getPlugin('imageResizer');
    }

    public function getSettings()
    {
        return $this->getPlugin()->getSettings();
    }

    public function resize($asset)
    {
        // Get the full path of the asset we want to resize
        $path = $this->_getImagePath($asset);

        // If the path is false, we're not allowed to modify images in the source - kill it!
        if (!$path) {
            return true;
        }

        $image = craft()->images->loadImage($path);

        // Our maximum width/height for assets from plugin settings
        $imageWidth = $this->getSettings()->imageWidth;
        $imageHeight = $this->getSettings()->imageHeight;

        // Lets check to see if this image needs resizing. Split into two steps to ensure
        // proper aspect ratio is preserved and no upscaling occurs.
        $hasResized = false;

        if ($image->getWidth() > $imageWidth) {
            $hasResized = true;
            $this->_resizeImage($image, $imageWidth, null);
        }

        if ($image->getHeight() > $imageHeight) {
            $hasResized = true;
            $this->_resizeImage($image, null, $imageHeight);
        }

        if ($hasResized) {
            // Set image quality - but normalise (for PNG)!
            $quality = $this->_getImageQuality($asset);
            $image->setQuality($quality);

            $image->saveAs($path);

            // Update our model
            $asset->size         = IOHelper::getFileSize($path);
            $asset->width        = $image->getWidth();
            $asset->height       = $image->getHeight();

            // Then, make sure we update the asset info as stored in the database
            $fileRecord = AssetFileRecord::model()->findById($asset->id);
            $fileRecord->size         = $asset->size;
            $fileRecord->width        = $asset->width;
            $fileRecord->height       = $asset->height;

            $fileRecord->save(false);
        }

        return $asset;
    }


    // Private Methods
    // =========================================================================

    private function _getImagePath($asset)
    {
        // Get the full path for the asset being uploaded
        $source = $asset->getSource();

        // Can only deal with local assets for now
        if ($source->type != 'Local') {
            return false;
        }

        // Should we be modifying images in this source?
        $assetSources = $this->getSettings()->assetSources;

        if ($assetSources != '*') {
            if (!in_array($source->id, $assetSources)) {
                return false;
            }
        }

        $sourcePath = craft()->config->parseEnvironmentString($source->settings['path']);
        $folderPath = $asset->getFolder()->path;

        return $sourcePath . $folderPath . $asset->filename;
    }

    private function _getImageQuality($asset)
    {
        if ($asset->getExtension() == 'png') {
            // Valid PNG quality settings are 0-9, so normalize and flip, because we're talking about compression
            // levels, not quality, like jpg and gif.
            $quality = round(($this->getSettings()->imageQuality * 9) / 100);
            $quality = 9 - $quality;

            if ($quality < 0) {
                $quality = 0;
            }

            if ($quality > 9) {
                $quality = 9;
            }
        } else {
            $quality = $this->getSettings()->imageQuality;
        }

        return $quality;
    }

    private function _resizeImage(&$image, $width, $height)
    {
        // Calculate the missing width/height for the asset - ensure aspect ratio is maintained
        $dimensions = ImageHelper::calculateMissingDimension($width, $height, $image->getWidth(), $image->getHeight());

        $image->resize($dimensions[0], $dimensions[1]);
    }
}