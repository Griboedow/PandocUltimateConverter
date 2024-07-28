PandocUltimateConverter is a Pandoc converter extension for MediaWiki which converts files and webpages and imports not only text, but also images.

MediaWiki page: https://www.mediawiki.org/wiki/Extension:PandocUltimateConverter

# Prerequisites
Tested on MediaWiki 1.39, 1.40, 1.41, 1.42

Requires pandoc to be installed.

Tested on Windows and Linux.

# Installation
Installation is just a bit more complicated than usual:
1. [Install pandoc](https://pandoc.org/installing.html)
2. Download extension
3. Load the extension in LocalSettings.php ```wfLoadExtension( 'PandocUltimateConverter' );```
4. Configure path to pandoc binary ```$wgPandocUltimateConverter_PandocExecutablePath = 'C:\Program Files\Pandoc\pandoc.exe';```. It will work without this param if pandoc is in the PATH env. variable
6. [Optional] Configure path to a temp folder where pandoc will store images before upload ```$wgPandocUltimateConverter_TempFolderPath = 'D:\_TMP';```. IT will try to use default temp folder if not specified. 
7. Allow additional file extensions to be uploaded to MediaWiki
```php
$wgFileExtensions[] = 'docx';
$wgFileExtensions[] = 'odt';
// You can specify other requried extensions as well
```

TL;DR:
```php
$wgEnableUploads = true;

$wgFileExtensions[] = 'docx';
$wgFileExtensions[] = 'odt';
$wgPandocUltimateConverter_PandocExecutablePath = '/your/path/to/pandoc'; # For example, 'C:\Program Files\Pandoc\pandoc.exe'

wfLoadExtension( 'PandocUltimateConverter' );
```

# Usage
Follow these steps:
1. Go to ```Special:PandocUltimateConverter``` page. ![PandocUltimateConverter-Extension-with-URL](https://github.com/user-attachments/assets/d935bc66-a7f8-4e1f-9d1f-4442c555570b)

2. Choose what to convert: file or webpage (URL).

3. Specify file (or URL) to convert and target page name.
   - Target page and all the images will be overwritten if they already exist
4. After the file conversion is finished, you will be redirected to the target page
   - Source file will be automatically removed from the wiki
   - All the images will be automatically uploaded to MediaWiki with a name ```Pandocultimateconverter-{guid}-{imageOriginalNameAndExtension}```
   - If the image is already present on wiki, the image duplicate will not be uploaded. We will just use the existing image.
   - All the images will be automatically removed from the temp folder
   

# Supported formats
Theoretically it supports [everything Pandoc supports](https://pandoc.org/MANUAL.html#general-options). On practice, I've tested for docx and odt only. 
PDF is not supported as input format of pandoc.

Webpages can be imported as well. Pandoc does not work very well with webpages, but it might be helpful if the webpage contains a lot of images and other files.

# Simple demo
## Convert file
Simple gif to show how it works for files:
![PandocConverterWordGif](https://github.com/user-attachments/assets/3c52a62c-5647-47a9-a941-37ac2ac3c192)

## Convert webpage (URL)
And another gif to show demo for importing a webpage:
![PandocConverterUrlGif](https://github.com/user-attachments/assets/0c1a8855-a09b-42c8-9e94-003bd5487404)

# Advanced configuration
There are additional configs:
1.  ```$wgPandocUltimateConverter_MediaFileExtensionsToSkip = [ 'emf' ];``` -- You can specify array of extensions which should not be uploaded to MediaWiki as a file. For example, emf images are not supported in web, and you there is no reason to upload them. The config is case insensitive.
2. Global configs ```$wgPandocExecutablePath``` and ```$wgPandocTmpFolderPath ``` are still working but we recommend to switch to confiuration parameteres ```$wgPandocUltimateConverter_PandocExecutablePath``` and ```$wgPandocUltimateConverter_TempFolderPath```.
3. You can specify custom user rights for the extensions: via ```$wgPandocUltimateConverter_PandocCustomUserRight``` where you can specify the [required permission](https://www.mediawiki.org/wiki/Manual:User_rights#List_of_permissions). For example: ```$wgPandocUltimateConverter_PandocCustomUserRight = 'nominornewtalk';``` should prohibit access for non-bots:

![image](https://github.com/user-attachments/assets/550ec70b-60fe-4074-b0aa-acb475aed9ab)

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
Confirm the issue once more and provide the content of /var/log/mediawiki/main.log. You may want to specify different path, especially if you are using Windows OS.
