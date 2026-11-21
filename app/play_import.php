<?php
declare(strict_types=1);
/**
 * "Traga seu deck" da página inicial: importa a lista colada (Moxfield, Arena, texto) como deck novo e já leva para a
 * mesa — sozinho (mode=solo) ou abrindo uma mesa compartilhada para chamar amigos (mode=friends). Se faltar algo para
 * jogar (comandante, cartas reconhecidas), leva para o deck com o aviso.
 */
require __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require __DIR__ . '/deck_library.php';
require __DIR__ . '/playtest_lib.php';

$user = authRequireLogin();
$userId = (int)$user['id'];
$_SESSION['builder_csrf'] ??= bin2hex(random_bytes(24));
$back = '/#trazer';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { header('Location: ' . $back); exit; }
try {
    if (!hash_equals((string)$_SESSION['builder_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Recarregue a página e tente de novo.');
    deckSchema();
    playtestSchema();
    $result = deckImportList((string)($_POST['name'] ?? ''), (string)($_POST['decklist'] ?? ''), (string)($_POST['commander'] ?? ''), (string)($_POST['format'] ?? DECK_DEFAULT_FORMAT));
    $deckId = (int)$result['id'];
    $format = $result['format'];
    $notes = [];
    if ($result['unmatched']) $notes[] = 'Não localizadas: ' . implode(', ', array_slice($result['unmatched'], 0, 8)) . (count($result['unmatched']) > 8 ? '…' : '') . '.';
    if ($result['trimmed']) $notes[] = 'Cópias acima do limite do ' . $format['name'] . ' foram reduzidas.';
    $playable = in_array($deckId, array_map(fn($row) => (int)$row['id'], playtestEligibleDecks($userId, $format)), true);
    if (!$playable) {
        $_SESSION['play_notice'] = 'Deck importado, mas ainda não dá para jogar: ' . (deckFormatHasLeader($format) && !$result['commander'] ? 'escolha ' . ($format['leader'] === 'oathbreaker' ? 'o oathbreaker' : 'a comandante') . ' do deck.' : 'nenhuma carta da lista foi reconhecida.') . ' ' . implode(' ', $notes);
        session_write_close();
        header('Location: /decks.php?deck=' . $deckId . (deckFormatHasLeader($format) && !$result['commander'] ? '&choose=1' : ''));
        exit;
    }
    if ($notes) $_SESSION['play_notice'] = implode(' ', $notes);
    session_write_close();
    if (($_POST['mode'] ?? 'solo') === 'friends') {
        $token = playtestCreateTable($userId, $deckId);
        header('Location: /deck_playtest.php?mesa=' . $token);
    } else {
        header('Location: /deck_playtest.php?deck=' . $deckId);
    }
    exit;
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    if (!$e instanceof RuntimeException || $e instanceof PDOException) error_log('Trazer deck: ' . $e->getMessage());
    $_SESSION['play_error'] = $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Não foi possível importar a lista agora.';
    $_SESSION['play_form'] = array_intersect_key($_POST, array_flip(['name', 'decklist', 'commander', 'format']));
    session_write_close();
    header('Location: ' . $back);
    exit;
}
