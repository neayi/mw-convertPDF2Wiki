<?php
/**
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
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

namespace MediaWiki\Extension\ConvertPDF2Wiki;

use ApiBase;
use ApiMain;
use Wikimedia\ParamValidator\ParamValidator;

class RotateImageFromPDF extends ApiBase {

	/**
	 * @param ApiMain $main main module
	 * @param string $action name of this module
	 */
	public function __construct( $main, $action ) {
		parent::__construct( $main, $action );
	}

	/**
	 * execute the API request
	 */
	public function execute() {
		$params = $this->extractRequestParams();

		$session = $this->getRequest()->getSession();

		$r = [];

		try {
			// '/images/fr/ConvertPDF_5c52a125027b2418d2f03912a092…/113ac52d5be96207966b0eba37e86a158073f1e5-2_6.jpg'
			$imageName = $params['image'];

			// Remove the part after the question mark
			$imageName = preg_replace('@\?.*$@', '', $imageName);
			$imageDir = basename(dirname($imageName));
						
			// "/var/www/html/images/fr/temp/ConvertPDF_5c52a125027b2418d2f03912a092332e"
			$tempDir = $session->get( 'ConvertPDF_tempDir' );

			if (basename($tempDir) != $imageDir)
				throw new \Exception("Error: please call this API from the ConvertPDF only", 1);
			
			$visibleTempFolder = $GLOBALS['wgUploadDirectory'] . '/' . basename($tempDir);

			$filename = $visibleTempFolder . '/' . basename($imageName);
			$command = "mogrify -rotate -90 \"$filename\"";

			exec($command, $output, $result_code);

			if ($result_code)
				throw new \Exception("Failed to execute command: " . $command, $result_code);

            // Rotate image 
            $r['success'] = 'success';

		} catch (\Exception $e) {
			$r['error'] = $e->getMessage();
		}

        $apiResult = $this->getResult();
        $apiResult->addValue( null, $this->getModuleName(), $r );
	}
    
	/**
	 * @return array allowed parameters
	 */
	public function getAllowedParams() {
		return [
			'image' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true
			]
		];
	}

	/**
	 * @return array examples of the use of this API module
	 */
	public function getExamplesMessages() {
		return [
			'action=' . $this->getModuleName() . '&image=someImage.png' =>
			'apihelp-' . $this->getModuleName() . '-example'
		];
	}

	/**
	 * @return string indicates that this API module does not require a CSRF token
	 */
	public function needsToken() {
		return false;
	}
}
