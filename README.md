# PandocUltimateConverter

MediaWiki extension for **importing** documents/webpages into wiki pages and **exporting** wiki pages to external formats — powered by [Pandoc](https://pandoc.org/).

- **Import**: convert DOCX, ODT, PDF, DOC, or a webpage URL into a wiki page (with images)
- **Export**: download wiki pages as DOCX, ODT, EPUB, PDF, HTML, RTF, or TXT
- **AI cleanup**: optional LLM-powered post-conversion wikitext polish (OpenAI or Claude)
- **Confluence migration**: mass-import an entire Confluence space (Cloud or Server) into the wiki

MediaWiki page: https://www.mediawiki.org/wiki/Extension:PandocUltimateConverter

Supported on MediaWiki 1.42–1.45, Windows and Linux. 1.39-1.41 are partially supported in branch REL1_39.

- [PandocUltimateConverter](#pandocultimateconverter)
  - [Installation](#installation)
  - [Configuration](#configuration)
  - [Demos](#demos)
  - [Import (Special:PandocUltimateConverter)](#import-specialpandocultimateconverter)
    - [Supported import formats](#supported-import-formats)
    - [AI Cleanup (LLM Polish)](#ai-cleanup-llm-polish)
      - [Setup](#setup)
      - [Usage](#usage)
    - [Built-in Lua filters](#built-in-lua-filters)
  - [Export (Special:PandocExport)](#export-specialpandocexport)
  - [Confluence Migration (Special:ConfluenceMigration)](#confluence-migration-specialconfluencemigration)
    - [Cloud vs. Server](#cloud-vs-server)
    - [What gets migrated](#what-gets-migrated)
    - [How it runs](#how-it-runs)
    - [Disabling the feature](#disabling-the-feature)
  - [Installing optional dependencies](#installing-optional-dependencies)
    - [Installing poppler](#installing-poppler)
    - [Installing Tesseract](#installing-tesseract)
    - [Installing LibreOffice](#installing-libreoffice)
  - [Action API](#action-api)
    - [action=pandocconvert](#actionpandocconvert)
    - [action=pandocllmpolish](#actionpandocllmpolish)
    - [action=pandocurltitle](#actionpandocurltitle)
    - [API error codes](#api-error-codes)
  - [Debugging](#debugging)


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
- **DOC import** and **PDF export** (default engine): [LibreOffice](https://www.libreoffice.org/) — see [Installing LibreOffice](#installing-libreoffice)
- **PDF export** (alternative engines): a LaTeX distribution (`pdflatex`, `xelatex`, `lualatex`), `wkhtmltopdf`, `weasyprint`, or any engine supported by Pandoc's `--pdf-engine`

## Configuration

All parameters are set in `LocalSettings.php` with the `$wg` prefix.

| Parameter | Default | Description |
|-----------|---------|-------------|
| `PandocUltimateConverter_PandocExecutablePath` | `null` | Path to the Pandoc binary. Not needed if Pandoc is in PATH. |
| `PandocUltimateConverter_TempFolderPath` | `null` | Temp folder for conversion files. Uses system default if not set. |
| `PandocUltimateConverter_PdfToHtmlExecutablePath` | `null` | Path to poppler's `pdftohtml`. Not needed if in PATH. |
| `PandocUltimateConverter_PdfToPpmExecutablePath` | `null` | Path to poppler's `pdftoppm`. Not needed if in PATH. |
| `PandocUltimateConverter_PdfToTextExecutablePath` | `null` | Path to poppler's `pdftotext`. Not needed if in PATH. |
| `PandocUltimateConverter_LibreOfficeExecutablePath` | `null` | Path to `soffice`/`libreoffice`. Not needed if in PATH. |
| `PandocUltimateConverter_TesseractExecutablePath` | `null` | Path to the Tesseract OCR binary. Not needed if in PATH. |
| `PandocUltimateConverter_OcrLanguage` | `"eng"` | Tesseract language code(s). Use `+` for multiple, e.g. `"eng+deu"`. |
| `PandocUltimateConverter_PandocCustomUserRight` | `""` | Restrict access to a specific [user right](https://www.mediawiki.org/wiki/Manual:User_rights#List_of_permissions). |
| `PandocUltimateConverter_MediaFileExtensionsToSkip` | `[]` | File extensions to skip during image upload (e.g. `["emf"]`). |
| `PandocUltimateConverter_FiltersToUse` | `[]` | Custom [Pandoc Lua filters](https://pandoc.org/filters.html) to apply. Must be in the `filters/` folder. |
| `PandocUltimateConverter_UseColorProcessors` | `false` | Preserve text/background colors from DOCX/ODT files. |
| `PandocUltimateConverter_PdfExportEngine` | `"libreoffice"` | Engine used for PDF export. `"libreoffice"` uses a two-step pipeline (Pandoc → DOCX → PDF via LibreOffice, no LaTeX needed). Any other value (e.g. `"xelatex"`, `"pdflatex"`, `"lualatex"`, `"wkhtmltopdf"`, `"weasyprint"`) is passed directly to Pandoc's `--pdf-engine` option. Preferably specify full path to the engine binary to be sure pandoc will be ble to find pdf engine. |
| `PandocUltimateConverter_ShowExportInPageTools` | `true` | Show "Export" in the page Actions menu. |
| `PandocUltimateConverter_LlmProvider` | `null` | LLM provider: `"openai"` or `"claude"`. |
| `PandocUltimateConverter_LlmApiKey` | `null` | API key for the LLM provider. Optional when `LlmBaseUrl` is set (e.g. unauthenticated self-hosted endpoints). |
| `PandocUltimateConverter_LlmModel` | `null` | Model name override. Should be set explicitly when using `LlmBaseUrl`. |
| `PandocUltimateConverter_LlmPrompt` | `null` | Custom system prompt for AI cleanup. |
| `PandocUltimateConverter_LlmBaseUrl` | `null` | Custom API endpoint URL. Overrides the provider default. Use for self-hosted models (Ollama, vLLM), Qwen/DashScope, OpenRouter, or any OpenAI-compatible service. |
| `PandocUltimateConverter_EnableConfluenceMigration` | `true` | Set to `false` to disable `Special:ConfluenceMigration`. |

## Demos
A few GIF files to show what does it do

**File import:**
![Pandoc-demo-file](https://github.com/user-attachments/assets/4339883c-913e-422c-b859-d8df55c80637)

**URL import:**
![Pandoc-demo-url](https://github.com/user-attachments/assets/928c1822-8913-4071-b5e1-9fdfa161575d)

**Export to file**
![Pandoc-demo-export](https://github.com/user-attachments/assets/d06859b3-2851-41be-afb1-04724115d01f)

**Import Confluence space**
<tbd>

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

### Supported import formats

Supports [everything Pandoc supports](https://pandoc.org/MANUAL.html#general-options). Tested: **DOCX**, **ODT**, **PDF**, **DOC**.

| Format | Pipeline | Extra dependency |
|--------|----------|-----------------|
| DOCX, ODT | Pandoc → wikitext | — |
| DOC | LibreOffice → DOCX → Pandoc | LibreOffice |
| PDF (text) | pdftohtml → HTML → Pandoc | poppler |
| PDF (scanned) | pdftoppm → Tesseract OCR → wikitext | poppler + Tesseract |

### AI Cleanup (LLM Polish)

The extension can optionally run an LLM to clean up wikitext after conversion — fixing formatting issues, removing artefacts, and improving readability.

Any OpenAI-compatible API is supported: OpenAI, Anthropic Claude, Qwen/DashScope, Ollama, vLLM, LM Studio, OpenRouter, and more.

#### Setup

**OpenAI:**
```php
$wgPandocUltimateConverter_LlmProvider = 'openai';
$wgPandocUltimateConverter_LlmApiKey   = 'sk-...';
// Optional: override the default model (default: gpt-5.4-nano)
// $wgPandocUltimateConverter_LlmModel = 'gpt-4o';
```

**Anthropic Claude:**
```php
$wgPandocUltimateConverter_LlmProvider = 'claude';
$wgPandocUltimateConverter_LlmApiKey   = 'sk-ant-...';
// Optional: override the default model (default: claude-3-5-haiku-20241022)
// $wgPandocUltimateConverter_LlmModel = 'claude-3-5-sonnet-20241022';
```

**Qwen via Alibaba DashScope (OpenAI-compatible endpoint):**
```php
$wgPandocUltimateConverter_LlmProvider = 'openai';
$wgPandocUltimateConverter_LlmApiKey   = 'sk-...';   // your DashScope API key
$wgPandocUltimateConverter_LlmModel    = 'qwen-max';
$wgPandocUltimateConverter_LlmBaseUrl  = 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions';
```

**Self-hosted model via Ollama (no API key required):**
```php
$wgPandocUltimateConverter_LlmProvider = 'openai';
$wgPandocUltimateConverter_LlmModel    = 'qwen2.5:latest';   // or llama3.2, mistral, etc.
$wgPandocUltimateConverter_LlmBaseUrl  = 'http://localhost:11434/v1/chat/completions';
// No API key needed for local Ollama
```

**Any other OpenAI-compatible service (OpenRouter, Groq, vLLM, etc.):**
```php
$wgPandocUltimateConverter_LlmProvider = 'openai';
$wgPandocUltimateConverter_LlmApiKey   = 'your-api-key';
$wgPandocUltimateConverter_LlmModel    = 'your-model-name';
$wgPandocUltimateConverter_LlmBaseUrl  = 'https://your-service.example.com/v1/chat/completions';
```

> **Note:** When `LlmBaseUrl` is set, the `max_tokens` parameter is used instead of `max_completion_tokens` for compatibility with non-OpenAI providers (including Qwen). The API key becomes optional when `LlmBaseUrl` is set, which is useful for unauthenticated local endpoints.

#### Usage

There are two ways to use AI cleanup:

1. **Batch mode** — check the "Polish with AI" checkbox before clicking **Convert all**. Each item is converted first, then automatically queued for AI cleanup. The conversion queue and the AI cleanup queue run in parallel.
2. **Per-item** — click the ✨ button on any already-converted item to run AI cleanup on demand.

If AI cleanup fails, a per-item error is shown with a **Retry** button.

### Built-in Lua filters

Filters are placed in the `filters/` subfolder. Add them via:
```php
$wgPandocUltimateConverter_FiltersToUse[] = 'increase_heading_level.lua';
```

| Filter | Description |
|--------|-------------|
| `increase_heading_level.lua` | Increase heading levels by 1 (useful when documents start at H1) |
| `colorize_mark_class.lua` | Highlight "mark" classes with yellow background |

## Export (Special:PandocExport)

Export one or more wiki pages to an external document format.

Go to `Special:PandocExport` or use the **Export** action in the page tools menu (the same menu where "Delete" and "Move" appear).

Supported export formats: **DOCX**, **ODT**, **EPUB**, **PDF**, **HTML**, **RTF**, **TXT**.

Features:
- Export a single page or multiple pages into one document
- Export entire categories (subcategories are resolved recursively)
- "Separate files" option bundles each page as an individual file in a ZIP archive
- Images referenced in wikitext are embedded into the output document
- PDF export uses a configurable engine (default: LibreOffice pipeline, no LaTeX required; or any Pandoc-supported `--pdf-engine`)

## Confluence Migration (Special:ConfluenceMigration)

Mass-migrate an entire Confluence space to this wiki in one operation.

Go to `Special:ConfluenceMigration` and fill in:

| Field | Description |
|-------|-------------|
| **Confluence URL** | Base URL of your Confluence instance (see below) |
| **Space key** | Key of the Confluence space to migrate (e.g. `DOCS`, `DEV`) |
| **Email / Username** | Your Confluence login email (Cloud) or username (Server) |
| **API token / Password** | API token (Cloud) or password / personal access token (Server) |
| **Target page prefix** | Optional prefix prepended to every page title, e.g. `Confluence/DOCS` |
| **Overwrite existing pages** | When checked, existing wiki pages are replaced |
| **Auto-categorize** | Creates MediaWiki categories mirroring the Confluence page hierarchy (checked by default) |

### Cloud vs. Server

| | Confluence Cloud | Confluence Server / Data Center |
|---|---|---|
| **Base URL** | `https://yourcompany.atlassian.net` | `https://confluence.yourcompany.com` |
| **Username field** | Your Atlassian account email | Your Confluence username |
| **Token field** | [Atlassian API token](https://id.atlassian.com/manage-profile/security/api-tokens) | Password or [Personal Access Token](https://confluence.atlassian.com/enterprise/using-personal-access-tokens-1026032365.html) |

### What gets migrated

- All pages in the specified space are fetched via the Confluence REST API v1.
- Page content (Confluence "storage format" HTML) is converted to MediaWiki wikitext using Pandoc.
- Common Confluence macros (code blocks, info/note/warning/tip panels) are converted to their MediaWiki equivalents.
- File attachments are downloaded from Confluence and uploaded to the MediaWiki file repository.
- Pages are created with the edit summary "Imported from Confluence".
- When auto-categorize is enabled, pages with sub-pages get a matching category; nested sub-pages produce nested categories.

### How it runs

The migration is processed as a **background job** via the MediaWiki job queue.  You do not have to keep your browser open.  When the migration finishes you receive an **Echo notification** (requires the [Echo extension](https://www.mediawiki.org/wiki/Extension:Echo)).

Jobs are processed by `maintenance/runJobs.php` or automatically during regular wiki requests if `$wgJobRunRate > 0` (the default).

### Disabling the feature

```php
// LocalSettings.php
$wgPandocUltimateConverter_EnableConfluenceMigration = false;
```

Setting this to `false` hides `Special:ConfluenceMigration` entirely and displays a notice to users who navigate to it directly.


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
$wgPandocUltimateConverter_PdfToPpmExecutablePath = 'C:\poppler\Library\bin\pdftoppm.exe';
$wgPandocUltimateConverter_PdfToTextExecutablePath = 'C:\poppler\Library\bin\pdftotext.exe';
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

Required for DOC import and PDF export (when using the default `libreoffice` engine).

**Linux:**
```bash
sudo apt install libreoffice            # Debian/Ubuntu
sudo dnf install libreoffice            # RHEL/Fedora
```

**Windows:** Download from https://www.libreoffice.org/download/download/ and add the `program/` folder to PATH, or set:
```php
$wgPandocUltimateConverter_LibreOfficeExecutablePath = 'C:\Program Files\LibreOffice\program\soffice.exe';
```

To use a different PDF export engine instead of LibreOffice:
```php
$wgPandocUltimateConverter_PdfExportEngine = '/path/to/xelatex';   // or 'pdflatex', 'lualatex', 'wkhtmltopdf', 'weasyprint', etc.
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
