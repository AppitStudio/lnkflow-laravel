<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LnkFlow\Laravel\LnkFlowServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * The suite is offline, always.
     *
     * This is the transport guard the integration release gate requires: any
     * request that is not explicitly faked raises StrayRequestException and
     * fails the test, so a new code path can never quietly start talking to a
     * real LnkFlow host from CI or from a developer's machine.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        // Deterministic and table-free: unique-job locks and the client-side
        // throttle both need a working cache store.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('lnkflow.connections.default', [
            'url' => 'https://app.lnkflow.test/api/v1',
            'api_token' => 'api-test-token',
            'link_token' => 'link-test-token',
            'conversion_token' => 'conversion-test-token',
            'team' => 'team-test',
            'website' => 10,
            'connect_timeout' => 1,
            'timeout' => 1,
            'attempts' => 2,
            'retry_base_milliseconds' => 0,
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [
            LnkFlowServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        // The real framework `jobs` table, so queue-serialization tests can
        // assert on the payload the queue actually stores rather than on a
        // reimplementation of it.
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('test_contents', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('destination_url');
            $table->timestamps();
        });

        Schema::create('lnkflow_campaign_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('connection');
            $table->string('remote_team_id');
            $table->string('campaign_key');
            $table->unsignedBigInteger('remote_campaign_id')->nullable();
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64)->nullable();
            $table->string('state', 16)->default('pending');
            $table->string('last_error_code')->nullable();
            $table->string('last_request_id', 64)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['connection', 'remote_team_id', 'campaign_key']);
        });

        Schema::create('lnkflow_link_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('connection');
            $table->string('remote_team_id');
            $table->string('source_type');
            $table->string('source_id');
            $table->string('placement');
            $table->foreignId('campaign_mapping_id')->nullable();
            $table->unsignedBigInteger('remote_campaign_id')->nullable();
            $table->unsignedBigInteger('remote_link_id')->nullable();
            $table->string('short_url', 2048)->nullable();
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64)->nullable();
            $table->string('state', 16)->default('pending');
            $table->string('last_error_code')->nullable();
            $table->string('last_request_id', 64)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['connection', 'remote_team_id', 'source_type', 'source_id', 'placement']);
        });
    }
}
