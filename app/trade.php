<?php
declare(strict_types=1);
/**
 * À venda: a lista de negociação do usuário e o link público dela.
 *
 * Dois modos: "soltas" (todas as cópias fora dos decks, recalculadas sozinhas)
 * e "escolhidas" (o usuário marca carta por carta, com preço e observação).
 */
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
require __DIR__ . '/trade_lib.php';
$authUser = authRequireLogin();
$userId = (int)$authUser['id'];
$_SESSION['trade_csrf'] ??= bin2hex(random_bytes(24));
$csrf = $_SESSION['trade_csrf'];
$message = $_SESSION['trade_message'] ?? ''; unset($_SESSION['trade_message']);
$error = '';
deckSchema();
$list = tradeListFor($userId, true);

/** Preço digitado como "12,50" ou "12.50"; vazio significa "a combinar". */
function tradePriceInput(string $raw): ?float
{
    $raw = trim(str_replace(['R$', ' '], '', $raw));
    if ($raw === '') return null;
    $value = (float)str_replace(',', '.', $raw);
    if (!is_numeric(str_replace(',', '.', $raw)) || $value < 0) throw new RuntimeException(t('Informe um preço válido, como 12,50.'));
    return round(min($value, 99999.99), 2);
}
function tradeCardInput(): array
{
    $cardId = (string)($_POST['card'] ?? '');
    if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i', $cardId)) throw new RuntimeException(t('Impressão inválida.'));
    return [$cardId, ($_POST['foil'] ?? '0') === '1'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException(t('Sessão expirada. Recarregue a página e tente novamente.'));
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'settings') {
            $mode = ($_POST['mode'] ?? 'free') === 'manual' ? 'manual' : 'free';
            tradeUpdate($userId, [
                'title' => mb_substr(trim((string)($_POST['title'] ?? '')), 0, 80),
                'intro' => mb_substr(trim((string)($_POST['intro'] ?? '')), 0, 600),
                'contact' => mb_substr(trim((string)($_POST['contact'] ?? '')), 0, 160),
                'mode' => $mode,
                'show_prices' => ($_POST['show_prices'] ?? '') === '1' ? 'true' : 'false',
            ]);
            $message = $mode === 'manual'
                ? t('Lista salva. Ela mostra apenas as cartas que você marcar.')
                : t('Lista salva. Ela mostra sozinha todas as cópias que sobram fora dos decks.');
        } elseif ($action === 'visibility') {
            $public = ($_POST['public'] ?? '') === '1';
            tradeUpdate($userId, ['is_public' => $public ? 'true' : 'false']);
            $message = $public ? t('Link ativo: quem tiver o endereço vê suas cartas à venda.') : t('Link desligado: a página volta a ser só sua.');
        } elseif ($action === 'regenerate') {
            tradeUpdate($userId, ['token' => tradeNewToken()]);
            $message = t('Novo link gerado. O endereço anterior parou de funcionar.');
        } elseif ($action === 'add_free') {
            $added = tradeAddFreeCopies($userId);
            $message = $added === 0 ? t('Nenhuma cópia solta para adicionar.') : t(':count versão(ões) adicionada(s) à seleção.', ['count' => number_format($added, 0, ',', '.')]);
        } elseif ($action === 'clear') {
            $removed = tradeClearItems($userId);
            $message = t(':count versão(ões) retirada(s) da lista.', ['count' => number_format($removed, 0, ',', '.')]);
        } elseif ($action === 'item') {
            [$cardId, $foil] = tradeCardInput();
            tradeSaveItem($userId, $cardId, $foil, max(1, (int)($_POST['quantity'] ?? 1)), tradePriceInput((string)($_POST['price'] ?? '')), (string)($_POST['note'] ?? ''));
            $message = t('Item atualizado.');
        } elseif ($action === 'remove') {
            [$cardId, $foil] = tradeCardInput();
            tradeRemoveItem($userId, $cardId, $foil);
            $message = t('Carta retirada da lista.');
        } else {
            throw new RuntimeException(t('Ação inválida.'));
        }
        $_SESSION['trade_message'] = $message;
        header('Location: /trade.php', true, 303);
        exit;
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : t('Não foi possível atualizar a lista. Nada foi alterado.');
    }
}

