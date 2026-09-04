<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/RestaurantCatalogTestFactory.php';

$barrier = (string) ($argv[1] ?? '');
$actor = (int) ($argv[2] ?? 0);
$operation = (string) ($argv[3] ?? '');
$decoded = base64_decode((string) ($argv[4] ?? ''), true);
$input = $decoded === false ? null : json_decode($decoded, true);
if ($barrier === '' || $actor <= 0 || !is_array($input)) exit(2);
for ($attempt = 0; $attempt < 500 && !is_file($barrier); $attempt++) usleep(10000);
if (!is_file($barrier)) exit(3);

try {
    $db = Database::getInstance()->getConnection();
    $result = match ($operation) {
        'create_product' => (new RestaurantProductService($db, $actor))->createProduct($input),
        'update_product' => (new RestaurantProductService($db, $actor))->updateProduct($input),
        'set_price' => (new RestaurantPricingService($db, $actor))->setPrice($input),
        'set_availability' => (new RestaurantAvailabilityService($db, $actor))->setAvailability($input),
        'update_group' => (new RestaurantModifierService($db, $actor))->updateGroup($input),
        default => throw new InvalidArgumentException('unknown operation'),
    };
    echo json_encode(['success' => true, 'result' => $result], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'error' => get_class($error)], JSON_THROW_ON_ERROR);
    exit(1);
}
