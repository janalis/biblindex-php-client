<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use BiblIndex\Client\BiblIndexClient;
use BiblIndex\Client\LazyResource;
use Symfony\Component\Dotenv\Dotenv;

$dotenv = new Dotenv();
foreach (['.env', '.env.local'] as $file) {
    if (\is_file(__DIR__ . '/' . $file)) {
        $dotenv->load(__DIR__ . '/' . $file);
    }
}

$env = static fn (string $name): string => $_ENV[$name] ?? (\getenv($name) ?: '');

$client = new BiblIndexClient(
    $env('BIBLINDEX_API_URL'),
    $env('BIBLINDEX_API_USER'),
    $env('BIBLINDEX_API_PASSWORD'),
    $env('BIBLINDEX_API_KEY'),
    $env('BIBLINDEX_API_SECRET'),
);

$collection = $client->request('/api/quotations', ['page' => 1]);

printf(
    "Fetched quotation collection with %d loaded member link(s) out of %d total.\n",
    $collection->getLoadedItems(),
    \count($collection),
);

if ($collection->isEmpty()) {
    exit(0);
}

$item = $collection[0];
printf("First member is lazy: %s\n", \var_export($item instanceof LazyResource, true));

echo "Reading a field on the quotation now triggers the item fetch.\n";
$quotationId = $item['@id'] ?? $item['id'] ?? 'unknown';
printf("First quotation id: %s\n", (string) $quotationId);

foreach (['extract', 'work', 'works'] as $linkedProperty) {
    if (!isset($item[$linkedProperty])) {
        continue;
    }

    $linkedValue = $item[$linkedProperty];
    printf("%s is lazy: %s\n", $linkedProperty, \var_export($linkedValue instanceof LazyResource, true));

    if (\is_array($linkedValue) && $linkedValue !== []) {
        $firstLinkedValue = $linkedValue[0];
        printf(
            "First %s item is lazy: %s\n",
            $linkedProperty,
            \var_export($firstLinkedValue instanceof LazyResource, true),
        );
        printf("Reading a field on %s[0] now triggers its fetch.\n", $linkedProperty);
        $firstLinkedValueId = $firstLinkedValue['@id'] ?? $firstLinkedValue['id'] ?? null;
        printf("First %s item id: %s\n", $linkedProperty, (string) $firstLinkedValueId);
        break;
    }

    if ($linkedValue instanceof LazyResource) {
        printf("Reading a field on %s now triggers its fetch.\n", $linkedProperty);
        $linkedValueId = $linkedValue['@id'] ?? $linkedValue['id'] ?? null;
        printf("%s id: %s\n", $linkedProperty, (string) $linkedValueId);
        break;
    }
}
