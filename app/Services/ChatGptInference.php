<?php

namespace App\Services;

use App\Ai\Agents\DocumentPlanner;
use App\Models\AiConnection;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\ObjectSchema;
use Throwable;

class ChatGptInference
{
    public function __construct(private ChatGptAccess $access) {}

    /** @return array{result: array<string, mixed>, usage: array<string, mixed>} */
    public function analyze(AiConnection $connection, string $instructions, string $input): array
    {
        try {
            if (! collect($this->access->models($connection))->contains('id', $connection->model)) {
                $this->fail('This model is no longer available to your ChatGPT account. Refresh models and select one.');
            }
            $token = $this->access->token($connection);
            $current = $connection->fresh();
            if (! $current || $current->revision !== $connection->revision || ! $current->owner || ! $current->usableBy($current->owner)) {
                $this->fail('Your ChatGPT connection or team access changed. Start again.');
            }
            $schema = (new ObjectSchema(DocumentPlanner::make()->schema(new JsonSchemaTypeFactory), strict: true))->toSchema();
            unset($schema['name']);
            $response = Http::withToken($token)->accept('text/event-stream')->connectTimeout(5)->timeout(40)
                ->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 40])
                ->post(ChatGptAccess::RESOURCE.'/responses', [
                    'model' => $connection->model, 'instructions' => $instructions,
                    'input' => [['role' => 'user', 'content' => $input]], 'store' => false, 'stream' => true,
                    'text' => ['format' => ['type' => 'json_schema', 'name' => 'project_plan', 'strict' => true, 'schema' => $schema]],
                ]);
            if (! $response->successful()) {
                $this->fail($this->errorMessage($response->status() === 429 ? 'subscription_sharing_usage_limit_exceeded' : 'request_failed'));
            }
            $stream = $response->toPsrResponse()->getBody();
            $buffer = '';
            $received = 0;
            $deadline = microtime(true) + 40;
            try {
                while (! $stream->eof()) {
                    if (microtime(true) > $deadline || $received > 4_000_000) {
                        $this->fail('ChatGPT analysis exceeded its response limit. Try fewer documents.');
                    }
                    $chunk = $stream->read(8192);
                    $received += strlen($chunk);
                    $buffer .= $chunk;
                    while (preg_match('/\r?\n\r?\n/', $buffer, $separator, PREG_OFFSET_CAPTURE)) {
                        $position = $separator[0][1];
                        $frame = substr($buffer, 0, $position);
                        $buffer = substr($buffer, $position + strlen($separator[0][0]));
                        $lines = preg_split('/\r?\n/', $frame);
                        $data = implode("\n", array_map(fn (string $line): string => ltrim(substr($line, 5), ' '), array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'data:')))));
                        if ($data === '' || $data === '[DONE]') {
                            continue;
                        }
                        $event = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
                        if (in_array($event['type'] ?? '', ['response.failed', 'response.incomplete', 'error'], true)) {
                            $this->fail($this->errorMessage($event['response']['error']['code'] ?? $event['error']['code'] ?? $event['code'] ?? 'incomplete'));
                        }
                        if (($event['type'] ?? '') === 'response.completed') {
                            $completed = $event['response'] ?? [];
                            if (($completed['status'] ?? '') !== 'completed' || ! empty($completed['error'])) {
                                $this->fail('ChatGPT did not complete the analysis. No drafts were created.');
                            }
                            $text = '';
                            foreach ($completed['output'] ?? [] as $item) {
                                foreach ($item['content'] ?? [] as $content) {
                                    if (($content['type'] ?? '') === 'output_text') {
                                        $text .= $content['text'];
                                    }
                                }
                            }
                            $result = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
                            if (! is_array($result)) {
                                $this->fail('ChatGPT did not return a usable plan.');
                            }

                            return ['result' => $result, 'usage' => $completed['usage'] ?? []];
                        }
                    }
                }
            } finally {
                $stream->close();
            }
            $this->fail('The ChatGPT stream ended before completion. No drafts were created. Try again when ready.');
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable) {
            $this->fail('ChatGPT analysis could not finish. Check your connection, reconnect your account, or try again later. No API-key fallback was used.');
        }
    }

    private function errorMessage(string $code): string
    {
        return match ($code) {
            'subscription_sharing_usage_limit_exceeded' => 'Your ChatGPT plan or app usage limit was reached. Open Manage usage in ChatGPT. No API-key fallback was used.',
            'subscription_sharing_user_not_eligible' => 'This ChatGPT account or workspace is not eligible for plan usage.',
            'subscription_sharing_usage_unavailable' => 'ChatGPT usage availability could not be checked. Try again later.',
            default => 'ChatGPT did not complete the request. Check your account access and try again. No drafts were created.',
        };
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['chatgpt' => $message]);
    }
}
