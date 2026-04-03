<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use MediaWiki\Extension\PandocUltimateConverter\LlmPolishService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LlmPolishService.
 *
 * HTTP calls are intercepted by the TestableLlmPolishService subclass, which
 * overrides the protected httpPost() hook so that no real network requests are
 * made during testing.
 *
 * @covers \MediaWiki\Extension\PandocUltimateConverter\LlmPolishService
 */
class LlmPolishServiceTest extends TestCase {

	// ------------------------------------------------------------------
	// newFromConfig
	// ------------------------------------------------------------------

	public function testNewFromConfigReturnsNullWhenProviderMissing(): void {
		$config = $this->makeConfig( [ 'PandocUltimateConverter_LlmProvider' => '' ] );
		$this->assertNull( LlmPolishService::newFromConfig( $config ) );
	}

	public function testNewFromConfigReturnsNullWhenApiKeyMissing(): void {
		$config = $this->makeConfig( [
			'PandocUltimateConverter_LlmProvider' => 'openai',
			'PandocUltimateConverter_LlmApiKey'   => '',
		] );
		$this->assertNull( LlmPolishService::newFromConfig( $config ) );
	}

	public function testNewFromConfigReturnsNullWhenBothMissing(): void {
		$config = $this->makeConfig( [
			'PandocUltimateConverter_LlmProvider' => null,
			'PandocUltimateConverter_LlmApiKey'   => null,
		] );
		$this->assertNull( LlmPolishService::newFromConfig( $config ) );
	}

	public function testNewFromConfigReturnsServiceWhenOpenAiConfigured(): void {
		$config = $this->makeConfig( [
			'PandocUltimateConverter_LlmProvider' => 'openai',
			'PandocUltimateConverter_LlmApiKey'   => 'sk-test',
		] );
		$this->assertInstanceOf( LlmPolishService::class, LlmPolishService::newFromConfig( $config ) );
	}

	public function testNewFromConfigReturnsServiceWhenClaudeConfigured(): void {
		$config = $this->makeConfig( [
			'PandocUltimateConverter_LlmProvider' => 'claude',
			'PandocUltimateConverter_LlmApiKey'   => 'sk-ant-test',
		] );
		$this->assertInstanceOf( LlmPolishService::class, LlmPolishService::newFromConfig( $config ) );
	}

	// ------------------------------------------------------------------
	// Unknown provider
	// ------------------------------------------------------------------

