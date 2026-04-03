<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter;

/**
 * Service that sends converted wikitext to an LLM (OpenAI, Claude/Anthropic, or any
 * OpenAI-compatible endpoint) for optional post-conversion cleanup.
 *
 * Configuration (in LocalSettings.php):
 *   $wgPandocUltimateConverter_LlmProvider  = 'openai';        // or 'claude'
 *   $wgPandocUltimateConverter_LlmApiKey    = 'sk-...';
 *   $wgPandocUltimateConverter_LlmModel     = 'gpt-5.4-nano';  // optional, uses provider default
 *   $wgPandocUltimateConverter_LlmPrompt    = '...';           // optional, uses built-in default
 *   $wgPandocUltimateConverter_LlmBaseUrl   = 'http://localhost:11434/v1/chat/completions';
 *                                             // optional; overrides the provider's default API URL.
 *                                             // Useful for self-hosted models (Ollama, vLLM, etc.)
 *                                             // or OpenAI-compatible third-party services.
 *                                             // When set, the API key becomes optional.
 */
class LlmPolishService {

	private const PROVIDER_OPENAI = 'openai';
	private const PROVIDER_CLAUDE = 'claude';

	private const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';
	private const CLAUDE_API_URL = 'https://api.anthropic.com/v1/messages';

	private const OPENAI_DEFAULT_MODEL = 'gpt-5.4-nano';
	private const CLAUDE_DEFAULT_MODEL = 'claude-3-5-haiku-20241022';

	private const CLAUDE_API_VERSION = '2023-06-01';

	/** Maximum tokens to request from the LLM */
	private const MAX_TOKENS = 81920;

	/** cURL timeout in seconds for LLM API calls */
	private const HTTP_TIMEOUT = 300;

	/** Default cleanup prompt */
	private const DEFAULT_PROMPT = 'Clean up the following MediaWiki wikitext. It was created by conversion from other source (file or URL).'
		. 'Remove unnecessary formatting artifacts (html tags, extra whitespace, website headers and menu, empty span tags, etc.), fix inconsistent spacing, and improve readability '
		. 'while strictly preserving all content, wiki links, templates, and MediaWiki markup syntax. '
		. 'Return only the cleaned wikitext without any explanations or commentary.';

	/** @var string */
	private $provider;

	/** @var string */
	private $apiKey;

	/** @var string */
	private $model;

	/** @var string */
	private $prompt;

	/** @var string */
	private $baseUrl;

	/**
	 * @param string $provider  'openai' or 'claude'
	 * @param string $apiKey    API key for the chosen provider (may be empty for self-hosted endpoints)
	 * @param string $model     Model name, or empty string to use provider default
	 * @param string $prompt    System/instruction prompt, or empty string to use built-in default
	 * @param string $baseUrl   Custom API endpoint URL, or empty string to use the provider default
	 */
	public function __construct( string $provider, string $apiKey, string $model = '', string $prompt = '', string $baseUrl = '' ) {
		$this->provider = strtolower( trim( $provider ) );
		$this->apiKey   = $apiKey;
		$this->model    = $model !== '' ? $model : $this->defaultModel();
		$this->prompt   = $prompt !== '' ? $prompt : self::DEFAULT_PROMPT;
		$this->baseUrl  = rtrim( $baseUrl, '/' );
	}

	/**
	 * Build an LlmPolishService from the extension configuration, or return null if not configured.
	 *
	 * @param \MediaWiki\Config\Config $config
	 * @return self|null
	 */
	public static function newFromConfig( $config ): ?self {
		$provider = (string)( $config->get( 'PandocUltimateConverter_LlmProvider' ) ?? '' );
		$apiKey   = (string)( $config->get( 'PandocUltimateConverter_LlmApiKey' )   ?? '' );
		$baseUrl  = (string)( $config->get( 'PandocUltimateConverter_LlmBaseUrl' )  ?? '' );

		if ( $provider === '' ) {
			return null;
		}

		// API key is required unless a custom base URL is provided (e.g. self-hosted, unauthenticated)
		if ( $apiKey === '' && $baseUrl === '' ) {
			return null;
		}

		$model  = (string)( $config->get( 'PandocUltimateConverter_LlmModel' )  ?? '' );
		$prompt = (string)( $config->get( 'PandocUltimateConverter_LlmPrompt' ) ?? '' );

		return new self( $provider, $apiKey, $model, $prompt, $baseUrl );
	}

	/**
	 * Polish the given wikitext using the configured LLM.
	 *
	 * @param string $wikitext
	 * @return string  Polished wikitext, or the original if the call fails.
	 * @throws \RuntimeException If the LLM provider is unknown or the API call fails fatally.
	 */
	public function polish( string $wikitext ): string {
		switch ( $this->provider ) {
			case self::PROVIDER_OPENAI:
				return $this->callOpenAi( $wikitext );
			case self::PROVIDER_CLAUDE:
				return $this->callClaude( $wikitext );
			default:
				throw new \RuntimeException(
					"Unknown LLM provider: '{$this->provider}'. Use 'openai' or 'claude'."
				);
		}
	}

	/**
	 * Call the OpenAI Chat Completions API.
	 *
	 * Uses `max_completion_tokens` for the native OpenAI endpoint and `max_tokens` for custom
	 * endpoints (e.g. Qwen/DashScope, Ollama, vLLM) which follow the older OpenAI-compatible
	 * convention and do not recognise `max_completion_tokens`.
	 *
	 * @param string $wikitext
	 * @return string
	 */
	private function callOpenAi( string $wikitext ): string {
		// OpenAI renamed the field; third-party compatible APIs still expect max_tokens
		$tokenLimitKey = $this->baseUrl !== '' ? 'max_tokens' : 'max_completion_tokens';

		$body = json_encode( [
			'model'          => $this->model,
			$tokenLimitKey   => self::MAX_TOKENS,
			'messages'       => [
				[ 'role' => 'system', 'content' => $this->prompt ],
				[ 'role' => 'user',   'content' => $wikitext ],
			],
		] );

		$headers = [
			'Content-Type: application/json',
		];
		if ( $this->apiKey !== '' ) {
			$headers[] = 'Authorization: Bearer ' . $this->apiKey;
		}

		$url = $this->baseUrl !== '' ? $this->baseUrl : self::OPENAI_API_URL;
		$response = $this->httpPost( $url, $body, $headers );
		$data = json_decode( $response, true );

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

	/**
	 * Call the Anthropic Claude Messages API.
	 *
	 * @param string $wikitext
	 * @return string
	 */
	private function callClaude( string $wikitext ): string {
		$body = json_encode( [
			'model'      => $this->model,
			'max_tokens' => self::MAX_TOKENS,
			'system'     => $this->prompt,
			'messages'   => [
				[ 'role' => 'user', 'content' => $wikitext ],
			],
		] );

		$headers = [
			'Content-Type: application/json',
			'anthropic-version: ' . self::CLAUDE_API_VERSION,
		];
		if ( $this->apiKey !== '' ) {
			$headers[] = 'x-api-key: ' . $this->apiKey;
		}

		$url = $this->baseUrl !== '' ? $this->baseUrl : self::CLAUDE_API_URL;
		$response = $this->httpPost( $url, $body, $headers );
		$data = json_decode( $response, true );

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
			$msg  = is_array( $data ) ? ( $data['error']['message'] ?? (string)$response ) : (string)$response;
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
		return $this->provider === self::PROVIDER_CLAUDE
			? self::CLAUDE_DEFAULT_MODEL
			: self::OPENAI_DEFAULT_MODEL;
	}
}