$list = tradeListFor($userId, true);
$mode = tradeMode($list);
$isPublic = tradeIsPublic($list);
$rows = tradeCards($list, false);
$publicRows = array_values(array_filter($rows, static fn(array $row): bool => (int)$row['available'] > 0));
$summary = tradeSummary($publicRows);
$freeCount = $mode === 'manual' ? count(tradeFreeCopies($userId)) : count($rows);
$publicUrl = tradePublicUrl($list);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cartas-a-venda-deckarium.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Scryfall ID', 'Quantity', 'Foil', 'Edição', 'Código', 'Número', 'Idioma', 'Preço (R$)', 'Observação'], ',', '"', '');
    foreach ($publicRows as $row) {
        fputcsv($out, [$row['name'], $row['id'], $row['available'], $row['is_foil'] ? 'foil' : 'normal', $row['set_name'], strtoupper((string)$row['set_code']),
            $row['collector_number'], strtoupper((string)$row['lang']), $row['final_price'] === null ? '' : number_format((float)$row['final_price'], 2, ',', ''), $row['note'] ?? ''], ',', '"', '');
    }
    fclose($out);
    exit;
}
session_write_close();
pageHeader(t('À venda'), t('Monte a lista de cartas que você quer vender ou trocar e compartilhe o link.'));
?>
<section class="hero"><div><h1><?= te('Cartas à venda') ?></h1><p><?= te('Um link público com o que você quer negociar. Suas cartas em decks ficam de fora quando a lista usa as cópias soltas.') ?></p></div><a class="text-link" href="/collection.php"><?= te('Minha coleção') ?></a></section>

