# Moderation and Echo verification (0.7.4)

Issues: #71 (Moderation and temporary DOCX cleanup), #69 (Confluence Echo delivery).
The initial Echo work from #70 is included, with actual hook registration and delivery tests.

## Import behavior

- Both interfaces upload temporary documents with `action=upload&stash=1`.
- `action=pandocconvert` accepts `filekey`, `sourcefilename` and `pagename`.
  The stash must belong to the current user. `sourcefilename` preserves the input
  suffix when PHP/MediaWiki stores an HTTP upload as `.tmp`.
- Converted text is submitted through the ordinary edit API, including permissions,
  protected-page checks, edit filters, and Moderation. `result=queued` means the edit
  is awaiting moderation; `result=success` means it was saved.
- A source is removed only after successful submission. This does not require
  deletion rights. Cleanup checks the physical temporary file as well as its database
  entry, compensating for older `UploadStashFile::remove()` implementations.
- A failed conversion or rejected save retains the private source for retry. Normal
  MediaWiki stash expiry still applies. Cleanup failures are shown separately and do
  not turn an accepted edit into a failed conversion.
- The older `filename` API parameter still reads an existing repository file. Such
  files are retained; the importer never deletes an arbitrary repository file.
- Pending imports are not sent to the optional LLM polishing queue. Polishing saves
  also use the edit API and a base revision to detect intervening edits.
- Classic form submissions retain `codex=0`; successful pending submissions display
  the moderation status instead of redirecting to an unpublished page.

## Echo behavior

`BeforeCreateEchoEvent` registers the category and event when Echo is installed.
Web notifications default on and can be disabled in user preferences. The job initiator
is the recipient, including when they are also the event agent. Success, partial failure,
and fatal failure render different headers and link to Special:ConfluenceMigration.
Echo remains optional. No external Confluence credentials are required for these tests:
the job's actual completion methods are exercised with synthetic migration results.

## Reproducible checks

On a **disposable** MediaWiki instance with PandocUltimateConverter, Moderation and
Echo loaded, create an `Admin` administrator with `moderation` and `skip-moderation`
rights, enable uploads, and set `$wgEchoUseJobQueue = false`. Generate the fixture:

```sh
printf '# Import test\n\nPreserved text with **bold**.\n\n| Left | Right |\n| --- | --- |\n| Alpha | Beta |\n' | pandoc -f markdown -o /tmp/source.docx
MW_INSTALL_PATH=/path/to/wiki php tests/extension-interoperability.php --fixture /tmp/source.docx
```

The script verifies real queue contents, approval and rejection after source cleanup,
disk/database deletion, an unprivileged importer, cross-user ownership rejection,
failed-save retention, page protection, edit-filter rejection, and rendered Echo API
output for all three completion paths. It includes `.tmp` source input.

`cd tests/js && npm ci && npm test` checks private uploads, queued-state handling,
no LLM polish for pending edits, retry without duplicate upload, and upload failures.

`.github/workflows/interoperability.yml` runs these checks on MediaWiki
1.43.11, 1.44.6, 1.45.6 and 1.46.2. The existing suite also covers unit tests on PHP
8.1/8.2/8.3, Pandoc 3.1.11/3.5, and MediaWiki 1.42–1.46 smoke and browser tests.

## Local XAMPP verification

On 2026-10-17, MediaWiki 1.43.1 / PHP 8.1.25 / Moderation 1.9.8 passed the real
interoperability script. A separate HTTP session uploaded a DOCX through the upload
API and received `result=queued` from conversion. Browser checks confirmed pending
status in both Codex and classic forms and all three Echo notifications.

The Chrome file chooser could not be automated because its extension lacks file-URL
access. File uploads were therefore verified via the real HTTP API and client regression
tests; browser form checks used a local HTML URL. No production Confluence migration
was run. Newly added strings have English fallback copies in locales without translations.
