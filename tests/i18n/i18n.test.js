/**
 * Validation tests for the i18n JSON files in the i18n/ directory.
 *
 * Checks performed for every file:
 *  1. No UTF-8 BOM at the start of the file.
 *  2. File is valid JSON.
 *  3. Top-level value is an object.
 *  4. Required "@metadata" key is present with an "authors" array.
 *  5. All non-metadata values are strings (no nested objects/arrays).
 *  6. Consistent indentation – no mixed tabs and spaces at the line start.
 *
 * Checks performed for every translation file (all files except en.json):
 *  7. Every message key present in the file also exists in en.json
 *     (no stray keys that MediaWiki would never load).
 *  8. No message value is verbatim identical to the corresponding en.json
 *     value – copy-pasted English text is not a translation. MediaWiki
 *     falls back to English automatically for missing keys, so untranslated
 *     messages must simply be omitted rather than duplicated.
 *     (qqq.json is exempt: its values are translator notes, not strings.)
 */

import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const I18N_DIR = path.resolve(__dirname, '..', '..', 'i18n');

// Collect all JSON files in the i18n directory.
const i18nFiles = fs.readdirSync(I18N_DIR)
	.filter((f) => f.endsWith('.json'))
	.sort();

assert.ok(i18nFiles.length > 0, 'Expected at least one JSON file in i18n/');
assert.ok(i18nFiles.includes('en.json'), 'en.json must exist in i18n/');

// Parse en.json (reference file) once; strip BOM if present so we get a
// clean string even if the source file itself violates rule 1.
const enRaw = fs.readFileSync(path.join(I18N_DIR, 'en.json'), 'utf8').replace(/^\uFEFF/, '');
const enMessages = JSON.parse(enRaw);
const enKeys = new Set(Object.keys(enMessages).filter((k) => k !== '@metadata'));

// ---------------------------------------------------------------------------

for (const filename of i18nFiles) {
	const filePath = path.join(I18N_DIR, filename);

	describe(filename, () => {
		// Read the raw bytes once per file so we can do both byte-level and
		// string-level checks without reading the file twice.
		const rawBuffer = fs.readFileSync(filePath);
		const rawString = rawBuffer.toString('utf8');
		const cleanString = rawString.replace(/^\uFEFF/, '');

		// ------------------------------------------------------------------
		// 1. No UTF-8 BOM
		// ------------------------------------------------------------------
		test('must not start with a UTF-8 BOM (EF BB BF)', () => {
			const hasBom =
				rawBuffer[0] === 0xef &&
				rawBuffer[1] === 0xbb &&
				rawBuffer[2] === 0xbf;
			assert.equal(
				hasBom,
				false,
				`${filename} starts with a UTF-8 BOM. Remove the BOM from the file.`
			);
		});

		// ------------------------------------------------------------------
		// 2. Valid JSON
		// ------------------------------------------------------------------
		let parsed;
		test('must be valid JSON', () => {
			try {
				parsed = JSON.parse(cleanString);
			} catch (err) {
				assert.fail(`${filename} is not valid JSON: ${err.message}`);
			}
		});

		// The remaining tests rely on successful parsing; skip if parse failed.
		if (parsed === undefined) {
			try {
				parsed = JSON.parse(cleanString);
			} catch {
				return; // Skip structural tests when the file is unparseable.
			}
		}

		// ------------------------------------------------------------------
		// 3. Top-level value must be an object
		// ------------------------------------------------------------------
		test('top-level value must be an object', () => {
			assert.equal(
				typeof parsed,
				'object',
				`${filename}: top-level value is not an object.`
			);
			assert.notEqual(parsed, null, `${filename}: top-level value is null.`);
			assert.ok(!Array.isArray(parsed), `${filename}: top-level value is an array.`);
		});

		// ------------------------------------------------------------------
		// 4. "@metadata" key with "authors" array
		// ------------------------------------------------------------------
		test('must have a "@metadata" key containing an "authors" array', () => {
			assert.ok(
				Object.prototype.hasOwnProperty.call(parsed, '@metadata'),
				`${filename}: missing required "@metadata" key.`
			);
			const meta = parsed['@metadata'];
			assert.equal(
				typeof meta,
				'object',
				`${filename}: "@metadata" must be an object.`
			);
			assert.ok(!Array.isArray(meta), `${filename}: "@metadata" must not be an array.`);
			assert.ok(
				Object.prototype.hasOwnProperty.call(meta, 'authors'),
				`${filename}: "@metadata" must have an "authors" property.`
			);
			assert.ok(
				Array.isArray(meta.authors),
				`${filename}: "@metadata.authors" must be an array.`
			);
			for (const author of meta.authors) {
				assert.equal(
					typeof author,
					'string',
					`${filename}: every entry in "@metadata.authors" must be a string, got ${JSON.stringify(author)}.`
				);
			}
		});

		// ------------------------------------------------------------------
		// 5. All non-metadata values must be strings
		// ------------------------------------------------------------------
		test('all message values must be strings', () => {
			for (const [key, value] of Object.entries(parsed)) {
				if (key === '@metadata') continue;
				assert.equal(
					typeof value,
					'string',
					`${filename}: value for key "${key}" must be a string, got ${typeof value}.`
				);
			}
		});

		// ------------------------------------------------------------------
		// 6. Consistent indentation (no mixed tabs and spaces at line start)
		// ------------------------------------------------------------------
		test('must use consistent indentation (no mixed tabs and spaces)', () => {
			const lines = cleanString.split('\n');
			const tabIndented = lines.filter((l) => /^\t/.test(l));
			const spaceIndented = lines.filter((l) => /^ /.test(l));

			const hasMixed = tabIndented.length > 0 && spaceIndented.length > 0;
			assert.equal(
				hasMixed,
				false,
				`${filename}: file mixes tab-indented lines (${tabIndented.length}) and space-indented lines (${spaceIndented.length}). Use a single consistent indentation style throughout.`
			);
		});

		// ------------------------------------------------------------------
		// 7. No unknown keys (translation files only)
		// ------------------------------------------------------------------
		if (filename !== 'en.json') {
			test('must not contain keys absent from en.json', () => {
				const unknownKeys = Object.keys(parsed)
					.filter((k) => k !== '@metadata')
					.filter((k) => !enKeys.has(k));
				assert.deepEqual(
					unknownKeys,
					[],
					`${filename}: contains keys not found in en.json: ${unknownKeys.join(', ')}`
				);
			});
		}

		// ------------------------------------------------------------------
		// 8. No verbatim English copies (translation files only, not qqq.json)
		// ------------------------------------------------------------------
		if (filename !== 'en.json' && filename !== 'qqq.json') {
			test('must not contain values that are verbatim copies of the English source', () => {
				const copiedKeys = Object.entries(parsed)
					.filter(([k]) => k !== '@metadata')
					.filter(([k, v]) => enMessages[k] !== undefined && v === enMessages[k])
					.map(([k]) => k);
				assert.deepEqual(
					copiedKeys,
					[],
					`${filename}: the following keys have values identical to en.json ` +
					`(remove them so MediaWiki falls back to English automatically): ` +
					copiedKeys.join(', ')
				);
			});
		}
	});
}
