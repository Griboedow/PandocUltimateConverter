# PandocUltimateConverter
Pandoc converter extension for MediaWiki which imports not only text, but also images.

MediaWiki page: https://www.mediawiki.org/wiki/Extension:PandocUltimateConverter

# Prerequisites
Tested on MediaWiki 1.39, 1.40, 1.41

Requires pandoc to be installed.

Tested on Windows and Linux.

# Installation
Installation is just a bit more complicated than usual:
1. [Install pandoc](https://pandoc.org/installing.html)
2. Download extension
3. Load the extension in LocalSettings.php ```wfLoadExtension( 'PandocUltimateConverter' );```
4. Configure path to pandoc binary ```$wgPandocExecutablePath = 'C:\Program Files\Pandoc\pandoc.exe';```
6. Configure path to a temp folder where pandoc will store images before upload ```$wgPandocTmpFolderPath = 'D:\_TMP';```
7. Allow additional file extensions to be uploaded to MediaWiki
```php
$wgFileExtensions[] = 'docx';
$wgFileExtensions[] = 'odt';
// You can specify other requried extensions as well
```

# Usage
Follow these steps:
1. Go to ```Special:PandocUltimateConverter``` page. ![PandocUltimateConverterExtension](https://github.com/Griboedow/PandocUltimateConverter/assets/4194526/5ac1fcfd-1b2b-442b-a98a-06996f854649)

2. Specify file to convert and target page name.
   - Target page and all the images will be overwritten if they already exist
4. After the file conversion is finished, you will be redirected to the target page
   - Source file will be automatically removed from the wiki
   - All the images will be automatically uploaded to MediaWiki with a name ```Pandocultimateconverter-{guid}-{imageOriginalNameAndExtension}```
   - If the image is already present on wiki, the image duplicate will not be uploaded. We will just use the existing image.
   - All the images will be automatically removed from the temp folder
   

# Supported formats
Theoretically it supports [everything Pandoc supports](https://pandoc.org/MANUAL.html#general-options). On practice, I've tested for docx and odt only. 

PDF is not supported as input format of pandoc.

# Simple demo
Simple gif to show how it works:
![PandocConverterGif](https://github.com/Griboedow/PandocUltimateConverter/assets/4194526/4be5a325-f95e-4e62-b9ce-e6189d6ee8fa)

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
```
Confirm the issue once more and provide the content of /var/log/mediawiki/main.log. You may want to specify different path, especially if you are using WIndows OS.
