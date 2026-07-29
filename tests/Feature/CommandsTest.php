<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LnkFlow\Laravel\Contracts\ConsentResolver;
use LnkFlow\Laravel\Contracts\LinkableContent;
use LnkFlow\Laravel\Data\ConsentState;
use LnkFlow\Laravel\Data\LinkDefinition;
use LnkFlow\Laravel\Jobs\SyncLinkableContentJob;
use LnkFlow\Laravel\Tests\Fixture;

final class CommandContent extends Model
{
    protected $table = 'test_contents';

    protected $guarded = [];
}

final class CommandContentAdapter implements LinkableContent
{
    public function lnkFlowSourceKey(Model $model): string
    {
        return (string) $model->getKey();
    }

    public function lnkFlowLinks(Model $model): iterable
    {
        yield new LinkDefinition(
            placement: 'primary',
            campaignKey: 'commands',
            campaignName: 'Commands',
            destinationUrl: (string) $model->getAttribute('destination_url'),
            name: (string) $model->getAttribute('title'),
            slug: 'commands',
        );
    }
}

it('passes the doctor when the connection is configured and reachable', function (): void {
    Http::fake([Fixture::url('me/200').'*' => Fixture::response('me/200')]);

    $this->artisan('lnkflow:doctor')
        ->expectsOutputToContain('API URL is valid')
        ->expectsOutputToContain('An API token is configured')
        ->expectsOutputToContain('A team is configured')
        ->expectsOutputToContain('API connectivity succeeded')
        ->assertSuccessful();
});

it('fails the doctor without touching the network when no token is configured', function (): void {
    config()->set('lnkflow.connections.default.api_token', null);
    config()->set('lnkflow.connections.default.link_token', null);
    config()->set('lnkflow.connections.default.conversion_token', null);
    Http::fake();

    $this->artisan('lnkflow:doctor')->assertFailed();

    Http::assertNothingSent();
});

it('fails the doctor when the API rejects the token', function (): void {
    Http::fake(['*' => Fixture::response('me/401')]);

    $this->artisan('lnkflow:doctor')
        ->expectsOutputToContain('API connectivity failed: Unauthenticated.')
        ->assertFailed();
});

it('fails the doctor when a production URL is not TLS', function (): void {
    config()->set('lnkflow.connections.default.url', 'http://api.lnkflow.io/api/v1');
    Http::fake();

    $this->artisan('lnkflow:doctor')
        ->expectsOutputToContain('Production API URL uses TLS')
        ->assertFailed();

    Http::assertNothingSent();
});

it('fails the doctor when journeys are on but no host consent resolver is bound', function (): void {
    config()->set('lnkflow.features.journeys', true);
    Http::fake();

    $this->artisan('lnkflow:doctor')
        ->expectsOutputToContain('A host ConsentResolver is bound')
        ->assertFailed();

    Http::assertNothingSent();
});

it('passes the doctor once a host consent resolver is bound', function (): void {
    config()->set('lnkflow.features.journeys', true);
    app()->bind(ConsentResolver::class, fn (): ConsentResolver => new class implements ConsentResolver
    {
        public function storage(Request $request): ConsentState
        {
            return ConsentState::Granted;
        }

        public function adUserData(Request $request): ConsentState
        {
            return ConsentState::Granted;
        }

        public function adPersonalization(Request $request): ConsentState
        {
            return ConsentState::Denied;
        }
    });
    Http::fake([Fixture::url('me/200').'*' => Fixture::response('me/200')]);

    $this->artisan('lnkflow:doctor')->assertSuccessful();
});

it('fails the doctor when content sync is on but its tables are missing', function (): void {
    config()->set('lnkflow.features.content', true);
    Schema::drop('lnkflow_link_mappings');
    Http::fake();

    $this->artisan('lnkflow:doctor')
        ->expectsOutputToContain('Content mapping migrations are applied')
        ->assertFailed();

    Http::assertNothingSent();
});

