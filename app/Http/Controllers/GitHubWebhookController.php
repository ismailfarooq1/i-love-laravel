<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessGitHubDelivery;
use App\Models\GitHubDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('services.github.webhook_secret');
        abort_unless($secret && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), (string) $request->header('X-Hub-Signature-256')), 401, 'Invalid GitHub webhook signature.');
        $request->validate(['action' => ['sometimes', 'string', 'max:100']]);
        $id = (string) $request->header('X-GitHub-Delivery');
        abort_unless($id !== '' && strlen($id) <= 100, 400);
        $event = (string) $request->header('X-GitHub-Event');
        if (! in_array($event, ['issues', 'installation', 'installation_repositories'], true)) {
            return response()->json(['accepted' => true]);
        }
        $delivery = GitHubDelivery::firstOrCreate(['delivery_id' => $id], ['event' => $event, 'payload' => $request->only(['action', 'repository', 'issue', 'installation', 'sender'])]);
        if ($delivery->wasRecentlyCreated || $delivery->status === 'failed') {
            ProcessGitHubDelivery::dispatch($delivery->id);
        }

        return response()->json(['accepted' => true], 202);
    }
}
