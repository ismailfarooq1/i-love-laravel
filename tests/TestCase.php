<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function fakeChatGpt(array $result): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['models' => [['slug' => 'test-model', 'display_name' => 'Test model', 'visibility' => 'list']]]),
            'https://api.openai.com/v1/responses' => Http::response('data: '.json_encode(['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($result)]]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 20]]])."\n\n", 200, ['Content-Type' => 'text/event-stream']),
        ]);
    }
}
