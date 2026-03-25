# PandocUltimateConverter

MediaWiki extension for **importing** documents/webpages into wiki pages and **exporting** wiki pages to external formats — powered by [Pandoc](https://pandoc.org/).

- **Import**: convert DOCX, ODT, PDF, DOC, or a webpage URL into a wiki page (with images)
- **Export**: download wiki pages as DOCX, ODT, EPUB, PDF, HTML, RTF, or TXT
- **AI cleanup**: optional LLM-powered post-conversion wikitext polish (OpenAI or Claude)

MediaWiki page: https://www.mediawiki.org/wiki/Extension:PandocUltimateConverter

Supported on MediaWiki 1.42–1.45, Windows and Linux.

## Installation

1. [Install Pandoc](https://pandoc.org/installing.html)
2. Download the extension into your `extensions/` folder
3. Add to `LocalSettings.php`:

```php
wfLoadExtension( 'PandocUltimateConverter' );

$wgEnableUploads = true;
$wgFileExtensions[] = 'docx';
$wgFileExtensions[] = 'odt';
$wgFileExtensions[] = 'pdf';
$wgFileExtensions[] = 'doc';

// Only needed if Pandoc is not in PATH:
// $wgPandocUltimateConverter_PandocExecutablePath = 'C:\Program Files\Pandoc\pandoc.exe';
```

Optional dependencies (only needed for specific formats):
- **PDF import**: [poppler](https://poppler.freedesktop.org/) (`pdftohtml`) — see [Installing poppler](#installing-poppler)
- **Scanned PDF / OCR**: [Tesseract](https://github.com/tesseract-ocr/tesseract) — see [Installing Tesseract](#installing-tesseract)
- **DOC import** and **PDF export**: [LibreOffice](https://www.libreoffice.org/) — see [Installing LibreOffice](#installing-libreoffice)

## Import (Special:PandocUltimateConverter)

Go to `Special:PandocUltimateConverter` to convert a file or URL into a wiki page.

<img width="1048" height="406" alt="image" src="https://github.com/user-attachments/assets/8ffe8a6c-92d1-4bec-bf43-e98c3fecb612" />

1. Choose source: **file upload** or **URL**
2. Enter the target page name
3. Click convert — you'll be redirected to the new page

What happens during conversion:
- Images are extracted and uploaded to the wiki automatically (duplicates are skipped)
- The uploaded source file is removed after conversion
- Temporary files are cleaned up
A legacy (non-Codex) form is available at `Special:PandocUltimateConverter?codex=0`.

## AI Cleanup (LLM Polish)

The extension can optionally run an LLM (OpenAI or Claude) to clean up wikitext after conversion — fixing formatting issues, removing artefacts, and improving readability.

### Setup

Add to `LocalSettings.php`:
```php
$wgPandocUltimateConverter_LlmProvider = 'openai';   // or 'claude'
$wgPandocUltimateConverter_LlmApiKey   = 'sk-...';
// Optional: override the default model
// $wgPandocUltimateConverter_LlmModel = 'gpt-4o';
```

### Usage

There are two ways to use AI cleanup:

1. **Batch mode** — check the "Polish with AI" checkbox before clicking **Convert all**. Each item is converted first, then automatically queued for AI cleanup. The conversion queue and the AI cleanup queue run in parallel.
2. **Per-item** — click the ✨ button on any already-converted item to run AI cleanup on demand.

If AI cleanup fails, a per-item error is shown with a **Retry** button.

### LLM Configuration

| Parameter | Default | Description |
|-----------|---------|-------------|
| `PandocUltimateConverter_LlmProvider` | `null` | `"openai"` or `"claude"`. Leave null to disable. |
| `PandocUltimateConverter_LlmApiKey` | `null` | API key for the configured provider. |
| `PandocUltimateConverter_LlmModel` | `null` | Model override. Defaults to `gpt-5.4-nano` (OpenAI) or `claude-3-5-haiku-20241022` (Claude). |
| `PandocUltimateConverter_LlmPrompt` | `null` | Custom system prompt for the cleanup step. |

## Export (Special:PandocExport)

Export one or more wiki pages to an external document format.

Go to `Special:PandocExport` or use the **Export** action in the page tools menu (the same menu where "Delete" and "Move" appear).

Supported export formats: **DOCX**, **ODT**, **EPUB**, **PDF**, **HTML**, **RTF**, **TXT**.

Features:
- Export a single page or multiple pages into one document
- Export entire categories (subcategories are resolved recursively)
- "Separate files" option bundles each page as an individual file in a ZIP archive
- Images referenced in wikitext are embedded into the output document
- PDF export uses a Pandoc → DOCX → LibreOffice pipeline (no LaTeX required)

### Demos

**File import:**
![Pandoc-demo-file](https://github.com/user-attachments/assets/4339883c-913e-422c-b859-d8df55c80637)

**URL import:**
![Pandoc-demo-url](https://github.com/user-attachments/assets/928c1822-8913-4071-b5e1-9fdfa161575d)

**Export to file**
![Pandoc-demo-export](https://github.com/user-attachments/assets/d06859b3-2851-41be-afb1-04724115d01f)


## Supported import formats

Supports [everything Pandoc supports](https://pandoc.org/MANUAL.html#general-options). Tested: **DOCX**, **ODT**, **PDF**, **DOC**.

| Format | Pipeline | Extra dependency |
|--------|----------|-----------------|
| DOCX, ODT | Pandoc → wikitext | — |
| DOC | LibreOffice → DOCX → Pandoc | LibreOffice |
| PDF (text) | pdftohtml → HTML → Pandoc | poppler |
| PDF (scanned) | pdftoppm → Tesseract OCR → wikitext | poppler + Tesseract |

## Configuration

All parameters are set in `LocalSettings.php` with the `$wg` prefix.

| Parameter | Default | Description |
|-----------|---------|-------------|
| `PandocUltimateConverter_PandocExecutablePath` | `null` | Path to the Pandoc binary. Not needed if Pandoc is in PATH. |
| `PandocUltimateConverter_TempFolderPath` | `null` | Temp folder for conversion files. Uses system default if not set. |
| `PandocUltimateConverter_PdfToHtmlExecutablePath` | `null` | Path to poppler's `pdftohtml`. Not needed if in PATH. |
| `PandocUltimateConverter_LibreOfficeExecutablePath` | `null` | Path to `soffice`/`libreoffice`. Not needed if in PATH. |
| `PandocUltimateConverter_TesseractExecutablePath` | `null` | Path to the Tesseract OCR binary. Not needed if in PATH. |
| `PandocUltimateConverter_OcrLanguage` | `"eng"` | Tesseract language code(s). Use `+` for multiple, e.g. `"eng+deu"`. |
| `PandocUltimateConverter_PandocCustomUserRight` | `""` | Restrict access to a specific [user right](https://www.mediawiki.org/wiki/Manual:User_rights#List_of_permissions). |
| `PandocUltimateConverter_MediaFileExtensionsToSkip` | `[]` | File extensions to skip during image upload (e.g. `["emf"]`). |
| `PandocUltimateConverter_FiltersToUse` | `[]` | Custom [Pandoc Lua filters](https://pandoc.org/filters.html) to apply. Must be in the `filters/` folder. |
| `PandocUltimateConverter_UseColorProcessors` | `false` | Preserve text/background colors from DOCX/ODT files. |
| `PandocUltimateConverter_ShowExportInPageTools` | `true` | Show "Export" in the page Actions menu. |
| `PandocUltimateConverter_LlmProvider` | `null` | LLM provider: `"openai"` or `"claude"`. |
| `PandocUltimateConverter_LlmApiKey` | `null` | API key for the LLM provider. |
| `PandocUltimateConverter_LlmModel` | `null` | Model name override. |
| `PandocUltimateConverter_LlmPrompt` | `null` | Custom system prompt for AI cleanup. |

### Built-in Lua filters

Filters are placed in the `filters/` subfolder. Add them via:
```php
$wgPandocUltimateConverter_FiltersToUse[] = 'increase_heading_level.lua';
```

| Filter | Description |
|--------|-------------|
| `increase_heading_level.lua` | Increase heading levels by 1 (useful when documents start at H1) |
| `colorize_mark_class.lua` | Highlight "mark" classes with yellow background |

## Installing optional dependencies

### Installing poppler

Required for PDF import. If not installed, PDF files will fail to convert — all other formats work normally.

**Linux:**
```bash
sudo apt install poppler-utils          # Debian/Ubuntu
sudo dnf install poppler-utils          # RHEL/Fedora
```

**Windows:**
```powershell
choco install poppler
```
Or download manually from https://github.com/oschwartz10612/poppler-windows/releases and add `bin/` to PATH, or set:
```php
$wgPandocUltimateConverter_PdfToHtmlExecutablePath = 'C:\poppler\Library\bin\pdftohtml.exe';
```

### Installing Tesseract

Required for scanned PDF OCR. Also requires poppler (`pdftoppm`, installed with `pdftohtml`).

**Linux:**
```bash
sudo apt install tesseract-ocr          # Debian/Ubuntu
sudo apt install tesseract-ocr-deu      # additional languages
sudo dnf install tesseract              # RHEL/Fedora
```

**Windows:**
```powershell
choco install tesseract
```
Or download from https://github.com/UB-Mannheim/tesseract/wiki and add to PATH, or set:
```php
$wgPandocUltimateConverter_TesseractExecutablePath = 'C:\Program Files\Tesseract-OCR\tesseract.exe';
```

### Installing LibreOffice

Required for DOC import and PDF export.

**Linux:**
```bash
sudo apt install libreoffice            # Debian/Ubuntu
sudo dnf install libreoffice            # RHEL/Fedora
```

**Windows:** Download from https://www.libreoffice.org/download/download/ and add the `program/` folder to PATH, or set:
```php
$wgPandocUltimateConverter_LibreOfficeExecutablePath = 'C:\Program Files\LibreOffice\program\soffice.exe';
```

## Action API

The extension exposes three API modules. Write operations (`pandocconvert`, `pandocllmpolish`) require a CSRF token and POST.

Obtain a CSRF token first:
```
GET /api.php?action=query&meta=tokens&format=json
```

### action=pandocconvert

Converts a file or URL to a wiki page. Requires a CSRF token and POST.

```
POST /api.php
action=pandocconvert&pagename=My Article&url=https://example.com&forceoverwrite=1&token=<csrf>&format=json
```

**Response:**
```json
{ "pandocconvert": { "result": "success", "pagename": "My Article" } }
```

| Parameter | Required | Description |
|-----------|----------|-------------|
| `pagename` | yes | Target wiki page title |
| `filename` | one of | Uploaded file name (mutually exclusive with `url`) |
| `url` | one of | `http`/`https` URL to fetch (mutually exclusive with `filename`) |
| `forceoverwrite` | no | `1` to overwrite existing page (default: `0`) |
| `token` | yes | CSRF token |

### action=pandocllmpolish

Runs LLM AI cleanup on an existing wiki page's wikitext. Requires a CSRF token and POST. The LLM provider must be [configured](#llm-configuration).

```
POST /api.php
action=pandocllmpolish&pagename=My Article&token=<csrf>&format=json
```

**Response:**
```json
{ "pandocllmpolish": { "result": "success", "pagename": "My Article" } }
```

| Parameter | Required | Description |
|-----------|----------|-------------|
| `pagename` | yes | Title of existing wiki page to polish |
| `token` | yes | CSRF token |

### action=pandocurltitle

Fetches remote URLs and extracts their HTML `<title>` tags. Used internally by the Codex UI to suggest page names for URL imports. GET request, no token required.

```
GET /api.php?action=pandocurltitle&urls=https://example.com&format=json
```

**Response:**
```json
{ "pandocurltitle": { "results": [ { "url": "https://example.com", "title": "Example Domain" } ] } }
```

Accepts multiple URLs (pipe-separated). Only `http`/`https` URLs are accepted.

| Parameter | Required | Description |
|-----------|----------|-------------|
| `urls` | yes | One or more URLs (pipe-separated) to fetch titles from |

### API error codes

**pandocconvert:**

| Code | Meaning |
|------|---------|
| `nosource` | Neither `filename` nor `url` supplied |
| `multiplesource` | Both `filename` and `url` supplied |
| `invalidurlscheme` | URL is not `http`/`https` |
| `pageexists` | Page exists and `forceoverwrite` not set |

**pandocllmpolish:**

| Code | Meaning |
|------|---------|
| `pagenotfound` | The specified page does not exist |
| `notconfigured` | LLM provider is not configured on this wiki |
| `notwikitext` | The page content is not wikitext |

## Debugging

Add to `LocalSettings.php`:
```php
$wgShowExceptionDetails = true;
$wgDebugLogGroups['PandocUltimateConverter'] = '/var/log/mediawiki/pandoc.log';
```

The extension logs diagnostic messages to the `PandocUltimateConverter` log group.
