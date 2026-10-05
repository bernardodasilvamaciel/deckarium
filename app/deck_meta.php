<?php
declare(strict_types=1);

/**
 * Meta dos formatos a partir das listas publicadas pelo MTGO (www.mtgo.com/decklists).
 *
 * A Wizards publica todos os dias os decks das ligas (5-0) e dos Challenges de Standard, Pioneer, Modern, Legacy,
 * Vintage, Pauper, Premodern e Duel Commander, com a lista inteira embutida na página em JSON. Não há uma API
 * oficial de meta para esses formatos (MTGGoldfish e MTGTop8 não têm API; o EDHREC cobre só Commander), então
 * o Deckarium guarda as listas recentes e calcula aqui o que o EDHREC dá para as comandantes:
 *
 *  - presença de cada carta no formato (em quantos decks aparece e com quantas cópias);
 *  - arquétipos: cores + a carta que define o deck (a 4-of menos comum do formato);
 *  - decks do meta parecidos com o seu (Jaccard ponderado pelas cópias, sem básicos);
 *  - "combina com o seu deck": presença da carta nos decks parecidos menos a presença no formato inteiro,
 *    a mesma ideia da sinergia do EDHREC;
 *  - quanto de cada lista você já tem livre na coleção.
 *
 * A busca roda em segundo plano (bin/sync_meta.php), no máximo uma por formato ao mesmo tempo.
 */

const META_BASE = 'https://www.mtgo.com';
const META_MAX_AGE_HOURS = 36;
const META_KEEP_DAYS = 60;

function metaSchema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (db()->query("SELECT to_regclass('public.meta_formats') IS NOT NULL")->fetchColumn()) return;
    db()->exec("CREATE TABLE IF NOT EXISTS meta_events (
            id text PRIMARY KEY, format text NOT NULL, name text NOT NULL, kind text NOT NULL DEFAULT 'league',
            event_date date NULL, url text NOT NULL, deck_count int NOT NULL DEFAULT 0, synced_at timestamptz NOT NULL DEFAULT now());
        CREATE INDEX IF NOT EXISTS meta_events_format_idx ON meta_events(format, event_date DESC);
        CREATE TABLE IF NOT EXISTS meta_decks (
            id bigserial PRIMARY KEY, event_id text NOT NULL REFERENCES meta_events(id) ON DELETE CASCADE, format text NOT NULL,
            player text NOT NULL DEFAULT '', rank int NULL, wins int NULL, losses int NULL,
            colors text NOT NULL DEFAULT '', archetype text NOT NULL DEFAULT '', signature text NOT NULL DEFAULT '',
            main_count int NOT NULL DEFAULT 0, core_count int NOT NULL DEFAULT 0, side_count int NOT NULL DEFAULT 0);
        CREATE INDEX IF NOT EXISTS meta_decks_format_idx ON meta_decks(format);
        CREATE TABLE IF NOT EXISTS meta_deck_cards (
            deck_id bigint NOT NULL REFERENCES meta_decks(id) ON DELETE CASCADE, name text NOT NULL, sideboard boolean NOT NULL DEFAULT false,
            quantity int NOT NULL, basic boolean NOT NULL DEFAULT false, logical_id uuid NULL,
            PRIMARY KEY (deck_id, sideboard, name));
        CREATE INDEX IF NOT EXISTS meta_deck_cards_logical_idx ON meta_deck_cards(logical_id);
        CREATE TABLE IF NOT EXISTS meta_formats (
            format text PRIMARY KEY, synced_at timestamptz NULL, attempted_at timestamptz NULL, decks int NOT NULL DEFAULT 0,
            events int NOT NULL DEFAULT 0, message text NOT NULL DEFAULT '');");
}

/** Formato do Deckarium → prefixo das páginas do MTGO (modern → modern-challenge-32-…). */
function metaSlug(array $format): ?string
{
    return $format['meta'] ?? null;
}

/** GET com curl: o MTGO devolve a página sem as listas para o cliente HTTP embutido do PHP. */
function metaHttpGet(string $url, int $timeout = 20): ?string
{
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Deckarium/1.0; colecao pessoal de Magic)']);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return is_string($raw) && $status < 400 ? $raw : null;
}

/**
 * Eventos do formato listados no índice do MTGO, do mais novo para o mais antigo.
 * Cada item: ['id' => site_name, 'kind' => challenge|qualifier|league|other, 'date' => Y-m-d, 'name' => rótulo].
 */
