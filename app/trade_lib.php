<?php
declare(strict_types=1);
require_once __DIR__ . '/deck_library.php';

/**
 * Lista de venda e troca.
 *
 * Cada usuário tem uma lista com link próprio (token), que pode montar de duas
 * formas: "soltas" — todas as cópias que sobram depois do que está reservado nos
 * decks, recalculadas a cada visita — ou "escolhidas", em que ele mesmo marca as
 * cartas, com preço e observação por item. O link só responde quando a lista
 * está pública; o dono sempre vê a própria página como prévia.
 */

function tradeSchema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS trade_lists (
            user_id bigint PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            token text NOT NULL UNIQUE,
            title text NOT NULL DEFAULT '',
            intro text NOT NULL DEFAULT '',
            contact text NOT NULL DEFAULT '',
            mode text NOT NULL DEFAULT 'free' CHECK (mode IN ('free','manual')),
            is_public boolean NOT NULL DEFAULT false,
            show_prices boolean NOT NULL DEFAULT true,
            created_at timestamptz NOT NULL DEFAULT now(),
            updated_at timestamptz NOT NULL DEFAULT now()
        );
        CREATE TABLE IF NOT EXISTS trade_items (
            user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            scryfall_id uuid NOT NULL REFERENCES cards(id),
            foil boolean NOT NULL DEFAULT false,
            quantity int NOT NULL DEFAULT 1 CHECK (quantity > 0),
            price numeric(10,2) NULL CHECK (price IS NULL OR price >= 0),
            note text NOT NULL DEFAULT '',
            added_at timestamptz NOT NULL DEFAULT now(),
            PRIMARY KEY (user_id, scryfall_id, foil)
        );
        CREATE INDEX IF NOT EXISTS trade_items_user_idx ON trade_items (user_id);
    SQL);
}

function tradeNewToken(): string
{
    return rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
}

/** A lista do usuário; com $create, nasce na primeira visita a /trade.php. */
function tradeListFor(int $userId, bool $create = false): ?array
{
    tradeSchema();
    $list = deckQuery('SELECT * FROM trade_lists WHERE user_id=?', [$userId])->fetch() ?: null;
    if ($list || !$create || $userId < 1) return $list;
    deckQuery('INSERT INTO trade_lists (user_id, token) VALUES (?, ?) ON CONFLICT (user_id) DO NOTHING', [$userId, tradeNewToken()]);
    return deckQuery('SELECT * FROM trade_lists WHERE user_id=?', [$userId])->fetch() ?: null;
}

function tradeListByToken(string $token): ?array
{
    tradeSchema();
    if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token)) return null;
    return deckQuery('SELECT l.*, u.username, u.display_name, u.full_name, u.avatar_file, u.location, u.is_active
        FROM trade_lists l JOIN users u ON u.id=l.user_id WHERE l.token=?', [$token])->fetch() ?: null;
}

function tradeUpdate(int $userId, array $fields): void
{
    if (!$fields) return;
    $sets = [];
    $values = [];
    foreach ($fields as $column => $value) { $sets[] = "{$column}=?"; $values[] = $value; }
    $values[] = $userId;
    deckQuery('UPDATE trade_lists SET ' . implode(',', $sets) . ', updated_at=now() WHERE user_id=?', $values);
}

function tradeMode(?array $list): string
{
    return ($list['mode'] ?? 'free') === 'manual' ? 'manual' : 'free';
}

function tradeIsPublic(?array $list): bool
{
    return (bool)$list && deckIsFoil($list['is_public']);
}

/**
 * Cópias soltas: o que sobra de cada impressão depois de reservar o que os decks usam.
 *
 * Os decks reservam por carta lógica (oracle), não por impressão — então as cópias
 * livres são distribuídas entre as impressões numa ordem fixa, e nenhuma impressão
 * pode oferecer o que outra já contou.
 */
