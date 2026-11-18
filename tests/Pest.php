<?php

use App\Models\Generation;
use App\Models\User;
use App\Services\Credits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/** @return array<string, mixed> */
function pixelforgeSnapshot(Generation $generation, string $status = 'succeeded'): array
{
    $pricing = json_decode(file_get_contents(__DIR__.'/Fixtures/pricing.json'), true);
    $usage = [
        'input_text_tokens' => intdiv(mb_strlen($generation->prompt) + 3, 4),
        'input_image_tokens' => count($generation->reference_images) * $pricing['tokens']['per_reference_image'],
        'output_tokens' => $pricing['tokens']['output'][$generation->quality][$generation->size],
        'reasoning_tokens' => $pricing['tokens']['reasoning'][$generation->quality]['min'],
    ];
    $usage['total_tokens'] = array_sum($usage);

    return [
        ...$generation->providerParameters(),
        'id' => $generation->provider_id ?? 'gen_'.$generation->id,
        'object' => 'generation', 'status' => $status,
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'usage' => $status === 'succeeded' ? $usage : null,
        'image_url' => $status === 'succeeded' ? 'https://pixelforge.promptedit.dev/images/test.png?signature=synthetic' : null,
        'image_url_expires_at' => $status === 'succeeded' ? now()->addDay()->toIso8601String() : null,
        'completed_at' => in_array($status, ['succeeded', 'failed']) ? now()->toIso8601String() : null,
        'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'Failed.'] : null,
    ];
}

function sendPixelforgeEvent(array $event, ?string $signature = null): TestResponse
{
    $body = json_encode($event, JSON_UNESCAPED_SLASHES);
    $timestamp = now()->timestamp;
    $signature ??= 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test');

    return test()->call('POST', route('webhooks.pixelforge'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => $signature], $body);
}

function generationEvent(Generation $generation, string $status, string $id = 'evt_test'): array
{
    return ['id' => $id, 'object' => 'event', 'type' => 'generation.'.$status, 'data' => ['object' => pixelforgeSnapshot($generation, $status)]];
}

function generationSettings(): array
{
    return ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []];
}

function fakePricing(): void
{
    Http::fake(['*/v1/pricing' => Http::response(json_decode(file_get_contents(__DIR__.'/Fixtures/pricing.json'), true))]);
}

function assertBlockedPostgresWorkers(string $name, int $expected): void
{
    $deadline = microtime(true) + 10;
    do {
        DB::select('select pg_stat_clear_snapshot()');
        $blocked = DB::selectOne("select count(*) as count from pg_stat_activity where application_name = ? and wait_event_type = 'Lock'", [$name])->count;
        if ((int) $blocked === $expected) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    expect((int) $blocked)->toBe($expected, 'All workers must overlap and wait on real PostgreSQL locks.');
}

function reservedGeneration(User $user, string $amount = '3'): Generation
{
    return DB::transaction(function () use ($user, $amount) {
        User::query()->lockForUpdate()->findOrFail($user->id);
        $generation = Generation::factory()->for($user)->create(['estimated_cost' => bcdiv($amount, '1.25', 18), 'estimated_charge' => $amount]);
        app(Credits::class)->reserve($user, $generation);

        return $generation;
    });
}
