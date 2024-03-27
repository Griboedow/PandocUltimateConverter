<?php
namespace MediaWiki\Extension\PandocUltimateConverter;

use MediaWiki\Shell\Shell;
use MediaWiki\MediaWikiServices;

function findFiles ($dir, &$results = array()) {
    $files = scandir($dir);

    foreach ($files as $key => $value) {
        $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
        if (!is_dir($path)) {
            $results[] = $path;
        } else if ($value != "." && $value != "..") {
            findFiles($path, $results);
            $results[] = $path;
        }
    }

    return $results;
}

class PandocWrapper{
    private static $supportedFormats = [
        "docx" => "docx",
        "md" => "markdown",
        "markdown" => "markdown"
    ];


    public static function convert($filePath){
        global $wgPandocExecutablePath;
        global $wgPandocTmpFolderPath;

        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $base_name = pathinfo($filePath, PATHINFO_FILENAME);
        $subfolder_name = join(DIRECTORY_SEPARATOR, [$wgPandocTmpFolderPath, pathinfo($filePath, PATHINFO_FILENAME)]);
        $subfolder_name = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $subfolder_name);

        // Try to upload even if format is not in the list
        // In the future we may want to extend list of formats.
        $sourceFormat = self::$supportedFormats[$ext] ?? $ext;
		$res = Shell::command(
			$wgPandocExecutablePath,
			'--from=' . $sourceFormat,
			'--to=mediawiki',
            '--extract-media='. $subfolder_name,
			$filePath
		  )->includeStderr()
			->execute();

        //Return text part and path to folder
        return [
            "text" => $res->getStdout(),
            "baseName" =>  $base_name,
            "mediaFolder" => $subfolder_name
        ];
    }

    public static function processImages($subfolder_name, $base_name, $user){
        $imagesVocabulary = [];

        $services = MediaWikiServices::getInstance();

        $files = findFiles( $subfolder_name );
        foreach ($files as $file){
            $base = wfBaseName( $file );
            $file_page_name = $base_name . '-' . $base;
            $title = \Title::makeTitleSafe( NS_FILE, $file_page_name );
            $image = $services->getRepoGroup()->getLocalRepo()
                ->newFile( $title );
            
            $sha1 = \FSFile::getSha1Base36FromPath( $file );
            
            # check duplicates, skip upload duplicate (use duplicate)
            # TODO - make it an optioal parameter?
            $repo = $image->getRepo();
            $dupes = $repo->findBySha1( $sha1 );
            if ( $dupes ) {
                $imagesVocabulary[$file] = $dupes[0]->getName();
                continue; 
            }

            # Upload missing file
            $flags = 0;
            $publishOptions = [];
            $archive = $image->publish( $file, $flags, $publishOptions );
            if ( !$archive->isGood() ) {
                $errorMesg = $archive->getMessage( false, false, 'en' )->text() . "\n";
                throw new \Exception ($errorMesg );
            }
            $mwProps = new \MWFileProps( $services->getMimeAnalyzer() );
            if ($image->recordUpload3(
                $archive->value,
                wfMessage("pandocultimateconverter-history-comment"), 
                '',
                $user,
                $mwProps->getPropsFromPath( $file, true )
            )->isOK()){
                $imagesVocabulary[$file] = $file_page_name;
                continue;
            }
            else{
                throw new \Exception('Failed to upload ' . $file);
            }
            
        }

        return $imagesVocabulary;
    }

    public static function deleteDirectory($dir) {
        if (!file_exists($dir)) {
            return true;
        }
    
        if (!is_dir($dir)) {
            return unlink($dir);
        }
    
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
    
            if (!self::deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
    
        }
    
        return rmdir($dir);
    }

}