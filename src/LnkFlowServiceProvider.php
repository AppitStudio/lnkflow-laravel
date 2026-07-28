<?php

declare(strict_types=1);

namespace LnkFlow\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use LnkFlow\Laravel\Commands\DoctorCommand;
use LnkFlow\Laravel\Commands\InstallCommand;
use LnkFlow\Laravel\Commands\SyncCommand;
use LnkFlow\Laravel\Commands\VerifyCommand;
use LnkFlow\Laravel\Contracts\ConsentResolver;
use LnkFlow\Laravel\Contracts\CustomerExternalIdResolver;
use LnkFlow\Laravel\Contracts\Transport;
use LnkFlow\Laravel\Http\ApiTransport;
use LnkFlow\Laravel\Listeners\CashierWebhookListener;
use LnkFlow\Laravel\Observers\LinkableContentObserver;
use LnkFlow\Laravel\Services\Client;
use LnkFlow\Laravel\Services\ContentSynchronizer;
use LnkFlow\Laravel\Services\ConversionDispatcher;
use LnkFlow\Laravel\Services\DefaultConsentResolver;
use LnkFlow\Laravel\Services\DefaultCustomerExternalIdResolver;
use LnkFlow\Laravel\Services\JourneyContext;
use LnkFlow\Laravel\Services\LnkFlowManager;
use LnkFlow\Laravel\Subscribers\AuthIdentitySubscriber;

final class LnkFlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lnkflow.php', 'lnkflow');

        $this->app->bind(ConsentResolver::class, DefaultConsentResolver::class);
        $this->app->bind(CustomerExternalIdResolver::class, DefaultCustomerExternalIdResolver::class);
        $this->app->singleton(Transport::class, fn (Application $app): Transport => new ApiTransport(
            $app->make(Factory::class),
            $app->make(ConfigRepository::class)->get('lnkflow', []),
        ));
        $this->app->singleton(Client::class, fn (Application $app): Client => new Client(
            $app->make(Transport::class),
        ));
        $this->app->singleton(LnkFlowManager::class, fn (Application $app): LnkFlowManager => new LnkFlowManager(
            $app,
            $app->make(Client::class),
        ));
        $this->app->alias(LnkFlowManager::class, 'lnkflow');
        $this->app->singleton(JourneyContext::class);
        $this->app->singleton(ContentSynchronizer::class);
        $this->app->singleton(ConversionDispatcher::class);
    }

    public function boot(): void
    {
        $this->registerConfiguredObservers();
        $this->registerAuthLifecycle();
        $this->registerCashierAdapter();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/lnkflow.php' => config_path('lnkflow.php'),
        ], ['lnkflow', 'lnkflow-config']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations/create_lnkflow_campaign_mappings_table.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_lnkflow_campaign_mappings_table.php'),
            __DIR__.'/../database/migrations/create_lnkflow_link_mappings_table.php.stub' => database_path('migrations/'.date('Y_m_d_His', time() + 1).'_create_lnkflow_link_mappings_table.php'),
        ], ['lnkflow', 'lnkflow-migrations']);

        $this->commands([
            DoctorCommand::class,
            InstallCommand::class,
            SyncCommand::class,
            VerifyCommand::class,
        ]);
    }

    private function registerConfiguredObservers(): void
    {
        if (config('lnkflow.features.content') !== true) {
            return;
        }

        $models = config('lnkflow.content.models', []);

        foreach (is_array($models) ? array_keys($models) : [] as $model) {
            if (is_string($model) && class_exists($model) && method_exists($model, 'observe')) {
                $model::observe(LinkableContentObserver::class);
            }
        }
    }

    private function registerAuthLifecycle(): void
    {
        if (config('lnkflow.features.auth_identity') === true) {
            Event::subscribe(AuthIdentitySubscriber::class);
        }
    }

    private function registerCashierAdapter(): void
    {
        if (config('lnkflow.cashier.enabled') !== true
            || ! class_exists('Laravel\\Cashier\\Events\\WebhookHandled')) {
            return;
        }

        Event::listen(
            'Laravel\\Cashier\\Events\\WebhookHandled',
            CashierWebhookListener::class,
        );
    }
}