function tradeFreeCopies(int $userId): array
{
    tradeSchema();
    return deckQuery('SELECT c.*, o.quantity, o.foil, f.used_in_decks, f.available
        FROM ' . tradeFreeCopiesSql($userId) . ' f
        JOIN builder_collection o ON o.user_id=? AND o.scryfall_id=f.scryfall_id AND o.foil=f.foil
        JOIN cards c ON c.id=f.scryfall_id
        WHERE f.available > 0
        ORDER BY c.name, c.set_code, c.collector_number, o.foil', [$userId])->fetchAll();
}

/**
 * Tabela derivada (scryfall_id, foil, available, used_in_decks) com as cópias soltas
 * de cada impressão — também usada pela coleção pública para "só o que sobra".
 */
function tradeFreeCopiesSql(int $userId): string
{
    $usage = deckUsageSql($userId);
    return "(SELECT a.scryfall_id, a.foil, a.used_in_decks,
            LEAST(a.quantity, GREATEST(0, a.free_total - a.taken_before))::int AS available
        FROM (
            SELECT o.scryfall_id, o.foil, o.quantity, COALESCE(u.used,0)::int AS used_in_decks,
                GREATEST(0, SUM(o.quantity) OVER (PARTITION BY COALESCE(c.oracle_id,c.id)) - COALESCE(u.used,0))::int AS free_total,
                COALESCE(SUM(o.quantity) OVER (PARTITION BY COALESCE(c.oracle_id,c.id)
                    ORDER BY o.foil, c.set_code, c.collector_number, c.id
                    ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING), 0)::int AS taken_before
            FROM builder_collection o JOIN cards c ON c.id=o.scryfall_id
            LEFT JOIN {$usage} u ON u.logical_id=COALESCE(c.oracle_id,c.id)
            WHERE o.user_id=" . $userId . "
        ) a)";
}

