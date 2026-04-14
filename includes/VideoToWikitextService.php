<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter;

/**
 * Generates MediaWiki wikitext from video frames and an optional audio track
 * by calling a vision-capable (and optionally audio-capable) LLM API.
 *
 * Supported providers and their audio strategy
 * ---------------------------------------------
 * - 'openai'  — OpenAI Chat Completions API with base64 image_url (e.g. gpt-4o).
 *               Audio is transcribed separately via the Whisper API
 *               (/v1/audio/transcriptions) using the same API key, then included
 *               in the wikitext-generation prompt as a text transcript.
 *
 * - 'claude'  — Anthropic Messages API with base64 image content (e.g. claude-3-5-sonnet).
 *               Claude itself does not support audio input.  When a separate
 *               TranscriptionApiKey (OpenAI key) is configured, Whisper is used
 *               for transcription.  Otherwise the audio track is silently skipped.
 *
 * - 'gemini'  — Google Generative Language API (e.g. gemini-1.5-flash).
 *               Both the extracted JPEG frames AND the raw audio file are passed
 *               as inline_data in a single multimodal request — no separate
 *               transcription step is needed.
 *
 * Pipeline
 * --------
 * 1. VideoPreprocessor extracts JPEG frames to $mediaFolder.
 * 2. VideoPreprocessor extracts audio to a temporary directory (separate from frames).
 * 3. generateWikitext() is called with frame paths + audio path.
 *    - OpenAI: Whisper transcribes the audio → transcript text is added to the prompt.
 *    - Claude: Whisper transcribes with TranscriptionApiKey (if set) → same as OpenAI.
 *    - Gemini: Audio file sent inline alongside frames in one multimodal request.
 * 4. Generated wikitext uses bare frame basenames (e.g. [[File:frame-001.jpg]]).
 * 5. PandocTextPostprocessor rewrites basenames to fully-qualified wiki file names.
 *
 * Configuration (LocalSettings.php)
 * ----------------------------------
 *   $wgPandocUltimateConverter_LlmProvider          = 'openai';  // 'claude' or 'gemini'
 *   $wgPandocUltimateConverter_LlmApiKey            = '...';
 *   $wgPandocUltimateConverter_LlmVideoModel        = 'gpt-4o';  // optional
 *   $wgPandocUltimateConverter_LlmVideoPrompt       = '...';     // optional
 *   $wgPandocUltimateConverter_TranscriptionApiKey  = '...';     // optional OpenAI key for
 *                                                                 // Whisper when provider='claude'
 */
class VideoToWikitextService {