	public function testPolishThrowsForUnknownProvider(): void {
		$svc = new TestableLlmPolishService( 'unknownprovider', 'key', '', '', '', 'any_response' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Unknown LLM provider/' );
		$svc->polish( 'some wikitext' );
	}

	// ------------------------------------------------------------------
	// OpenAI — request shape and response parsing
	// ------------------------------------------------------------------

	public function testOpenAiRequestSendsCorrectUrlAndAuthHeader(): void {
		$svc = new TestableLlmPolishService( 'openai', 'sk-mykey', '', '', '', json_encode( [
			'choices' => [ [ 'message' => [ 'content' => 'polished text' ] ] ],
		] ) );

		$svc->polish( 'raw wikitext' );

		$this->assertStringContainsString( 'api.openai.com', $svc->lastUrl );
		$this->assertContains( 'Authorization: Bearer sk-mykey', $svc->lastHeaders );
	}

	public function testOpenAiRequestBodyContainsWikitext(): void {
		$svc = new TestableLlmPolishService( 'openai', 'sk-key', '', '', '', json_encode( [
			'choices' => [ [ 'message' => [ 'content' => 'result' ] ] ],
		] ) );

		$svc->polish( 'my wikitext content' );

		$body = json_decode( $svc->lastBody, true );
		$this->assertIsArray( $body );
		$this->assertSame( 'my wikitext content', $body['messages'][1]['content'] );
		$this->assertSame( 'user', $body['messages'][1]['role'] );
	}

	public function testOpenAiRequestBodyContainsSystemPrompt(): void {
		$svc = new TestableLlmPolishService( 'openai', 'sk-key', '', 'Custom prompt', '', json_encode( [
			'choices' => [ [ 'message' => [ 'content' => 'result' ] ] ],
		] ) );

		$svc->polish( 'wikitext' );

		$body = json_decode( $svc->lastBody, true );
		$this->assertSame( 'system', $body['messages'][0]['role'] );
		$this->assertSame( 'Custom prompt', $body['messages'][0]['content'] );
	}

	public function testOpenAiResponseContentIsReturned(): void {
		$svc = new TestableLlmPolishService( 'openai', 'key', '', '', '', json_encode( [
			'choices' => [ [ 'message' => [ 'content' => 'cleaned wikitext' ] ] ],
		] ) );

		$result = $svc->polish( 'raw' );

		$this->assertSame( 'cleaned wikitext', $result );
	}

	public function testOpenAiErrorResponseThrows(): void {
		$svc = new TestableLlmPolishService( 'openai', 'key', '', '', '', json_encode( [
			'error' => [ 'message' => 'Rate limit exceeded' ],
		] ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Rate limit exceeded/' );
		$svc->polish( 'text' );
	}

	public function testOpenAiMissingContentFieldThrows(): void {
		$svc = new TestableLlmPolishService( 'openai', 'key', '', '', '', json_encode( [
			'choices' => [ [ 'message' => [] ] ],
		] ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/missing expected content field/' );
		$svc->polish( 'text' );
	}

	public function testOpenAiInvalidJsonResponseThrows(): void {
		$svc = new TestableLlmPolishService( 'openai', 'key', '', '', '', 'not-json{{{' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/invalid JSON/' );
		$svc->polish( 'text' );
	}

	// ------------------------------------------------------------------
	// OpenAI — default model
	// ------------------------------------------------------------------

	public function testOpenAiUsesDefaultModelWhenNoneSpecified(): void {
		$svc = new TestableLlmPolishService( 'openai', 'key', '', '', '', json_encode( [
			'choices' => [ [ 'message' => [ 'content' => 'out' ] ] ],
		] ) );

		$svc->polish( 'wikitext' );

		$body = json_decode( $svc->lastBody, true );
		$this->assertArrayHasKey( 'model', $body );
		// The default must be a non-empty string that does not equal 'claude' default
		$this->assertNotEmpty( $body['model'] );
		$this->assertStringNotContainsString( 'claude', $body['model'] );
	}

	public function testOpenAiUsesCustomModelWhenSpecified(): void {
		$svc = new TestableLlmPolishService( 'openai', 'key', 'gpt-4-turbo', '', '', json_encode( [
			'choices' => [ [ 'message' => [ 'content' => 'out' ] ] ],
		] ) );

		$svc->polish( 'wikitext' );

		$body = json_decode( $svc->lastBody, true );
		$this->assertSame( 'gpt-4-turbo', $body['model'] );
	}

	// ------------------------------------------------------------------
	// Claude — request shape and response parsing
	// ------------------------------------------------------------------

	public function testClaudeRequestSendsCorrectUrlAndAuthHeaders(): void {
		$svc = new TestableLlmPolishService( 'claude', 'sk-ant-mykey', '', '', '', json_encode( [
			'content' => [ [ 'text' => 'polished' ] ],
		] ) );

		$svc->polish( 'raw wikitext' );

		$this->assertStringContainsString( 'anthropic.com', $svc->lastUrl );
		$this->assertContains( 'x-api-key: sk-ant-mykey', $svc->lastHeaders );
		// Must include anthropic-version header
		$hasVersion = false;
		foreach ( $svc->lastHeaders as $h ) {
			if ( str_starts_with( $h, 'anthropic-version:' ) ) {
				$hasVersion = true;
				break;
			}
		}
		$this->assertTrue( $hasVersion, 'anthropic-version header must be present' );
	}

	public function testClaudeRequestBodyContainsWikitext(): void {
		$svc = new TestableLlmPolishService( 'claude', 'key', '', '', '', json_encode( [
			'content' => [ [ 'text' => 'result' ] ],
		] ) );

		$svc->polish( 'wikitext input' );

		$body = json_decode( $svc->lastBody, true );
		$this->assertSame( 'wikitext input', $body['messages'][0]['content'] );
		$this->assertSame( 'user', $body['messages'][0]['role'] );
	}

	public function testClaudeRequestBodyContainsSystemPromptAtTopLevel(): void {
		$svc = new TestableLlmPolishService( 'claude', 'key', '', 'Be concise', '', json_encode( [
			'content' => [ [ 'text' => 'result' ] ],
		] ) );

		$svc->polish( 'wikitext' );

		$body = json_decode( $svc->lastBody, true );
		$this->assertArrayHasKey( 'system', $body );
		$this->assertSame( 'Be concise', $body['system'] );
	}

	public function testClaudeResponseContentIsReturned(): void {
		$svc = new TestableLlmPolishService( 'claude', 'key', '', '', '', json_encode( [
			'content' => [ [ 'text' => 'cleaned by claude' ] ],
		] ) );

		$result = $svc->polish( 'raw' );

		$this->assertSame( 'cleaned by claude', $result );
	}

	public function testClaudeErrorResponseThrows(): void {
		$svc = new TestableLlmPolishService( 'claude', 'key', '', '', '', json_encode( [
			'error' => [ 'message' => 'Overloaded' ],
		] ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Overloaded/' );
		$svc->polish( 'text' );
	}

	public function testClaudeMissingContentFieldThrows(): void {
		$svc = new TestableLlmPolishService( 'claude', 'key', '', '', '', json_encode( [
			'content' => [],
		] ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/missing expected content field/' );
		$svc->polish( 'text' );
	}

	public function testClaudeInvalidJsonResponseThrows(): void {
		$svc = new TestableLlmPolishService( 'claude', 'key', '', '', '', 'not valid json' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/invalid JSON/' );
		$svc->polish( 'text' );
	}

	// ------------------------------------------------------------------
	// Claude — default model
	// ------------------------------------------------------------------

	public function testClaudeUsesDefaultModelWhenNoneSpecified(): void {
		$svc = new TestableLlmPolishService( 'claude', 'key', '', '', '', json_encode( [
			'content' => [ [ 'text' => 'out' ] ],
		] ) );

		$svc->polish( 'wikitext' );

		$body = json_decode( $svc->lastBody, true );
		$this->assertArrayHasKey( 'model', $body );
		$this->assertStringContainsString( 'claude', $body['model'] );
	}

	public function testClaudeUsesCustomModelWhenSpecified(): void {
		$svc = new TestableLlmPolishService( 'claude', 'key', 'claude-3-opus', '', '', json_encode( [
			'content' => [ [ 'text' => 'out' ] ],
		] ) );

		$svc->polish( 'wikitext' );

		$body = json_decode( $svc->lastBody, true );
		$this->assertSame( 'claude-3-opus', $body['model'] );
	}

	// ------------------------------------------------------------------
	// Provider name normalisation
	// ------------------------------------------------------------------

	public function testProviderNameIsCaseInsensitive(): void {
		$svc = new TestableLlmPolishService( 'OpenAI', 'key', '', '', '', json_encode( [
			'choices' => [ [ 'message' => [ 'content' => 'out' ] ] ],
		] ) );

		// Should not throw; OpenAI endpoint must be used
		$result = $svc->polish( 'wikitext' );
		$this->assertSame( 'out', $result );
		$this->assertStringContainsString( 'openai.com', $svc->lastUrl );
	}

	// ------------------------------------------------------------------
	// Custom base URL
	// ------------------------------------------------------------------

	public function testOpenAiUsesCustomBaseUrl(): void {
		$svc = new TestableLlmPolishService( 'openai', 'key', '', '',
			'http://localhost:11434/v1/chat/completions',
			json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'out' ] ] ] ] )
		);

		$svc->polish( 'wikitext' );

		$this->assertSame( 'http://localhost:11434/v1/chat/completions', $svc->lastUrl );
	}

	public function testClaudeUsesCustomBaseUrl(): void {
		$svc = new TestableLlmPolishService( 'claude', 'key', '', '',
			'http://my-claude-proxy.example.com/v1/messages',
			json_encode( [ 'content' => [ [ 'text' => 'out' ] ] ] )
		);

		$svc->polish( 'wikitext' );

		$this->assertSame( 'http://my-claude-proxy.example.com/v1/messages', $svc->lastUrl );
	}

	public function testOpenAiCustomBaseUrlStripsTrailingSlash(): void {
		$svc = new TestableLlmPolishService( 'openai', 'key', '', '',
			'http://localhost:11434/v1/chat/completions/',
			json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'out' ] ] ] ] )
		);

		$svc->polish( 'wikitext' );

		$this->assertSame( 'http://localhost:11434/v1/chat/completions', $svc->lastUrl );
	}

	public function testClaudeCustomBaseUrlStripsTrailingSlash(): void {
		$svc = new TestableLlmPolishService( 'claude', 'key', '', '',
			'http://my-claude-proxy.example.com/v1/messages/',
			json_encode( [ 'content' => [ [ 'text' => 'out' ] ] ] )
		);

		$svc->polish( 'wikitext' );

		$this->assertSame( 'http://my-claude-proxy.example.com/v1/messages', $svc->lastUrl );
	}

	public function testOpenAiOmitsAuthHeaderWhenApiKeyEmpty(): void {
		$svc = new TestableLlmPolishService( 'openai', '', '', '',
			'http://localhost:11434/v1/chat/completions',
			json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'out' ] ] ] ] )
		);

		$svc->polish( 'wikitext' );

		foreach ( $svc->lastHeaders as $header ) {
			$this->assertStringNotContainsStringIgnoringCase( 'authorization', $header );
		}
	}

	public function testClaudeOmitsApiKeyHeaderWhenApiKeyEmpty(): void {
		$svc = new TestableLlmPolishService( 'claude', '', '', '',
			'http://my-claude-proxy.example.com/v1/messages',
			json_encode( [ 'content' => [ [ 'text' => 'out' ] ] ] )
		);

		$svc->polish( 'wikitext' );

		foreach ( $svc->lastHeaders as $header ) {
			$this->assertStringNotContainsString( 'x-api-key', $header );
		}
	}

	public function testNewFromConfigReturnsServiceWithCustomBaseUrlAndNoApiKey(): void {
		$config = $this->makeConfig( [
			'PandocUltimateConverter_LlmProvider' => 'openai',
			'PandocUltimateConverter_LlmApiKey'   => '',
			'PandocUltimateConverter_LlmBaseUrl'  => 'http://localhost:11434/v1/chat/completions',
		] );
		$this->assertInstanceOf( LlmPolishService::class, LlmPolishService::newFromConfig( $config ) );
	}

	public function testNewFromConfigReturnsNullWhenNoApiKeyAndNoBaseUrl(): void {
		$config = $this->makeConfig( [
			'PandocUltimateConverter_LlmProvider' => 'openai',
			'PandocUltimateConverter_LlmApiKey'   => '',
			'PandocUltimateConverter_LlmBaseUrl'  => '',
		] );
		$this->assertNull( LlmPolishService::newFromConfig( $config ) );
	}

	// ------------------------------------------------------------------
	// Qwen / OpenAI-compatible — max_tokens compatibility
	// ------------------------------------------------------------------

	public function testNativeOpenAiUsesMaxCompletionTokens(): void {
		$svc = new TestableLlmPolishService( 'openai', 'key', '', '', '', json_encode( [
			'choices' => [ [ 'message' => [ 'content' => 'out' ] ] ],
		] ) );

		$svc->polish( 'wikitext' );

		$body = json_decode( $svc->lastBody, true );
		$this->assertArrayHasKey( 'max_completion_tokens', $body );
		$this->assertArrayNotHasKey( 'max_tokens', $body );
	}

	public function testCustomEndpointUsesMaxTokensForQwenCompatibility(): void {
		$svc = new TestableLlmPolishService( 'openai', 'sk-key', 'qwen-max', '',
			'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions',
			json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'out' ] ] ] ] )
		);

		$svc->polish( 'wikitext' );

		$body = json_decode( $svc->lastBody, true );
		$this->assertArrayHasKey( 'max_tokens', $body );
		$this->assertArrayNotHasKey( 'max_completion_tokens', $body );
	}

	public function testQwenViaDashScopeUsesCorrectUrl(): void {
		$dashScopeUrl = 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions';
		$svc = new TestableLlmPolishService( 'openai', 'sk-dashscope', 'qwen-max', '',
			$dashScopeUrl,
			json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'out' ] ] ] ] )
		);

		$svc->polish( 'wikitext' );

		$this->assertSame( $dashScopeUrl, $svc->lastUrl );
		$this->assertContains( 'Authorization: Bearer sk-dashscope', $svc->lastHeaders );
		$body = json_decode( $svc->lastBody, true );
		$this->assertSame( 'qwen-max', $body['model'] );
	}

	public function testQwenViaOllamaNoApiKeyRequired(): void {
		$ollamaUrl = 'http://localhost:11434/v1/chat/completions';
		$svc = new TestableLlmPolishService( 'openai', '', 'qwen2.5:latest', '',
			$ollamaUrl,
			json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'out' ] ] ] ] )
		);

		$svc->polish( 'wikitext' );

		$this->assertSame( $ollamaUrl, $svc->lastUrl );
		$body = json_decode( $svc->lastBody, true );
		$this->assertSame( 'qwen2.5:latest', $body['model'] );
		foreach ( $svc->lastHeaders as $header ) {
			$this->assertStringNotContainsStringIgnoringCase( 'authorization', $header );
		}
	}

	// ------------------------------------------------------------------
	// Helper
	// ------------------------------------------------------------------

	/**
	 * Return a minimal config stub with the given overrides on top of defaults.
	 *
	 * @param array<string, mixed> $overrides
	 * @return object
	 */
	private function makeConfig( array $overrides ): object {
		$defaults = [
			'PandocUltimateConverter_LlmProvider' => null,
			'PandocUltimateConverter_LlmApiKey'   => null,
			'PandocUltimateConverter_LlmModel'    => null,
			'PandocUltimateConverter_LlmPrompt'   => null,
			'PandocUltimateConverter_LlmBaseUrl'  => null,
		];
		$values = array_merge( $defaults, $overrides );
		return new class( $values ) {
			private array $values;
			public function __construct( array $v ) { $this->values = $v; }
			public function get( string $key ): mixed { return $this->values[$key] ?? null; }
		};
	}
}

/**
 * Test-only subclass that overrides httpPost() so no real HTTP calls are made.
 *
 * Public properties record the last call so tests can assert on the request
 * that would have been sent.
 */
class TestableLlmPolishService extends LlmPolishService {

	public string $lastUrl     = '';
	public string $lastBody    = '';
	/** @var list<string> */
	public array  $lastHeaders = [];

	private string $stubbedResponse;

	public function __construct(
		string $provider,
		string $apiKey,
		string $model,
		string $prompt,
		string $baseUrl,
		string $stubbedResponse
	) {
		parent::__construct( $provider, $apiKey, $model, $prompt, $baseUrl );
		$this->stubbedResponse = $stubbedResponse;
	}

	/** @inheritDoc */
	protected function httpPost( string $url, string $body, array $headers ): string {
		$this->lastUrl     = $url;
		$this->lastBody    = $body;
		$this->lastHeaders = $headers;
		return $this->stubbedResponse;
	}
}
