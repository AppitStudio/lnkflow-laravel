<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Data\Workspace;

/**
 * Websites, domains, influencers, and accessible teams in one round trip.
 *
 * Cheaper than three list calls when bootstrapping an adapter, a picker UI, or
 * a diagnostic command.
 */
final class WorkspaceClient extends AbstractClient
{
    public function bootstrap(): Workspace
    {
        return new Workspace($this->transport->send('GET', 'browser-extension/bootstrap')->data());
    }
}
