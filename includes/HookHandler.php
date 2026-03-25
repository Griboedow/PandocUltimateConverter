<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter;

use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use SkinTemplate;

class HookHandler implements SkinTemplateNavigation__UniversalHook {

	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		$config = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'PandocUltimateConverter' );

		if ( !$config->get( 'PandocUltimateConverter_ShowExportInPageTools' ) ) {
			return;
		}

		$title = $sktemplate->getRelevantTitle();

		// Only show the Export action on content pages (not on special pages, etc.)
		if ( !$title || $title->isSpecialPage() || !$title->exists() ) {
			return;
		}

		$exportUrl = SpecialPage::getTitleFor( 'PandocExport', $title->getPrefixedDBkey() )
			->getLocalURL();

		$links['actions']['pandocexport'] = [
			'text' => $sktemplate->msg( 'pandocultimateconverter-toolbox-export' )->text(),
			'href' => $exportUrl,
		];
	}
}
