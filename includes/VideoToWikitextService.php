<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter;

/**
 * Generates MediaWiki wikitext from a set of video frames by calling a
 * vision-capable LLM API.
 *
 * Supported providers
 * -------------------
 * - 'openai'  — OpenAI Chat Completions API with base64 image_url content (e.g. gpt-4o)
 * - 'claude'  — Anthropic Messages API with base64 image content (e.g. claude-3-5-sonnet)
 * - 'gemini'  — Google Generative Language API with inline image data (e.g. gemini-1.5-flash)
 *
 * Pipeline
 * --------
 * 1. VideoPreprocessor extracts JPEG frames to $mediaFolder.
 * 2. VideoToWikitextService encodes each frame as base64.
 * 3. LLM is prompted to write a MediaWiki article referencing the frames.
 * 4. Generated wikitext uses bare filenames (e.g. [[File:frame-001.jpg]]).
 * 5. PandocTextPostprocessor rewrites basenames to fully-qualified wiki names.
 *
 * Configuration (LocalSettings.php)
 * ----------------------------------
 *   $wgPandocUltimateConverter_LlmProvider    = 'openai';           // 'claude' or 'gemini'
 *   $wgPandocUltimateConverter_LlmApiKey      = '...';
 *   $wgPandocUltimateConverter_LlmVideoModel  = 'gpt-4o';           // optional
 *   $wgPandocUltimateConverter_LlmVideoPrompt = '...';              // optional
 */
class VideoToWikitextService {

	private const PROVIDER_OPENAI = 'openai';
	private const PROVIDER_CLAUDE = 'claude';
	private const PROVIDER_GEMINI = 'gemini';

	private const OPENAI_API_URL  = 'https://api.openai.com/v1/chat/completions';
	private const CLAUDE_API_URL  = 'https://api.anthropic.com/v1/messages';
	private const GEMINI_API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

	private const OPENAI_DEFAULT_MODEL = 'gpt-4o';
	private const CLAUDE_DEFAULT_MODEL = 'claude-3-5-sonnet-20241022';
	private const GEMINI_DEFAULT_MODEL = 'gemini-1.5-flash';

	private const CLAUDE_API_VERSION = '2023-06-01';

	/** Maximum output tokens to request from the LLM. */
	private const MAX_TOKENS = 8192;

	/** cURL timeout in seconds for LLM API calls. */
	private const HTTP_TIMEOUT = 300;

	/**
	 * Maximum byte size for a single frame before it is skipped.
	 * Keeps individual API requests within reasonable limits (≈ 2 MB per frame).
	 */
	private const MAX_FRAME_BYTES = 2097152;

	/** Default system prompt that instructs the LLM to produce a wiki article. */
	private const DEFAULT_PROMPT =
		'You are an expert wiki editor. You have been given a series of frames extracted from a video. '
		. 'Based on these frames, write a MediaWiki wikitext article that describes the video content. '
		. 'Include: a concise lead paragraph summarising the overall content; ==Sections== for distinct topics or scenes visible in the frames; '
		. 'embed the provided frame images using proper MediaWiki image syntax '
		. '(e.g. [[File:frame-001.jpg|thumb|Description of what is shown]]). '
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
	 * @param string $provider  'openai', 'claude', or 'gemini'
	 * @param string $apiKey    API key for the chosen provider
	 * @param string $model     Model name, or empty string to use the provider default
	 * @param string $prompt    System/instruction prompt, or empty string to use the built-in default
	 */
	public function __construct( string $provider, string $apiKey, string $model = '', string $prompt = '' ) {
		$this->provider = strtolower( trim( $provider ) );
		$this->apiKey   = $apiKey;
		$this->model    = $model !== '' ? $model : $this->defaultModel();
		$this->prompt   = $prompt !== '' ? $prompt : self::DEFAULT_PROMPT;
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

		$model  = (string)( $config->get( 'PandocUltimateConverter_LlmVideoModel' )  ?? '' );
		$prompt = (string)( $config->get( 'PandocUltimateConverter_LlmVideoPrompt' ) ?? '' );

		return new self( $provider, $apiKey, $model, $prompt );
	}

