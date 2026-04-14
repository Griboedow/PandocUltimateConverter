<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use MediaWiki\Extension\PandocUltimateConverter\VideoToWikitextService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VideoToWikitextService.
 *
 * HTTP calls are intercepted by TestableVideoToWikitextService, which overrides
 * both httpPost() and transcribeWithWhisper() so no real network requests are made.
 *
 * @covers \MediaWiki\Extension\PandocUltimateConverter\VideoToWikitextService
 */
class VideoToWikitextServiceTest extends TestCase {

	// ------------------------------------------------------------------
	// newFromConfig
	// ------------------------------------------------------------------

	public function testNewFromConfigReturnsNullWhenProviderMissing(): void {
		$config = $this->makeConfig( [ 'PandocUltimateConverter_LlmProvider' => '' ] );
		$this->assertNull( VideoToWikitextService::newFromConfig( $config ) );
	}

	public function testNewFromConfigReturnsNullWhenApiKeyMissing(): void {
		$config = $this->makeConfig( [
			'PandocUltimateConverter_LlmProvider' => 'openai',
			'PandocUltimateConverter_LlmApiKey'   => '',
		] );
		$this->assertNull( VideoToWikitextService::newFromConfig( $config ) );
	}

	public function testNewFromConfigReturnsNullWhenBothMissing(): void {
		$config = $this->makeConfig( [] );
		$this->assertNull( VideoToWikitextService::newFromConfig( $config ) );
	}

	public function testNewFromConfigReturnsServiceWhenOpenAiConfigured(): void {
		$config = $this->makeConfig( [
			'PandocUltimateConverter_LlmProvider' => 'openai',
			'PandocUltimateConverter_LlmApiKey'   => 'sk-test',
		] );
		$this->assertInstanceOf( VideoToWikitextService::class, VideoToWikitextService::newFromConfig( $config ) );
	}

	public function testNewFromConfigReturnsServiceWhenClaudeConfigured(): void {
		$config = $this->makeConfig( [
			'PandocUltimateConverter_LlmProvider' => 'claude',
			'PandocUltimateConverter_LlmApiKey'   => 'sk-ant-test',
		] );
		$this->assertInstanceOf( VideoToWikitextService::class, VideoToWikitextService::newFromConfig( $config ) );
	}

	public function testNewFromConfigReturnsServiceWhenGeminiConfigured(): void {
		$config = $this->makeConfig( [
			'PandocUltimateConverter_LlmProvider' => 'gemini',
			'PandocUltimateConverter_LlmApiKey'   => 'AI-test-key',
		] );
		$this->assertInstanceOf( VideoToWikitextService::class, VideoToWikitextService::newFromConfig( $config ) );
	}

	// ------------------------------------------------------------------
	// generateWikitext — no frames throws
	// ------------------------------------------------------------------

	public function testGenerateWikitextThrowsWhenNoFramesProvided(): void {
		$svc = new TestableVideoToWikitextService( 'openai', 'key', '', '', '', '' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/No frames provided/' );
		$svc->generateWikitext( 'MyVideo', [] );
	}

	// ------------------------------------------------------------------
	// Unknown provider
	// ------------------------------------------------------------------

	public function testGenerateWikitextThrowsForUnknownProvider(): void {
		$svc = new TestableVideoToWikitextService( 'unknownprovider', 'key', '', '', '', '' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Unknown LLM provider/' );
		$svc->generateWikitext( 'MyVideo', [ '/tmp/frame-001.jpg' ] );
	}

	// ------------------------------------------------------------------
	// OpenAI — request shape and response parsing
	// ------------------------------------------------------------------

	public function testOpenAiRequestSendsToOpenAiUrl(): void {
		$svc = $this->makeOpenAiSvc( json_encode( [
			'choices' => [ [ 'message' => [ 'content' => 'wikitext result' ] ] ],
		] ) );

		$svc->generateWikitext( 'MyVideo', [ '/tmp/frame-001.jpg' ] );

		$this->assertStringContainsString( 'api.openai.com', $svc->lastPostUrl );
	}

	public function testOpenAiRequestBodyContainsVideoTitle(): void {
		$svc = $this->makeOpenAiSvc( json_encode( [
			'choices' => [ [ 'message' => [ 'content' => 'result' ] ] ],
		] ) );

		$svc->generateWikitext( 'TestVideoTitle', [ '/tmp/frame-001.jpg' ] );

		$body = json_decode( $svc->lastPostBody, true );
		$userContent = $body['messages'][1]['content'];
		// The first element is the text part with the user message
		$this->assertSame( 'user', $body['messages'][1]['role'] );
		$hasTitle = false;
		foreach ( $userContent as $part ) {
			if ( isset( $part['text'] ) && strpos( $part['text'], 'TestVideoTitle' ) !== false ) {
				$hasTitle = true;
			}
		}
		$this->assertTrue( $hasTitle, 'User message must contain the video title' );
	}

	public function testOpenAiRequestBodyContainsSystemPrompt(): void {
		$svc = new TestableVideoToWikitextService( 'openai', 'key', '', 'Custom video prompt', '', json_encode( [
			'choices' => [ [ 'message' => [ 'content' => 'result' ] ] ],
		] ) );

		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ] );

		$body = json_decode( $svc->lastPostBody, true );
		$this->assertSame( 'system', $body['messages'][0]['role'] );
		$this->assertSame( 'Custom video prompt', $body['messages'][0]['content'] );
	}

