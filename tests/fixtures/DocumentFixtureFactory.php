<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Fixtures;

use ZipArchive;

/**
 * Programmatically generates minimal DOCX, ODT, and HTML fixture files that can
 * be used by integration tests without shipping large binary blobs in the repo.
 *
 * The generated files are valid enough for Pandoc to process them; they contain
 * a heading, a paragraph of body text, and a simple two-column table.
 */
class DocumentFixtureFactory {

	// ------------------------------------------------------------------
	// DOCX (Office Open XML — ZIP of XML parts)
	// ------------------------------------------------------------------

	/**
	 * Create a minimal but valid DOCX file at the given path.
	 *
	 * @param string $destPath Absolute path where the .docx file should be written.
	 */
	public static function createDocx( string $destPath ): void {
		$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'docx_fixture_' . uniqid();
		mkdir( $tmpDir . DIRECTORY_SEPARATOR . 'word', 0755, true );
		mkdir( $tmpDir . DIRECTORY_SEPARATOR . '_rels', 0755, true );
		mkdir( $tmpDir . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . '_rels', 0755, true );

		// [Content_Types].xml
		file_put_contents( $tmpDir . DIRECTORY_SEPARATOR . '[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>
XML
		);

		// _rels/.rels
		file_put_contents( $tmpDir . DIRECTORY_SEPARATOR . '_rels' . DIRECTORY_SEPARATOR . '.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
    Target="word/document.xml"/>
</Relationships>
XML
		);

		// word/_rels/document.xml.rels
		file_put_contents(
			$tmpDir . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . '_rels' . DIRECTORY_SEPARATOR . 'document.xml.rels',
			<<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
</Relationships>
XML
		);

		// word/document.xml — heading + paragraph + table
		file_put_contents( $tmpDir . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . 'document.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas"
            xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <w:p>
      <w:pPr><w:pStyle w:val="Heading1"/></w:pPr>
      <w:r><w:t>Test Heading</w:t></w:r>
    </w:p>
    <w:p>
      <w:r><w:t>This is a test paragraph with some body text.</w:t></w:r>
    </w:p>
    <w:tbl>
      <w:tr>
        <w:tc><w:p><w:r><w:t>Column A</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>Column B</w:t></w:r></w:p></w:tc>
      </w:tr>
      <w:tr>
        <w:tc><w:p><w:r><w:t>Value 1</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>Value 2</w:t></w:r></w:p></w:tc>
      </w:tr>
    </w:tbl>
    <w:sectPr/>
  </w:body>
</w:document>
XML
		);

		// Package into ZIP
		$zip = new ZipArchive();
		$zip->open( $destPath, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		foreach ( self::listFiles( $tmpDir ) as $file ) {
			$relPath = str_replace( DIRECTORY_SEPARATOR, '/', substr( $file, strlen( $tmpDir ) + 1 ) );
			$zip->addFile( $file, $relPath );
		}
		$zip->close();

		// Clean up temp extraction dir
		self::rmdir( $tmpDir );
	}

	// ------------------------------------------------------------------
	// ODT (OpenDocument Text — ZIP of XML parts)
	// ------------------------------------------------------------------

	/**
	 * Create a minimal but valid ODT file at the given path.
	 *
	 * @param string $destPath Absolute path where the .odt file should be written.
	 */
	public static function createOdt( string $destPath ): void {
		$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'odt_fixture_' . uniqid();
		mkdir( $tmpDir, 0755, true );

		// mimetype (must be first entry, uncompressed)
		file_put_contents( $tmpDir . DIRECTORY_SEPARATOR . 'mimetype',
			'application/vnd.oasis.opendocument.text' );

		// META-INF/manifest.xml
		mkdir( $tmpDir . DIRECTORY_SEPARATOR . 'META-INF', 0755, true );
		file_put_contents(
			$tmpDir . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'manifest.xml',
			<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0"
                   manifest:version="1.3">
  <manifest:file-entry manifest:full-path="/"
    manifest:media-type="application/vnd.oasis.opendocument.text"/>
  <manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>
  <manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>
</manifest:manifest>
XML
		);

		// styles.xml (minimal)
		file_put_contents( $tmpDir . DIRECTORY_SEPARATOR . 'styles.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<office:document-styles
  xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
  xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"
  xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"
  office:version="1.3">
  <office:styles/>
  <office:automatic-styles/>
  <office:master-styles/>
</office:document-styles>
XML
		);

		// content.xml — heading + paragraph + table
		file_put_contents( $tmpDir . DIRECTORY_SEPARATOR . 'content.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<office:document-content
  xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
  xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"
  xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"
  xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"
  xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"
  office:version="1.3">
  <office:automatic-styles/>
  <office:body>
    <office:text>
      <text:h text:outline-level="1">Test Heading</text:h>
      <text:p>This is a test paragraph with some body text.</text:p>
      <table:table table:name="TestTable">
        <table:table-column table:number-columns-repeated="2"/>
        <table:table-row>
          <table:table-cell>
            <text:p>Column A</text:p>
          </table:table-cell>
          <table:table-cell>
            <text:p>Column B</text:p>
          </table:table-cell>
        </table:table-row>
        <table:table-row>
          <table:table-cell>
            <text:p>Value 1</text:p>
          </table:table-cell>
          <table:table-cell>
            <text:p>Value 2</text:p>
          </table:table-cell>
        </table:table-row>
      </table:table>
    </office:text>
  </office:body>
</office:document-content>
XML
		);

		// Package into ZIP (mimetype MUST be first, stored uncompressed per ODF spec)
		$zip = new ZipArchive();
		$zip->open( $destPath, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFile(
			$tmpDir . DIRECTORY_SEPARATOR . 'mimetype',
			'mimetype'
		);
		foreach ( self::listFiles( $tmpDir ) as $file ) {
			$relPath = str_replace( DIRECTORY_SEPARATOR, '/', substr( $file, strlen( $tmpDir ) + 1 ) );
			if ( $relPath === 'mimetype' ) {
				continue; // already added
			}
			$zip->addFile( $file, $relPath );
		}
		$zip->close();

		self::rmdir( $tmpDir );
	}

	// ------------------------------------------------------------------
	// HTML (simple static file)
	// ------------------------------------------------------------------

	/**
	 * Create a minimal HTML file at the given path suitable for Pandoc HTML→mediawiki.
	 *
	 * @param string $destPath Absolute path where the .html file should be written.
	 */
	public static function createHtml( string $destPath ): void {
		file_put_contents( $destPath, <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Test Document</title></head>
<body>
<h1>Test Heading</h1>
<p>This is a test paragraph with some <strong>bold</strong> and <em>italic</em> text.</p>
<table>
  <thead>
    <tr><th>Column A</th><th>Column B</th></tr>
  </thead>
  <tbody>
    <tr><td>Value 1</td><td>Value 2</td></tr>
    <tr><td>Value 3</td><td>Value 4</td></tr>
  </tbody>
</table>
<ul>
  <li>Item one</li>
  <li>Item two</li>
</ul>
</body>
</html>
HTML
		);
	}

	// ------------------------------------------------------------------
	// Internal helpers
	// ------------------------------------------------------------------

	/** @return string[] */
	private static function listFiles( string $dir ): array {
		$result = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) ) as $file ) {
			if ( $file->isFile() ) {
				$result[] = $file->getRealPath();
			}
		}
		return $result;
	}

	private static function rmdir( string $dir ): void {
		if ( !is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			is_dir( $path ) ? self::rmdir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}
}