/** Cartas marcadas à mão, com o que ainda existe na coleção e o que os decks usam. */
function tradeChosenCards(int $userId): array
{
    tradeSchema();
    $usage = deckUsageSql($userId);
    $rows = deckQuery("SELECT c.*, i.foil, i.quantity AS listed_quantity, i.price, i.note,
            COALESCE(o.quantity,0)::int AS owned_quantity, COALESCE(u.used,0)::int AS used_in_decks
        FROM trade_items i JOIN cards c ON c.id=i.scryfall_id
        LEFT JOIN builder_collection o ON o.user_id=i.user_id AND o.scryfall_id=i.scryfall_id AND o.foil=i.foil
        LEFT JOIN {$usage} u ON u.logical_id=COALESCE(c.oracle_id,c.id)
        WHERE i.user_id=? ORDER BY c.name, c.set_code, c.collector_number, i.foil", [$userId])->fetchAll();
    foreach ($rows as &$row) {
        $row['available'] = min((int)$row['listed_quantity'], (int)$row['owned_quantity']);
        $row['quantity'] = (int)$row['owned_quantity'];
    }
    return $rows;
}

/**
 * As cartas da lista, no formato que as páginas usam.
 * Na visão pública só entra o que ainda existe na coleção.
 */
function tradeCards(array $list, bool $publicView = true): array
{
    $userId = (int)$list['user_id'];
    $rows = tradeMode($list) === 'manual' ? tradeChosenCards($userId) : tradeFreeCopies($userId);
    if ($publicView) $rows = array_values(array_filter($rows, static fn(array $row): bool => (int)$row['available'] > 0));
    foreach ($rows as &$row) {
        $row['is_foil'] = deckIsFoil($row['foil']);
        $row['reference_price'] = deckFinishPriceBrl($row, $row['is_foil']);
        $row['asking_price'] = isset($row['price']) && $row['price'] !== null ? (float)$row['price'] : null;
        $row['final_price'] = $row['asking_price'] ?? $row['reference_price'];
    }
    return $rows;
}

function tradeSummary(array $rows): array
{
    $copies = 0;
    $total = 0.0;
    $priced = 0;
    foreach ($rows as $row) {
        $available = max(0, (int)$row['available']);
        $copies += $available;
        if ($row['final_price'] !== null) { $total += $available * (float)$row['final_price']; $priced++; }
    }
    return ['printings' => count($rows), 'copies' => $copies, 'total' => $total, 'priced' => $priced];
}

/** Liga/desliga uma impressão na seleção manual; devolve true quando a carta ficou à venda. */
function tradeToggleItem(int $userId, string $cardId, bool $foil): bool
{
    tradeSchema();
    $foilValue = $foil ? 'true' : 'false';
    $removed = deckQuery('DELETE FROM trade_items WHERE user_id=? AND scryfall_id=? AND foil=? RETURNING scryfall_id', [$userId, $cardId, $foilValue])->fetch();
    if ($removed) return false;
    $owned = (int)deckQuery('SELECT COALESCE(quantity,0) FROM builder_collection WHERE user_id=? AND scryfall_id=? AND foil=?', [$userId, $cardId, $foilValue])->fetchColumn();
    if ($owned < 1) throw new RuntimeException('Esta impressão não está na sua coleção.');
    deckQuery('INSERT INTO trade_items (user_id, scryfall_id, foil, quantity) VALUES (?,?,?,?)
        ON CONFLICT (user_id, scryfall_id, foil) DO UPDATE SET quantity=EXCLUDED.quantity', [$userId, $cardId, $foilValue, $owned]);
    return true;
}

function tradeItemExists(int $userId, string $cardId, bool $foil): bool
{
    tradeSchema();
    return (bool)deckQuery('SELECT 1 FROM trade_items WHERE user_id=? AND scryfall_id=? AND foil=?', [$userId, $cardId, $foil ? 'true' : 'false'])->fetchColumn();
}

/** Impressões marcadas à mão, indexadas por "id:foil", para a página da coleção. */
function tradeItemKeys(int $userId): array
{
    tradeSchema();
    $keys = [];
    foreach (deckQuery('SELECT scryfall_id, foil FROM trade_items WHERE user_id=?', [$userId])->fetchAll() as $row) {
        $keys[(string)$row['scryfall_id'] . ':' . (deckIsFoil($row['foil']) ? '1' : '0')] = true;
    }
    return $keys;
}

function tradeSaveItem(int $userId, string $cardId, bool $foil, int $quantity, ?float $price, string $note): void
{
    tradeSchema();
    deckQuery('UPDATE trade_items SET quantity=?, price=?, note=? WHERE user_id=? AND scryfall_id=? AND foil=?',
        [max(1, $quantity), $price, mb_substr(trim($note), 0, 160), $userId, $cardId, $foil ? 'true' : 'false']);
}

function tradeRemoveItem(int $userId, string $cardId, bool $foil): void
{
    tradeSchema();
    deckQuery('DELETE FROM trade_items WHERE user_id=? AND scryfall_id=? AND foil=?', [$userId, $cardId, $foil ? 'true' : 'false']);
}

/** Copia as cópias soltas de hoje para a seleção manual; devolve quantas impressões entraram. */
function tradeAddFreeCopies(int $userId): int
{
    $added = 0;
    foreach (tradeFreeCopies($userId) as $row) {
        $inserted = deckQuery('INSERT INTO trade_items (user_id, scryfall_id, foil, quantity) VALUES (?,?,?,?)
            ON CONFLICT (user_id, scryfall_id, foil) DO NOTHING RETURNING scryfall_id',
            [$userId, (string)$row['id'], deckIsFoil($row['foil']) ? 'true' : 'false', (int)$row['available']])->fetch();
        if ($inserted) $added++;
    }
    return $added;
}

function tradeClearItems(int $userId): int
{
    tradeSchema();
    return (int)deckQuery('WITH removed AS (DELETE FROM trade_items WHERE user_id=? RETURNING 1) SELECT COUNT(*) FROM removed', [$userId])->fetchColumn();
}

function tradePublicPath(array $list): string
{
    return '/public_trade.php?t=' . rawurlencode((string)$list['token']);
}

function tradePublicUrl(array $list): string
{
    $scheme = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'http') ? 'http' : 'https';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'deckarium.bernas.shop');
    return $scheme . '://' . $host . tradePublicPath($list);
}

function tradePriceLabel(?float $price): string
{
    return $price === null ? 'A combinar' : 'R$ ' . number_format($price, 2, ',', '.');
}