<?php if ($message): ?><p class="notice success"><?= h($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="notice warning"><?= h($error) ?></p><?php endif; ?>

<section class="trade-share <?= $isPublic ? 'is-public' : '' ?>" aria-label="<?= te('Link da lista') ?>">
    <div>
        <strong><?= $isPublic ? te('Link ativo') : te('Link desligado') ?></strong>
        <p><?= $isPublic ? te('Qualquer pessoa com o endereço abaixo vê as cartas, as quantidades e o seu contato.') : te('Ative o link para compartilhar. Enquanto isso, só você enxerga a página.') ?></p>
        <p class="trade-link"><code><?= h($publicUrl) ?></code></p>
        <p class="trade-share-links">
            <a href="<?= h(tradePublicPath($list)) ?>"><?= $isPublic ? te('Ver página pública') : te('Ver prévia') ?></a>
            <button type="button" class="text-button" data-copy-share="<?= h($publicUrl) ?>"><?= te('Copiar link') ?></button>
            <?php if ($publicRows): ?><a href="/trade.php?export=csv" download><?= te('Exportar CSV') ?></a><?php endif; ?>
        </p>
    </div>
    <div class="trade-share-actions">
        <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="visibility"><input type="hidden" name="public" value="<?= $isPublic ? '0' : '1' ?>"><button class="<?= $isPublic ? 'secondary-link' : 'primary-link' ?>"><?= $isPublic ? te('Desligar link') : te('Ativar link') ?></button></form>
        <form method="post" onsubmit="return confirm('<?= te('Gerar um link novo? O endereço atual para de funcionar.') ?>')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="regenerate"><button class="text-button"><?= te('Gerar link novo') ?></button></form>
    </div>
</section>

<div class="stats trade-stats">
    <div><span><?= te('Cartas na lista') ?></span><strong><?= number_format($summary['copies'], 0, ',', '.') ?></strong></div>
    <div><span><?= te('Versões diferentes') ?></span><strong><?= number_format($summary['printings'], 0, ',', '.') ?></strong></div>
    <div><span><?= te('Valor de referência') ?></span><strong>R$ <?= number_format($summary['total'], 2, ',', '.') ?></strong></div>
</div>

<section class="panel trade-settings">
    <div class="section-heading"><h2><?= te('Como a lista é montada') ?></h2></div>
    <form method="post" class="trade-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="settings">
        <fieldset class="trade-modes">
            <legend class="sr-only"><?= te('Modo da lista') ?></legend>
            <label class="trade-mode <?= $mode === 'free' ? 'is-active' : '' ?>">
                <input type="radio" name="mode" value="free" <?= $mode === 'free' ? 'checked' : '' ?>>
                <span><strong><?= te('Cópias soltas (automático)') ?></strong><small><?= te('Tudo que sobra depois do que seus decks usam. A lista se ajusta sozinha quando você monta ou desmonta um deck.') ?></small></span>
            </label>
            <label class="trade-mode <?= $mode === 'manual' ? 'is-active' : '' ?>">
                <input type="radio" name="mode" value="manual" <?= $mode === 'manual' ? 'checked' : '' ?>>
                <span><strong><?= te('Cartas escolhidas') ?></strong><small><?= te('Só o que você marcar, com preço e observação por carta. Marque pela coleção ou traga as cópias soltas de uma vez.') ?></small></span>
            </label>
        </fieldset>
        <div class="trade-fields">
            <label><?= te('Título da página') ?><input type="text" name="title" maxlength="80" value="<?= h((string)$list['title']) ?>" placeholder="<?= te('Cartas à venda') ?>"></label>
            <label><?= te('Contato') ?><input type="text" name="contact" maxlength="160" value="<?= h((string)$list['contact']) ?>" placeholder="<?= te('WhatsApp, e-mail ou @ do Discord') ?>"></label>
        </div>
        <label class="trade-intro"><?= te('Recado para quem abrir o link') ?><textarea name="intro" rows="3" maxlength="600" placeholder="<?= te('Combino entrega em mãos, envio pelos Correios, aceito troca por cartas da minha lista de desejos…') ?>"><?= h((string)$list['intro']) ?></textarea></label>
        <label class="trade-check"><input type="checkbox" name="show_prices" value="1" <?= deckIsFoil($list['show_prices']) ? 'checked' : '' ?>><span><?= te('Mostrar preços na página pública') ?></span></label>
        <div class="trade-form-actions"><button class="primary-link"><?= te('Salvar') ?></button></div>
    </form>
</section>

<section class="panel trade-items">
    <div class="section-heading">
        <h2><?= $mode === 'manual' ? te('Cartas escolhidas') : te('Cópias soltas hoje') ?></h2>
        <span class="muted"><?= number_format(count($rows), 0, ',', '.') ?> <?= count($rows) === 1 ? te('versão') : te('versões') ?></span>
    </div>
    <?php if ($mode === 'manual'): ?>
    <div class="trade-bulk">
        <p class="muted"><?= te('Você tem') ?> <strong><?= number_format($freeCount, 0, ',', '.') ?></strong> <?= $freeCount === 1 ? te('versão com cópia solta') : te('versões com cópias soltas') ?> <?= te('fora dos decks.') ?></p>
        <div class="status-controls">
            <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="add_free"><button class="secondary-link" <?= $freeCount ? '' : 'disabled' ?>><?= te('Adicionar as cópias soltas') ?></button></form>
            <?php if ($rows): ?><form method="post" onsubmit="return confirm('<?= te('Retirar todas as cartas da lista?') ?>')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="clear"><button class="text-button"><?= te('Limpar lista') ?></button></form><?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <p class="muted"><?= te('Esta relação é recalculada a cada visita: entra o que sobra de cada carta depois das cópias reservadas nos seus decks. Para definir preço por carta, use o modo “Cartas escolhidas”.') ?></p>
    <?php endif; ?>

    <?php if (!$rows): ?>
    <p class="empty-state"><?= $mode === 'manual'
        ? te('Nenhuma carta marcada ainda. Use o botão acima ou marque “À venda” nas cartas de Minha coleção.')
        : te('Nenhuma cópia sobrando: todas as suas cartas estão reservadas em decks — ou a coleção ainda está vazia.') ?></p>
    <?php else: ?>
    <div class="table-scroll"><table class="trade-table">
        <thead><tr><th><?= te('Carta') ?></th><th><?= te('Disponível') ?></th><th><?= te('Preço') ?></th><?php if ($mode === 'manual'): ?><th><?= te('Observação') ?></th><th><span class="sr-only"><?= te('Ações') ?></span></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): $image = cardImageUrl($row, 'front', 'small'); $unavailable = (int)$row['available'] < 1; ?>
        <tr class="<?= $unavailable ? 'is-unavailable' : '' ?>">
            <td>
                <?php if ($mode === 'manual'): $formId = 'item-' . $row['id'] . '-' . ($row['is_foil'] ? '1' : '0'); ?>
                <form method="post" id="<?= h($formId) ?>" class="trade-row-form">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="item">
                    <input type="hidden" name="card" value="<?= h((string)$row['id']) ?>"><input type="hidden" name="foil" value="<?= $row['is_foil'] ? '1' : '0' ?>">
                </form>
                <?php endif; ?>
                <div class="trade-card-cell">
                    <?php if ($image): ?><img src="<?= h($image) ?>" alt="" loading="lazy" width="44" height="61"><?php endif; ?>
                    <div>
                        <a href="/card.php?id=<?= h(rawurlencode((string)$row['id'])) ?>"><?= h($row['name']) ?></a>
                        <small><?= h(strtoupper((string)$row['set_code'])) ?> #<?= h((string)$row['collector_number']) ?> · <?= $row['is_foil'] ? te('Foil') : te('Normal') ?> · <?= h(strtoupper((string)$row['lang'])) ?></small>
                        <?php if ($unavailable): ?><small class="trade-warning"><?= te('Fora da coleção: esta versão não aparece no link.') ?></small>
                        <?php elseif ((int)$row['used_in_decks'] > 0): ?><small class="muted"><?= te(':count cópia(s) reservada(s) em decks', ['count' => (int)$row['used_in_decks']]) ?></small><?php endif; ?>
                    </div>
                </div>
            </td>
            <?php if ($mode === 'manual'): ?>
            <td class="trade-quantity">
                <input form="<?= h($formId) ?>" type="number" name="quantity" min="1" max="<?= max(1, (int)$row['owned_quantity']) ?>" value="<?= (int)$row['listed_quantity'] ?>" aria-label="<?= te('Quantidade à venda') ?>">
                <small class="muted"><?= te('de :count na coleção', ['count' => (int)$row['owned_quantity']]) ?></small>
            </td>
            <td class="trade-price">
                <input form="<?= h($formId) ?>" type="text" name="price" inputmode="decimal" value="<?= $row['asking_price'] === null ? '' : number_format((float)$row['asking_price'], 2, ',', '') ?>" placeholder="<?= $row['reference_price'] === null ? te('a combinar') : number_format((float)$row['reference_price'], 2, ',', '') ?>" aria-label="<?= te('Preço em reais') ?>">
                <small class="muted"><?= te('Referência:') ?> <?= h(tradePriceLabel($row['reference_price'])) ?></small>
            </td>
            <td class="trade-note"><input form="<?= h($formId) ?>" type="text" name="note" maxlength="160" value="<?= h((string)($row['note'] ?? '')) ?>" placeholder="<?= te('Estado, idioma, detalhes…') ?>" aria-label="<?= te('Observação') ?>"></td>
            <td class="trade-row-actions">
                <button form="<?= h($formId) ?>" class="secondary-link"><?= te('Salvar') ?></button>
                <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="card" value="<?= h((string)$row['id']) ?>"><input type="hidden" name="foil" value="<?= $row['is_foil'] ? '1' : '0' ?>"><button class="collection-delete"><?= te('Retirar') ?></button></form>
            </td>
            <?php else: ?>
            <td class="trade-quantity"><strong><?= (int)$row['available'] ?>×</strong><small class="muted"><?= te('de :count na coleção', ['count' => (int)$row['quantity']]) ?></small></td>
            <td class="trade-price"><?= h(tradePriceLabel($row['final_price'])) ?></td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>
<?php pageFooter(); ?>