	/**
	 * Generate MediaWiki wikitext that describes the video, incorporating the supplied frames.
	 *
	 * The returned wikitext will contain [[File:frame-NNN.jpg|…]] references using the
	 * basenames of the supplied frame paths.  PandocTextPostprocessor will later rewrite
	 * these basenames to the fully-qualified wiki file names after the frames are uploaded.
	 *
	 * @param string   $videoTitle  Human-readable title / base name of the video.
	 * @param string[] $framePaths  Absolute paths to JPEG frame files on disk.
	 * @return string  Generated MediaWiki wikitext.
	 * @throws \RuntimeException If no frames are provided, the provider is unknown, or the API call fails.
	 */
	public function generateWikitext( string $videoTitle, array $framePaths ): string {
		if ( $framePaths === [] ) {
			throw new \RuntimeException( 'No frames provided for video-to-wikitext conversion.' );
		}

		switch ( $this->provider ) {
			case self::PROVIDER_OPENAI:
				return $this->callOpenAiVision( $videoTitle, $framePaths );
			case self::PROVIDER_CLAUDE:
				return $this->callClaudeVision( $videoTitle, $framePaths );
			case self::PROVIDER_GEMINI:
				return $this->callGeminiVision( $videoTitle, $framePaths );
			default:
				throw new \RuntimeException(
					"Unknown LLM provider: '{$this->provider}'. Supported providers for video import: 'openai', 'claude', 'gemini'."
				);
		}
	}

	// -----------------------------------------------------------------------
	// OpenAI
	// -----------------------------------------------------------------------

	/**
	 * Call the OpenAI Chat Completions API with vision (image_url content parts).
	 *
	 * @param string   $videoTitle
	 * @param string[] $framePaths
	 * @return string
	 */
	private function callOpenAiVision( string $videoTitle, array $framePaths ): string {
		$contentParts = [
			[ 'type' => 'text', 'text' => $this->buildUserMessage( $videoTitle, $framePaths ) ],
		];

		foreach ( $framePaths as $framePath ) {
			$frameData = $this->loadFrameBase64( $framePath );
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
	 * @param string   $videoTitle
	 * @param string[] $framePaths
	 * @return string
	 */
	private function callClaudeVision( string $videoTitle, array $framePaths ): string {
		$messageParts = [];

		foreach ( $framePaths as $framePath ) {
			$frameData = $this->loadFrameBase64( $framePath );
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
			'text' => $this->buildUserMessage( $videoTitle, $framePaths ),
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
	 * Call the Google Generative Language API (Gemini) with inline image data.
	 *
	 * The API key is passed as a query parameter as required by the Gemini REST API.
	 *
	 * @param string   $videoTitle
	 * @param string[] $framePaths
	 * @return string
	 */
	private function callGeminiVision( string $videoTitle, array $framePaths ): string {
		$parts = [
			[ 'text' => $this->prompt . "\n\n" . $this->buildUserMessage( $videoTitle, $framePaths ) ],
		];

		foreach ( $framePaths as $framePath ) {
			$frameData = $this->loadFrameBase64( $framePath );
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
	// Shared helpers
	// -----------------------------------------------------------------------

	/**
	 * Build the user-facing message that describes the video and lists the frame filenames
	 * the LLM should use in its [[File:…]] references.
	 *
	 * @param string   $videoTitle
	 * @param string[] $framePaths
	 * @return string
	 */
	private function buildUserMessage( string $videoTitle, array $framePaths ): string {
		$frameNames = implode( ', ', array_map( 'basename', $framePaths ) );
		return 'Video title: ' . $videoTitle
			. "\nFrame filenames (use these exact names in [[File:…]] wiki image references): "
			. $frameNames;
	}

	/**
	 * Load a frame file from disk and return its base64-encoded content.
	 *
	 * Returns null if the file does not exist or exceeds the per-frame size cap.
	 *
	 * @param string $framePath Absolute path to a JPEG frame file.
	 * @return string|null
	 */
	private function loadFrameBase64( string $framePath ): ?string {
		if ( !file_exists( $framePath ) ) {
			wfDebugLog( 'PandocUltimateConverter', "VideoToWikitextService: frame not found: $framePath" );
			return null;
		}

		$size = filesize( $framePath );
		if ( $size === false || $size > self::MAX_FRAME_BYTES ) {
			wfDebugLog(
				'PandocUltimateConverter',
				"VideoToWikitextService: skipping oversized frame ($size bytes): $framePath"
			);
			return null;
		}

		$data = file_get_contents( $framePath );
		return $data !== false ? base64_encode( $data ) : null;
	}

	/**
	 * Perform an HTTP POST request using cURL.
	 *
	 * @param string   $url
	 * @param string   $body     JSON-encoded request body
	 * @param string[] $headers
	 * @return string  Response body
	 * @throws \RuntimeException On cURL error or non-2xx HTTP status.
	 */
	protected function httpPost( string $url, string $body, array $headers ): string {
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
