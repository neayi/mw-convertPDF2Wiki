# mw-convertPDF2Wiki
An extension that allows a PDF to be imported as a Wiki page, extracting images and text as much as possible

# Usage
This extension adds a special page `Special:ImportPDF` that allows you to upload a PDF file (or to point to the URL of a PDF file somewhere on the web), and then converts the PDF to wiki, creating a new page.

The process is as follows:
1. Select the PDF file
2. Choose the images you want to keep (get rid of logos or other non essential images)
3. Rotate images that might be upside down
4. Select a title for the new page in the wiki (a default title is guessed from the PDF document)
5. Edit your page to make polish the details (tables might need to be recreated, etc...)

The selected images are imported with a name that matches the title of the page, and are added at the bottom of the page in case they don't appear in the flow of the text.

If the title matches an existing page, the converted text is added at the bottom of the existing page.
  

# Installation
Download and rename the extension so that it is placed in your extension folder: `extensions/ConvertPDF2Wiki`

Add `wfLoadExtension( 'ConvertPDF2Wiki' );`  to your LocalSettings.php 

The extension relies on the following 3 utilities that must be installed as well:
## Image magick
Image magick is used to rotate the images. See: https://imagemagick.org/

To install:
```
pecl  install  imagick
```
## pdf2docx 
pdf2docx is used to convert the PDF to a DOCX document (more robust than HTML). See : https://github.com/ArtifexSoftware/pdf2docx

To install: 
```
pip install pdf2docx
```
## Pandoc
Pandoc is used to convert from HTML to Wikitext : https://pandoc.org/installing.html
To install: 
```
apt-get install pandoc
```
