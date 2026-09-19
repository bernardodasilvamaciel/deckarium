<?php
declare(strict_types=1);

/**
 * Ações rápidas de uma carta, usadas no catálogo, nas edições e nos comandantes:
 * guardar a impressão na coleção e mandar a carta para as candidatas de um deck.
 *
 * Regra do Commander: a identidade de cor da carta precisa caber na identidade da
 * comandante do deck. Decks fora da regra aparecem bloqueados, com o motivo, em vez
 * de sumirem da lista — assim fica claro por que aquela carta não serve ali.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/deck_library.php';

function cardActionColorMap(): array { return ['W' => t('branco'), 'U' => t('azul'), 'B' => t('preto'), 'R' => t('vermelho'), 'G' => t('verde')]; }

function cardActionColors(mixed $identity): array
{
    if (is_string($identity)) $identity = json_decode($identity, true) ?: [];
    return is_array($identity) ? array_values(array_intersect(['W', 'U', 'B', 'R', 'G'], $identity)) : [];
}

function cardActionColorNames(array $colors): string
{
    if (!$colors) return t('incolor');
    $names = cardActionColorMap();
    return implode(', ', array_map(fn($color) => $names[$color] ?? $color, $colors));
}

/** Decks do usuário com a identidade da comandante, para decidir onde a carta cabe. */
function cardActionDecks(int $userId): array
{
    if ($userId < 1) return [];
    return deckQuery("SELECT d.id, d.name, d.status, c.name AS commander, c.color_identity
        FROM builder_decks d LEFT JOIN cards c ON c.id = d.commander_id
        WHERE d.user_id = ? ORDER BY d.name", [$userId])->fetchAll();
}

/** Motivo de a carta não caber no deck, ou null quando ela cabe. */
function cardActionBlockReason(array $card, array $deck): ?string
{
    if (empty($deck['commander'])) return null; // Deck sem comandante ainda não tem identidade.
    $deckColors = cardActionColors($deck['color_identity']);
    $cardColors = cardActionColors($card['color_identity'] ?? []);
    $extra = array_diff($cardColors, $deckColors);
    if (!$extra) return null;
    return t('Fora da identidade :deck: a carta é :card.', ['deck' => cardActionColorNames($deckColors), 'card' => cardActionColorNames($cardColors)]);
}

/**
 * Executa a ação enviada pelo formulário e volta para a página de origem com o recado.
 * Chamada no topo das páginas que mostram as ações.
 */
function cardActionHandlePost(int $userId): void
{
    // O token dos formulários precisa existir já na primeira visita da página.
    if ($userId > 0) $_SESSION['builder_csrf'] ??= bin2hex(random_bytes(24));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !isset($_POST['card_action'])) return;
    $back = authSafeNext((string)($_POST['back'] ?? ''), '/');
    $redirect = function (string $message, bool $ok) use ($back): never {
        $_SESSION[$ok ? 'card_action_message' : 'card_action_error'] = $message;
        header('Location: ' . $back, true, 303);
        exit;
    };

    try {
        if ($userId < 1) throw new RuntimeException(t('Entre na sua conta para usar esta ação.'));
        if (!hash_equals((string)$_SESSION['builder_csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException(t('Sessão expirada. Recarregue a página e tente novamente.'));
        }
        $cardId = (string)($_POST['card'] ?? '');
        if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i', $cardId)) throw new RuntimeException(t('Impressão inválida.'));
        $card = deckQuery('SELECT * FROM cards WHERE id=?', [$cardId])->fetch();
        if (!$card) throw new RuntimeException(t('Carta não encontrada no acervo.'));

        if ($_POST['card_action'] === 'collection_add') {
            $quantity = max(1, min(99, (int)($_POST['quantity'] ?? 1)));
            $foil = ($_POST['foil'] ?? '') === '1';
            deckQuery("INSERT INTO builder_collection(user_id,scryfall_id,name,quantity,foil) VALUES (?,?,?,?,?)
                ON CONFLICT (user_id,scryfall_id,foil) DO UPDATE SET quantity = builder_collection.quantity + EXCLUDED.quantity",
                [$userId, $card['id'], $card['name'], $quantity, $foil ? 'true' : 'false']);
            $total = (int)deckQuery('SELECT quantity FROM builder_collection WHERE user_id=? AND scryfall_id=? AND foil=?',
                [$userId, $card['id'], $foil ? 'true' : 'false'])->fetchColumn();
            $redirect(t(':quantity cópia(s) :foilde :name (:set #:number) na coleção. Total desta impressão: :total.',
                ['quantity' => $quantity, 'foil' => $foil ? t('foil') . ' ' : '', 'name' => $card['name'],
                 'set' => strtoupper((string)$card['set_code']), 'number' => $card['collector_number'], 'total' => $total]), true);
        }

        if ($_POST['card_action'] === 'deck_add') {
            $deckId = max(0, (int)($_POST['deck'] ?? 0));
            $deck = deckQuery("SELECT d.*, c.name AS commander, c.color_identity FROM builder_decks d
                LEFT JOIN cards c ON c.id = d.commander_id WHERE d.id=? AND d.user_id=?", [$deckId, $userId])->fetch();
            if (!$deck) throw new RuntimeException(t('Escolha um deck seu.'));
            if ($reason = cardActionBlockReason($card, $deck)) throw new RuntimeException(t(':card não entra em :deck.', ['card' => $card['name'], 'deck' => $deck['name']]) . ' ' . $reason);
            $logical = $card['oracle_id'] ?: $card['id'];
            $already = deckQuery('SELECT 1 FROM builder_items i JOIN cards c ON c.id=i.card_id WHERE i.deck_id=? AND COALESCE(c.oracle_id,c.id)=?::uuid', [$deckId, $logical])->fetchColumn();
            $commanderLogical = $deck['commander_id'] ? deckQuery('SELECT COALESCE(oracle_id,id) FROM cards WHERE id=?', [$deck['commander_id']])->fetchColumn() : null;
            if ($already || $commanderLogical === $logical) throw new RuntimeException(t(':card já está em :deck.', ['card' => $card['name'], 'deck' => $deck['name']]));
            deckQuery("INSERT INTO builder_items(deck_id,card_id,stage,quantity) VALUES (?,?,'candidate',1)", [$deckId, $card['id']]);
            $redirect(t(':card foi para as candidatas de :deck.', ['card' => $card['name'], 'deck' => $deck['name']]), true);
        }

        throw new RuntimeException(t('Ação inválida.'));
    } catch (Throwable $e) {
        $redirect($e instanceof RuntimeException ? $e->getMessage() : t('Não foi possível concluir a ação.'), false);
    }
}

/** Recado da última ação, mostrado uma vez. */
function cardActionNotice(): string
{
    $html = '';
    if (!empty($_SESSION['card_action_message'])) {
        $html .= '<p class="notice ok" role="status">' . h((string)$_SESSION['card_action_message']) . '</p>';
        unset($_SESSION['card_action_message']);
    }
    if (!empty($_SESSION['card_action_error'])) {
        $html .= '<p class="notice error" role="alert">' . h((string)$_SESSION['card_action_error']) . '</p>';
        unset($_SESSION['card_action_error']);
    }
    return $html;
}

/** Botões de coleção e deck de uma carta. Sem login, convida a entrar. */
function cardActionsMenu(array $card, array $decks, int $userId, string $back): void
{
    $cardId = h((string)$card['id']);
    if ($userId < 1) {
        echo '<a class="card-actions-login" href="/login.php?next=' . h(rawurlencode($back)) . '">' . te('Entrar para guardar') . '</a>';
        return;
    }
    $csrf = h((string)($_SESSION['builder_csrf'] ?? ''));
    $hidden = '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="card" value="' . $cardId . '"><input type="hidden" name="back" value="' . h($back) . '">';
    $eligible = 0;
    foreach ($decks as $deck) if (cardActionBlockReason($card, $deck) === null) $eligible++;
    ?>
    <details class="card-actions-menu">
      <summary aria-label="<?= te('Guardar') ?> <?= h((string)$card['name']) ?>"><?= te('Guardar') ?></summary>
      <div class="card-actions-body">
        <form method="post" class="card-actions-form">
          <?= $hidden ?><input type="hidden" name="card_action" value="collection_add">
          <span class="card-actions-title"><?= te('Na minha coleção') ?></span>
          <span class="card-actions-row">
            <label class="sr-only" for="qty-<?= $cardId ?>"><?= te('Quantidade') ?></label>
            <input id="qty-<?= $cardId ?>" type="number" name="quantity" value="1" min="1" max="99" inputmode="numeric">
            <label class="card-actions-foil"><input type="checkbox" name="foil" value="1"> <?= te('Foil') ?></label>
            <button class="secondary-link"><?= te('Adicionar') ?></button>
          </span>
          <small><?= te('Guarda esta impressão:') ?> <?= h(strtoupper((string)$card['set_code'])) ?> #<?= h((string)$card['collector_number']) ?>.</small>
        </form>
        <form method="post" class="card-actions-form">
          <?= $hidden ?><input type="hidden" name="card_action" value="deck_add">
          <span class="card-actions-title"><?= te('Nas candidatas de um deck') ?></span>
          <?php if (!$decks): ?>
            <small><?= te('Você ainda não tem decks.') ?> <a href="/decks.php"><?= te('Criar um deck') ?></a>.</small>
          <?php else: ?>
            <span class="card-actions-row">
              <label class="sr-only" for="deck-<?= $cardId ?>"><?= te('Deck') ?></label>
              <select id="deck-<?= $cardId ?>" name="deck" <?= $eligible ? '' : 'disabled' ?>>
                <?php foreach ($decks as $deck): $reason = cardActionBlockReason($card, $deck); ?>
                  <option value="<?= (int)$deck['id'] ?>" <?= $reason ? 'disabled' : '' ?>><?= h((string)$deck['name']) ?><?= $reason ? ' — ' . t('fora da identidade') : '' ?></option>
                <?php endforeach; ?>
              </select>
              <button class="secondary-link" <?= $eligible ? '' : 'disabled' ?>><?= te('Adicionar') ?></button>
            </span>
            <small><?php if ($eligible): ?><?= te('Só aparecem liberados os decks cuja comandante aceita :colors.', ['colors' => cardActionColorNames(cardActionColors($card['color_identity'] ?? []))]) ?><?php else: ?><?= te('Nenhum deck seu aceita uma carta :colors.', ['colors' => cardActionColorNames(cardActionColors($card['color_identity'] ?? []))]) ?><?php endif; ?></small>
          <?php endif; ?>
        </form>
      </div>
    </details>
    <?php
}