function metaIndexEvents(string $slug, ?string $html = null): array
{
    // O índice é o mesmo para todos os formatos: numa busca de vários formatos, baixa uma vez só (com uma nova tentativa).
    // Alguns servidores do MTGO respondem com a página sem as listas: tenta de novo até elas aparecerem.
    static $index = null;
    if ($html === null) {
        for ($attempt = 0; $index === null && $attempt < 6; $attempt++) {
            if ($attempt) sleep(2);
            $page = metaHttpGet(META_BASE . '/decklists', 45);
            if ($page !== null && str_contains($page, 'href="/decklist/')) $index = $page;
        }
        $html = $index;
    }
    if ($html === null) throw new RuntimeException('O MTGO não respondeu à lista de torneios.');
    preg_match_all('#href="/decklist/([a-z0-9-]+?)-(\d{4})-(\d{2})-(\d{2})(\d+)"#', $html, $matches, PREG_SET_ORDER);
    $events = [];
    foreach ($matches as $m) {
        $name = $m[1];
        if (!str_starts_with($name, $slug . '-')) continue;
        $rest = substr($name, strlen($slug) + 1);
        // "modern-challenge" não pode pegar "modern-pauper-…" etc.: o resto precisa ser um tipo de evento conhecido.
        $kind = str_starts_with($rest, 'challenge') ? 'challenge' : (str_contains($rest, 'qualifier') || str_starts_with($rest, 'showcase') || str_starts_with($rest, 'last-chance') ? 'qualifier' : (str_starts_with($rest, 'league') ? 'league' : 'other'));
        if ($kind === 'other' && !preg_match('/^(preliminary|super|premier|champ)/', $rest)) continue;
        $id = $m[1] . '-' . $m[2] . '-' . $m[3] . '-' . $m[4] . $m[5];
        $events[$id] = ['id' => $id, 'kind' => $kind, 'date' => $m[2] . '-' . $m[3] . '-' . $m[4], 'name' => ucwords(str_replace('-', ' ', $name))];
    }
    uasort($events, fn($a, $b) => strcmp($b['date'], $a['date']));
    return array_values($events);
}

/** Lê o JSON embutido numa página de decklist do MTGO. */
function metaFetchEvent(string $siteName): ?array
{
    // O servidor do MTGO às vezes demora ou responde sem os dados; a página ganha mais duas chances.
    $html = null;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        if ($attempt) sleep(1);
        $html = metaHttpGet(META_BASE . '/decklist/' . rawurlencode($siteName), 45);
        if ($html !== null && str_contains($html, 'decklists.data')) break;
    }
    if ($html === null) return null;
    if (!preg_match('/window\.MTGO\.decklists\.data\s*=\s*(\{.*?\});\s*\n/s', $html, $m)) return null;
    $data = json_decode($m[1], true);
    return is_array($data) && is_array($data['decklists'] ?? null) ? $data : null;
}

/** Soma as cópias de cada carta (o MTGO repete a carta por impressão). */
function metaCollapseCards(array $list): array
{
    $cards = [];
    foreach ($list as $row) {
        $attr = $row['card_attributes'] ?? [];
        $name = trim((string)($attr['card_name'] ?? ''));
        if ($name === '') continue;
        $cards[$name] ??= ['quantity' => 0, 'basic' => ($attr['rarity'] ?? '') === 'BASIC_LAND'];
        $cards[$name]['quantity'] += max(1, (int)($row['qty'] ?? 1));
    }
    return $cards;
}

