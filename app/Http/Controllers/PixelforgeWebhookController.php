<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $raw = $request->getContent();
        abort_if(strlen($raw) > 65536, 413);
        $secret = config('services.pixelforge.webhook_secret');
        abort_unless(is_string($secret) && $secret !== '', 503);
        $header = (string) $request->header('Pixelforge-Signature');
        abort_unless(preg_match('/\At=(\d{1,12}),v1=([a-f0-9]{64})\z/', $header, $matches) === 1, 400, 'Invalid signature.');
        $timestamp = (int) $matches[1];
        abort_unless(abs(now()->getTimestamp() - $timestamp) <= 300 && hash_equals(hash_hmac('sha256', $matches[1].'.'.$raw, $secret), $matches[2]), 400, 'Invalid signature.');
        $event = json_decode($raw, true);
        abort_unless(is_array($event), 400, 'Invalid event.');
        $validator = Validator::make($event, [
            'id' => ['required', 'string', 'starts_with:evt_', 'max:255'],
            'object' => ['required', 'in:event'],
            'type' => ['required', 'in:ping,generation.processing,generation.succeeded,generation.failed'],
            'data.object' => ['required_unless:type,ping', 'array'],
        ]);
        abort_if($validator->fails(), 400, 'Invalid event.');
        $stored = WebhookEvent::query()->firstOrCreate(['id' => $event['id']], ['type' => $event['type'], 'payload' => $event]);
        if ($stored->processed_at === null && $stored->failed_at === null && ! $stored->next_attempt_at?->isFuture()) {
            ProcessWebhookEvent::dispatch($stored->id);
        }

        return response()->noContent();
    }
}
