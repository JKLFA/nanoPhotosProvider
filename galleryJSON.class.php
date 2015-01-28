<?php

/**
 * galleryJSON add-on for nanoGALLERY (or other image galleries)
 *
 * This is an add-on for nanoGALLERY (image gallery for jQuery - http://nanogallery.brisbois.fr).
 * This PHP application will publish your images and albums from a webserver to nanoGALLERY.
 * The content is provided on demand, one album at one time.
 * Thumbnails are generated automatically.
 * 
 * License: For personal, non-profit organizations, or open source projects (without any kind of fee), you may use nanoGALLERY for free. 
 * -------- ALL OTHER USES REQUIRE THE PURCHASE OF A PROFESSIONAL LICENSE.
 *
 *
 * PHP 5.2+
 * @version    0.2.0
 * @author     Christophe BRISBOIS - http://www.brisbois.fr/
 * @copyright  Copyright 2014
 * @license    CC BY-NC 3.0
 * @link       https://github.com/Kris-B/galleryJSON
 * @Support    https://github.com/Kris-B/galleryJSON/issues
 *
 */
require './nanoPhotosProvider.Encoding.php';

class galleryData
{
    public $fullDir = '';

    //public $images;
    //public $URI;
}

class item
{
    public $src         = '';
    public $srct        = '';
    public $title       = '';
    public $description = '';
    public $ID          = '';
    public $albumID     = '0';
    public $kind        = '';      // album

}

class galleryJSON
{

    protected $config = array();
    public function __construct()
    {
        // retrieve the album ID in the URL
        $album   = '/';
        $albumID = '';
        if (isset($_GET['albumID'])) {
            $albumID = $_GET['albumID'];
        }
        if (!$albumID == '0' && $albumID != '' && $albumID != null) {
            // $album='/'.utf8_decode(urldecode($albumID)).'/';
            $album = '/' . $this->CustomDecode($albumID) . '/';
        } else {
            $albumID = '0';
        }

        $data          = new galleryData();
        $data->fullDir = __DIR__ . '/nanoPhotosContent' . ($album);

        // read configuration
        $config                            = parse_ini_file('./galleryJSON.cfg', true);
        $this->config['fileExtensions']         = $config['config']['fileExtensions'];
        $this->config['sortOrder']              = strtoupper($config['config']['sortOrder']);
        $this->config['titleDescSeparator']     = strtoupper($config['config']['titleDescSeparator']);
        $this->config['albumCoverDetector']     = strtoupper($config['config']['albumCoverDetector']);
        $this->config['albumBlackListDetector'] = strtoupper($config['config']['albumBlackListDetector']);


        if (isset($config['thumbnailSizes']['width']) && isset($config['thumbnailSizes']['height'])) {
            $this->config['thumbnailsGenerate']       = true;
            $this->config['thumbnailSizes']['width']  = $config['thumbnailSizes']['width'];
            $this->config['thumbnailSizes']['height'] = $config['thumbnailSizes']['height'];
            if (isset($config['thumbnailSizes']['crop'])) {
                $this->config['thumbnailSizes']['crop'] = $config['thumbnailSizes']['crop'];
            } else {
                $this->config['thumbnailSizes']['crop'] = false;
            }
        } else {
            $this->config['thumbnailsGenerate'] = false;
        }

        $lstImages = array();
        $lstAlbums = array();

        $dh = opendir($data->fullDir);
        // loop the folder to retrieve images and albums
        if ($dh != false) {
            while (false !== ($filename = readdir($dh))) {
                if ($filename != '.' && $filename != '..' && $filename != '_thumbnails' && substr($filename, 0, strlen($this->config['albumBlackListDetector'])) != $this->config['albumBlackListDetector']) {

                    if (is_file($data->fullDir . $filename) && preg_match("/\.(" . $this->config['fileExtensions'] . ")*$/i", $filename)) {
                        // ONE IMAGE
                        $oneItem = new item();

                        $e                    = $this->GetTitleDesc($filename, true);
                        $oneItem->title       = $e->title;
                        $oneItem->description = $e->description;
                        $oneItem->src         = $this->CustomEncode('nanoPhotosContent' . $album . '/' . $filename);

                        $tn                  = $this->GetThumbnail($data->fullDir, $filename);
                        $oneItem->srct       = $this->CustomEncode('nanoPhotosContent' . $album . $tn);
                        $size                = getimagesize($data->fullDir . $tn);
                        $oneItem->imgtWidth  = $size[0];
                        $oneItem->imgtHeight = $size[1];

                        $oneItem->albumID = $albumID;

                        $lstImages[] = $oneItem;
                    } else {
                        // ONE ALBUM
                        $oneItem       = new item();
                        $oneItem->kind = 'album';

                        $e                    = $this->GetTitleDesc($filename, false);
                        $oneItem->title       = $e->title;
                        $oneItem->description = $e->description;

                        $oneItem->albumID = $albumID;
                        if ($albumID == '0' || $albumID == '') {
                            $oneItem->ID = $this->CustomEncode($filename);
                        } else {
                            $oneItem->ID = $albumID . $this->CustomEncode('/' . $filename);
                        }

                        $s = $this->GetAlbumCover($data->fullDir . $filename . '/');
                        if ($s != '') {
                            // a cover has been found
                            $path = '';
                            if ($albumID == '0') {
                                $path = $filename;
                            } else {
                                $path = $album . '/' . $filename;
                            }
                            $oneItem->srct       = $this->CustomEncode('/nanoPhotosContent/' . $path . '/' . $s);
                            $size                = getimagesize(__DIR__ . '/nanoPhotosContent' . '/' . $path . '/' . $s);
                            $oneItem->imgtWidth  = $size[0];
                            $oneItem->imgtHeight = $size[1];

                            $lstAlbums[] = $oneItem;
                        }
                    }
                }
            }
            closedir($dh);
        }

        // sort data
        usort($lstAlbums, 'compare');
        usort($lstImages, 'compare');

        // return the data
        header('Content-Type: application/json; charset=utf-8');
        $output = json_encode(array_merge($lstAlbums, $lstImages));     // UTF-8 encoding is mandatory
        if (isset($_GET['jsonp'])) {
            // return in JSONP
            echo $_GET['jsonp'] . '(' . $output . ')';
        } else {
            // return in JSON
            echo $output;
        }
    }

