# Link management

Campaign and link creates require an integration-owned stable idempotency key:

```php
$campaign = $client->campaigns()->create(
    new CreateCampaign('Newsletter', websiteId: 12),
    'newsletter:campaign',
);

$preview = $client->links()->preview(
    new CreateLink('https://example.com/news', slug: 'news'),
    campaignId: $campaign->id,
);

$link = $client->links()->create(
    $campaign->id,
    new CreateLink(
        destinationUrl: 'https://example.com/news',
        slug: 'news',
        utm: ['utm_source' => 'email'],
        conversionTrackingEnabled: true,
        autoPromoCode: 'NEWS20',
    ),
    'newsletter:primary-link',
);
```

Persist idempotency keys before the first request and reuse them after timeouts.
Do not generate a new key for each retry. Preview validates without persisting.
`edgeStatus` is returned as an open string because new server states may be
added. Deactivation is preferred to deletion for managed content.
