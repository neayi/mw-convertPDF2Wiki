<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;

/**
 * importPDF SpecialPage for ConvertPDF2Wiki extension
 *
 * @file
 * @ingroup Extensions
 */
class SpecialImport_PDF extends SpecialPage
{
	private $session;

	public function __construct()
	{
		$this->session = null;

		parent::__construct('import_PDF', 'edit');
	}

	/**
	 * Show the page to the user
	 *
	 * @param string $sub The subpage string argument (if any).
	 */
	public function execute($sub)
	{
		$this->checkPermissions();

		$out = $this->getOutput();
		$out->setPageTitle($this->msg('special-importPDF-title'));
		//		$out->addHelpLink( 'How to become a MediaWiki hacker' );
		//		$out->addWikiMsg( 'special-importPDF-intro' );

		$html = '';

		if (!empty($sub) && !$this->checkFormToken($sub)) {
			$sub = '';
		}

		if (!$this->hasSessionData('ConvertPDF_started'))
			$sub = '';

		switch ($sub) {
			
			case 'Select_images':
				$this->cleanUpSession(false);
				$this->setSessionData('ConvertPDF_started', true);

				try {
					$this->handleUpload();

					$this->extractImagesAndHTML();

					$html = $this->showImagesSelectionForm();

				} catch (RuntimeException $e) {
					$out->showErrorPage( 'special-importPDF-error', 'special-importPDF-uploaderror', [ $e->getMessage() ] );
				}
				
				break;

			case 'Choose_title':
				$this->storeImagesToKeep();
				$html = $this->showSelectPageTitle();
				break;

			case 'Confirm':
				$url = $this->createPage();
				$this->importImages();
				$this->cleanUp();
				$out->redirect( $url );
		
				break;

			default: 
				$this->setSessionData('ConvertPDF_started', true);
				$html = $this->getFirstPage();
		}

		$out->addHTML($html);
	}

	protected function getGroupName()
	{
		return 'other';
	}

	// Return the empty form
	private function getFirstPage()
	{
		$formToken = $this->getNewFormToken('');
		$action = $this->getAction('Select_images');

		$label1 = $this->msg('form-select-a-pdf-from-computer');
		$label2 = $this->msg('form-or-enter-the-url-of-a-pdf-online');
		$submitButton = $this->msg('form-submit');

		$html = <<<HTML
<form method="post" enctype="multipart/form-data" action="{$action}">
    <!-- upload of a single file -->
	{$formToken}

	<div class="form-group">
		<label for="PDFFileId">{$label1}</label>
		<input type="file" class="form-control-file" name="PDFFile" id="PDFFileId">
	</div>

	<div class="form-group">
		<label for="PDFURLId">{$label2}</label>
		<input type="text" class="form-control" name="PDFURL" id="PDFURLId" placeholder="https://somewebsite.com/some_pdf_file.pdf">
  	</div>

    <div class="text-right">
		<button type="submit" class="btn btn-primary">{$submitButton}</button>
	</div>
</form>
HTML;
		return $html;
	}