it('queues a sync for every configured content record', function (): void {
    config()->set('lnkflow.content.models', [CommandContent::class => CommandContentAdapter::class]);
    CommandContent::query()->create(['title' => 'One', 'destination_url' => 'https://example.com/1']);
    CommandContent::query()->create(['title' => 'Two', 'destination_url' => 'https://example.com/2']);
    Bus::fake();
    Http::fake();

    $this->artisan('lnkflow:sync')->assertSuccessful();

    Bus::assertDispatchedTimes(SyncLinkableContentJob::class, 2);
    Http::assertNothingSent();
});

it('queues a sync for a single record when one is named', function (): void {
    config()->set('lnkflow.content.models', [CommandContent::class => CommandContentAdapter::class]);
    $content = CommandContent::query()->create(['title' => 'One', 'destination_url' => 'https://example.com/1']);
    CommandContent::query()->create(['title' => 'Two', 'destination_url' => 'https://example.com/2']);
    Bus::fake();

    $this->artisan('lnkflow:sync', ['--model' => CommandContent::class, '--id' => (string) $content->id])
        ->assertSuccessful();

    Bus::assertDispatchedTimes(SyncLinkableContentJob::class, 1);
});

it('performs no remote write in a dry run', function (): void {
    config()->set('lnkflow.content.models', [CommandContent::class => CommandContentAdapter::class]);
    $content = CommandContent::query()->create(['title' => 'One', 'destination_url' => 'https://example.com/1']);
    Bus::fake();
    Http::fake([Fixture::url('links-preview/200').'*' => Fixture::response('links-preview/200')]);

    $this->artisan('lnkflow:sync', ['--dry-run' => true])
        ->expectsOutputToContain('Would synchronize '.CommandContent::class.':'.$content->id.' with 1 link(s).')
        ->assertSuccessful();

    // The only call a dry run may make is the side-effect-free preview: no
    // create, no update, no queued job.
    Bus::assertNothingDispatched();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://app.lnkflow.test/api/v1/links/preview');
});

it('fails the sync when no matching content model is configured', function (): void {
    config()->set('lnkflow.content.models', []);
    Http::fake();

    $this->artisan('lnkflow:sync')
        ->expectsOutputToContain('No matching content model is configured.')
        ->assertFailed();

    Http::assertNothingSent();
});

it('fails the sync when the named model is not one of the configured ones', function (): void {
    config()->set('lnkflow.content.models', [CommandContent::class => CommandContentAdapter::class]);
    Http::fake();

    $this->artisan('lnkflow:sync', ['--model' => 'App\\Models\\Unconfigured'])->assertFailed();

    Http::assertNothingSent();
});

it('refuses to verify without an explicit mutating flag', function (): void {
    Http::fake();

    $this->artisan('lnkflow:verify')
        ->expectsOutputToContain('Pass --test-conversion to select the explicit mutation.')
        ->assertFailed();

    Http::assertNothingSent();
});

it('aborts the verification when the operator declines the confirmation', function (): void {
    Http::fake();

    $this->artisan('lnkflow:verify', ['--test-conversion' => true])
        ->expectsConfirmation('Create a clearly labeled test conversion in LnkFlow?', 'no')
        ->assertFailed();

    Http::assertNothingSent();
});

it('creates a labeled test conversion and reads it back', function (): void {
    Http::fake([
        'app.lnkflow.test/api/v1/track/lead' => Fixture::response('track-lead/201'),
        'app.lnkflow.test/api/v1/track/events*' => Http::response([
            'data' => [Fixture::data('track-lead/201')],
        ]),
    ]);

    $this->artisan('lnkflow:verify', ['--test-conversion' => true, '--force' => true])
        ->expectsOutputToContain('Test conversion 1 was created and read back.')
        ->expectsOutputToContain('excluded from production statistics')
        ->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://app.lnkflow.test/api/v1/track/lead'
        && $request['test'] === true
        && $request['event_name'] === 'lnkflow_verification');
});

it('fails the verification when the test conversion cannot be read back', function (): void {
    Http::fake([
        'app.lnkflow.test/api/v1/track/lead' => Fixture::response('track-lead/201'),
        'app.lnkflow.test/api/v1/track/events*' => Http::response(['data' => []]),
    ]);

    $this->artisan('lnkflow:verify', ['--test-conversion' => true, '--force' => true])
        ->expectsOutputToContain('created but not found')
        ->assertFailed();
});
