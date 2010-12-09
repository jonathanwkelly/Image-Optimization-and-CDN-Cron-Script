<?php

/**
 * @author Jonathan W. Kelly <jonathanwkelly@gmail.com>
**/

error_reporting(E_ALL);
ini_set('display_errors', 1);

ini_set('memory_limit', '128M');
ini_set('max_execution_time', 300);

// Define which pieces of the cron should run 
DEFINE("RUN_IMAGE_OPTIMIZE", true); # takes raw images and resamples them to cut down file size
DEFINE("RUN_CDN", true);
DEFINE("PRINT_ERRORS", true);

// This will hold any errors we get along the way
// Set the PRINT_ERRORS constant above to print these out at the end of the script
$arrErrors = array();

// Config for the scripts below
$arrConfig = array();
$arrConfig['image_directory'] = '/path/to/original/image/dir/'; # where the original images are held
$arrConfig['optimized_directory'] = '/path/to/optimized/dir/'; # where the optimized images are held
$arrConfig['resample_quality'] = 70; # adjust lower for less quality, 100 for no quality loss
$arrConfig['rs_api_username'] = 'myusername'; # the username you use to login to your cloud account
$arrConfig['rs_api_key'] = 'XXXXXXXXXXXXXXXXXXXXXXXXX'; # this will need to be generated
$arrConfig['rs_container'] = 'Container Name'; # the name of the container you created to house CDN images

/*
	Image Optimization
*/
if(RUN_IMAGE_OPTIMIZE) {

	// We'll set this to false if something bad happens and we want to abort!
	$boolContinue = true;

	// Open the directory with our non-optimized images
	if(!$rsrcHandle = opendir($arrConfig['image_directory'])) {
		$boolContinue = false;
		$arrErrors[] = 'Could not open image directory';
	}

	if($boolContinue) { 

		// Loop through all the files in this directory
		while(false !== ($strFilename = readdir($rsrcHandle))) {
		
			$boolContinue = true;

			// Make sure this file is a .jpg before we try to do anything with it
			if($boolContinue && strstr($strFilename, '.jpg')) {
			
				$rsrcNewImage = false;
		
				// Get the dimensions of the image we want to optimize
				if($boolContinue) {
					list($intWidth, $intHeight) = getimagesize($arrConfig['image_directory'].$strFilename);
					if(($intWidth < 1) || ($intHeight < 1)) {
						$boolContinue = false;
						$arrErrors[] = 'Could not get dimensions for image '.$strFilename;
					}
				}

				// This is the start of the new image. Create a resource for it
				if($boolContinue) {
					if(!$rsrcNewImage = imagecreatetruecolor($intWidth, $intHeight)) {
						$boolContinue = false;
						$arrErrors[] = 'Could not create true color resource for image '.$strFilename;
					}
				}
			
				// Now create an actual jpg from the existing non-optimized image
				if($boolContinue) {
					if(!$rsrcNewJpg = imagecreatefromjpeg($arrConfig['image_directory'].$strFilename)) {
						$boolContinue = false;
						$arrErrors[] = 'Could not create new jpg for image '.$strFilename;
					}
				}
			
				// Create a resource of the copied jpg image
				if($boolContinue) {
					if(!imagecopyresampled($rsrcNewImage, $rsrcNewJpg, 0, 0, 0, 0, $intWidth, $intHeight, $intWidth, $intHeight)) {
						$boolContinue = false;
						$arrErrors[] = 'Could not create resampled image for '.$strFilename;
					}
				}
			
				// Place the new image in the optimized directory
				if($rsrcNewImage) {

					// Make the new image
					imagejpeg($rsrcNewImage, $arrConfig['optimized_directory'].$strFilename, $arrConfig['resample_quality']);
			
					// Destroy the resources
					imagedestroy($rsrcNewImage);
					imagedestroy($rsrcNewJpg);
				
				}

			}

		}

	}
	
}

/*
	Content Delivery Network upload
*/
if(RUN_CDN) {
	
	// Rackspace API class
	include_once './cloudfiles/cloudfiles.php';
	
	// Reset the continue flag
	$boolContinue = true;

	// Auth & Connect
	$objAuth = new CF_Authentication($arrConfig['rs_api_username'], $arrConfig['rs_api_key']);
	$objAuth->authenticate();
	$objConn = new CF_Connection($objAuth);
	$objImages = $objConn->get_container($arrConfig['rs_container']);

	// Now loop through all the optimized images and make sure they're on the CDN
	if(!$rsrcHandle = opendir($arrConfig['optimized_directory'])) {
		$boolContinue = false;
		$arrErrors[] = 'Could not open optimized image directory';
	}

	if($boolContinue) { 
		
		// Get a list of all the images that have not been added to the CDN
		$arrImages = array();
		$rsrcQuery = mysql_query("SELECT id, image FROM image_record_table WHERE cdn != 1");
		while($arrRow = mysql_fetch_array($rsrcQuery)) {
			$arrImages[$arrRow['id']] = $arrRow['image'];
		}

		// Loop through all the .jpg files in this directory
		while(false !== ($strFilename = readdir($rsrcHandle))) {
		
			// Make sure this file is a .jpg before we try to do anything with it
			if(in_array($strFilename, $arrImages) && $boolContinue && strstr($strFilename, '.jpg')) {
				
				// Get the key for the DB image record
				$intImageKey = array_keys($arrImages, $strFilename);

				// Make an object in the files container
				$objCdnImage = $objImages->create_object($strFilename);
				$strFileName = $arrConfig['optimized_directory'].$strFilename;
				$objCdnImage->load_from_filename($strFileName);
				
				// Update the upload DB record with the RS cloud file filename
				mysql_query("UPDATE image_record_table SET cdn = 1 WHERE id = {$intImageKey} LIMIT 1");
			
			}
		
		}
	
	}

	// Publish all the images in this container
	$objImages->make_public();
	
}

// Handle your errors
if(PRINT_ERRORS && count($arrErrors) > 0) print_r($arrErrors);

?>