	private function handleUpload()
	{
		if (!empty($_POST['PDFURL'])) {

			// Case with a remote PDF
			$url = $_POST['PDFURL'];

			$tempDir = $this->createTempDir($url);
			$tempRootFilename = $tempDir . sha1($url);

			$urlParts = parse_url($url);
			
			$ch = curl_init($url);
			$fp = fopen($tempRootFilename, 'wb');

			curl_setopt($ch, CURLOPT_FILE, $fp);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_FAILONERROR, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

			$result = curl_exec($ch);

			if ($result === false) {
				$error = curl_error($ch);
				curl_close($ch);
				fclose($fp);
				throw new RuntimeException('Failed to download the file from the URL - ' . $error);
			}

			curl_close($ch);
			fclose($fp);

			// Keep the filename in case we need it for the page title
			$this->setSessionData('ConvertPDF_originalPDFFilename', basename($urlParts['path']));
			$this->setSessionData('ConvertPDF_originalPDFURL', $url);	
			
		} else if (!empty($_FILES['PDFFile']['tmp_name'])) {

			// Case with a local file upload
			$tempDir = $this->createTempDir($_FILES['PDFFile']['tmp_name']);
			$tempRootFilename = $tempDir . sha1_file($_FILES['PDFFile']['tmp_name']);
	
			// Undefined | Multiple Files | $_FILES Corruption Attack
			// If this request falls under any of them, treat it invalid.
			if ( !isset($_FILES['PDFFile']['error']) ||
				is_array($_FILES['PDFFile']['error']) ) {
				throw new RuntimeException('Invalid parameters.');
			}
	
			// Check $_FILES['PDFFile']['error'] value.
			switch ($_FILES['PDFFile']['error']) {
				case UPLOAD_ERR_OK:
					break;
				case UPLOAD_ERR_NO_FILE:
					throw new RuntimeException('No file sent.');
				case UPLOAD_ERR_INI_SIZE:
				case UPLOAD_ERR_FORM_SIZE:
					throw new RuntimeException('Exceeded filesize limit.');
				default:
					throw new RuntimeException('Unknown errors.');
			}
	
			// You should also check filesize here. 
			if ($_FILES['PDFFile']['size'] > 1000000) {
				throw new RuntimeException('Exceeded filesize limit.');
			}
	
			// DO NOT TRUST $_FILES['PDFFile']['mime'] VALUE !!
			// Check MIME Type by yourself.
			$finfo = new finfo(FILEINFO_MIME_TYPE);
			if (false === $ext = array_search(
				$finfo->file($_FILES['PDFFile']['tmp_name']),
				array(
					'pdf' => 'application/pdf',
				),
				true
			)) {
				throw new RuntimeException('Invalid file format.');
			}
	
			// You should name it uniquely.
			// DO NOT USE $_FILES['PDFFile']['name'] WITHOUT ANY VALIDATION !!
			// On this example, obtain safe unique name from its binary data.
			if (!move_uploaded_file( $_FILES['PDFFile']['tmp_name'], $tempRootFilename )) {
				throw new RuntimeException('Failed to move uploaded file.');
			}
	
			// Keep the filename in case we need it for the page title
			$this->setSessionData('ConvertPDF_originalPDFFilename', $_FILES['PDFFile']['name']);
			$this->setSessionData('ConvertPDF_originalPDFURL', $_FILES['PDFFile']['name']);	
		}
			
		$this->setSessionData('ConvertPDF_tempRootFilename', $tempRootFilename);
		
		return true;	
	}

	private function showImagesSelectionForm() {
		$tempDir = $this->getSessionData('ConvertPDF_tempDir');
		$tempRootFilename = $this->getSessionData('ConvertPDF_tempRootFilename');

		$label1 = $this->msg('form-please-select-the-images-you-want-to-keep');
		$submitButton = $this->msg('form-submit');

		$visibleTempFolder = $GLOBALS['wgUploadDirectory'] . '/' . basename($tempDir);
		if (!is_dir($visibleTempFolder)) {
			mkdir($visibleTempFolder);
		}

		$visibleTempPath = $GLOBALS['wgUploadPath'] . '/' . basename($tempDir);
		$action = $this->getAction('Choose_title');
		$formToken = $this->getNewFormToken('Choose_title');

		// Get all the files with a name of the form: a5bf01512354aeb5b8b7cfc0aec0e86e95145e7d-2_9.jpg
		$images = glob($tempRootFilename . '-*');
		$imagesToChooseFrom = [];
		foreach ($images as $anImage) {
			$finfo = new finfo(FILEINFO_MIME_TYPE);
			if (array_search($finfo->file($anImage), ['image/gif', 'image/jpe', 'image/jpg', 'image/jpeg', 'image/png', 'image/svg+xml'], true)) {
				copy($anImage, $visibleTempFolder . '/' . basename($anImage));
				$imagesToChooseFrom[] = basename($anImage);
			}
		}
		
		$tempDir = $this->setSessionData('ConvertPDF_imagesToChooseFrom', $imagesToChooseFrom);

		$html = <<<HTML
<style>
.imageFromPDF {
    display: inline-block;
}

.imageFromPDF img {
    max-width: 150px;
}

</style>

{$label1}
<form method="post" action="{$action}">{$formToken}<input type="hidden" name="select_images" value="yes">
HTML;


		foreach ($imagesToChooseFrom as $anImage) {
			$imageURL = $visibleTempPath . '/' . $anImage;
			$anImageMD5 = md5($anImage);

			$html .= <<<HTML
			
			<div class="imageFromPDF">
				<div><label for="{$anImage}_id">
					<img src="{$imageURL}">
				</label></div>
				<div class="text-center">
					<input type="checkbox" name="{$anImageMD5}" id="{$anImage}_id">
					<a href="#" class="rotate_image btn btn-primary btn-sm" role="button"><i class="fas fa-undo text-white"></i></a>
				</div>
			</div>
HTML;
		}

		$html .= <<<HTML
		<div class="text-right">
			<button type="submit" class="btn btn-primary">{$submitButton}</button>
		</div></form>	
<script>
( function () {

	function waitForMWAndJquery(callback) {
		// Check every 100ms to see if inJump is available
		var interval = setInterval(function() {
			if (typeof mw !== 'undefined' && typeof mw.Api === 'function' &&
				typeof window.jQuery !== 'undefined' ) {
				clearInterval(interval);  // Stop checking once it's available
				callback();  // Execute the callback function
			}
		}, 100);
	}

	waitForMWAndJquery(function() {
		$(".rotate_image").on('click', function() {
			let image = $( this ).parent().parent().find( 'img' );

			let imageFile = image.attr('src');;

			var api = new mw.Api();
			api.get( {
				'action': 'rotateimagefrompdf',
				'image': imageFile
			} )
			.done( function ( data ) {
				if (data['rotateimagefrompdf']['success'] == 'success') {
					d = new Date();
					image.attr('src', imageFile + "?" + d.getTime());
				}
			} );
		});
	});

}() );


</script>

HTML;

		return $html;
	}