    /**
     * RETRIEVE THE COVER IMAGE (THUMBNAIL) OF ONE ALBUM (FOLDER)
     * 
     * @param string $baseFolder
     * @return string
     */
    protected function GetAlbumCover($baseFolder)
    {

        // look for cover image
        $files = glob($baseFolder . '/' . $this->config['albumCoverDetector'] . '*.*');
        if (count($files) > 0) {
            $i = basename($files[0]);
            if (preg_match("/\.(" . $this->config['fileExtensions'] . ")*$/i", $i)) {
                $tn = $this->GetThumbnail($baseFolder, $i);
                if ($tn != '') {
                    return $tn;
                }
            }
        }

        // no cover image found --> use the first image for the cover
        $i = $this->GetFirstImageFolder($baseFolder);
        if ($i != '') {
            $tn = $this->GetThumbnail($baseFolder, $i);
            if ($tn != '') {
                return $tn;
            }
        }

        return '';
    }

    /**
     * Retrieve the first image of one folder --> ALBUM THUMBNAIL
     * 
     * @param string $folder
     * @return string
     */
    protected function GetFirstImageFolder($folder)
    {
        $image = '';

        $dh       = opendir($folder);
        while (false !== ($filename = readdir($dh))) {
            if (is_file($folder . '/' . $filename) && preg_match("/\.(" . $this->config['fileExtensions'] . ")*$/i", $filename)) {
                $image = $filename;
                break;
            }
        }
        closedir($dh);

        return $image;
    }

    /**
     * 
     * @param object $a
     * @param object $b
     * @return int
     */
    protected function compare($a, $b)
    {
        $al = strtolower($a->title);
        $bl = strtolower($b->title);
        if ($al == $bl) {
            return 0;
        }
        $b = false;
        switch ($this->config['sortOrder']) {
            case 'DESC' :
                if ($al < $bl) {
                    $b = true;
                }
                break;
            case 'ASC':
            default:
                if ($al > $bl) {
                    $b = true;
                }
                break;
        }
        return ($b) ? +1 : -1;
    }

    /**
     * RETRIEVE ONE IMAGE'S THUMBNAIL
     * 
     * @param type $baseFolder
     * @param type $filename
     * @return type
     */
    protected function GetThumbnail($baseFolder, $filename)
    {
        $tn = $baseFolder . '_thumbnails/' . $filename;

        if (file_exists($tn)) {
            //if( filemtime($tn) < filemtime($baseFolder.$filename) ) {
            // image file is older as the thumbnail file
            return '_thumbnails/' . $filename;
            //}
        }

        // generate the thumbnail
        $tn = $this->GenerateThumbnail($baseFolder, $filename);
        if ($tn != '') {
            return $tn;
        }

        // fallback: original image (no thumbnail)
        return $filename;
    }

