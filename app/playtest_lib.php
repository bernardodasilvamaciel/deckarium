<?php
declare(strict_types=1);

/**
 * Mesas compartilhadas da Mesa de teste: até 4 jogadores, cada um com um deck da própria conta.
 *
 * Cada navegador continua dono da própria partida (mão, grimório, campo, vida): ele manda o que é público (campo,
 * cemitério, exílio, zona de comando, contagens, vida) e, só para ele mesmo, o estado completo para retomar a partida
 * ao recarregar. A mesa guarda o que é de todos: quem está sentado, de quem é a vez, a fase e os eventos (registro,
 * dano entre jogadores, revelações, dados, chat).
 *
 * A partida em si corre num WebSocket, no serviço Node.js da pasta realtime/ (em /realtime/mesa). O PHP abre a mesa,
 * senta os jogadores (escolha do deck), entrega o bilhete assinado que o socket usa para entrar e avisa o serviço
 * quando muda algo (NOTIFY playtest).
 */

const PLAYTEST_MAX_SEATS = 4;
const PLAYTEST_TICKET_SECONDS = 43200;

function playtestSchema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    // Só cria quando falta (DDL trava as tabelas e a mesa é usada o tempo todo).
    if (db()->query("SELECT to_regclass('public.playtest_events') IS NOT NULL AND to_regclass('public.app_secrets') IS NOT NULL")->fetchColumn()) return;
    db()->exec("CREATE TABLE IF NOT EXISTS playtest_tables (
            id text PRIMARY KEY, host_user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            format text NOT NULL DEFAULT 'commander', status text NOT NULL DEFAULT 'lobby' CHECK (status IN ('lobby','playing')),
            game int NOT NULL DEFAULT 0, starting_seat smallint NULL, turn_seat smallint NULL, turn_number int NOT NULL DEFAULT 0,
            phase smallint NOT NULL DEFAULT 0, version bigint NOT NULL DEFAULT 1,
            created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now());
        CREATE TABLE IF NOT EXISTS playtest_seats (
            table_id text NOT NULL REFERENCES playtest_tables(id) ON DELETE CASCADE,
            seat smallint NOT NULL CHECK (seat BETWEEN 0 AND 3),
            user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            deck_id bigint NOT NULL REFERENCES builder_decks(id) ON DELETE CASCADE,
            public_state jsonb NULL, private_state jsonb NULL, state_version bigint NOT NULL DEFAULT 0,
            eliminated boolean NOT NULL DEFAULT false, joined_at timestamptz NOT NULL DEFAULT now(), last_seen timestamptz NOT NULL DEFAULT now(),
            PRIMARY KEY (table_id, seat), UNIQUE (table_id, user_id));
        CREATE TABLE IF NOT EXISTS playtest_events (
            id bigserial PRIMARY KEY, table_id text NOT NULL REFERENCES playtest_tables(id) ON DELETE CASCADE,
            seat smallint NULL, kind text NOT NULL, payload jsonb NOT NULL DEFAULT '{}', created_at timestamptz NOT NULL DEFAULT now());
        CREATE INDEX IF NOT EXISTS playtest_events_table_idx ON playtest_events(table_id, id);
        CREATE TABLE IF NOT EXISTS app_secrets (name text PRIMARY KEY, value text NOT NULL, created_at timestamptz NOT NULL DEFAULT now());");
}

/** Segredo compartilhado com o serviço de tempo real, criado na primeira vez e guardado no banco. */
function playtestSecret(): string
{
    static $secret = null;
    if ($secret !== null) return $secret;
    playtestSchema();
    deckQuery("INSERT INTO app_secrets(name, value) VALUES ('realtime', ?) ON CONFLICT (name) DO NOTHING", [bin2hex(random_bytes(32))]);
    return $secret = (string)deckQuery("SELECT value FROM app_secrets WHERE name='realtime'")->fetchColumn();
}

/** Bilhete do WebSocket: quem é, em qual mesa e até quando (base64url do JSON + HMAC-SHA256), conferido em realtime/server.js. */
function playtestTicket(int $userId, string $token): string
{
    $encode = static fn(string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $body = $encode(json_encode(['u' => $userId, 't' => $token, 'e' => time() + PLAYTEST_TICKET_SECONDS]));
    return $body . '.' . $encode(hash_hmac('sha256', $body, playtestSecret(), true));
}

/** Código curto e difícil de adivinhar para o link da mesa. */
function playtestToken(): string
{
    $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $token = '';
    for ($i = 0; $i < 12; $i++) $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $token;
}

function playtestValidToken(string $token): bool
{
    return (bool)preg_match('/^[A-Za-z0-9]{12}$/', $token);
}

function playtestTable(string $token): ?array
{
    if (!playtestValidToken($token)) return null;
    playtestSchema();
    return deckQuery('SELECT * FROM playtest_tables WHERE id=?', [$token])->fetch() ?: null;
}

/** Avisa o serviço de tempo real que a mesa mudou (ele relê assentos e eventos e repassa aos jogadores). */
function playtestNotify(string $token): void
{
    deckQuery("SELECT pg_notify('playtest', ?)", [$token]);
}

/** Registra um evento e avisa a mesa. */
function playtestEvent(string $token, ?int $seat, string $kind, array $payload = []): int
{
    $id = (int)deckQuery('INSERT INTO playtest_events(table_id,seat,kind,payload) VALUES (?,?,?,?::jsonb) RETURNING id',
        [$token, $seat, $kind, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)])->fetchColumn();
    deckQuery('UPDATE playtest_tables SET updated_at=now() WHERE id=?', [$token]);
    playtestNotify($token);
    return $id;
}