	private function storeImagesToKeep() {
		if (empty($_POST['select_images']))
			return;

		$imagesToKeep = [];
		$imagesToRemove = [];
		
		$imagesToChooseFrom = $this->getSessionData('ConvertPDF_imagesToChooseFrom');
	
		foreach ($imagesToChooseFrom as $imageName) {

			$anImageMD5 = md5($imageName);

			if (isset($_POST[$anImageMD5]))
				$imagesToKeep[] = $imageName;
			else
				$imagesToRemove[] = $imageName;
		}

		$this->setSessionData('ConvertPDF_imagesToKeep', $imagesToKeep);
		$this->setSessionData('ConvertPDF_imagesToRemove', $imagesToRemove);
	}

	private function extractImagesAndHTML() {

		$output = '';
		$result_code = 0;
		$tempRootFilename = $this->getSessionData('ConvertPDF_tempRootFilename');

		// Convert from PDF to HTML
		$command = "pdftohtml -s -p -noframes -nodrm \"$tempRootFilename\" \"$tempRootFilename\"";

		exec($command, $output, $result_code);

		if ($result_code)
			throw new Exception("Failed to execute command: " . $command, $result_code);

		// Keep the html filename so that we can grab its title later
		$this->setSessionData('ConvertPDF_htmlFilename', $tempRootFilename . '.html');
	}

	private function getWikiCode($pageTitle) {
		$htmlFile = $this->getSessionData('ConvertPDF_htmlFilename');

		// Convert from HTML to mediawiki text
		$command = "pandoc \"$htmlFile\" -f html -t mediawiki -s -o \"$htmlFile.wiki\"";

		exec($command, $output, $result_code);
		if ($result_code)
			throw new Exception("Failed to execute command: " . $command, $result_code);
	
		$wikiCode = file_get_contents($htmlFile . ".wiki");
	
		// Replace all div tags and all empty span pairs
		$wikiCode = preg_replace("@</?div[^>]*>@", '', $wikiCode);
		$wikiCode = preg_replace("@<span[^>]*></span>@", '', $wikiCode);
		
		// Replace non breakable spaces (&nbsp;) that might have been added abusively:
		$wikiCode = str_replace(" ", ' ', $wikiCode);

		// Deal with images:
		// [[File:a5bf01512354aeb5b8b7cfc0aec0e86e95145e7d002.png|892x1262px|background image]]
		$imagesToUpload = [];
		$imagesToKeep = $this->getSessionData('ConvertPDF_imagesToKeep');

		foreach ($imagesToKeep as $key => $imageFile) {
			$ext = pathinfo($imageFile, PATHINFO_EXTENSION);

			$newImageName = str_replace('/', ' - ', $pageTitle) . " " . ($key+1) . '.' . $ext;

			$imagesToUpload[$newImageName] = $imageFile;

			$wikiCode = str_replace($imageFile, $newImageName, $wikiCode);

			// Also add the image at the bottom, just in case :
			$wikiCode .= "\n[[File:$newImageName]]\n";
		}
		
		// Remove image tags that are associated with images we didn't keep
		$imagesToRemove = $this->getSessionData('ConvertPDF_imagesToRemove');
		foreach ($imagesToRemove as $imageFile) {
			$wikiCode = preg_replace('@\[\[File:'.preg_quote($imageFile).'[^]]*\]\]@', '', $wikiCode);
		}

		// Also remove background images
		$tempRootFilename = $this->getSessionData('ConvertPDF_tempRootFilename');
		$wikiCode = preg_replace('@\[\[File:'.preg_quote(basename($tempRootFilename)).'[0-9]{3}\.png[^]]*\]\]@', '', $wikiCode);

		// Eventually, add the original URL at the bottom of the page too: 
		$wikiCode .= "\nOriginal source: " . $this->getSessionData('ConvertPDF_originalPDFURL');

		$this->setSessionData('ConvertPDF_imagesToUpload', $imagesToUpload);

		return $wikiCode;
	}