	/** Valid provider identifiers. Exposed as public constants for external validation. */
	public const PROVIDER_OPENAI = 'openai';
	public const PROVIDER_CLAUDE = 'claude';
	public const PROVIDER_GEMINI = 'gemini';

private const OPENAI_API_URL      = 'https://api.openai.com/v1/chat/completions';
private const WHISPER_API_URL     = 'https://api.openai.com/v1/audio/transcriptions';
private const CLAUDE_API_URL      = 'https://api.anthropic.com/v1/messages';
private const GEMINI_API_BASE     = 'https://generativelanguage.googleapis.com/v1beta/models/';

private const OPENAI_DEFAULT_MODEL = 'gpt-4o';
private const CLAUDE_DEFAULT_MODEL = 'claude-3-5-sonnet-20241022';
private const GEMINI_DEFAULT_MODEL = 'gemini-1.5-flash';
private const WHISPER_MODEL        = 'whisper-1';

private const CLAUDE_API_VERSION = '2023-06-01';

/** Maximum output tokens to request from the LLM. */
private const MAX_TOKENS = 8192;

/** cURL timeout in seconds for LLM API calls. */
private const HTTP_TIMEOUT = 300;

/**
 * Maximum byte size for a single JPEG frame sent to the LLM.
 * Frames exceeding this are skipped to keep request sizes manageable (≈ 2 MB).
 */
private const MAX_FRAME_BYTES = 2097152;

/**
 * Maximum byte size for an audio file sent inline to the Gemini API (20 MB).
 * Files exceeding this are silently dropped.
 */
private const MAX_AUDIO_BYTES = 20971520;

/** Default system prompt instructing the LLM to produce a wiki article. */
private const DEFAULT_PROMPT =
'You are an expert wiki editor. You have been given a series of frames extracted from a video'
. ' and, when available, a transcript of the audio narration. '
. 'Based on the visual content and the transcript, write a comprehensive MediaWiki wikitext article. '
. 'Include: a concise lead paragraph summarising the overall content; ==Sections== for distinct topics or scenes; '
. 'embed the provided frame images using proper MediaWiki image syntax '
. '(e.g. [[File:frame-001.jpg|thumb|Description of what is shown]]). '
. 'When a transcript is provided, incorporate the spoken information into the relevant sections. '
. 'Use proper MediaWiki markup: ==Section headings==, \'\'\'bold\'\'\', \'\'italic\'\', * bullet lists. '
. 'Return ONLY the wikitext content without any explanation, markdown fences, or meta-commentary.';

/** @var string */
private $provider;

/** @var string */
private $apiKey;

/** @var string */
private $model;

/** @var string */
private $prompt;

/**
 * Optional OpenAI API key used solely for Whisper transcription.
 * Required when provider='claude' and audio transcription is desired.
 * Ignored for 'openai' (uses $apiKey) and 'gemini' (native audio support).
 *
 * @var string
 */
private $transcriptionApiKey;

/**
 * @param string $provider            'openai', 'claude', or 'gemini'
 * @param string $apiKey              API key for the chosen provider
 * @param string $model               Model name, or empty string to use the provider default
 * @param string $prompt              System/instruction prompt, or empty string for built-in default
 * @param string $transcriptionApiKey Optional separate OpenAI key for Whisper (used with Claude)
 */
public function __construct(
string $provider,
string $apiKey,
string $model = '',
string $prompt = '',
string $transcriptionApiKey = ''
) {
$this->provider            = strtolower( trim( $provider ) );
$this->apiKey              = $apiKey;
$this->model               = $model !== '' ? $model : $this->defaultModel();
$this->prompt              = $prompt !== '' ? $prompt : self::DEFAULT_PROMPT;
$this->transcriptionApiKey = $transcriptionApiKey;
}

/**
 * Build an instance from the extension configuration, or return null if LLM is not configured.
 *
 * @param \MediaWiki\Config\Config $config
 * @return self|null
 */
public static function newFromConfig( $config ): ?self {
$provider = (string)( $config->get( 'PandocUltimateConverter_LlmProvider' ) ?? '' );
$apiKey   = (string)( $config->get( 'PandocUltimateConverter_LlmApiKey' )   ?? '' );

if ( $provider === '' || $apiKey === '' ) {
return null;
}

$model               = (string)( $config->get( 'PandocUltimateConverter_LlmVideoModel' )       ?? '' );
$prompt              = (string)( $config->get( 'PandocUltimateConverter_LlmVideoPrompt' )      ?? '' );
$transcriptionApiKey = (string)( $config->get( 'PandocUltimateConverter_TranscriptionApiKey' ) ?? '' );

return new self( $provider, $apiKey, $model, $prompt, $transcriptionApiKey );
}

/**
 * Generate MediaWiki wikitext that describes the video.
 *
 * Visual content is supplied as extracted JPEG frames; spoken content is
 * optionally supplied as the path to an audio file extracted from the video.
 *
 * The returned wikitext contains [[File:frame-NNN.jpg|…]] references using
 * the bare basenames of the supplied frame paths.  PandocTextPostprocessor
 * will later rewrite these to fully-qualified wiki file names after upload.
 *
 * @param string      $videoTitle  Human-readable title / base name of the video.
 * @param string[]    $framePaths  Absolute paths to JPEG frame files.
 * @param string|null $audioPath   Absolute path to the extracted audio file (MP3),
 *                                 or null if no audio was extracted / available.
 * @return string  Generated MediaWiki wikitext.
 * @throws \RuntimeException If no frames are provided, the provider is unknown, or the API call fails.
 */
public function generateWikitext( string $videoTitle, array $framePaths, ?string $audioPath = null ): string {
if ( $framePaths === [] ) {
throw new \RuntimeException( 'No frames provided for video-to-wikitext conversion.' );
}

switch ( $this->provider ) {
case self::PROVIDER_OPENAI:
return $this->callOpenAiVision( $videoTitle, $framePaths, $audioPath );
case self::PROVIDER_CLAUDE:
return $this->callClaudeVision( $videoTitle, $framePaths, $audioPath );
case self::PROVIDER_GEMINI:
return $this->callGeminiVision( $videoTitle, $framePaths, $audioPath );
default:
throw new \RuntimeException(
"Unknown LLM provider: '{$this->provider}'. "
. "Supported providers for video import: 'openai', 'claude', 'gemini'."
);
}
}

// -----------------------------------------------------------------------
// OpenAI
// -----------------------------------------------------------------------

/**
 * Call the OpenAI Chat Completions API with vision (base64 image_url parts).
 *
 * Audio is transcribed first via Whisper and included as additional text.
 *
 * @param string      $videoTitle
 * @param string[]    $framePaths
 * @param string|null $audioPath
 * @return string
 */
private function callOpenAiVision( string $videoTitle, array $framePaths, ?string $audioPath ): string {
$transcript = null;
if ( $audioPath !== null ) {
$transcript = $this->transcribeWithWhisper( $audioPath, $this->apiKey );
}

$contentParts = [
[ 'type' => 'text', 'text' => $this->buildUserMessage( $videoTitle, $framePaths, $transcript ) ],
];

foreach ( $framePaths as $framePath ) {
$frameData = $this->loadBase64( $framePath, self::MAX_FRAME_BYTES );
if ( $frameData === null ) {
continue;
}
$contentParts[] = [
'type'      => 'image_url',
'image_url' => [ 'url' => 'data:image/jpeg;base64,' . $frameData ],
];
}

$body = json_encode( [
'model'                 => $this->model,
'max_completion_tokens' => self::MAX_TOKENS,
'messages'              => [
[ 'role' => 'system', 'content' => $this->prompt ],
[ 'role' => 'user',   'content' => $contentParts ],
],
] );

$headers = [
'Content-Type: application/json',
'Authorization: Bearer ' . $this->apiKey,
];

$response = $this->httpPost( self::OPENAI_API_URL, $body, $headers );
$data     = json_decode( $response, true );

if ( !is_array( $data ) ) {
throw new \RuntimeException( 'OpenAI API returned invalid JSON.' );
}
if ( isset( $data['error'] ) ) {
throw new \RuntimeException( 'OpenAI API error: ' . ( $data['error']['message'] ?? 'unknown error' ) );
}

$content = $data['choices'][0]['message']['content'] ?? null;
if ( $content === null ) {
throw new \RuntimeException( 'OpenAI API response missing expected content field.' );
}

return (string)$content;
}

// -----------------------------------------------------------------------
// Anthropic Claude
// -----------------------------------------------------------------------

/**
 * Call the Anthropic Claude Messages API with base64-encoded image content.
 *
 * Audio is transcribed via Whisper using the separate TranscriptionApiKey if
 * configured; otherwise the audio track is silently skipped.
 *
 * @param string      $videoTitle
 * @param string[]    $framePaths
 * @param string|null $audioPath
 * @return string
 */
private function callClaudeVision( string $videoTitle, array $framePaths, ?string $audioPath ): string {
$transcript = null;
if ( $audioPath !== null && $this->transcriptionApiKey !== '' ) {
$transcript = $this->transcribeWithWhisper( $audioPath, $this->transcriptionApiKey );
}

$messageParts = [];

foreach ( $framePaths as $framePath ) {
$frameData = $this->loadBase64( $framePath, self::MAX_FRAME_BYTES );
if ( $frameData === null ) {
continue;
}
$messageParts[] = [
'type'   => 'image',
'source' => [
'type'       => 'base64',
'media_type' => 'image/jpeg',
'data'       => $frameData,
],
];
}

$messageParts[] = [
'type' => 'text',
'text' => $this->buildUserMessage( $videoTitle, $framePaths, $transcript ),
];

$body = json_encode( [
'model'      => $this->model,
'max_tokens' => self::MAX_TOKENS,
'system'     => $this->prompt,
'messages'   => [
[ 'role' => 'user', 'content' => $messageParts ],
],
] );

$headers = [
'Content-Type: application/json',
'x-api-key: ' . $this->apiKey,
'anthropic-version: ' . self::CLAUDE_API_VERSION,
];

$response = $this->httpPost( self::CLAUDE_API_URL, $body, $headers );
$data     = json_decode( $response, true );

if ( !is_array( $data ) ) {
throw new \RuntimeException( 'Claude API returned invalid JSON.' );
}
if ( isset( $data['error'] ) ) {
throw new \RuntimeException( 'Claude API error: ' . ( $data['error']['message'] ?? 'unknown error' ) );
}

$content = $data['content'][0]['text'] ?? null;
if ( $content === null ) {
throw new \RuntimeException( 'Claude API response missing expected content field.' );
}

return (string)$content;
}

// -----------------------------------------------------------------------
// Google Gemini
// -----------------------------------------------------------------------

/**
 * Call the Google Generative Language API (Gemini) with inline image data
 * and (when available) the raw audio file — no separate transcription step.
 *
 * The API key is passed as a query parameter as required by the Gemini REST API.
 *
 * @param string      $videoTitle
 * @param string[]    $framePaths
 * @param string|null $audioPath
 * @return string
 */
private function callGeminiVision( string $videoTitle, array $framePaths, ?string $audioPath ): string {
$parts = [
[ 'text' => $this->prompt . "\n\n" . $this->buildUserMessage( $videoTitle, $framePaths, null ) ],
];

// Include audio inline — Gemini understands audio natively.
if ( $audioPath !== null ) {
$audioData = $this->loadBase64( $audioPath, self::MAX_AUDIO_BYTES );
if ( $audioData !== null ) {
$parts[] = [
'inline_data' => [
'mime_type' => 'audio/mpeg',
'data'      => $audioData,
],
];
}
}

foreach ( $framePaths as $framePath ) {
$frameData = $this->loadBase64( $framePath, self::MAX_FRAME_BYTES );
if ( $frameData === null ) {
continue;
}
$parts[] = [
'inline_data' => [
'mime_type' => 'image/jpeg',
'data'      => $frameData,
],
];
}

$body = json_encode( [
'contents'         => [ [ 'parts' => $parts ] ],
'generationConfig' => [ 'maxOutputTokens' => self::MAX_TOKENS ],
] );

$url     = self::GEMINI_API_BASE . $this->model . ':generateContent?key=' . urlencode( $this->apiKey );
$headers = [ 'Content-Type: application/json' ];

$response = $this->httpPost( $url, $body, $headers );
$data     = json_decode( $response, true );

if ( !is_array( $data ) ) {
throw new \RuntimeException( 'Gemini API returned invalid JSON.' );
}
if ( isset( $data['error'] ) ) {
throw new \RuntimeException( 'Gemini API error: ' . ( $data['error']['message'] ?? 'unknown error' ) );
}

$content = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
if ( $content === null ) {
throw new \RuntimeException( 'Gemini API response missing expected content field.' );
}

return (string)$content;
}

// -----------------------------------------------------------------------
// Audio transcription (Whisper)
// -----------------------------------------------------------------------

/**
 * Transcribe an audio file using the OpenAI Whisper API.
 *
 * Uses multipart/form-data as required by the /v1/audio/transcriptions endpoint.
 * Returns the plain-text transcript, or null on failure (logged but not thrown
 * so that the caller can still proceed with frames-only wikitext generation).
 *
 * @param string $audioPath  Absolute path to the audio file (MP3 recommended).
 * @param string $apiKey     OpenAI API key (may differ from $this->apiKey for Claude setups).
 * @return string|null
 */
protected function transcribeWithWhisper( string $audioPath, string $apiKey ): ?string {
if ( !file_exists( $audioPath ) ) {
wfDebugLog( 'PandocUltimateConverter', "VideoToWikitextService: audio file not found: $audioPath" );
return null;
}

if ( !function_exists( 'curl_init' ) ) {
wfDebugLog( 'PandocUltimateConverter', 'VideoToWikitextService: cURL unavailable, skipping transcription' );
return null;
}

wfDebugLog( 'PandocUltimateConverter', "VideoToWikitextService: transcribing audio via Whisper: $audioPath" );

$ch = curl_init( self::WHISPER_API_URL );
curl_setopt_array( $ch, [
CURLOPT_POST           => true,
CURLOPT_POSTFIELDS     => [
'file'            => new \CURLFile( $audioPath, 'audio/mpeg', basename( $audioPath ) ),
'model'           => self::WHISPER_MODEL,
'response_format' => 'text',
],
CURLOPT_HTTPHEADER     => [ 'Authorization: Bearer ' . $apiKey ],
CURLOPT_RETURNTRANSFER => true,
CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
] );

$response = curl_exec( $ch );
$error    = curl_error( $ch );
$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
curl_close( $ch );

if ( $response === false || $httpCode < 200 || $httpCode >= 300 ) {
wfDebugLog(
'PandocUltimateConverter',
"VideoToWikitextService: Whisper transcription failed (HTTP $httpCode): "
. ( $response !== false ? substr( (string)$response, 0, 300 ) : $error )
);
return null;
}

$transcript = trim( (string)$response );
wfDebugLog(
'PandocUltimateConverter',
'VideoToWikitextService: Whisper transcript (' . strlen( $transcript ) . ' chars): '
. substr( $transcript, 0, 200 )
);

return $transcript !== '' ? $transcript : null;
}

// -----------------------------------------------------------------------
// Shared helpers
// -----------------------------------------------------------------------

/**
 * Build the user-facing message describing the video title, frame filenames,
 * and (when available) the audio transcript.
 *
 * @param string      $videoTitle
 * @param string[]    $framePaths
 * @param string|null $transcript  Plain-text audio transcript, or null if unavailable.
 * @return string
 */
private function buildUserMessage( string $videoTitle, array $framePaths, ?string $transcript ): string {
$frameNames = implode( ', ', array_map( 'basename', $framePaths ) );
$message    = 'Video title: ' . $videoTitle
. "\nFrame filenames (use these exact names in [[File:…]] wiki image references): "
. $frameNames;

if ( $transcript !== null ) {
$message .= "\n\n=== Audio transcript ===\n" . $transcript;
}

return $message;
}

/**
 * Load a file from disk and return its base64-encoded content.
 *
 * Returns null when the file is missing or exceeds $maxBytes.
 *
 * @param string $path     Absolute path to the file.
 * @param int    $maxBytes Maximum allowed file size in bytes.
 * @return string|null
 */
private function loadBase64( string $path, int $maxBytes ): ?string {
if ( !file_exists( $path ) ) {
wfDebugLog( 'PandocUltimateConverter', "VideoToWikitextService: file not found: $path" );
return null;
}

$size = filesize( $path );
if ( $size === false || $size > $maxBytes ) {
	// Intentionally skip oversized files: LLM APIs have per-request payload limits and
	// charge per token/byte. Silently dropping a frame or an audio file is preferable
	// to failing the whole conversion.  The debug log records the skip for diagnostics.
	wfDebugLog(
		'PandocUltimateConverter',
		"VideoToWikitextService: skipping oversized file ($size bytes, max $maxBytes): $path"
	);
	return null;
}

$data = file_get_contents( $path );
return $data !== false ? base64_encode( $data ) : null;
}

/**
 * Perform an HTTP POST request using cURL.
 *
 * @param string          $url
 * @param string|string[] $body     JSON-encoded string, or array for multipart/form-data.
 * @param string[]        $headers
 * @return string  Response body
 * @throws \RuntimeException On cURL error or non-2xx HTTP status.
 */
protected function httpPost( string $url, $body, array $headers ): string {
if ( !function_exists( 'curl_init' ) ) {
throw new \RuntimeException( 'cURL is required for LLM API calls but is not available.' );
}

$ch = curl_init( $url );
curl_setopt_array( $ch, [
CURLOPT_POST           => true,
CURLOPT_POSTFIELDS     => $body,
CURLOPT_HTTPHEADER     => $headers,
CURLOPT_RETURNTRANSFER => true,
CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
] );

$response = curl_exec( $ch );
$error    = curl_error( $ch );

if ( $response === false ) {
curl_close( $ch );
throw new \RuntimeException( 'LLM API cURL request failed: ' . $error );
}

$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
curl_close( $ch );

if ( $httpCode < 200 || $httpCode >= 300 ) {
$data = json_decode( (string)$response, true );
$msg  = is_array( $data )
? ( $data['error']['message'] ?? (string)$response )
: (string)$response;
throw new \RuntimeException( "LLM API request failed (HTTP $httpCode): $msg" );
}

return (string)$response;
}

/**
 * Return the default model name for the configured provider.
 *
 * @return string
 */
private function defaultModel(): string {
switch ( $this->provider ) {
case self::PROVIDER_CLAUDE:
return self::CLAUDE_DEFAULT_MODEL;
case self::PROVIDER_GEMINI:
return self::GEMINI_DEFAULT_MODEL;
default:
return self::OPENAI_DEFAULT_MODEL;
}
}
}