    /**
     * GENERATE ONE THUMBNAIL
     * 
     * @param type $baseFolder
     * @param type $filename
     * @return string
     */
    protected function GenerateThumbnail($baseFolder, $filename)
    {
        if (!$this->config['thumbnailsGenerate']) {
            return '';
        }

        $td = $baseFolder . '/_thumbnails';
        if (!file_exists($td)) {
            mkdir($td, 0777, true);
        }
        //$orgImage = imagecreatefromjpeg($baseFolder.'/'.$filename);

        $size = getimagesize($baseFolder . '/' . $filename);
        switch ($size['mime']) {
            case 'image/jpeg':
                $orgImage = imagecreatefromjpeg($baseFolder . '/' . $filename);
                break;
            case 'image/gif':
                $orgImage = imagecreatefromgif($baseFolder . '/' . $filename);
                break;
            case 'image/png':
                $orgImage = imagecreatefrompng($baseFolder . '/' . $filename);
                break;
            default:
                return '';
                break;
        }
        $width  = $size[0];
        $height = $size[1];

        $tnFilename  = $baseFolder . '/_thumbnails/' . $filename;
        $thumbWidth  = $this->config['thumbnailSizes']['width'];
        $thumbHeight = $this->config['thumbnailSizes']['height'];

        $originalAspect = $width / $height;
        $thumbAspect    = $thumbWidth / $thumbHeight;

        if ($this->config['thumbnailSizes']['crop']) {
            // CROP THE IMAGE
            // some inspiration found in donkeyGallery (from Gix075) https://github.com/Gix075/donkeyGallery 
            if ($originalAspect >= $thumbAspect) {
                // If image is wider than thumbnail (in aspect ratio sense)
                $newHeight = $thumbHeight;
                $newWidth  = $width / ($height / $thumbHeight);
            } else {
                // If the thumbnail is wider than the image
                $newWidth  = $thumbWidth;
                $newHeight = $height / ($width / $thumbWidth);
            }

            $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);

            // Resize and crop
            imagecopyresampled($thumb, $orgImage, 0 - ($newWidth - $thumbWidth) / 2, // dest_x: Center the image horizontally
                    0 - ($newHeight - $thumbHeight) / 2, // dest-y: Center the image vertically
                    0, 0, // src_x, src_y
                    $newWidth, $newHeight, $width, $height);
        } else {
            // DO NOT CROP
            if ($originalAspect >= $thumbAspect) {
                $newHeight = $height / $width * $thumbWidth;
                $newWidth  = $thumbWidth;
            } else {
                $newWidth  = $width / $height * $thumbHeight;
                $newHeight = $thumbHeight;
            }

            $thumb = imagecreatetruecolor($newWidth, $newHeight);

            // Resize
            imagecopyresampled($thumb, $orgImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        }

        switch ($size['mime']) {
            case 'image/jpeg':
                imagejpeg($thumb, $tnFilename, 90);
                break;
            case 'image/gif':
                imagegif($thumb, $tnFilename);
                break;
            case 'image/png':
                imagepng($thumb, $tnFilename, 1);
                break;
        }

        return '_thumbnails/' . $filename;
    }

    /**
     * Extract title and description from filename
     * 
     * @param string $filename
     * @param boolean $isImage
     * @return \item
     */
    protected function GetTitleDesc($filename, $isImage)
    {
        if ($isImage) {
            $filename = $this->file_ext_strip($filename);
        }

        $oneItem = new item();
        if (strpos($filename, $this->config['titleDescSeparator']) > 0) {
            // title and description
            $s              = explode($this->config['titleDescSeparator'], $filename);
            $oneItem->title = $this->CustomEncode($s[0]);
            if ($isImage) {
                $oneItem->description = $this->CustomEncode(preg_replace('/.[^.]*$/', '', $s[1]));
            } else {
                $oneItem->description = $this->CustomEncode($s[1]);
            }
        } else {
            // only title
            if ($isImage) {
                $oneItem->title = $this->CustomEncode($filename);  //(preg_replace('/.[^.]*$/', '', $filename));
            } else {
                $oneItem->title = $this->CustomEncode($filename);
            }
            $oneItem->description = '';
        }

        $oneItem->title = str_replace($this->config['albumCoverDetector'], '', $oneItem->title);   // filter cover detector string
        return $oneItem;
    }

    /**
     * Returns only the file extension (without the period).
     * 
     * @param string $filename
     * @return string
     */
    protected function file_ext($filename)
    {
        if (!preg_match('/./', $filename)) {
            return '';
        }
        return preg_replace('/^.*./', '', $filename);
    }

    /**
     * Returns the file name, less the extension.
     * 
     * @param string $filename
     * @return string
     */
    protected function file_ext_strip($filename)
    {
        return preg_replace('/.[^.]*$/', '', $filename);
    }

    /**
     * 
     * @param string $s
     * @return string
     */
    protected function CustomEncode($s)
    {
        return \ForceUTF8\Encoding::toUTF8(($s));
        //return \ForceUTF8\Encoding::fixUTF8(($s));
    }

    /**
     * 
     * @param type $s
     * @return type
     */
    protected function CustomDecode($s)
    {
        return utf8_decode($s);
        // return $s;
    }

}