	private function showSelectPageTitle() {
		$defaultPageTitle = htmlspecialchars($this->getDocumentTitle());
		$action = $this->getAction('Confirm');
		$formToken = $this->getNewFormToken('Confirm');
		$label = $this->msg('form-enter-the-title-of-the-page-to-create');
		$helpText = $this->msg('form-if-the-page-already-exists-the-content-of-the-pdf-will-be-appended-at-its-bottom');
		$submitButton = $this->msg('form-submit');
		
		$html = <<<HTML
<form method="post" action="{$action}">
	{$formToken}

	<div class="form-group">
		<label for="PDFPageTitleId">{$label}</label>
		<input type="text" class="form-control" name="PDFPageTitle" id="PDFPageTitleId" value="{$defaultPageTitle}">
		<small class="form-text text-muted">{$helpText}</small>
  	</div>

    <div class="text-right">
		<button type="submit" class="btn btn-primary">{$submitButton}</button>
	</div>
</form>
HTML;
		return $html;
	}

	private function cleanUp() {
		$tempDir = $this->getSessionData('ConvertPDF_tempDir');
		$visibleTempFolder = $GLOBALS['wgUploadDirectory'] . '/' . basename($tempDir);

		wfRecursiveRemoveDir($visibleTempFolder);
		wfRecursiveRemoveDir($tempDir);

		$tempDir = $this->getSessionData('ConvertPDF_tempDir');
		wfRecursiveRemoveDir($tempDir);

		if (empty($this->session))
			$this->session = $this->getRequest()->getSession();

		$this->cleanUpSession();
	}

	private function cleanUpSession() {
		if (empty($this->session))
			$this->session = $this->getRequest()->getSession();

		$this->session->remove('ConvertPDF_defaultDocumentTitle');
		$this->session->remove('ConvertPDF_form_token');
		$this->session->remove('ConvertPDF_form_tokenChoose_title');
		$this->session->remove('ConvertPDF_form_tokenConfirm');
		$this->session->remove('ConvertPDF_htmlFilename');
		$this->session->remove('ConvertPDF_imagesToChooseFrom');
		$this->session->remove('ConvertPDF_imagesToKeep');
		$this->session->remove('ConvertPDF_imagesToRemove');
		$this->session->remove('ConvertPDF_imagesToUpload');
		$this->session->remove('ConvertPDF_originalPDFFilename');
		$this->session->remove('ConvertPDF_originalPDFURL');
		$this->session->remove('ConvertPDF_tempDir');
		$this->session->remove('ConvertPDF_tempRootFilename');
	}

	private function getDocumentTitle() {
		$documentTitle = $this->getSessionData('ConvertPDF_defaultDocumentTitle');

		if (!empty($documentTitle))
			return $documentTitle;

		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$htmlFileName = $this->getSessionData('ConvertPDF_htmlFilename');
		$dom->loadHTMLFile($htmlFileName);
		$titleNodes = $dom->getElementsByTagName('title');
		
		foreach ($titleNodes as $title) {
			$documentTitle = $title->nodeValue;
			break;
		}

		if (empty($documentTitle)) {
			$documentTitle = $this->getSessionData('ConvertPDF_originalPDFFilename');
		}
		
		$this->setSessionData('ConvertPDF_defaultDocumentTitle', $documentTitle);

		return $documentTitle;
	}

	private function createPage() {
		$pageTitle = $_POST['PDFPageTitle'];

		$wikiCode = $this->getWikiCode($pageTitle);

		$titleObj = Title::newFromText ( $pageTitle );
		if (! $titleObj || $titleObj->isExternal ()) {
			trigger_error ( 'Fail to get title ' . $pageTitle, E_USER_WARNING );
			return false;
		}
		if (! $titleObj->canExist ()) {
			trigger_error ( 'Title cannot be created ' . $pageTitle, E_USER_WARNING );
			return false;
		}

		// $pageObj = WikiPage::factory ( $titleObj );
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $titleObj );

