<?php

namespace MediaWiki\Extension\PandocUltimateConverter;

use MediaWiki\Shell\Shell;
use MediaWiki\MediaWikiServices;

function findFiles($dir, &$results = array())
{
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


/*
 TODO: set useful context like MW services, context, user, etc in constructor.
 It looks ugly when I look for it in every single method. 
*/
class PandocWrapper
{
    public static function convertInternal($source, $base_name, $format = null)
    {
        // Legacy config from globals
        global $wgPandocExecutablePath;
        global $wgPandocTmpFolderPath;

        // New config
        $config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig('PandocUltimateConverter');
        $pandocExecutablePath = $wgPandocExecutablePath ?? $config->get('PandocUltimateConverter_PandocExecutablePath') ?? 'pandoc';
        $tempFolderPath = $wgPandocTmpFolderPath ?? $config->get('PandocUltimateConverter_TempFolderPath') ?? sys_get_temp_dir();
        $subfolder_name = join(DIRECTORY_SEPARATOR, [$tempFolderPath, $base_name]);
        $subfolder_name = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $subfolder_name);


        // Try to upload even if format is not in the list
        // In the future we may want to extend list of formats.
        $commands = [
            $pandocExecutablePath,
            '--to=mediawiki',
            '--extract-media=' . $subfolder_name,
            '--request-header=User-Agent:"Mozilla/5.0"',
            $source
        ];
        if ($format) {
            $commands[] = '--from=' . $format;
        }

        $envArr = getenv();
        if (!is_array($envArr)) {
            $envArr = [];
        }
        $res = Shell::command(
            $commands
        )->environment($envArr) //network stack does not work without it
            ->includeStderr()
            ->execute();

        //Return text part and path to folder
        return [
            "text" => $res->getStdout(),
            "baseName" =>  $base_name,
            "mediaFolder" => $subfolder_name
        ];
    }

    public static function convertFile($filePath)
    {
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $base_name = pathinfo($filePath, PATHINFO_FILENAME);
        return PandocWrapper::convertInternal($filePath, $base_name);
    }


    public static function convertUrl($sourceUrl)
    {
        $base_name = parse_url($sourceUrl, PHP_URL_HOST);
        // html works better with format specified for smth like https://github.com/Griboedow/PandocUltimateConverter/blob/main/README.md
        return PandocWrapper::convertInternal($sourceUrl, $base_name, 'html');
    }

    public static function processImages($subfolder_name, $base_name, $user)
    {
        $services = MediaWikiServices::getInstance();
        $config = $services->getConfigFactory()->makeConfig('PandocUltimateConverter');
        $mediaFilesExtensionsToSkip = $config->get('PandocUltimateConverter_MediaFileExtensionsToSkip') ?? [];

        $imagesVocabulary = [];

        $files = findFiles($subfolder_name);
        foreach ($files as $file) {
            //TODO: find why findFiles method returns directories sometimes
            if (is_dir($file)) {
                continue;
            }

            // Skip uploading unsupported media files
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            if (in_array(strtolower($extension), array_map('strtolower', $mediaFilesExtensionsToSkip))) {
                continue;
            }

            // TODO: move upload single file to a spearate method
            $base = wfBaseName($file);
            $file_page_name = $base_name . '-' . $base;
            $title = \Title::makeTitleSafe(NS_FILE, $file_page_name);
            $image = $services->getRepoGroup()->getLocalRepo()
                ->newFile($title);

            $sha1 = \FSFile::getSha1Base36FromPath($file);

            # check duplicates, skip upload duplicate (use duplicate)
            # TODO - make it an optioal parameter?
            $repo = $image->getRepo();
            $dupes = $repo->findBySha1($sha1);
            if ($dupes) {
                $imagesVocabulary[$file] = $dupes[0]->getName();
                continue;
            }

            # Upload missing file
            $flags = 0;
            $publishOptions = [];
            $archive = $image->publish($file, $flags, $publishOptions);
            if (!$archive->isGood()) {
                $errorMesg = $archive->getMessage(false, false, 'en')->text() . "\n";
                throw new \Exception($errorMesg);
            }
            $mwProps = new \MWFileProps($services->getMimeAnalyzer());
            if ($image->recordUpload3(
                $archive->value,
                wfMessage("pandocultimateconverter-history-comment")->text(),
                '',
                $user,
                $mwProps->getPropsFromPath($file, true)
            )->isOK()) {
                $imagesVocabulary[$file] = $file_page_name;
                continue;
            } else {
                throw new \Exception('Failed to upload ' . $file);
            }
        }

        return $imagesVocabulary;
    }

    public static function deleteDirectory($dir)
    {
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
