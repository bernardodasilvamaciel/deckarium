<?php
declare(strict_types=1);
/**
 * API das mesas compartilhadas da Mesa de teste (JSON). A página é deck_playtest.php?mesa=CÓDIGO.
 *
 * POST (com csrf): create (abre a mesa com um deck) e join (senta com um deck). O resto da partida — estados, eventos,
 * vez, fase, lobby, sair — corre no WebSocket do serviço de tempo real (realtime/, em /realtime/mesa).
 * GET  ?table=…  devolve um bilhete novo para o WebSocket.
 */
require __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require __DIR__ . '/deck_library.php';
require __DIR__ . '/playtest_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function playtestRespond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$user = authUser();
if (!$user) playtestRespond(['ok' => false, 'message' => 'Entre na sua conta para jogar na mesa.'], 401);
$userId = (int)$user['id'];
$_SESSION['builder_csrf'] ??= bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['builder_csrf'];
playtestSchema();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Recarregue a página.');
        session_write_close();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create') {
            playtestCleanup();
            $deck = deckQuery('SELECT * FROM builder_decks WHERE id=? AND user_id=?', [(int)($_POST['deck'] ?? 0), $userId])->fetch();
            if (!$deck) throw new RuntimeException('Deck não encontrado na sua conta.');
            $format = deckFormatOf($deck);
            if (!in_array((int)$deck['id'], array_map(fn($row) => (int)$row['id'], playtestEligibleDecks($userId, $format)), true)) throw new RuntimeException('O deck precisa de cartas aprovadas' . (deckFormatHasLeader($format) ? ' e de comandante' : '') . ' para abrir uma mesa.');
            do { $token = playtestToken(); } while (deckQuery('SELECT 1 FROM playtest_tables WHERE id=?', [$token])->fetchColumn());
            db()->beginTransaction();
            deckQuery('INSERT INTO playtest_tables(id,host_user_id,format) VALUES (?,?,?)', [$token, $userId, $format['key']]);
            deckQuery('INSERT INTO playtest_seats(table_id,seat,user_id,deck_id) VALUES (?,0,?,?)', [$token, $userId, (int)$deck['id']]);
            db()->commit();
            playtestEvent($token, 0, 'join', ['deck' => (string)$deck['name']]);
            playtestRespond(['ok' => true, 'table' => $token, 'url' => '/deck_playtest.php?mesa=' . $token]);
        }

        $token = (string)($_POST['table'] ?? '');
        $table = playtestTable($token);
        if (!$table) throw new RuntimeException('Mesa não encontrada. O link pode ter expirado.');
        $format = deckFormatInfo((string)$table['format']);
        $seatRow = deckQuery('SELECT * FROM playtest_seats WHERE table_id=? AND user_id=?', [$token, $userId])->fetch() ?: null;
        $mySeat = $seatRow ? (int)$seatRow['seat'] : null;

        if ($action !== 'join') throw new RuntimeException('Ação inválida.');
        if ($mySeat !== null) playtestRespond(['ok' => true, 'seat' => $mySeat]);
        if ($table['status'] !== 'lobby') throw new RuntimeException('A partida já começou. Peça para quem abriu a mesa voltar ao lobby.');
        $deckId = (int)($_POST['deck'] ?? 0);
        $eligible = array_map(fn($row) => (int)$row['id'], playtestEligibleDecks($userId, $format));
        if (!in_array($deckId, $eligible, true)) throw new RuntimeException('Escolha um deck de ' . $format['name'] . ' seu, com cartas aprovadas' . (deckFormatHasLeader($format) ? ' e comandante' : '') . '.');
        db()->beginTransaction();
        try {
            deckQuery('SELECT id FROM playtest_tables WHERE id=? FOR UPDATE', [$token]);
            $taken = array_map('intval', deckQuery('SELECT seat FROM playtest_seats WHERE table_id=?', [$token])->fetchAll(PDO::FETCH_COLUMN));
            $free = array_values(array_diff(range(0, PLAYTEST_MAX_SEATS - 1), $taken));
            if (!$free) throw new RuntimeException('A mesa já tem ' . PLAYTEST_MAX_SEATS . ' jogadores.');
            deckQuery('INSERT INTO playtest_seats(table_id,seat,user_id,deck_id) VALUES (?,?,?,?)', [$token, $free[0], $userId, $deckId]);
            db()->commit();
        } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
        $deckName = (string)deckQuery('SELECT name FROM builder_decks WHERE id=?', [$deckId])->fetchColumn();
        playtestEvent($token, $free[0], 'join', ['deck' => $deckName]);
        playtestTouch($token);
        playtestRespond(['ok' => true, 'seat' => $free[0]]);
    }

    // Bilhete novo para o WebSocket (o da página vale 12 horas; a mesa pede outro se ele vencer).
    $token = (string)($_GET['table'] ?? '');
    $table = playtestTable($token);
    if (!$table) playtestRespond(['ok' => false, 'gone' => true, 'message' => 'A mesa foi encerrada.'], 404);
    if (!deckQuery('SELECT 1 FROM playtest_seats WHERE table_id=? AND user_id=?', [$token, $userId])->fetchColumn()) playtestRespond(['ok' => false, 'gone' => true, 'message' => 'Você não está mais sentado nesta mesa.'], 403);
    playtestRespond(['ok' => true, 'ticket' => playtestTicket($userId, $token)]);
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    if (!$e instanceof RuntimeException || $e instanceof PDOException) error_log('Mesa compartilhada: ' . $e->getMessage());
    playtestRespond(['ok' => false, 'message' => $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Não foi possível falar com a mesa agora.'], 422);
}