		if ($page->exists()) {
			$existingWikiCode = $page->getContent()->getText();
			$wikiCode = $existingWikiCode . "\n\n\n" . $wikiCode;
		}

		$content = $page->getContentHandler()->makeContent( $wikiCode, $page->getTitle() );

		$updater = $page->newPageUpdater( $this->getUser() );
		$updater->setContent( SlotRecord::MAIN, $content );

		$originalSource = $this->getSessionData('ConvertPDF_originalPDFURL');

		$comment = CommentStoreComment::newUnsavedComment( 'Importing content from PDF File: ' . $originalSource );
		$updater->saveRevision( $comment );

		$status = $updater->getStatus();

		if ( $status->isOK() ) 
			return $titleObj->getFullURL();

		throw new Exception("Error while updating page content: " . $status->getMessage( false, false, 'en' )->text(), 1);
	}

	private function importImages() {
		$tempDir = $this->getSessionData('ConvertPDF_tempDir');		
		$visibleTempFolder = $GLOBALS['wgUploadDirectory'] . '/' . basename($tempDir);

		$services = MediaWikiServices::getInstance();

		$imagesToUpload = $this->getSessionData('ConvertPDF_imagesToUpload');

		foreach ( $imagesToUpload as $imageTitle => $basename ) {

			$imageFile = $visibleTempFolder . '/' . $basename;

			# Validate a title
			$title = Title::makeTitleSafe( NS_FILE, $imageTitle );
			if ( !is_object( $title ) ) {
				throw new Exception("{$imageTitle} could not be imported; a valid title cannot be produced", 1);
			}

			# Check existence
			$image = $services->getRepoGroup()->getLocalRepo()->newFile( $title );
			
			if ( $image->exists() ) {
				continue;
			}

			$mwProps = new MWFileProps( $services->getMimeAnalyzer() );
			$props = $mwProps->getPropsFromPath( $imageFile, true );
			$flags = 0;
			$publishOptions = [];
			$handler = MediaHandler::getHandler( $props['mime'] );
			if ( $handler ) {
				$publishOptions['headers'] = $handler->getContentHeaders( $props['metadata'] );
			} else {
				$publishOptions['headers'] = [];
			}

			$archive = $image->publish( $imageFile, $flags, $publishOptions );
			if ( !$archive->isGood() ) {
				throw new Exception($archive->getMessage( false, false, 'en' )->text(), 1);
			}
			
			$commentText = 'Image extracted from PDF File: ' . $this->getSessionData('ConvertPDF_originalPDFURL');
			$license = 'CC-By-SA4';
			$commentText = SpecialUpload::getInitialPageText( $commentText, $license );

			$user = $this->getUser();

			if ( !$image->recordUpload3( $archive->value, $commentText, '', $user, $props )->isOK() ) {
				throw new Exception("failed. (at recordUpload stage)", 1);
			}
		}
	}

	private function getNewFormToken($sub) {
		$formToken = base64_encode(random_bytes(10));
		$this->setSessionData('ConvertPDF_form_token' . $sub, $formToken);

		return '<input type="hidden" name="form_token'. $sub .'" value="'. $formToken . '">';
	}

	private function checkFormToken($sub) {
		$formToken = $this->getSessionData('ConvertPDF_form_token' . $sub);
		if (!empty($_POST['form_token' . $sub]) && $_POST['form_token' . $sub] != $formToken)
			return false;

		return true;
	}

	private function getAction($sub = '') {
		if (!empty($sub))
			$sub = '/' . $sub;

		return str_replace('$1', 'Special:Import_PDF' . $sub, $GLOBALS['wgArticlePath']);
	}

	private function getSessionData($key)
	{
		if (empty($this->session))
			$this->session = $this->getRequest()->getSession();
		
		return $this->session->get( $key );
	}

	private function setSessionData($key, $value)
	{
		if (empty($this->session))
			$this->session = $this->getRequest()->getSession();
		
		return $this->session->set( $key, $value );
	}

	private function hasSessionData($key)
	{
		if (empty($this->session))
			$this->session = $this->getRequest()->getSession();
		
		return $this->session->exists( $key );
	}

	private function createTempDir($originalFilename) {

		$tempDir = wfTempDir() . '/ConvertPDF_' . md5($originalFilename) . '/';

		if (!is_dir($tempDir)) {
			if (!@mkdir($tempDir)) {
				$error = error_get_last();
				throw new RuntimeException($error['message']);
			}	
		}

		$this->setSessionData('ConvertPDF_tempDir', $tempDir);

		return $tempDir;
	}
}