/** Guarda um evento e os decks dele. Devolve quantos decks entraram. */
function metaStoreEvent(string $formatKey, array $event, array $data): int
{
    $ranks = [];
    foreach ((array)($data['standings'] ?? []) as $row) if (isset($row['loginid'])) $ranks[(string)$row['loginid']] = (int)($row['rank'] ?? 0) ?: null;
    $records = [];
    foreach ((array)($data['winloss'] ?? []) as $row) if (isset($row['loginid'])) $records[(string)$row['loginid']] = [(int)($row['wins'] ?? 0), (int)($row['losses'] ?? 0)];
    $stored = 0;
    db()->beginTransaction();
    try {
        deckQuery('INSERT INTO meta_events(id,format,name,kind,event_date,url,deck_count) VALUES (?,?,?,?,?,?,0) ON CONFLICT (id) DO NOTHING',
            [$event['id'], $formatKey, (string)($data['description'] ?? $data['name'] ?? $event['name']), $event['kind'], $event['date'], META_BASE . '/decklist/' . $event['id']]);
        foreach ($data['decklists'] as $list) {
            $main = metaCollapseCards((array)($list['main_deck'] ?? []));
            $side = metaCollapseCards((array)($list['sideboard_deck'] ?? []));
            if (!$main) continue;
            $login = (string)($list['loginid'] ?? '');
            $wins = isset($list['wins']['wins']) ? (int)$list['wins']['wins'] : ($records[$login][0] ?? null);
            $losses = isset($list['wins']['losses']) ? (int)$list['wins']['losses'] : ($records[$login][1] ?? null);
            $mainCount = array_sum(array_column($main, 'quantity'));
            $coreCount = array_sum(array_map(fn($c) => $c['basic'] ? 0 : $c['quantity'], $main));
            $deckId = (int)deckQuery('INSERT INTO meta_decks(event_id,format,player,rank,wins,losses,main_count,core_count,side_count) VALUES (?,?,?,?,?,?,?,?,?) RETURNING id',
                [$event['id'], $formatKey, mb_substr((string)($list['player'] ?? ''), 0, 60), $ranks[$login] ?? null, $wins, $losses, $mainCount, $coreCount, array_sum(array_column($side, 'quantity'))])->fetchColumn();
            foreach ([[$main, false], [$side, true]] as [$cards, $isSide]) {
                foreach ($cards as $name => $card) {
                    deckQuery('INSERT INTO meta_deck_cards(deck_id,name,sideboard,quantity,basic) VALUES (?,?,?,?,?) ON CONFLICT DO NOTHING',
                        [$deckId, $name, $isSide ? 'true' : 'false', $card['quantity'], $card['basic'] ? 'true' : 'false']);
                }
            }
            $stored++;
        }
        deckQuery('UPDATE meta_events SET deck_count=? WHERE id=?', [$stored, $event['id']]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    return $stored;
}

/** Liga os nomes do MTGO às cartas do catálogo (nome inteiro, face da frente ou "A/B" das cartas divididas). */
function metaResolveNames(): int
{
    $names = deckQuery('SELECT DISTINCT name FROM meta_deck_cards WHERE logical_id IS NULL')->fetchAll(PDO::FETCH_COLUMN);
    if (!$names) return 0;
    $wanted = [];
    foreach ($names as $name) {
        $wanted[mb_strtolower($name)] = $name;
        if (str_contains($name, '/') && !str_contains($name, ' // ')) $wanted[mb_strtolower(preg_replace('#\s*/\s*#', ' // ', $name))] = $name;
    }
    $rows = deckQuery("SELECT DISTINCT ON (key) key, logical_id FROM (
            SELECT lower(c.name) AS key, COALESCE(c.oracle_id,c.id) AS logical_id, c.released_at, c.lang FROM cards c WHERE lower(c.name) = ANY(?::text[])
            UNION ALL SELECT lower(split_part(c.name,' // ',1)), COALESCE(c.oracle_id,c.id), c.released_at, c.lang FROM cards c WHERE c.name LIKE '% // %' AND lower(split_part(c.name,' // ',1)) = ANY(?::text[])
        ) found WHERE logical_id IS NOT NULL ORDER BY key, (lang='en') DESC, released_at DESC NULLS LAST",
        [metaPgArray(array_keys($wanted)), metaPgArray(array_keys($wanted))])->fetchAll();
    $resolved = 0;
    foreach ($rows as $row) {
        $original = $wanted[$row['key']] ?? null;
        if ($original === null) continue;
        $resolved += deckQuery('UPDATE meta_deck_cards SET logical_id=? WHERE name=? AND logical_id IS NULL', [$row['logical_id'], $original])->rowCount();
    }
    return $resolved;
}

function metaPgArray(array $values): string
{
    return '{' . implode(',', array_map(fn($v) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$v) . '"', $values)) . '}';
}

/**
 * Nomeia os arquétipos: cores das mágicas do deck + a carta que o define, a de mais cópias (3 ou 4, de preferência)
 * que aparece em menos decks do formato — Lightning Bolt está em todo lugar, Murktide Regent diz qual deck é.
 */
