<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Services\ChatGptInference;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChatGptInferenceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_plan_requests_use_oauth_streaming_schema_and_only_supported_parameters(): void
    {
        $connection = AiConnection::factory()->create();
        $expected = ['summary' => 'Nothing supported', 'nodes' => [], 'questions' => []];
        $this->fakeChatGpt($expected);
        $result = app(ChatGptInference::class)->analyze($connection, 'Read only selected sources.', '{"documents":[]}');
        $this->assertSame($expected, $result['result']);
        $this->assertSame(10, $result['usage']['input_tokens']);
        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/responses')) {
                return false;
            }
            $this->assertSame(['model', 'instructions', 'input', 'store', 'stream', 'text'], array_keys($request->data()));
            $this->assertFalse($request['store']);
            $this->assertTrue($request['stream']);
            $this->assertTrue($request['text']['format']['strict']);
            $this->assertSame('json_schema', $request['text']['format']['type']);
            $this->assertSame('user', $request['input'][0]['role']);

            return $request->hasHeader('Authorization', 'Bearer test-access-token');
        });
        Http::assertSentCount(2);
    }

    #[DataProvider('failedStreams')]
    public function test_partial_failed_and_malformed_streams_never_return_a_plan(string $stream, int $status, string $message): void
    {
        $connection = AiConnection::factory()->create();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['models' => [['slug' => 'test-model', 'visibility' => 'list']]]),
            'https://api.openai.com/v1/responses' => Http::response($stream, $status),
        ]);
        try {
            app(ChatGptInference::class)->analyze($connection, 'Instructions', '{}');
            $this->fail('An incomplete response must not produce a plan.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString($message, $error->errors()['chatgpt'][0]);
        }
        Http::assertSentCount(2);
    }

    public static function failedStreams(): array
    {
        return [
            ["data: {\"type\":\"response.output_text.delta\",\"delta\":\"{}\"}\n\n", 200, 'before completion'],
            ["data: {\"type\":\"response.failed\",\"response\":{\"error\":{\"code\":\"subscription_sharing_usage_limit_exceeded\"}}}\n\n", 200, 'usage limit'],
            ["data: {\"type\":\"response.incomplete\"}\n\n", 200, 'did not complete'],
            ["data: not-json\n\n", 200, 'could not finish'],
            ['{}', 429, 'usage limit'],
            ["data: {\"type\":\"response.completed\",\"response\":{\"status\":\"failed\"}}\n\n", 200, 'did not complete'],
        ];
    }

    public function test_unavailable_model_and_disconnected_account_never_send_inference(): void
    {
        $connection = AiConnection::factory()->create();
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/models' => Http::response(['models' => []])]);
        try {
            app(ChatGptInference::class)->analyze($connection, 'Instructions', '{}');
            $this->fail('Unavailable models cannot run.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('no longer available', $error->errors()['chatgpt'][0]);
        }
        Http::assertSentCount(1);
        $connection->update(['credentials' => null]);
        try {
            app(ChatGptInference::class)->analyze($connection, 'Instructions', '{}');
            $this->fail('Disconnected accounts cannot run.');
        } catch (ValidationException) {
            Http::assertSentCount(1);
        }
    }
}
