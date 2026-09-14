<?php
declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cardImageUrl(array $card, string $face = 'front', string $size = 'normal'): ?string
{
    $face = $face === 'back' ? 'back' : 'front';
    $size = in_array($size, ['small', 'normal'], true) ? $size : 'small';

    $raw = $card['raw'] ?? null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($raw)) $raw = [];

    if ($face === 'front') {
        $available = !empty($card['local_image'])
            || !empty($card['image_uri'])
            || !empty($raw['image_uris'])
            || !empty($raw['card_faces'][0]['image_uris']);
    } else {
        $available = !empty($card['local_image_back'])
            || !empty($card['image_uri_back'])
            || !empty($raw['card_faces'][1]['image_uris']);
    }

    $sizeKey = $size === 'small' ? 'small' : 'normal';
    $remote = $face === 'front'
        ? ($raw['image_uris'][$sizeKey] ?? $raw['card_faces'][0]['image_uris'][$sizeKey] ?? ($card['image_uri'] ?? null))
        : ($raw['card_faces'][1]['image_uris'][$sizeKey] ?? ($card['image_uri_back'] ?? null));
    if ($remote) return (string)$remote;

    if (!empty($card['id']) && $available) {
        return '/image.php?id=' . rawurlencode((string)$card['id'])
            . '&face=' . $face
            . '&size=' . $size;
    }

    return null;
}

function displayDate(?string $date): string
{
    if (!$date) return 'Sem data';
    $value = strtotime($date);
    return $value === false ? $date : date('d/m/Y', $value);
}

function manaSymbols(?string $cost): string
{
    if (!$cost) return '<span class="mana-muted">Sem custo</span>';
    $tokens = preg_split('/\{([^}]+)\}/', $cost, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    $html = '';
    foreach ($tokens as $token) {
        $symbol = strtoupper(trim($token));
        if ($symbol === '') continue;
        $src = 'https://svgs.scryfall.io/card-symbols/' . rawurlencode(str_replace('/', '', $symbol)) . '.svg';
        $html .= '<img class="mana-symbol" src="' . h($src) . '" alt="' . h($symbol) . '" loading="lazy" width="18" height="18">';
    }
    return $html ?: '<span class="mana-muted">Sem custo</span>';
}

function setIconUrl(?string $code): ?string
{
    $code = strtolower(trim((string)$code));
    return preg_match('/^[a-z0-9]{2,8}$/', $code) ? 'https://svgs.scryfall.io/sets/' . rawurlencode($code) . '.svg' : null;
}

function editionUmbrella(string $name): string
{
    $name = trim($name);
    // Scryfall publishes commander, token, promo and art subsets as separate
    // set codes. Keep them under the collection people recognize first.
    do {
        $previous = $name;
        $name = (string)preg_replace('/\s+(?:Commander(?:\s+Edition)?|Tokens?|Token Cards?|Jumpstart(?:\s+Front)?\s+Cards?|Beginner Box(?:\s+Front)?\s+Cards?|Front Cards?|Art Series|Promos?|Promotional|Decks?|Eternal|Special Guests|Scene Box|Starter Kit)$/iu', '', $name);
    } while ($name !== $previous);
    $base = $name;
    return trim((string)$base) ?: $name;
}

function cardTile(array $card): void
{
    $url = '/card.php?id=' . rawurlencode((string)$card['id']);
    $image = cardImageUrl($card);
    echo '<article class="card-tile"><a class="card-art" href="' . h($url) . '">';
    if ($image) echo '<img loading="lazy" decoding="async" width="488" height="680" src="' . h($image) . '" alt="' . h($card['name']) . '">';
    else echo '<div class="placeholder"><strong>' . h($card['name']) . '</strong><span>Imagem indisponível</span></div>';
    echo '</a><div class="card-meta"><a href="' . h($url) . '">' . h($card['name']) . '</a><small>' . h(strtoupper((string)$card['set_code'])) . ' · #' . h((string)$card['collector_number']) . '</small></div></article>';
}

function jsonArrayToText($value): string
{
    if (is_array($value)) {
        return implode('', $value);
    }
    if (!is_string($value) || $value === '') {
        return '';
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? implode('', $decoded) : '';
}