function metaNameArchetypes(string $formatKey): int
{
    $total = max(1, (int)deckQuery('SELECT COUNT(*) FROM meta_decks WHERE format=?', [$formatKey])->fetchColumn());
    $df = deckQuery("SELECT mc.name, COUNT(DISTINCT mc.deck_id) FROM meta_deck_cards mc JOIN meta_decks d ON d.id=mc.deck_id
        WHERE d.format=? AND NOT mc.sideboard GROUP BY mc.name", [$formatKey])->fetchAll(PDO::FETCH_KEY_PAIR);
    $info = [];
    foreach (deckQuery("SELECT DISTINCT ON (COALESCE(c.oracle_id,c.id)) COALESCE(c.oracle_id,c.id)::text lid, c.colors::text colors, c.type_line
        FROM cards c WHERE COALESCE(c.oracle_id,c.id) IN (SELECT DISTINCT mc.logical_id FROM meta_deck_cards mc JOIN meta_decks d ON d.id=mc.deck_id WHERE d.format=? AND mc.logical_id IS NOT NULL)
        ORDER BY COALESCE(c.oracle_id,c.id), (c.lang='en') DESC", [$formatKey])->fetchAll() as $row) $info[$row['lid']] = $row;
    $decks = deckQuery("SELECT mc.deck_id, mc.name, mc.quantity, mc.logical_id::text lid FROM meta_deck_cards mc JOIN meta_decks d ON d.id=mc.deck_id
        WHERE d.format=? AND NOT mc.sideboard AND NOT mc.basic ORDER BY mc.deck_id", [$formatKey])->fetchAll(PDO::FETCH_GROUP);
    $updated = 0;
    foreach ($decks as $deckId => $cards) {
        $colors = [];
        $best = null;
        foreach ($cards as $card) {
            $meta = $info[$card['lid'] ?? ''] ?? null;
            $isLand = $meta && str_contains(explode(' // ', (string)$meta['type_line'])[0], 'Land');
            if ($meta && !$isLand) foreach (json_decode((string)$meta['colors'], true) ?: [] as $color) $colors[$color] = true;
            if ($isLand) continue;
            $share = ((int)($df[$card['name']] ?? 1)) / $total;
            if ((int)($df[$card['name']] ?? 0) < 2 && count($cards) > 4) continue;
            $score = [min(3, (int)$card['quantity']), -$share];
            if ($best === null || $score > $best[0]) $best = [$score, $card['name']];
        }
        $colorKey = implode('', array_values(array_intersect(['W', 'U', 'B', 'R', 'G'], array_keys($colors))));
        $signature = $best[1] ?? '';
        $label = deckColorComboName(str_split($colorKey) ?: []) . ($signature !== '' ? ' · ' . explode(' // ', $signature)[0] : '');
        $updated += deckQuery('UPDATE meta_decks SET colors=?, signature=?, archetype=? WHERE id=?', [$colorKey, $signature, $label, $deckId])->rowCount();
    }
    return $updated;
}

/**
 * Busca os eventos novos do formato e recalcula os arquétipos. Challenges e qualifiers (32–64 decks) vêm antes das ligas.
 * $log recebe mensagens de andamento (usado pela linha de comando).
 */
function metaSync(string $formatKey, int $maxEvents = 16, ?callable $log = null): array
{
    metaSchema();
    $format = deckFormatInfo($formatKey);
    $slug = metaSlug($format);
    if ($slug === null || $format['key'] !== $formatKey) throw new RuntimeException('O MTGO não publica listas de ' . $format['name'] . '.');
    $log ??= static function (string $text): void {};
    deckQuery("INSERT INTO meta_formats(format,attempted_at) VALUES (?,now()) ON CONFLICT (format) DO UPDATE SET attempted_at=now()", [$formatKey]);
    $events = metaIndexEvents($slug);
    $known = array_flip(deckQuery('SELECT id FROM meta_events WHERE format=?', [$formatKey])->fetchAll(PDO::FETCH_COLUMN));
    $fresh = array_values(array_filter($events, fn($e) => !isset($known[$e['id']]) && $e['date'] >= date('Y-m-d', strtotime('-' . META_KEEP_DAYS . ' days'))));
    // Poucos eventos grandes dão mais decks que muitas ligas (cada liga publica poucos 5-0).
    usort($fresh, fn($a, $b) => [$a['kind'] === 'league', $b['date']] <=> [$b['kind'] === 'league', $a['date']]);
    $fresh = array_slice($fresh, 0, $maxEvents);
    $decks = 0; $done = 0;
    foreach ($fresh as $event) {
        $data = metaFetchEvent($event['id']);
        if ($data === null) { $log('Sem lista: ' . $event['id']); continue; }
        $stored = metaStoreEvent($formatKey, $event, $data);
        $decks += $stored; $done++;
        $log(sprintf('%s: %d decks', $event['id'], $stored));
        usleep(350000);
    }
    $resolved = metaResolveNames();
    // Mantém só o período recente: o meta muda com banimentos e coleções novas.
    deckQuery("DELETE FROM meta_events WHERE format=? AND event_date < current_date - (?::int * interval '1 day')", [$formatKey, META_KEEP_DAYS]);
    metaNameArchetypes($formatKey);
    $totals = deckQuery('SELECT COUNT(*) decks, COUNT(DISTINCT event_id) events FROM meta_decks WHERE format=?', [$formatKey])->fetch();
    deckQuery("INSERT INTO meta_formats(format,synced_at,decks,events,message) VALUES (?,now(),?,?,?) ON CONFLICT (format) DO UPDATE SET synced_at=now(),decks=excluded.decks,events=excluded.events,message=excluded.message",
        [$formatKey, (int)$totals['decks'], (int)$totals['events'], $done . ' evento(s) novo(s), ' . $decks . ' deck(s).']);
    return ['events' => $done, 'decks' => $decks, 'resolved' => $resolved, 'total_decks' => (int)$totals['decks'], 'total_events' => (int)$totals['events']];
}

function metaLockPath(string $formatKey): string
{
    $config = require __DIR__ . '/config.php';
    $dir = rtrim((string)$config['storage_dir'], '/') . '/meta';
    @mkdir($dir, 0775, true);
    return $dir . '/' . preg_replace('/[^a-z]/', '', $formatKey) . '.lock';
}

function metaRunning(string $formatKey): bool
{
    $path = metaLockPath($formatKey);
    $handle = @fopen($path, 'c');
    if (!$handle) return false;
    $free = @flock($handle, LOCK_EX | LOCK_NB);
    if ($free) @flock($handle, LOCK_UN);
    fclose($handle);
    return !$free;
}

/** Situação do meta de um formato: decks guardados, última busca e se há uma busca em andamento. */
function metaStatus(string $formatKey): array
{
    metaSchema();
    $row = deckQuery('SELECT * FROM meta_formats WHERE format=?', [$formatKey])->fetch() ?: [];
    $synced = !empty($row['synced_at']) ? strtotime((string)$row['synced_at']) : null;
    return ['decks' => (int)($row['decks'] ?? 0), 'events' => (int)($row['events'] ?? 0), 'synced_at' => $synced,
        'attempted_at' => !empty($row['attempted_at']) ? strtotime((string)$row['attempted_at']) : null,
        'stale' => $synced === null || $synced < time() - META_MAX_AGE_HOURS * 3600, 'running' => metaRunning($formatKey), 'message' => (string)($row['message'] ?? '')];
}

/** Dispara a busca em segundo plano (php bin/sync_meta.php formato). */
function metaLaunch(string $formatKey): bool
{
    if (metaRunning($formatKey) || !function_exists('proc_open')) return false;
    $config = require __DIR__ . '/config.php';
    $logPath = rtrim((string)$config['storage_dir'], '/') . '/meta/sync.log';
    $command = [PHP_BINDIR . '/php', __DIR__ . '/bin/sync_meta.php', $formatKey];
    $shell = 'nohup ' . implode(' ', array_map('escapeshellarg', $command)) . ' >> ' . escapeshellarg($logPath) . ' 2>&1 < /dev/null &';
    $process = @proc_open(['/bin/sh', '-c', $shell], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, __DIR__ . '/bin');
    if (!is_resource($process)) return false;
    @proc_close($process);
    return true;
}

/** Busca o meta quando ele está velho ou nunca foi buscado; uma tentativa falha espera 30 minutos. */
function metaEnsure(array $format): array
{
    if (metaSlug($format) === null) return ['decks' => 0, 'events' => 0, 'synced_at' => null, 'stale' => false, 'running' => false, 'message' => '', 'available' => false];
    $status = metaStatus($format['key']);
    $recentTry = $status['attempted_at'] !== null && $status['attempted_at'] > time() - 1800;
    if ($status['stale'] && !$status['running'] && !$recentTry && metaLaunch($format['key'])) $status['running'] = true;
    return $status + ['available' => true];
}

/**
 * Presença de cada carta no formato: [logical_id => ['name','decks','share','avg','side_share']] e o total de decks.
 * Guardada em cache até a próxima busca.
 */
function metaCardStats(string $formatKey): array
{
    metaSchema();
    $stamp = (string)deckQuery('SELECT COALESCE(synced_at::text,\'\') FROM meta_formats WHERE format=?', [$formatKey])->fetchColumn();
    return catalogCached('meta-stats-v1-' . $formatKey . '-' . $stamp, function () use ($formatKey): array {
        $total = (int)deckQuery('SELECT COUNT(*) FROM meta_decks WHERE format=?', [$formatKey])->fetchColumn();
        $rows = deckQuery("SELECT mc.logical_id::text lid, MIN(mc.name) name,
                COUNT(DISTINCT mc.deck_id) FILTER (WHERE NOT mc.sideboard) main_decks,
                COUNT(DISTINCT mc.deck_id) FILTER (WHERE mc.sideboard) side_decks,
                AVG(mc.quantity) FILTER (WHERE NOT mc.sideboard) avg_main, bool_or(mc.basic) basic
            FROM meta_deck_cards mc JOIN meta_decks d ON d.id=mc.deck_id
            WHERE d.format=? AND mc.logical_id IS NOT NULL GROUP BY mc.logical_id", [$formatKey])->fetchAll();
        $cards = [];
        foreach ($rows as $row) {
            $cards[$row['lid']] = ['name' => $row['name'], 'decks' => (int)$row['main_decks'], 'share' => $total ? (int)$row['main_decks'] / $total : 0.0,
                'avg' => $row['avg_main'] !== null ? round((float)$row['avg_main'], 1) : 0.0, 'side_share' => $total ? (int)$row['side_decks'] / $total : 0.0, 'basic' => in_array($row['basic'], [true, 't', 1, '1'], true)];
        }
        return ['total' => $total, 'cards' => $cards];
    }, 7 * 86400);
}

/**
 * Decks do meta mais parecidos com a lista: Jaccard ponderado pelas cópias (Σ min / Σ max), sem terrenos básicos.
 * $mine: [logical_id => cópias] das cartas do deck (sem básicos).
 */
function metaSimilarDecks(string $formatKey, array $mine, int $limit = 8): array
{
    $mine = array_filter($mine, fn($qty) => $qty > 0);
    if (!$mine) return [];
    $myTotal = array_sum($mine);
    $rows = deckQuery("WITH mine(lid, qty) AS (SELECT * FROM unnest(?::uuid[], ?::int[]))
        SELECT d.id, d.player, d.rank, d.wins, d.losses, d.colors, d.archetype, d.main_count, d.core_count, d.side_count,
            e.name event_name, e.event_date, e.url, e.kind,
            SUM(LEAST(mc.quantity, m.qty))::int shared, COUNT(*)::int shared_cards
        FROM meta_decks d JOIN meta_events e ON e.id=d.event_id
        JOIN meta_deck_cards mc ON mc.deck_id=d.id AND NOT mc.sideboard AND NOT mc.basic
        JOIN mine m ON m.lid=mc.logical_id
        WHERE d.format=?
        GROUP BY d.id, e.name, e.event_date, e.url, e.kind
        ORDER BY SUM(LEAST(mc.quantity, m.qty))::float / NULLIF(d.core_count + ? - SUM(LEAST(mc.quantity, m.qty)), 0) DESC NULLS LAST, e.event_date DESC
        LIMIT ?", ['{' . implode(',', array_keys($mine)) . '}', '{' . implode(',', array_values($mine)) . '}', $formatKey, $myTotal, $limit])->fetchAll();
    foreach ($rows as &$row) {
        $union = max(1, (int)$row['core_count'] + $myTotal - (int)$row['shared']);
        $row['similarity'] = (int)$row['shared'] / $union;
    }
    unset($row);
    return $rows;
}

/**
 * "Combina com o seu deck": para cada carta dos decks parecidos, a presença neles (ponderada pela semelhança)
 * menos a presença no formato inteiro. Positivo = aparece mais nas listas como a sua do que no formato em geral.
 * Devolve [logical_id => ['score' => lift, 'share' => presença nos parecidos, 'format_share' => presença no formato]].
 */
function metaDeckSynergy(string $formatKey, array $mine, int $neighbours = 30): array
{
    $similar = metaSimilarDecks($formatKey, $mine, $neighbours);
    $similar = array_values(array_filter($similar, fn($row) => $row['similarity'] >= 0.05));
    if (!$similar) return [];
    $weights = [];
    foreach ($similar as $row) $weights[(int)$row['id']] = $row['similarity'];
    $weightTotal = array_sum($weights);
    $rows = deckQuery('SELECT deck_id, logical_id::text lid FROM meta_deck_cards WHERE deck_id = ANY(?::bigint[]) AND NOT sideboard AND NOT basic AND logical_id IS NOT NULL',
        ['{' . implode(',', array_keys($weights)) . '}'])->fetchAll();
    $shares = [];
    foreach ($rows as $row) $shares[$row['lid']] = ($shares[$row['lid']] ?? 0) + $weights[(int)$row['deck_id']];
    $stats = metaCardStats($formatKey)['cards'];
    $out = [];
    foreach ($shares as $lid => $weight) {
        $share = $weight / $weightTotal;
        $formatShare = (float)($stats[$lid]['share'] ?? 0);
        $out[$lid] = ['score' => round($share - $formatShare, 4), 'share' => round($share, 4), 'format_share' => round($formatShare, 4)];
    }
    return $out;
}

/**
 * Arquétipos do formato com a participação no meta e o melhor deck de cada um que dá para montar com a coleção.
 */
function metaArchetypes(string $formatKey, int $limit = 14, int $excludeDeckId = 0): array
{
    $total = max(1, (int)deckQuery('SELECT COUNT(*) FROM meta_decks WHERE format=?', [$formatKey])->fetchColumn());
    $rows = deckQuery("SELECT archetype, MIN(colors) colors, COUNT(*)::int decks,
            SUM(COALESCE(wins,0))::int wins, SUM(COALESCE(losses,0))::int losses,
            (array_agg(id ORDER BY rank NULLS LAST, id DESC))[1] sample_id
        FROM meta_decks WHERE format=? AND archetype<>'' GROUP BY archetype ORDER BY COUNT(*) DESC LIMIT ?", [$formatKey, $limit])->fetchAll();
    $coverage = metaCoverage($formatKey, $excludeDeckId);
    foreach ($rows as &$row) {
        $row['share'] = (int)$row['decks'] / $total;
        $best = null;
        foreach ($coverage as $deck) if ($deck['archetype'] === $row['archetype'] && ($best === null || $deck['coverage'] > $best['coverage'])) $best = $deck;
        $row['best'] = $best;
    }
    unset($row);
    return $rows;
}

/**
 * Quanto de cada deck do meta a coleção cobre: cópias livres (fora de outros decks) sobre as cartas da lista principal.
 * Terrenos básicos contam como tidos: qualquer um tem.
 */
function metaCoverage(string $formatKey, int $excludeDeckId = 0): array
{
    static $cache = [];
    $key = $formatKey . '|' . $excludeDeckId;
    if (isset($cache[$key])) return $cache[$key];
    $userId = deckOwnerId();
    if ($userId < 1) return $cache[$key] = [];
    // As cópias do deck aberto não contam como "em uso": elas já estão com você para esta lista.
    $rows = deckQuery(deckOwnedSql($excludeDeckId) . ", free AS (SELECT o.logical_id, GREATEST(o.owned - COALESCE(u.used,0), 0) free FROM owned o LEFT JOIN used u ON u.logical_id=o.logical_id)
        SELECT d.id, d.archetype, d.colors, d.player, d.rank, d.wins, d.losses, d.main_count, e.name event_name, e.event_date,
            SUM(CASE WHEN mc.basic THEN mc.quantity ELSE LEAST(mc.quantity, COALESCE(f.free,0)) END)::int have
        FROM meta_decks d JOIN meta_events e ON e.id=d.event_id
        JOIN meta_deck_cards mc ON mc.deck_id=d.id AND NOT mc.sideboard
        LEFT JOIN free f ON f.logical_id=mc.logical_id
        WHERE d.format=? GROUP BY d.id, e.name, e.event_date", [$formatKey])->fetchAll();
    foreach ($rows as &$row) $row['coverage'] = (int)$row['have'] / max(1, (int)$row['main_count']);
    unset($row);
    usort($rows, fn($a, $b) => [$b['coverage'], $b['event_date']] <=> [$a['coverage'], $a['event_date']]);
    return $cache[$key] = $rows;
}

/** Média de terrenos e curva das mágicas (faixas 1–7) de alguns decks do meta. */
function metaDeckShape(array $deckIds): array
{
    $deckIds = array_values(array_filter(array_map('intval', $deckIds)));
    if (!$deckIds) return ['decks' => 0, 'lands' => 0.0, 'curve' => []];
    $rows = deckQuery("SELECT mc.deck_id, mc.quantity, info.is_land, info.cmc FROM meta_deck_cards mc
        JOIN LATERAL (SELECT split_part(COALESCE(c.type_line,''),' // ',1) ILIKE '%Land%' is_land, c.cmc FROM cards c WHERE COALESCE(c.oracle_id,c.id)=mc.logical_id LIMIT 1) info ON true
        WHERE mc.deck_id = ANY(?::bigint[]) AND NOT mc.sideboard", ['{' . implode(',', $deckIds) . '}'])->fetchAll();
    $lands = 0; $curve = array_fill_keys(range(1, 7), 0); $decks = [];
    foreach ($rows as $row) {
        $decks[(int)$row['deck_id']] = true;
        if (in_array($row['is_land'], [true, 't', 1, '1'], true)) { $lands += (int)$row['quantity']; continue; }
        $curve[max(1, min(7, (int)round((float)$row['cmc'])))] += (int)$row['quantity'];
    }
    $count = max(1, count($decks));
    return ['decks' => count($decks), 'lands' => $lands / $count, 'curve' => array_map(fn($n) => $n / $count, $curve)];
}

/** Lista de um deck do meta com a situação de cada carta na coleção. */
function metaDeckCards(int $metaDeckId, int $excludeDeckId = 0): array
{
    return deckQuery(deckOwnedSql($excludeDeckId) . ", free AS (SELECT o.logical_id, o.owned, GREATEST(o.owned - COALESCE(u.used,0), 0) free FROM owned o LEFT JOIN used u ON u.logical_id=o.logical_id)
        SELECT mc.name, mc.quantity, mc.sideboard, mc.basic, mc.logical_id::text logical_id, COALESCE(f.owned,0) owned, COALESCE(f.free,0) free,
            (SELECT c.id FROM cards c WHERE COALESCE(c.oracle_id,c.id)=mc.logical_id ORDER BY (c.lang='en') DESC, (c.local_image IS NOT NULL) DESC, c.released_at DESC NULLS LAST LIMIT 1)::text card_id,
            (SELECT c.type_line FROM cards c WHERE COALESCE(c.oracle_id,c.id)=mc.logical_id LIMIT 1) type_line,
            (SELECT c.mana_cost FROM cards c WHERE COALESCE(c.oracle_id,c.id)=mc.logical_id LIMIT 1) mana_cost
        FROM meta_deck_cards mc LEFT JOIN free f ON f.logical_id=mc.logical_id
        WHERE mc.deck_id=? ORDER BY mc.sideboard, mc.basic, mc.name", [$metaDeckId])->fetchAll();
}

function metaDeck(int $metaDeckId): ?array
{
    metaSchema();
    return deckQuery('SELECT d.*, e.name event_name, e.event_date, e.url FROM meta_decks d JOIN meta_events e ON e.id=d.event_id WHERE d.id=?', [$metaDeckId])->fetch() ?: null;
}

/** Texto da lista no formato de importação ("4 Nome", linha vazia, "Sideboard"). */
function metaDeckText(int $metaDeckId): string
{
    $main = []; $side = [];
    foreach (deckQuery('SELECT name, quantity, sideboard FROM meta_deck_cards WHERE deck_id=? ORDER BY basic, name', [$metaDeckId])->fetchAll() as $row) {
        if (in_array($row['sideboard'], [true, 't', 1, '1'], true)) $side[] = $row['quantity'] . ' ' . $row['name'];
        else $main[] = $row['quantity'] . ' ' . $row['name'];
    }
    return implode("\n", $main) . ($side ? "\n\nSideboard\n" . implode("\n", $side) : '');
}

/** Resultado de um deck do meta em texto curto ("5-0", "3º lugar"). */
function metaDeckResult(array $deck): string
{
    if (!empty($deck['rank'])) return (int)$deck['rank'] . 'º lugar';
    if ($deck['wins'] !== null && $deck['losses'] !== null) return (int)$deck['wins'] . '-' . (int)$deck['losses'];
    return '';
}
