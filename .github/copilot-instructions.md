# PandocUltimateConverter — Copilot Instructions

## Project Overview

MediaWiki extension (MIT, PHP 8.1+, MW 1.42–1.45) that adds three capabilities:

1. **Import** — Convert documents (DOCX, ODT, PDF, DOC) or web URLs into wiki pages with auto-extracted images. Entry: `Special:PandocUltimateConverter`, API: `action=pandocconvert`.
2. **Export** — Download wiki pages/categories as DOCX, ODT, EPUB, PDF, HTML, RTF, TXT. Entry: `Special:PandocExport`. PDF uses Pandoc → DOCX → LibreOffice (no LaTeX).
3. **Confluence Migration** — Mass-import a Confluence space (Cloud or Server) via background jobs. Entry: `Special:ConfluenceMigration`, API: `action=pandocconfluencemigrate`.

All conversions go through [Pandoc](https://pandoc.org/). Optional LLM polish step (OpenAI / Claude) can clean up wikitext post-conversion.

## Architecture

```
includes/                         # PHP backend
├── Api/                          # MediaWiki API modules
│   ├── ApiPandocConvert.php      # action=pandocconvert (import)
│   ├── ApiPandocLlmPolish.php    # action=pandocllmpolish
│   ├── ApiPandocUrlTitle.php     # action=pandocurltitle (title extraction)
│   ├── ApiConfluenceMigrate.php  # action=pandocconfluencemigrate
│   └── ApiConfluenceJobs.php     # action=pandocconfluencejobs
├── SpecialPages/                 # Special page entry points
│   ├── SpecialPandocUltimateConverter.php  # Import UI
│   ├── SpecialPandocExport.php             # Export UI + download handler
│   └── SpecialConfluenceMigration.php      # Confluence migration UI
├── Jobs/
│   └── ConfluenceMigrationJob.php          # Background job (MW job queue)
├── ConfluenceClient.php          # Confluence REST API v1 HTTP client
├── PandocConverterService.php    # Shared import logic (file/URL → page)
├── PandocWrapper.php             # Pandoc invocation, image upload, temp dirs
├── HookHandler.php               # Hooks (page tools menu export action)
└── processors/                   # Pre/post-processors
    ├── DOCPreprocessor.php       # .doc → .docx via LibreOffice
    ├── PDFPreprocessor.php       # PDF → HTML (poppler) or OCR (tesseract)
    ├── DOCXColorPreprocessor.php # Color extraction from DOCX
    ├── ODTColorPreprocessor.php  # Color extraction from ODT
    └── PandocTextPostprocessor.php  # Rewrite image links in wikitext

modules/                          # Frontend (JavaScript/Vue 3 + Codex)
├── codex/                        # Import UI (Vue + Pinia)
├── export/                       # Export UI (Vue)
├── confluence/                   # Confluence migration UI (Vue)
└── ext.pandocUltimateConverterSpecial.*  # Legacy non-Codex import UI
```

## Code Conventions

### PHP
- **Namespace**: `MediaWiki\Extension\PandocUltimateConverter\`
- Follow [MediaWiki coding conventions](https://www.mediawiki.org/wiki/Manual:Coding_conventions/PHP): tabs for indentation, `lowerCamelCase` methods, `UpperCamelCase` classes.
- Use MediaWiki services via dependency injection (constructor params from `ObjectFactory` specs in `extension.json`), not global `$wg` variables in code — config is accessed via `$this->getConfig()->get(...)` or injected `ServiceOptions`.
- API modules extend `ApiBase`. Special pages extend `SpecialPage`. Jobs extend `Job`.
- Shell commands MUST use `MediaWiki\Shell\Shell::command()` — never `exec()` / `shell_exec()`.
- Temp files: create in system temp dir, always clean up in `finally` blocks or after streaming.

### JavaScript / Vue
- Frontend uses **Vue 3 Composition API** with **Wikimedia Codex** component library.
- Import UI uses **Pinia** store (`modules/codex/stores/converter.js`).
- API calls use `mw.Api()` (MediaWiki JS API wrapper) for action API, or `fetch()` for direct GET endpoints (export downloads).
- All user-visible strings go through `mw.msg()` — never hardcode text. Message keys are registered in `extension.json` → `ResourceModules` → `messages`.

### i18n
- Message files live in `i18n/`. English (`en.json`) is the source of truth; `qqq.json` has documentation.
- Key pattern: `pandocultimateconverter-*` (import/export), `confluencemigration-*` (Confluence feature).

### Configuration
- All config keys start with `PandocUltimateConverter_` and are registered in `extension.json` → `config`.
- Key settings: `PandocExecutablePath`, `TempFolderPath`, `MediaFileExtensionsToSkip`, `PandocCustomUserRight`, `LlmProvider`, `LlmApiKey`, `LlmModel`, `EnableConfluenceMigration`.

## Key Patterns

### Import Pipeline
```
Source (file/URL) → Preprocessor (DOC→DOCX, PDF→HTML, etc.)
  → Pandoc (→ mediawiki wikitext + extracted images)
  → processImages() uploads media to wiki (SHA1 dedup)
  → PandocTextPostprocessor rewrites [[File:...]] links
  → WikiPage save
  → Optional LLM polish (separate revision)
```

### Export Pipeline
```
Page selection (UI) → getPageWikitext() raw wikitext
  → gatherImages() copies files to temp media dir
  → buildCombinedWikitext() (multi-page: headings + separators)
  → Pandoc (mediawiki → target format, --resource-path for images)
  → PDF special case: mediawiki → docx → pdf via LibreOffice
  → streamDownload() with cleanup
```

### Confluence Migration Pipeline
```
UI form → ApiConfluenceMigrate validates & enqueues job
  → ConfluenceMigrationJob::run() fetches all pages
  → Per page: fetch HTML → sanitize Confluence tags → Pandoc → upload attachments → save
  → Write migration report page
  → Send Echo notification
```

### Image Vocabulary
A `{originalFilename → wikiFileTitle}` map is built during image upload and passed to `PandocTextPostprocessor` to rewrite wikitext image links. Two sources are merged for Confluence: Pandoc-extracted inline images + Confluence attachment downloads.

## Security

- URL inputs validated for `https://` scheme only (SSRF prevention). Confluence client enforces HTTPS.
- File uploads go through MediaWiki's standard upload pipeline with SHA1 deduplication.
- All API modules check CSRF tokens and user rights (`PandocCustomUserRight` if configured).
- Shell commands use `Shell::command()` with proper escaping — no string interpolation.
- Confluence credentials are never persisted; passed only in-memory to the job.

## Testing

- PHPUnit tests in `tests/phpunit/`. Config: `phpunit.xml.dist` (unit), `phpunit-e2e.xml.dist` (integration).
- CI: `.github/workflows/tests.yml`.
- Test fixtures in `test_files/`.

## Build & Run

- No build step for PHP. Frontend Vue components are bundled by MediaWiki's ResourceLoader (no separate webpack/vite).
- External dependencies: Pandoc (required), LibreOffice (PDF export, DOC import), poppler (PDF import), Tesseract (OCR).
- PHP dependencies via Composer: `composer install` in extension directory.
- Run tests: `php ../../tests/phpunit/phpunit.php --configuration phpunit.xml.dist`
