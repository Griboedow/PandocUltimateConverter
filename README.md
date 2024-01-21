# PandocUltimateConverter
Pandoc converter extension for mediawiki which imports not only text, but also images

# Prerequisites
Tested on MediaWiki 1.41. May work on earlier versions (but I would not expect anything earlier than 1.39 to work).

Requires pandoc to be installed.

Should work on WIndows and Linux. Tested on Windows only (bur reports are welcomed). 

# Installation
Installation is just a bit more complicated than usual:
1. [Install pandoc](https://pandoc.org/installing.html)
2. Download extension
3. Load the extension in LocalSettings.php
4. Configure path to pandoc binary ```php $wgPandocExecutablePath = 'C:\Program Files\Pandoc\pandoc.exe';```
6. Configure path to a temp folder where pandoc will store images before upload ```php $wgPandocTmpFolderPath = 'D:\_TMP';```
7. Allow additional file extensions to be uploaded to MediaWiki
```php
$wgFileExtensions[] = 'docx';
$wgFileExtensions[] = 'odt';
// You can specify other reuried extensions as well
```
   

# Supported formats
Theoretically it supports [everyting Pandoc supports](https://pandoc.org/MANUAL.html#general-options). On practice, I've tested for docx and odt only. 

PDF is not supported as input format of pandoc.

# Simple demo
Simple gif to hsow how it works:
![PandocConverterGif](https://github.com/Griboedow/PandocUltimateConverter/assets/4194526/4be5a325-f95e-4e62-b9ce-e6189d6ee8fa)
