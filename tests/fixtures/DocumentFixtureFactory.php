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

		// word/document.xml — heading + paragraph + table with proper column/cell widths
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
      <w:tblPr>
        <w:tblStyle w:val="TableGrid"/>
        <w:tblW w:w="9360" w:type="dxa"/>
      </w:tblPr>
      <w:tblGrid>
        <w:gridCol w:w="4680"/>
        <w:gridCol w:w="4680"/>
      </w:tblGrid>
      <w:tr>
        <w:tc>
          <w:tcPr><w:tcW w:w="4680" w:type="dxa"/></w:tcPr>
          <w:p><w:r><w:t>Column A</w:t></w:r></w:p>
        </w:tc>
        <w:tc>
          <w:tcPr><w:tcW w:w="4680" w:type="dxa"/></w:tcPr>
          <w:p><w:r><w:t>Column B</w:t></w:r></w:p>
        </w:tc>
      </w:tr>
      <w:tr>
        <w:tc>
          <w:tcPr><w:tcW w:w="4680" w:type="dxa"/></w:tcPr>
          <w:p><w:r><w:t>Value 1</w:t></w:r></w:p>
        </w:tc>
        <w:tc>
          <w:tcPr><w:tcW w:w="4680" w:type="dxa"/></w:tcPr>
          <w:p><w:r><w:t>Value 2</w:t></w:r></w:p>
        </w:tc>
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
	// Colored DOCX (color preservation tests)
	// ------------------------------------------------------------------

	/**
	 * Create a minimal DOCX file containing runs with inline colour and highlight
	 * attributes so that DOCXColorPreprocessor can be tested end-to-end.
	 *
	 * The document contains:
	 *  - A run with <w:color w:val="FF0000"/> (red foreground)
	 *  - A run with <w:highlight w:val="yellow"/> (yellow background highlight)
	 *  - A plain run without any colour
	 *
	 * @param string $destPath Absolute path where the .docx file should be written.
	 */
	public static function createColoredDocx( string $destPath ): void {
		$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'docx_colored_' . uniqid();
		mkdir( $tmpDir . DIRECTORY_SEPARATOR . 'word', 0755, true );
		mkdir( $tmpDir . DIRECTORY_SEPARATOR . '_rels', 0755, true );
		mkdir( $tmpDir . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . '_rels', 0755, true );

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

		file_put_contents( $tmpDir . DIRECTORY_SEPARATOR . '_rels' . DIRECTORY_SEPARATOR . '.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
    Target="word/document.xml"/>
</Relationships>
XML
		);

		file_put_contents(
			$tmpDir . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . '_rels' . DIRECTORY_SEPARATOR . 'document.xml.rels',
			<<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
</Relationships>
XML
		);

		// document.xml with colour-annotated runs
		file_put_contents( $tmpDir . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . 'document.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <w:p>
      <w:pPr><w:pStyle w:val="Heading1"/></w:pPr>
      <w:r><w:t>Color Test Document</w:t></w:r>
    </w:p>
    <w:p>
      <w:r>
        <w:rPr><w:color w:val="FF0000"/></w:rPr>
        <w:t>red text</w:t>
      </w:r>
    </w:p>
    <w:p>
      <w:r>
        <w:rPr><w:highlight w:val="yellow"/></w:rPr>
        <w:t>highlighted text</w:t>
      </w:r>
    </w:p>
    <w:p>
      <w:r><w:t>normal text after colors</w:t></w:r>
    </w:p>
    <w:sectPr/>
  </w:body>
</w:document>
XML
		);

		$zip = new ZipArchive();
		$zip->open( $destPath, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		foreach ( self::listFiles( $tmpDir ) as $file ) {
			$relPath = str_replace( DIRECTORY_SEPARATOR, '/', substr( $file, strlen( $tmpDir ) + 1 ) );
			$zip->addFile( $file, $relPath );
		}
		$zip->close();

		self::rmdir( $tmpDir );
	}

	// ------------------------------------------------------------------
	// Colored ODT (color preservation tests)
	// ------------------------------------------------------------------

	/**
	 * Create a minimal ODT file containing spans with inline colour styles so that
	 * ODTColorPreprocessor can be tested end-to-end.
	 *
	 * The document contains:
	 *  - A text:span with fo:color="#ff0000" (red foreground)
	 *  - A text:span with fo:background-color="#ffff00" (yellow background)
	 *  - A plain paragraph without colour
	 *
	 * @param string $destPath Absolute path where the .odt file should be written.
	 */
	public static function createColoredOdt( string $destPath ): void {
		$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'odt_colored_' . uniqid();
		mkdir( $tmpDir, 0755, true );

		file_put_contents( $tmpDir . DIRECTORY_SEPARATOR . 'mimetype',
			'application/vnd.oasis.opendocument.text' );

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
  <manifest:file-entry manifest:full-path="styles.xml"  manifest:media-type="text/xml"/>
</manifest:manifest>
XML
		);

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

		// content.xml: automatic-styles define the colours; spans reference them
		file_put_contents( $tmpDir . DIRECTORY_SEPARATOR . 'content.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<office:document-content
  xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
  xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"
  xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"
  xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"
  office:version="1.3">
  <office:automatic-styles>
    <style:style style:name="RedText" style:family="text">
      <style:text-properties fo:color="#ff0000"/>
    </style:style>
    <style:style style:name="YellowBg" style:family="text">
      <style:text-properties fo:background-color="#ffff00"/>
    </style:style>
  </office:automatic-styles>
  <office:body>
    <office:text>
      <text:h text:outline-level="1">Color Test Document</text:h>
      <text:p><text:span text:style-name="RedText">red text</text:span></text:p>
      <text:p><text:span text:style-name="YellowBg">highlighted text</text:span></text:p>
      <text:p>normal text after colors</text:p>
    </office:text>
  </office:body>
</office:document-content>
XML
		);

		$zip = new ZipArchive();
		$zip->open( $destPath, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		// mimetype must be the first entry, stored uncompressed
		$zip->addFile( $tmpDir . DIRECTORY_SEPARATOR . 'mimetype', 'mimetype' );
		foreach ( self::listFiles( $tmpDir ) as $file ) {
			$relPath = str_replace( DIRECTORY_SEPARATOR, '/', substr( $file, strlen( $tmpDir ) + 1 ) );
			if ( $relPath === 'mimetype' ) {
				continue;
			}
			$zip->addFile( $file, $relPath );
		}
		$zip->close();

		self::rmdir( $tmpDir );
	}

	// ------------------------------------------------------------------
	// Minimal text-based PDF
	// ------------------------------------------------------------------

	/**
	 * Create a minimal but valid PDF 1.4 file at the given path.
	 *
	 * The PDF contains a single page with two lines of extractable text using
	 * the standard Type1 Helvetica font.  pdftotext can extract the text without
	 * any embedded font data because Helvetica is one of the 14 standard PDF fonts.
	 *
	 * The text is long enough (> 50 non-whitespace characters) that
	 * PDFPreprocessor::isScannedPdf() correctly classifies it as a text-based PDF.
	 *
	 * @param string $destPath Absolute path where the .pdf file should be written.
	 */
	public static function createTextPdf( string $destPath ): void {
		// Content stream: two lines of text using PDF operator syntax.
		// We need > 100 non-whitespace chars because pdftotext appends a form-feed
		// after the last page, which makes PDFPreprocessor count 2 pages and apply
		// a threshold of 50 × 2 = 100.  Our two lines together contribute ~118
		// non-whitespace chars, safely exceeding the threshold.
		$line1  = 'Test Document Heading For PDF Import Verification';
		$line2  = 'Body text paragraph with sufficient content for text-based PDF extraction processing.';
		$stream = "BT /F1 12 Tf 72 720 Td ($line1) Tj 0 -20 Td ($line2) Tj ET";
		$streamLen = strlen( $stream );

		// Build objects; we compute byte offsets as we go.
		$obj1 = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
		$obj2 = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
		$obj3 = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792]\n"
			. "   /Resources << /Font << /F1 4 0 R >> >>\n"
			. "   /Contents 5 0 R >>\nendobj\n";
		$obj4 = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
		$obj5 = "5 0 obj\n<< /Length {$streamLen} >>\nstream\n{$stream}\nendstream\nendobj\n";

		$header = "%PDF-1.4\n";
		$body   = $header;
		$off    = [];

		foreach ( [ 1 => $obj1, 2 => $obj2, 3 => $obj3, 4 => $obj4, 5 => $obj5 ] as $id => $obj ) {
			$off[$id] = strlen( $body );
			$body    .= $obj;
		}

		// Cross-reference table (each entry is exactly 20 bytes)
		$xrefPos = strlen( $body );
		$xref    = "xref\n0 6\n0000000000 65535 f \n";
		foreach ( $off as $offset ) {
			$xref .= sprintf( "%010d 00000 n \n", $offset );
		}

		$body .= $xref;
		$body .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF\n";

		file_put_contents( $destPath, $body );
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