/** Muda algo da mesa (assentos, vez, fase): sobe a versão e avisa. */
function playtestTouch(string $token): void
{
    deckQuery('UPDATE playtest_tables SET version=version+1, updated_at=now() WHERE id=?', [$token]);
    playtestNotify($token);
}

/** Decks da pessoa que podem sentar numa mesa do formato: com cartas aprovadas e, se o formato pede, comandante. */
function playtestEligibleDecks(int $userId, array $format): array
{
    $rows = deckQuery("SELECT d.id, d.name, d.commander_id, c.name commander, (SELECT COALESCE(SUM(quantity),0) FROM builder_items i WHERE i.deck_id=d.id AND i.stage='deck')::int cards
        FROM builder_decks d LEFT JOIN cards c ON c.id=d.commander_id WHERE d.user_id=? AND d.format=? ORDER BY d.id DESC", [$userId, $format['key']])->fetchAll();
    return array_values(array_filter($rows, fn($row) => (int)$row['cards'] > 0 && (!deckFormatHasLeader($format) || $row['commander_id'])));
}

/** Assentos com o que todos podem ver de cada jogador (sem o estado privado). */
function playtestSeats(string $token): array
{
    return deckQuery("SELECT s.seat, s.user_id, s.deck_id, s.state_version, s.eliminated,
            (s.last_seen > now() - interval '40 seconds') online,
            COALESCE(NULLIF(u.display_name,''), NULLIF(split_part(u.full_name,' ',1),''), u.username) player, u.username, d.name deck_name,
            c.id commander_id, c.name commander_name
        FROM playtest_seats s JOIN users u ON u.id=s.user_id JOIN builder_decks d ON d.id=s.deck_id LEFT JOIN cards c ON c.id=d.commander_id
        WHERE s.table_id=? ORDER BY s.seat", [$token])->fetchAll();
}

/** Mesas em aberto em que a pessoa está sentada (as mais recentes primeiro), com os assentos de cada uma. */
function playtestOpenTables(int $userId, int $limit = 12): array
{
    playtestSchema();
    $rows = deckQuery("SELECT t.*, s.seat my_seat, s.eliminated my_eliminated FROM playtest_seats s JOIN playtest_tables t ON t.id=s.table_id
        WHERE s.user_id=? AND t.updated_at > now() - interval '2 days' ORDER BY t.updated_at DESC LIMIT " . max(1, $limit), [$userId])->fetchAll();
    foreach ($rows as &$row) $row['seats'] = playtestSeats((string)$row['id']);
    return $rows;
}

/** "agora", "há 5 min", "há 3 h", "ontem". */
function playtestAgo(string $timestamp): string
{
    $seconds = max(0, time() - (int)strtotime($timestamp));
    if ($seconds < 60) return 'agora';
    if ($seconds < 3600) return 'há ' . intdiv($seconds, 60) . ' min';
    if ($seconds < 86400) return 'há ' . intdiv($seconds, 3600) . ' h';
    return 'ontem';
}

/** Cartões das mesas em aberto: formato, situação, quem está sentado e o botão para voltar. */
function playtestTableCards(array $tables, int $userId): string
{
    $html = '';
    foreach ($tables as $table) {
        $format = deckFormatInfo((string)$table['format']);
        $bySeat = [];
        foreach ($table['seats'] as $seat) $bySeat[(int)$seat['seat']] = $seat;
        $turn = $table['turn_seat'] === null ? null : ($bySeat[(int)$table['turn_seat']] ?? null);
        $myTurn = $table['status'] === 'playing' && $turn && (int)$turn['user_id'] === $userId;
        $status = $table['status'] === 'playing'
            ? 'Partida ' . (int)$table['game'] . ' · turno ' . (int)$table['turn_number'] . ($turn ? ($myTurn ? ' · sua vez' : ' · vez de ' . $turn['player']) : '')
            : 'No lobby · ' . count($table['seats']) . ' de ' . PLAYTEST_MAX_SEATS . ' lugares';
        $players = '';
        foreach ($table['seats'] as $seat) {
            $online = in_array($seat['online'], [true, 't', 1, '1'], true);
            $players .= '<li' . ($online ? ' class="is-online"' : '') . '>'
                . ($seat['commander_id'] ? '<img src="/image.php?id=' . h(rawurlencode((string)$seat['commander_id'])) . '&amp;size=small" alt="" width="36" height="50" loading="lazy">' : '<span class="play-table-noart" aria-hidden="true">♦</span>')
                . '<span><strong>' . h((string)$seat['player']) . ((int)$seat['user_id'] === $userId ? ' (você)' : '') . '</strong><small>' . h((string)$seat['deck_name']) . '</small></span>'
                . '<i title="' . ($online ? 'Na mesa agora' : 'Fora da mesa') . '"></i></li>';
        }
        $html .= '<article class="play-table' . ($myTurn ? ' is-my-turn' : '') . '">'
            . '<div class="play-table-head"><b class="deck-format-chip">' . h($format['name']) . '</b><span>' . h($status) . '</span></div>'
            . '<ul class="play-table-seats">' . $players . '</ul>'
            . '<div class="play-table-foot"><small>Atualizada ' . h(playtestAgo((string)$table['updated_at'])) . '</small>'
            . '<a class="primary-link" href="/deck_playtest.php?mesa=' . h((string)$table['id']) . '">' . ($table['status'] === 'playing' ? 'Voltar à partida' : 'Voltar ao lobby') . '</a></div></article>';
    }
    return $html;
}

/** Mesas paradas há mais de dois dias somem (com assentos e eventos). */
function playtestCleanup(): void
{
    if (random_int(1, 20) === 1) deckQuery("DELETE FROM playtest_tables WHERE updated_at < now() - interval '2 days'");
}