	public function testOpenAiResponseContentIsReturned(): void {
		$svc = $this->makeOpenAiSvc( json_encode( [
			'choices' => [ [ 'message' => [ 'content' => '== My Article ==\nContent here.' ] ] ],
		] ) );

		$result = $svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ] );

		$this->assertSame( '== My Article ==\nContent here.', $result );
	}

	public function testOpenAiErrorResponseThrows(): void {
		$svc = $this->makeOpenAiSvc( json_encode( [
			'error' => [ 'message' => 'Rate limit exceeded' ],
		] ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Rate limit exceeded/' );
		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ] );
	}

	public function testOpenAiMissingContentFieldThrows(): void {
		$svc = $this->makeOpenAiSvc( json_encode( [
			'choices' => [ [ 'message' => [] ] ],
		] ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/missing expected content field/' );
		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ] );
	}

	public function testOpenAiInvalidJsonResponseThrows(): void {
		$svc = $this->makeOpenAiSvc( 'not-json{{{' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/invalid JSON/' );
		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ] );
	}

	public function testOpenAiUsesDefaultVisionModel(): void {
		$svc = $this->makeOpenAiSvc( json_encode( [
			'choices' => [ [ 'message' => [ 'content' => 'out' ] ] ],
		] ) );

		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ] );

		$body = json_decode( $svc->lastPostBody, true );
		$this->assertSame( 'gpt-4o', $body['model'] );
	}

	public function testOpenAiUsesCustomModelWhenSpecified(): void {
		$svc = new TestableVideoToWikitextService( 'openai', 'key', 'gpt-4-turbo', '', '', json_encode( [
			'choices' => [ [ 'message' => [ 'content' => 'out' ] ] ],
		] ) );

		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ] );

		$body = json_decode( $svc->lastPostBody, true );
		$this->assertSame( 'gpt-4-turbo', $body['model'] );
	}

	// ------------------------------------------------------------------
	// OpenAI + audio — transcript included in message
	// ------------------------------------------------------------------

	public function testOpenAiIncludesTranscriptInUserMessageWhenProvided(): void {
		$svc = new TestableVideoToWikitextService(
			'openai', 'key', '', '', 'Hello from the audio',
			json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'ok' ] ] ] ] )
		);

		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ], '/tmp/audio.mp3' );

		$body = json_decode( $svc->lastPostBody, true );
		$userContent = $body['messages'][1]['content'];
		$hasTranscript = false;
		foreach ( $userContent as $part ) {
			if ( isset( $part['text'] ) && strpos( $part['text'], 'Hello from the audio' ) !== false ) {
				$hasTranscript = true;
			}
		}
		$this->assertTrue( $hasTranscript, 'Transcript must appear in the user message' );
	}

	public function testOpenAiSkipsTranscriptWhenAudioPathIsNull(): void {
		$svc = $this->makeOpenAiSvc( json_encode( [
			'choices' => [ [ 'message' => [ 'content' => 'ok' ] ] ],
		] ) );

		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ], null );

		$body = json_decode( $svc->lastPostBody, true );
		$userContent = $body['messages'][1]['content'];
		$hasTranscript = false;
		foreach ( $userContent as $part ) {
			if ( isset( $part['text'] ) && strpos( $part['text'], 'Audio transcript' ) !== false ) {
				$hasTranscript = true;
			}
		}
		$this->assertFalse( $hasTranscript, 'No transcript section should appear when audioPath is null' );
	}

	// ------------------------------------------------------------------
	// Claude — request shape and response parsing
	// ------------------------------------------------------------------

	public function testClaudeRequestSendsToAnthropicUrl(): void {
		$svc = $this->makeClaudeSvc( json_encode( [
			'content' => [ [ 'text' => 'result' ] ],
		] ) );

		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ] );

		$this->assertStringContainsString( 'anthropic.com', $svc->lastPostUrl );
	}

	public function testClaudeRequestHeadersContainApiKeyAndVersion(): void {
		$svc = new TestableVideoToWikitextService( 'claude', 'sk-ant-mykey', '', '', '', json_encode( [
			'content' => [ [ 'text' => 'polished' ] ],
		] ) );

		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ] );

		$this->assertContains( 'x-api-key: sk-ant-mykey', $svc->lastPostHeaders );
		$hasVersion = false;
		foreach ( $svc->lastPostHeaders as $h ) {
			if ( str_starts_with( $h, 'anthropic-version:' ) ) {
				$hasVersion = true;
			}
		}
		$this->assertTrue( $hasVersion, 'anthropic-version header must be present' );
	}

	public function testClaudeResponseContentIsReturned(): void {
		$svc = $this->makeClaudeSvc( json_encode( [
			'content' => [ [ 'text' => 'claude wikitext output' ] ],
		] ) );

		$result = $svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ] );

		$this->assertSame( 'claude wikitext output', $result );
	}

	public function testClaudeErrorResponseThrows(): void {
		$svc = $this->makeClaudeSvc( json_encode( [
			'error' => [ 'message' => 'Overloaded' ],
		] ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Overloaded/' );
		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ] );
	}

	public function testClaudeUsesDefaultVisionModel(): void {
		$svc = $this->makeClaudeSvc( json_encode( [
			'content' => [ [ 'text' => 'out' ] ],
		] ) );

		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ] );

		$body = json_decode( $svc->lastPostBody, true );
		$this->assertStringContainsString( 'claude', $body['model'] );
	}

	// ------------------------------------------------------------------
	// Claude + audio — uses TranscriptionApiKey
	// ------------------------------------------------------------------

	public function testClaudeWithTranscriptionKeyIncludesTranscript(): void {
		$svc = new TestableVideoToWikitextService(
			'claude', 'sk-ant-key', '', '', 'Narration text here',
			json_encode( [ 'content' => [ [ 'text' => 'ok' ] ] ] ),
			'sk-openai-transcription-key'
		);

		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ], '/tmp/audio.mp3' );

		// The stubbed transcribeWithWhisper returns the $stubbedTranscript value.
		// Verify it was called with the transcription key.
		$this->assertSame( 'sk-openai-transcription-key', $svc->lastWhisperApiKey );
	}

	public function testClaudeWithoutTranscriptionKeySkipsAudio(): void {
		$svc = new TestableVideoToWikitextService(
			'claude', 'sk-ant-key', '', '', null,
			json_encode( [ 'content' => [ [ 'text' => 'ok' ] ] ] ),
			'' // no TranscriptionApiKey
		);

		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ], '/tmp/audio.mp3' );

		// transcribeWithWhisper should NOT have been called
		$this->assertNull( $svc->lastWhisperApiKey,
			'Whisper must not be called when TranscriptionApiKey is empty' );
	}

	// ------------------------------------------------------------------
	// Gemini — request shape and response parsing
	// ------------------------------------------------------------------

	public function testGeminiRequestUrlContainsApiKeyAndModel(): void {
		$svc = $this->makeGeminiSvc( json_encode( [
			'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => 'result' ] ] ] ] ],
		] ) );

		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ] );

		$this->assertStringContainsString( 'generativelanguage.googleapis.com', $svc->lastPostUrl );
		$this->assertStringContainsString( 'key=', $svc->lastPostUrl );
		$this->assertStringContainsString( 'gemini', $svc->lastPostUrl );
	}

	public function testGeminiResponseContentIsReturned(): void {
		$svc = $this->makeGeminiSvc( json_encode( [
			'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => 'gemini wikitext' ] ] ] ] ],
		] ) );

		$result = $svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ] );

		$this->assertSame( 'gemini wikitext', $result );
	}

	public function testGeminiErrorResponseThrows(): void {
		$svc = $this->makeGeminiSvc( json_encode( [
			'error' => [ 'message' => 'RESOURCE_EXHAUSTED' ],
		] ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/RESOURCE_EXHAUSTED/' );
		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ] );
	}

	public function testGeminiMissingCandidatesThrows(): void {
		$svc = $this->makeGeminiSvc( json_encode( [ 'candidates' => [] ] ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/missing expected content field/' );
		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ] );
	}

	public function testGeminiUsesDefaultModel(): void {
		$svc = $this->makeGeminiSvc( json_encode( [
			'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => 'out' ] ] ] ] ],
		] ) );

		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ] );

		$this->assertStringContainsString( 'gemini-1.5-flash', $svc->lastPostUrl );
	}

	// ------------------------------------------------------------------
	// Gemini + audio — audio is passed inline, Whisper NOT called
	// ------------------------------------------------------------------

	public function testGeminiDoesNotCallWhisperForAudio(): void {
		$svc = new TestableVideoToWikitextService(
			'gemini', 'AI-key', '', '', null,
			json_encode( [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => 'ok' ] ] ] ] ] ] )
		);

		$svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ], '/tmp/audio.mp3' );

		$this->assertNull( $svc->lastWhisperApiKey,
			'Gemini must not call Whisper — it handles audio inline' );
	}

	// ------------------------------------------------------------------
	// Provider name normalisation
	// ------------------------------------------------------------------

	public function testProviderNameIsCaseInsensitive(): void {
		$svc = new TestableVideoToWikitextService( 'OpenAI', 'key', '', '', '', json_encode( [
			'choices' => [ [ 'message' => [ 'content' => 'out' ] ] ],
		] ) );

		$result = $svc->generateWikitext( 'Video', [ '/tmp/frame-001.jpg' ] );

		$this->assertSame( 'out', $result );
		$this->assertStringContainsString( 'openai.com', $svc->lastPostUrl );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function makeOpenAiSvc( string $stubbedResponse ): TestableVideoToWikitextService {
		return new TestableVideoToWikitextService( 'openai', 'sk-test', '', '', '', $stubbedResponse );
	}

	private function makeClaudeSvc( string $stubbedResponse ): TestableVideoToWikitextService {
		return new TestableVideoToWikitextService( 'claude', 'sk-ant-test', '', '', '', $stubbedResponse );
	}

	private function makeGeminiSvc( string $stubbedResponse ): TestableVideoToWikitextService {
		return new TestableVideoToWikitextService( 'gemini', 'AI-test', '', '', '', $stubbedResponse );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return object
	 */
	private function makeConfig( array $overrides ): object {
		$defaults = [
			'PandocUltimateConverter_LlmProvider'         => null,
			'PandocUltimateConverter_LlmApiKey'           => null,
			'PandocUltimateConverter_LlmVideoModel'       => null,
			'PandocUltimateConverter_LlmVideoPrompt'      => null,
			'PandocUltimateConverter_TranscriptionApiKey' => null,
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
 * Test-only subclass that stubs out all external I/O.
 *
 * - httpPost() records the last call and returns a configured stub response.
 * - transcribeWithWhisper() records the API key it was called with and returns
 *   a configured transcript string (or null if none was set).
 */
class TestableVideoToWikitextService extends VideoToWikitextService {

	public string $lastPostUrl     = '';
	public string $lastPostBody    = '';
	/** @var list<string> */
	public array  $lastPostHeaders = [];
	/** @var string|null */
	public $lastWhisperApiKey = null;

	private string $stubbedResponse;
	/** @var string|null */
	private $stubbedTranscript;

	/**
	 * @param string|null $stubbedTranscript  Transcript returned by the stubbed Whisper call.
	 *                                         Pass null to simulate no transcription.
	 */
	public function __construct(
		string $provider,
		string $apiKey,
		string $model,
		string $prompt,
		?string $stubbedTranscript,
		string $stubbedResponse,
		string $transcriptionApiKey = ''
	) {
		parent::__construct( $provider, $apiKey, $model, $prompt, $transcriptionApiKey );
		$this->stubbedResponse  = $stubbedResponse;
		$this->stubbedTranscript = $stubbedTranscript;
	}

	/** @inheritDoc */
	protected function httpPost( string $url, $body, array $headers ): string {
		$this->lastPostUrl     = $url;
		$this->lastPostBody    = is_string( $body ) ? $body : json_encode( $body );
		$this->lastPostHeaders = $headers;
		return $this->stubbedResponse;
	}

	/** @inheritDoc */
	public function transcribeWithWhisper( string $audioPath, string $apiKey ): ?string {
		$this->lastWhisperApiKey = $apiKey;
		return $this->stubbedTranscript;
	}
}
