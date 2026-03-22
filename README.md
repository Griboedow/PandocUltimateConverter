PandocUltimateConverter is a Pandoc converter extension for MediaWiki which converts files and webpages and imports not only text, but also images.

MediaWiki page: https://www.mediawiki.org/wiki/Extension:PandocUltimateConverter

# Prerequisites
Tested on MediaWiki 1.39 - 1.43

Requires pandoc to be installed.

For **PDF import**: requires [poppler](https://poppler.freedesktop.org/) (specifically the `pdftohtml` utility).

Tested on Windows and Linux (Ubuntu).

# Installation
Installation is just a bit more complicated than usual:
1. [Install pandoc](https://pandoc.org/installing.html)
2. Download extension
3. Load the extension in LocalSettings.php ```wfLoadExtension( 'PandocUltimateConverter' );```
4. Configure path to pandoc binary ```$wgPandocUltimateConverter_PandocExecutablePath = 'C:\Program Files\Pandoc\pandoc.exe';```. It will work without this param if pandoc is in the PATH env. variable
5. [Optional] **For PDF support**: install poppler and configure the path (see [Installing poppler for PDF support](#installing-poppler-for-pdf-support) below)
6. [Optional] Configure path to a temp folder where pandoc will store images before upload ```$wgPandocUltimateConverter_TempFolderPath = 'D:\_TMP';```. It will try to use default temp folder if not specified. 
7. Allow additional file extensions to be uploaded to MediaWiki
```php
$wgFileExtensions[] = 'docx';
$wgFileExtensions[] = 'odt';
$wgFileExtensions[] = 'pdf';
// You can specify other required extensions as well
```

TL;DR:
```php
$wgEnableUploads = true;

$wgFileExtensions[] = 'docx';
$wgFileExtensions[] = 'odt';
$wgFileExtensions[] = 'pdf';
$wgPandocUltimateConverter_PandocExecutablePath = '/your/path/to/pandoc'; # For example, 'C:\Program Files\Pandoc\pandoc.exe'

wfLoadExtension( 'PandocUltimateConverter' );
```

# Usage
Follow these steps:
1. Go to ```Special:PandocUltimateConverter``` page. You can also open legacy page via Special:PandocUltimateConverter&codex=0
<img width="1048" height="406" alt="image" src="https://github.com/user-attachments/assets/8ffe8a6c-92d1-4bec-bf43-e98c3fecb612" />

2. Choose what to convert: file or webpage (URL).

3. Specify file (or URL) to convert and target page name.
   
4. After the file conversion is finished, you will be redirected to the target page
   - Source file will be automatically removed from the wiki
   - All the images will be automatically uploaded to MediaWiki with a name ```Pandocultimateconverter-{guid}-{imageOriginalNameAndExtension}```
   - If the image is already present on wiki, the image duplicate will not be uploaded. We will just use the existing image.
   - All the images will be automatically removed from the temp folder

5. (Optional) Ask any LLM (chatGPT, Claude,etc) to cleanup page (you can copy-paste source code to it). Typicalyy they handle such tasks quite well and that would much cheaper than converting the whole page via LLM.

# Supported formats
Theoretically it supports [everything Pandoc supports](https://pandoc.org/MANUAL.html#general-options). Tested formats: **DOCX**, **ODT**, and **PDF**.

**PDF support** works via a two-step pipeline: poppler's `pdftohtml` first converts the PDF to HTML with extracted images, then Pandoc converts that HTML to MediaWiki wikitext. Embedded images are automatically extracted and uploaded to the wiki. This works with text-based PDFs (not scanned documents that would require OCR).

Webpages can be imported as well. Pandoc does not work very well with webpages, but it might be helpful if the webpage contains a lot of images and other files.

# Simple demo
## Convert file
Simple gif to show how it works for files:
![Pandoc-demo-file](https://github.com/user-attachments/assets/4339883c-913e-422c-b859-d8df55c80637)


## Convert webpage (URL)
And another gif to show demo for importing a webpage:
![Pandoc-demo-url](https://github.com/user-attachments/assets/928c1822-8913-4071-b5e1-9fdfa161575d)


# Advanced configuration
There are additional configs:
1.  ```$wgPandocUltimateConverter_MediaFileExtensionsToSkip = [ 'emf' ];``` -- You can specify array of extensions which should not be uploaded to MediaWiki as a file. For example, emf images are not supported in web, and you there is no reason to upload them. The config is case insensitive.
2.  ```$wgPandocUltimateConverter_UseColorProcessors = true;``` -- Controls whether ODT/DOCX color preprocessing is enabled. Set to `true` to preserve text/background colors from Word and LibreOffice documents. This is the new parameter for issue #14.
3. Global configs ```$wgPandocExecutablePath``` and ```$wgPandocTmpFolderPath ``` are still working but we recommend to switch to confiuration parameteres ```$wgPandocUltimateConverter_PandocExecutablePath``` and ```$wgPandocUltimateConverter_TempFolderPath```.
4. You can specify custom user rights for the extensions: via ```$wgPandocUltimateConverter_PandocCustomUserRight``` where you can specify the [required permission](https://www.mediawiki.org/wiki/Manual:User_rights#List_of_permissions). For example: ```$wgPandocUltimateConverter_PandocCustomUserRight = 'nominornewtalk';``` should prohibit access for non-bots:

![image](https://github.com/user-attachments/assets/550ec70b-60fe-4074-b0aa-acb475aed9ab)

## Installing poppler for PDF support
PDF import requires [poppler](https://poppler.freedesktop.org/)'s `pdftohtml` utility. If `pdftohtml` is not installed, PDF files will fail to convert — all other formats will continue to work normally.

**Linux (Debian/Ubuntu):**
```bash
sudo apt install poppler-utils
```

**Linux (RHEL/Fedora):**
```bash
sudo dnf install poppler-utils
```

**Windows (Chocolatey):**
```powershell
choco install poppler
```

**Windows (manual):**
1. Download the latest release from https://github.com/oschwartz10612/poppler-windows/releases
2. Extract to a folder (e.g. `C:\poppler`)
3. Either add `C:\poppler\Library\bin` to your system PATH, or configure the path in `LocalSettings.php`:
```php
$wgPandocUltimateConverter_PdfToHtmlExecutablePath = 'C:\poppler\Library\bin\pdftohtml.exe';
```

If `pdftohtml` is already in your PATH, no additional configuration is needed — the extension will find it automatically.

## Specifying custom Pandoc filters
You can specify custom [Pandoc filters](https://pandoc.org/filters.html) using ```$wgPandocUltimateConverter_FiltersToUse[] = 'filter_name.lua';``` (multiple filters can be specified). Filter must be located in a ```filters``` subfolder of an extension. We have a few pre-built filters you can use:
1. ```increase_heading_level.lua``` -- increase heading levels by 1. Helps when document has heading starting from level 1 (MediaWiki users typically prefer startin from level 2 headings)
2. ```colorize_mark_class.lua``` -- highlight with yellow all the 'mark' classes. These classes usually appear when you convert a docx with background color. See [Issue #14](https://github.com/Griboedow/PandocUltimateConverter/issues/14)

# Debug
In case you face any issues with the extension, please add these lines to the LocalSettings.php:

```php
error_reporting( -1 );
$wgDebugLogFile = "/var/log/mediawiki/main.log";
$wgDebugLogPrefix = date( '[Y-m-d H:i:s] ' );
$wgShowExceptionDetails = true;
$wgShowDBErrorBacktrace = true;
error_reporting( E_ALL ); ini_set( 'display_errors', 1 );

$wgDebugLogGroups['DBQuery'] =
$wgDebugLogGroups['DBReplication'] =
$wgDebugLogGroups['DBConnection'] =
$wgDebugLogGroups['runJobs'] =
$wgDebugLogGroups['Parsoid'] =
$wgDebugLogGroups['rdbms'] = "/var/log/mediawiki/misc.log";

// Extension-specific log group — all PandocUltimateConverter messages go here
$wgDebugLogGroups['PandocUltimateConverter'] = "/var/log/mediawiki/pandoc.log";
```
The extension writes its own diagnostic messages to the `PandocUltimateConverter` log group. Configuring that group to a dedicated file (e.g. `pandoc.log`) makes it much easier to trace conversion issues without digging through the main log.

Confirm the issue once more and provide the content of `/var/log/mediawiki/pandoc.log` (or whichever path you specified). You may want to use a different path, especially on Windows.

# Action API
The extension exposes a `pandocconvert` action API module so that conversions can be triggered programmatically.

## Authentication
The module requires a **CSRF token** and the caller must be logged in (or be a bot account). Obtain a token with:
```
GET /api.php?action=query&meta=tokens&format=json
```

## Convert a URL
```
POST /api.php
action=pandocconvert
url=https://example.com/article
pagename=My Article
forceoverwrite=1
token=<csrf-token>
format=json
```

## Convert an already-uploaded file
Upload the file first via the standard `action=upload` API, then call:
```
POST /api.php
action=pandocconvert
filename=Document.docx
pagename=My Article
forceoverwrite=1
token=<csrf-token>
format=json
```
The source file is **not** automatically deleted — remove it afterwards with `action=delete` if desired.

## Parameters
| Parameter | Required | Description |
|-----------|----------|-------------|
| `pagename` | yes | Target wiki page title. |
| `filename` | one of | Name of an already-uploaded file in the wiki (e.g. `Document.docx`). Mutually exclusive with `url`. |
| `url` | one of | Remote `http`/`https` URL to fetch and convert. Mutually exclusive with `filename`. |
| `forceoverwrite` | no | Set to `1` to overwrite the target page if it already exists (default: `0`). |
| `token` | yes | Standard MediaWiki CSRF token. |

## Successful response
```json
{
  "pandocconvert": {
    "result": "success",
    "pagename": "My Article"
  }
}
```

## Error codes
| Code | Meaning |
|---|---|
| `apierror-pandocultimateconverter-nosource` | Neither `filename` nor `url` was supplied. |
| `apierror-pandocultimateconverter-multiplesource` | Both `filename` and `url` were supplied. |
| `apierror-pandocultimateconverter-invalidurlscheme` | URL scheme is not `http` or `https`. |
| `apierror-pandocultimateconverter-pageexists` | Target page exists and `forceoverwrite` was not set. |
| `apierror-pandocultimateconverter-conversionfailed` | Pandoc conversion failed (details in the message). |
