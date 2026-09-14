<?php
declare(strict_types=1);

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'db',
        'port' => getenv('DB_PORT') ?: '5432',
        'name' => getenv('DB_NAME') ?: 'mtg',
        'user' => getenv('DB_USER') ?: 'mtg',
        'password' => getenv('DB_PASSWORD') ?: 'mtg_local_change_me',
    ],
    'storage_dir' => rtrim(getenv('STORAGE_DIR') ?: '/var/www/storage', '/'),
    'scryfall' => [
        'bulk_type' => getenv('SCRYFALL_BULK_TYPE') ?: 'default_cards',
        'user_agent' => getenv('SCRYFALL_USER_AGENT') ?: 'Deckarium/1.0',
        'accept' => getenv('SCRYFALL_ACCEPT') ?: 'application/json;q=0.9,*/*;q=0.8',
    ],
];
