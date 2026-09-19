<?php
declare(strict_types=1);
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
require __DIR__ . '/catalog_cache.php';
require __DIR__ . '/card_filters.php';
require __DIR__ . '/deck_tokens.php';
require __DIR__ . '/deck_lands.php';
$authUser = authRequireLogin();
$userId = (int)$authUser['id'];
$_SESSION['builder_csrf'] ??= bin2hex(random_bytes(24));
$csrf = $_SESSION['builder_csrf'];
deckSchema();
$id = max(0,(int)($_GET['deck'] ?? $_POST['deck'] ?? 0));
$message = $_SESSION['builder_message'] ?? ''; unset($_SESSION['builder_message']);
$error = '';
// Subpáginas do deck: cada uma é curta e tem sua própria aba.
$deckViews = ['overview'=>'Visão geral','guide'=>'Guia da comandante','needs'=>'O que falta','explore'=>'Explorar','selection'=>'Minha seleção'];
$view = (string)($_GET['view'] ?? $_POST['view'] ?? '');
// Parâmetros de busca indicam o Explorar (links antigos, redirecionamentos após adicionar, paginação, planos do guia).
$exploreParams = array_intersect(array_keys($_GET), ['q','oracle','type','sort','page','role','card_types','availability','colors','colors_set','rarity','set','cmc_min','cmc_max','choose','synergy','owned','exclude_owned','hide_selected','match','commander_colors']);
if ($view === 'discover' || !isset($deckViews[$view])) $view = $exploreParams ? 'explore' : 'overview';
$selectionStage = (string)($_GET['stage'] ?? $_POST['selection_stage'] ?? 'candidate');
// "Em avaliação" foi unificada com as candidatas: links antigos com stage=review abrem as candidatas.
if (!in_array($selectionStage,['candidate','deck'],true)) $selectionStage='candidate';
$stages = ['candidate'=>'Candidatas','deck'=>'No deck'];
$stageMoves = ['candidate'=>['candidate','deck'],'deck'=>['deck','candidate']];
// Filtro "Tipo de carta" do Explorar possibilidades: chave da URL => [termo do type_line, rótulo].
$cardTypeOptions = ['creature'=>['Creature','Criatura'],'instant'=>['Instant','Instantânea'],'sorcery'=>['Sorcery','Feitiço'],'artifact'=>['Artifact','Artefato'],'enchantment'=>['Enchantment','Encantamento'],'planeswalker'=>['Planeswalker','Planeswalker'],'land'=>['Land','Terreno']];
$cardTypesFrom = fn($value): array => array_values(array_intersect(array_keys($cardTypeOptions), array_map('strval', array_filter((array)$value, 'is_scalar'))));
$wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf,(string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Recarregue a página e tente novamente.');
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || strlen($name)>160) throw new RuntimeException('Informe um nome de até 160 caracteres.');
            $id = (int)deckQuery('INSERT INTO builder_decks(user_id,name) VALUES (?,?) RETURNING id',[$userId,$name])->fetchColumn();
            $message = 'Deck criado. Escolha o comandante ou comece explorando cartas.';
        } elseif ($action === 'import_deck') {
            $result=deckImportList((string)($_POST['name']??''),(string)($_POST['decklist']??''),(string)($_POST['commander']??''));
            $id=(int)$result['id']; $message=$result['matched'].' linhas importadas com as impressões disponíveis na coleção.';
            if(!$result['commander']) $message.=' Nenhuma comandante definida: escolha uma no deck.';
            if($result['unmatched']) $message.=' Não localizadas: '.implode(', ',array_slice($result['unmatched'],0,8)).(count($result['unmatched'])>8?'…':'').'.';
        } elseif ($action === 'import') {
            if (($_FILES['collection']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Selecione um CSV válido dentro do limite de upload do servidor.');
            try { $result = deckImport($_FILES['collection']['tmp_name'], 'replace'); }
            catch (CollectionImportException $importError) { throw new RuntimeException($importError->getMessage().' '.implode(' ', array_map(fn($failedLine) => 'Linha '.$failedLine['line'].($failedLine['name']!==''?' ('.$failedLine['name'].')':'').': '.$failedLine['reason'], array_slice($importError->lines, 0, 8))).(count($importError->lines) > 8 ? ' Veja todas em Minha coleção.' : '')); }
            $message = number_format($result['quantity'],0,',','.') . ' cartas importadas. As quantidades da coleção foram substituídas pelo CSV.';
        } else {
            $deck = deckQuery('SELECT * FROM builder_decks WHERE id=? AND user_id=?',[$id,$userId])->fetch();
            if (!$deck) throw new RuntimeException('Deck não encontrado.');
            if ($action === 'delete_deck') {
                deckQuery('DELETE FROM builder_decks WHERE id=? AND user_id=?',[$id,$userId]); $id=0; $message='Deck excluído. Sua coleção foi preservada.';
            } elseif ($action === 'visibility') {
                $public=($_POST['public']??'')==='1';
                deckQuery('UPDATE builder_decks SET is_public=? WHERE id=? AND user_id=?',[$public?'true':'false',$id,$userId]);
                $message=$public ? 'Deck público: qualquer pessoa com o link pode ver a lista.' : 'Deck privado: só você pode ver.';
            } elseif ($action === 'strategy') {
                deckQuery('UPDATE builder_decks SET strategy=?,terms=? WHERE id=? AND user_id=?',[substr(trim((string)$_POST['strategy']),0,5000),substr(trim((string)$_POST['terms']),0,1000),$id,$userId]);
                $message = 'Estratégia e termos salvos.';
            } elseif (in_array($action, ['scoring_config','scoring_reset'], true)) {
                $scoreCommander = $deck['commander_id'] ? deckQuery('SELECT * FROM cards WHERE id=?',[$deck['commander_id']])->fetch() : null;
                if (!$scoreCommander) throw new RuntimeException('Escolha uma comandante antes de ajustar metas e regras.');
                if ($action === 'scoring_reset') {
                    deckQuery('UPDATE builder_decks SET scoring_config=NULL WHERE id=? AND user_id=?',[$id,$userId]);
                    $message = 'Metas e regras restauradas: as metas voltaram a ser calculadas para a comandante.';
                } elseif ($action === 'scoring_config') {
                    $scoreConfig = deckScoreConfigFromPost($_POST);
                    $scoreConfig['land_fill'] = deckScoreConfig($deck['scoring_config'] ?? null)['land_fill'];
                    deckQuery('UPDATE builder_decks SET scoring_config=?::jsonb WHERE id=? AND user_id=?',[json_encode($scoreConfig),$id,$userId]);
                    $message = 'Metas e regras salvas para este deck.';
                }
            } elseif ($action === 'bulk_remove') {
                // Remoção em massa é limitada às candidatas; cartas já aprovadas continuam exigindo a ação de devolvê-las.
                if ($selectionStage !== 'candidate') throw new RuntimeException('Somente cartas candidatas podem ser removidas em massa.');
                $chosen = array_slice(array_values(array_unique(array_filter(array_map('strval', array_filter((array)($_POST['cards'] ?? []), 'is_scalar')), fn($value) => (bool)preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i', $value)))), 0, 400);
                if (!$chosen) throw new RuntimeException('Marque ao menos uma candidata para remover.');
                $removed = deckQuery("DELETE FROM builder_items WHERE deck_id=? AND stage='candidate' AND card_id::text = ANY(?::text[])", [$id, '{'.implode(',', array_map('strtolower', $chosen)).'}'])->rowCount();
                if (!$removed) throw new RuntimeException('Nenhuma candidata foi removida — a seleção mudou desde que a página abriu. Recarregue e tente novamente.');
                $message = $removed.($removed === 1 ? ' candidata removida da seleção.' : ' candidatas removidas da seleção.');
            } elseif ($action === 'bulk_move') {
                // Movimentação em massa na Minha seleção: mesmas regras do mover individual, aplicadas na ordem da tela.
                $source = $selectionStage;
                $destination = (string)($_POST['stage'] ?? '');
                $bulkAllowed = ['candidate'=>['deck'],'deck'=>['candidate']];
                if (!in_array($destination, $bulkAllowed[$source] ?? [], true)) throw new RuntimeException('Movimento inválido: as cartas vão das candidatas para o deck e podem voltar.');
                $chosen = array_slice(array_values(array_unique(array_filter(array_map('strval', array_filter((array)($_POST['cards'] ?? []), 'is_scalar')), fn($value) => (bool)preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i', $value)))), 0, 400);
                if (!$chosen) throw new RuntimeException('Marque ao menos uma carta para mover.');
                $moved = 0; $skippedFull = 0; $skippedCopies = 0;
                db()->beginTransaction();
                try {
                    deckQuery('SELECT id FROM builder_decks WHERE id=? AND user_id=? FOR UPDATE', [$id, $userId]);
                    $rows = [];
                    foreach (deckQuery('SELECT i.card_id::text card_id,i.quantity,c.type_line FROM builder_items i JOIN cards c ON c.id=i.card_id WHERE i.deck_id=? AND i.stage=? AND i.card_id::text = ANY(?::text[])', [$id, $source, '{'.implode(',', array_map('strtolower', $chosen)).'}'])->fetchAll() as $row) $rows[strtolower($row['card_id'])] = $row;
                    $slots = 100 - ((int)deckQuery("SELECT COALESCE(SUM(quantity),0) FROM builder_items WHERE deck_id=? AND stage='deck'", [$id])->fetchColumn() + ($deck['commander_id'] ? 1 : 0));
                    foreach ($chosen as $chosenId) {
                        $row = $rows[strtolower($chosenId)] ?? null;
                        if (!$row) continue;
                        $quantity = max(1, (int)$row['quantity']);
                        if ($destination === 'deck') {
                            if ($quantity > 1 && !str_contains((string)$row['type_line'], 'Basic')) { $skippedCopies++; continue; }
                            if ($quantity > $slots) { $skippedFull++; continue; }
                            $slots -= $quantity;
                        }
                        deckQuery('UPDATE builder_items SET stage=? WHERE deck_id=? AND card_id=?::uuid AND stage=?', [$destination, $id, $row['card_id'], $source]);
                        $moved++;
                    }
                    db()->commit();
                } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
                $skippedNotes = [];
                if ($skippedFull) $skippedNotes[] = $skippedFull.($skippedFull === 1 ? ' ficou' : ' ficaram').' de fora porque o deck chegou a 100 cartas (use “Preparar upgrade”)';
                if ($skippedCopies) $skippedNotes[] = $skippedCopies.($skippedCopies === 1 ? ' tem' : ' têm').' mais de 1 cópia e Commander só permite isso para terrenos básicos';
                if (!$moved) throw new RuntimeException('Nenhuma carta foi movida'.($skippedNotes ? ': '.implode('; ', $skippedNotes) : ' — a seleção mudou desde que a página abriu. Recarregue e tente novamente').'.');
                $destinationLabel = ['candidate'=>'as candidatas','deck'=>'o deck'][$destination];
                $message = $moved.($moved === 1 ? ' carta movida' : ' cartas movidas').' para '.$destinationLabel.'.'.($skippedNotes ? ' '.ucfirst(implode('; ', $skippedNotes)).'.' : '');
            } elseif (in_array($action, ['autofill_lands','clear_auto_lands','land_target'], true)) {
                // Base de mana automática: você cuida das mágicas; os terrenos são calculados e podem ser refeitos.
                $landCommander = $deck['commander_id'] ? deckQuery('SELECT * FROM cards WHERE id=?',[$deck['commander_id']])->fetch() : null;
                if (!$landCommander) throw new RuntimeException('Escolha uma comandante antes de completar os terrenos.');
                // A meta de terrenos e a opção de compra ficam salvas no deck (scoring_config.land_fill).
                $landRequest = deckLandRequestOptions($_POST);
                if ($action !== 'clear_auto_lands' && ($landRequest['total'] !== null || $landRequest['buy'] !== null || $landRequest['basic_share_set'] || ($_POST['land_total'] ?? null) === '')) {
                    $landFill = deckScoreConfig($deck['scoring_config'] ?? null)['land_fill'];
                    if (array_key_exists('land_total', $_POST)) $landFill['total'] = $landRequest['total'];
                    if ($landRequest['buy'] !== null) $landFill['buy'] = $landRequest['buy'];
                    if ($landRequest['basic_share_set']) $landFill['basic_share'] = $landRequest['basic_share'];
                    deckQuery("UPDATE builder_decks SET scoring_config=COALESCE(scoring_config,'{}'::jsonb) || jsonb_build_object('land_fill', ?::jsonb) WHERE id=? AND user_id=?", [json_encode($landFill), $id, $userId]);
                    $deck['scoring_config'] = deckQuery('SELECT scoring_config FROM builder_decks WHERE id=?', [$id])->fetchColumn();
                }
                if ($action === 'land_target') {
                    $message = $landRequest['total'] === null ? 'Meta de terrenos: sugestão calculada pelo deck.' : 'Meta de terrenos salva: '.$landRequest['total'].'.';
                    $landReturn = '/decks.php?deck='.$id.'&view=selection&stage=deck'.($landRequest['skip'] ? '&'.http_build_query(['land_skip'=>$landRequest['skip']]) : '').'#land-fill';
                } elseif ($action === 'clear_auto_lands') {
                    $removed = (int)deckQuery("SELECT COALESCE(SUM(quantity),0) FROM builder_items WHERE deck_id=? AND stage='deck' AND role=?",[$id,DECK_AUTO_LAND_ROLE])->fetchColumn();
                    deckQuery("DELETE FROM builder_items WHERE deck_id=? AND stage='deck' AND role=?",[$id,DECK_AUTO_LAND_ROLE]);
                    $message = $removed ? $removed.' terreno(s) automático(s) retirado(s) do deck. Os terrenos que você escolheu continuam.' : 'Não havia terrenos automáticos no deck.';
                } else {
                    $landPlan = deckLandPlan($id, $landCommander, deckScoreLoadItems($id,$userId), deckScoreConfigFor($deck,$landCommander), ['total'=>null] + $landRequest);
                    if ($landPlan['blocked']) throw new RuntimeException($landPlan['blocked']);
                    if (!$landPlan['need'] && !$landPlan['auto_existing']) throw new RuntimeException('O deck já tem os terrenos da meta.');
                    $added = deckApplyLandPlan($id, $landPlan);
                    $message = $added.' terreno(s) no deck: '.count($landPlan['picks']).' não básico(s) e '.array_sum(array_column($landPlan['basics'],'quantity')).' básico(s).'.($landPlan['missing_count'] ? ' '.$landPlan['missing_count'].' cópia(s) não estão livres na coleção.' : ' Todos vêm da sua coleção.');
                }
            } elseif ($action === 'sync_edhrec') {
                if(!$deck['commander_id']) throw new RuntimeException('Escolha um comandante antes de buscar recomendações.');
                $commanderCard=deckQuery('SELECT * FROM cards WHERE id=?',[$deck['commander_id']])->fetch();
                $saved=deckSyncEdhrec($commanderCard); deckWarmGuide($commanderCard); $message=$saved.' recomendações do EDHREC atualizadas.';
            } elseif ($action === 'prepare_upgrade') {
                if (!$deck['commander_id']) throw new RuntimeException('Escolha um comandante antes de preparar um upgrade.');
                $currentCount=(int)deckQuery("SELECT COALESCE(SUM(quantity),0) FROM builder_items WHERE deck_id=? AND stage='deck'",[$id])->fetchColumn()+1;
                if ($currentCount!==100) throw new RuntimeException('O deck precisa estar fechado com 100 cartas para preparar um upgrade.');
                $incoming=deckQuery('SELECT stage FROM builder_items WHERE deck_id=? AND card_id=?',[$id,(string)($_POST['card']??'')])->fetch();
                if (!$incoming || $incoming['stage']!=='candidate') throw new RuntimeException('A carta precisa estar nas candidatas para entrar num upgrade.');
                $message='Escolha abaixo qual carta sairá para abrir espaço para o upgrade.';
            } elseif ($action === 'confirm_upgrade') {
                $remove=(string)($_POST['remove_card']??''); $incoming=(string)($_POST['card']??'');
                $currentCount=(int)deckQuery("SELECT COALESCE(SUM(quantity),0) FROM builder_items WHERE deck_id=? AND stage='deck'",[$id])->fetchColumn()+1;
                if ($currentCount!==100) throw new RuntimeException('O deck precisa estar fechado com 100 cartas para confirmar um upgrade.');
                $out=deckQuery("SELECT c.* FROM builder_items i JOIN cards c ON c.id=i.card_id WHERE i.deck_id=? AND i.card_id=? AND i.stage='deck'",[$id,$remove])->fetch();
                $in=deckQuery("SELECT c.* FROM builder_items i JOIN cards c ON c.id=i.card_id WHERE i.deck_id=? AND i.card_id=? AND i.stage='candidate'",[$id,$incoming])->fetch();
                if(!$out||!$in) throw new RuntimeException('Escolha uma carta do deck para sair e uma candidata para entrar.');
                if(($out['oracle_id']?:$out['id'])===($in['oracle_id']?:$in['id'])) throw new RuntimeException('As cartas do upgrade precisam ser diferentes.');
                if(deckQuery("SELECT 1 FROM deck_upgrades WHERE deck_id=? AND status='planned' AND (remove_card_id=? OR add_card_id=?) LIMIT 1",[$id,$remove,$incoming])->fetchColumn()) throw new RuntimeException('Uma destas cartas já participa de outro upgrade pendente.');
                deckQuery('INSERT INTO deck_upgrades(deck_id,remove_card_id,add_card_id,reason) VALUES (?,?,?,?)',[$id,$remove,$incoming,substr(trim((string)($_POST['reason']??'')),0,2000)]);
                $message='Upgrade pendente registrado. O deck continua com 100 cartas até você confirmar a troca.';
            } elseif (in_array($action,['apply_upgrade','cancel_upgrade'],true)) {
                $upgradeId=max(0,(int)($_POST['upgrade']??0));
                $plan=deckQuery("SELECT * FROM deck_upgrades WHERE id=? AND deck_id=? AND status='planned'",[$upgradeId,$id])->fetch();
                if(!$plan) throw new RuntimeException('Upgrade pendente não encontrado.');
                if($action==='cancel_upgrade') { deckQuery('DELETE FROM deck_upgrades WHERE id=?',[$upgradeId]); $message='Upgrade cancelado. A seleção foi preservada.'; }
                else {
                    $out=deckQuery("SELECT * FROM builder_items WHERE deck_id=? AND card_id=? AND stage='deck'",[$id,$plan['remove_card_id']])->fetch();
                    $in=deckQuery("SELECT * FROM builder_items WHERE deck_id=? AND card_id=? AND stage='candidate'",[$id,$plan['add_card_id']])->fetch();
                    if(!$out||!$in) throw new RuntimeException('As cartas do upgrade precisam continuar no deck e nas candidatas.');
                    deckQuery("UPDATE builder_items SET stage='candidate' WHERE deck_id=? AND card_id=?",[$id,$plan['remove_card_id']]);
                    deckQuery("UPDATE builder_items SET stage='deck' WHERE deck_id=? AND card_id=?",[$id,$plan['add_card_id']]);
                    deckQuery("UPDATE deck_upgrades SET status='done' WHERE id=?",[$upgradeId]); $message='Upgrade confirmado. A nova carta entrou e a anterior voltou para as candidatas.';
                }
            } elseif (in_array($action,['commander','add','item','remove','move'],true)) {
                $cardId = (string)($_POST['card'] ?? '');
                if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i',$cardId)) throw new RuntimeException('Carta inválida.');
                $card = deckQuery('SELECT * FROM cards WHERE id=?',[$cardId])->fetch();
                if (!$card) throw new RuntimeException('Carta não encontrada no acervo.');
                if ($action === 'commander') {
                    if (!deckQuery('SELECT 1 FROM cards c WHERE c.id=? AND '.deckCommanderSql(),[$cardId])->fetchColumn()) throw new RuntimeException('Escolha uma carta elegível como comandante.');
                    deckQuery('UPDATE builder_decks SET commander_id=? WHERE id=? AND user_id=?',[$cardId,$id,$userId]);
                    deckQuery('DELETE FROM builder_items i USING cards c WHERE i.card_id=c.id AND i.deck_id=? AND COALESCE(c.oracle_id,c.id)=?::uuid',[$id,$card['oracle_id'] ?: $cardId]);
                    $message = 'Comandante definido. Confira a identidade de cor das cartas já selecionadas.';
                    if ($insightsMessage = deckRefreshInsightsIfStale($card)) $message .= ' '.$insightsMessage;
                } elseif ($action === 'move') {
                    $stage=(string)($_POST['stage']??'');
                    if(!isset($stages[$stage])) throw new RuntimeException('Etapa inválida.');
                    $currentStage=(string)deckQuery('SELECT stage FROM builder_items WHERE deck_id=? AND card_id=?',[$id,$cardId])->fetchColumn();
                    $allowed=$stageMoves;
                    if(!in_array($stage,$allowed[$currentStage]??[],true)) throw new RuntimeException('Movimento inválido: as cartas vão das candidatas para o deck e podem voltar.');
                    if($stage==='deck' && $currentStage!=='deck') {
                        if(!str_contains((string)$card['type_line'],'Basic') && (int)deckQuery('SELECT quantity FROM builder_items WHERE deck_id=? AND card_id=?',[$id,$cardId])->fetchColumn()>1) throw new RuntimeException('Commander permite apenas 1 cópia de cada carta. Apenas terrenos básicos podem ter quantidade maior.');
                        $currentCount=(int)deckQuery("SELECT COALESCE(SUM(quantity),0) FROM builder_items WHERE deck_id=? AND stage='deck'",[$id])->fetchColumn()+($deck['commander_id']?1:0);
                        if($currentCount>=100) throw new RuntimeException('O deck já tem 100 cartas. Use “Preparar upgrade” para escolher a carta que sairá.');
                    }
                    deckQuery('UPDATE builder_items SET stage=? WHERE deck_id=? AND card_id=?',[$stage,$id,$cardId]);
                    $message='Carta movida para '.$stages[$stage].'.';
                } elseif ($action === 'remove') {
                    deckQuery('DELETE FROM builder_items WHERE deck_id=? AND card_id=?',[$id,$cardId]);
                    $message = 'Carta retirada da seleção.';
                } elseif ($action === 'add') {
                    if(!$deck['commander_id']) throw new RuntimeException('Escolha uma comandante antes de adicionar cartas às candidatas.');
                    $logical = $card['oracle_id'] ?: $cardId;
                    $exists = deckQuery('SELECT 1 FROM builder_items i JOIN cards c ON c.id=i.card_id WHERE i.deck_id=? AND COALESCE(c.oracle_id,c.id)=?::uuid',[$id,$logical])->fetchColumn();
                    $commanderLogical = $deck['commander_id'] ? deckQuery('SELECT COALESCE(oracle_id,id) FROM cards WHERE id=?',[$deck['commander_id']])->fetchColumn() : null;
                    if ($exists || $commanderLogical === $logical) throw new RuntimeException('Essa carta já está no deck ou é o comandante. Ajuste a quantidade na seleção existente.');
                    deckQuery("INSERT INTO builder_items(deck_id,card_id,stage,quantity) VALUES (?,?,'candidate',1)",[$id,$cardId]);
                    $message = 'Carta adicionada às candidatas.';
                } else {
                    $stage = (string)($_POST['stage'] ?? '');
                    $quantity = filter_var($_POST['quantity'] ?? '',FILTER_VALIDATE_INT);
                    if (!isset($stages[$stage]) || !$quantity || $quantity<1 || $quantity>1000) throw new RuntimeException('Etapa ou quantidade inválida.');
                    if ($quantity>1 && !str_contains((string)$card['type_line'],'Basic')) throw new RuntimeException('Commander permite apenas 1 cópia de cada carta. Apenas terrenos básicos podem ter quantidade maior.');
                    $currentStage=(string)deckQuery('SELECT stage FROM builder_items WHERE deck_id=? AND card_id=?',[$id,$cardId])->fetchColumn();
                    $allowed=$stageMoves;
                    if($stage!==$currentStage && !in_array($stage,$allowed[$currentStage]??[],true)) throw new RuntimeException('Movimento inválido: as cartas vão das candidatas para o deck e podem voltar.');
                    if($stage==='deck' && $currentStage!=='deck') {
                        $currentCount=(int)deckQuery("SELECT COALESCE(SUM(quantity),0) FROM builder_items WHERE deck_id=? AND stage='deck'",[$id])->fetchColumn()+($deck['commander_id']?1:0);
                        if($currentCount>=100) throw new RuntimeException('O deck já tem 100 cartas. Use “Preparar upgrade” para escolher a carta que sairá.');
                    }
                    deckQuery('UPDATE builder_items SET stage=?,quantity=?,role=?,notes=? WHERE deck_id=? AND card_id=?',[$stage,$quantity,substr(trim((string)$_POST['role']),0,100),substr(trim((string)$_POST['notes']),0,2000),$id,$cardId]);
                    $message = 'Seleção atualizada.';
                }
            } else throw new RuntimeException('Ação inválida.');
        }
        if ($wantsJson && $action === 'add') {
            // Adição rápida pela busca/guia: responde sem recarregar a página inteira.
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true,'message'=>$message,'card'=>$cardId,'stage'=>'candidate','label'=>deckStageLabel('candidate'),
                'candidates'=>(int)deckQuery("SELECT COUNT(*) FROM builder_items WHERE deck_id=? AND stage='candidate'",[$id])->fetchColumn()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION['builder_message'] = $message;
        $return = '/decks.php' . ($id ? '?deck='.$id : '');
        if ($id && in_array($action,['add','item','remove'],true)) {
            $return .= '&'.http_build_query(['q'=>(string)($_POST['q']??''),'oracle'=>(string)($_POST['oracle']??''),'type'=>(string)($_POST['type']??''),'match'=>(string)($_POST['match']??'all'),'availability'=>(string)($_POST['availability']??'all'),'colors'=>(string)($_POST['colors']??''),'hide_selected'=>(string)($_POST['hide_selected']??'1'),'sort'=>(string)($_POST['sort']??'relevance'),'rarity'=>(string)($_POST['rarity']??''),'set'=>(string)($_POST['set']??''),'cmc_min'=>(string)($_POST['cmc_min']??''),'cmc_max'=>(string)($_POST['cmc_max']??''),'card_types'=>$cardTypesFrom($_POST['card_types']??[]),'role'=>(string)($_POST['role']??''),'page'=>max(1,(int)($_POST['page']??1))]);
            $return .= $action==='add' ? '#result-'.rawurlencode($cardId) : '#selection';
        }
        if($id && $view==='selection') {
            $return='/decks.php?deck='.$id.'&view=selection&stage='.urlencode($selectionStage);
            $return.=$action==='prepare_upgrade' ? '&upgrade_card='.rawurlencode((string)($_POST['card']??'')).'#upgrade' : '#selection';
        }
        if($id && $action==='sync_edhrec') $return='/decks.php?deck='.$id.'&view=guide';
        if($id && isset($landReturn)) $return=$landReturn;
        if($id && in_array($action,['strategy','commander','visibility'],true)) $return='/decks.php?deck='.$id.'&view=overview';
        header('Location: '.$return, true,303); exit;
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Não foi possível salvar. Nenhuma seleção foi descartada; tente novamente.';
        if ($wantsJson) { http_response_code(422); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'message'=>$error], JSON_UNESCAPED_UNICODE); exit; }
    }
}
session_write_close();
$deck = $id ? deckQuery('SELECT * FROM builder_decks WHERE id=? AND user_id=?',[$id,$userId])->fetch() : null;
if ($id && !$deck) { http_response_code(404); $id = 0; $error = $error ?: 'Deck não encontrado na sua conta.'; }
// Lista de decks com valores: só na biblioteca (nenhum deck aberto); lê preços de todas as cartas de todos os decks.
$decks=[]; $deckValues=[];
if (!$deck) {
    $decks = deckQuery("SELECT d.*,c.name commander,c.id commander_card_id,COALESCE(c.raw->'image_uris'->>'art_crop',c.raw->'card_faces'->0->'image_uris'->>'art_crop') commander_art,(SELECT COALESCE(SUM(quantity),0) FROM builder_items WHERE deck_id=d.id AND stage='deck') + CASE WHEN d.commander_id IS NULL THEN 0 ELSE 1 END card_count FROM builder_decks d LEFT JOIN cards c ON c.id=d.commander_id WHERE d.user_id=? ORDER BY d.id DESC",[$userId])->fetchAll();
    $deckValueRows=deckQuery("SELECT chosen.deck_id,chosen.quantity,c.prices,c.raw,COALESCE(bc.normal_quantity,0) normal_quantity,COALESCE(bc.foil_quantity,0) foil_quantity
        FROM (
            SELECT i.deck_id,i.card_id,SUM(i.quantity)::int quantity FROM builder_items i JOIN builder_decks ud ON ud.id=i.deck_id AND ud.user_id=? WHERE i.stage='deck' GROUP BY i.deck_id,i.card_id
            UNION ALL SELECT d.id,d.commander_id,1 FROM builder_decks d WHERE d.commander_id IS NOT NULL AND d.user_id=?
        ) chosen JOIN cards c ON c.id=chosen.card_id
        LEFT JOIN ".deckCollectionPrintingSql()." bc ON bc.scryfall_id=c.id",[$userId,$userId])->fetchAll();
    foreach($deckValueRows as $pricedCard){
        $deckId=(int)$pricedCard['deck_id'];$quantity=(int)$pricedCard['quantity'];$price=deckSelectedPriceBrl($pricedCard);
        $deckValues[$deckId]??=['total'=>0.0,'unpriced'=>0];
        if($price===null)$deckValues[$deckId]['unpriced']+=$quantity;else $deckValues[$deckId]['total']+=$price*$quantity;
    }
}
$commander = $deck && $deck['commander_id'] ? deckQuery("SELECT c.*,COALESCE(bc.quantity,0) owned_printing,COALESCE(bc.normal_quantity,0) normal_quantity,COALESCE(bc.foil_quantity,0) foil_quantity FROM cards chosen JOIN cards c ON COALESCE(c.oracle_id,c.id)=COALESCE(chosen.oracle_id,chosen.id) LEFT JOIN ".deckCollectionPrintingSql()." bc ON bc.scryfall_id=c.id WHERE chosen.id=? ORDER BY (COALESCE(bc.quantity,0)>0) DESC,(c.lang='en') DESC,(c.local_image IS NOT NULL) DESC,c.released_at DESC NULLS LAST,c.id LIMIT 1",[$deck['commander_id']])->fetch() : null;
$identity = $commander ? (json_decode($commander['color_identity'],true) ?: []) : [];
$identityMana = implode('', array_map(fn($color)=>'{'.$color.'}', $identity));
$collection = deckQuery('SELECT COALESCE(SUM(quantity),0) total,COUNT(*) printings,COUNT(*) FILTER(WHERE c.id IS NULL) unmatched FROM builder_collection o LEFT JOIN cards c ON c.id=o.scryfall_id WHERE o.user_id=?',[$userId])->fetch();
$q = substr(trim((string)($_GET['q']??'')),0,200);
$choosingCommander = (bool)$deck && (!$commander || isset($_GET['choose']));
// Sinergia do EDHREC chega sozinha na primeira visita após escolher ou importar a comandante.
if($commander && !$choosingCommander && deckEnsureEdhrec($commander) && !$message) $message='Recomendações e sinergias do EDHREC carregadas para '.$commander['name'].'.';
$guideInsights=null;$guidePlans=[];$guideCombos=[];$guideMechanics=['own'=>[],'new'=>[]];$guideNewCards=[];$guideSimilar=[];
// Sem comandante (ou trocando), o deck só tem a escolha da comandante no Explorar.
if($deck && $choosingCommander && $view!=='selection') $view='explore';
if($commander && !$choosingCommander && in_array($view,['guide','overview'],true)){
    $guideInsights=deckCommanderInsights($commander);
    $guidePlans=deckGuidePlans($commander,$guideInsights);
    $guideCombos=deckGuideCombos($commander,$guideInsights);
    try { $guideMechanics=deckGuideMechanics($commander); } catch (PDOException) { $guideMechanics=['own'=>[],'new'=>[]]; }
    $guideNewCards=deckGuideNewCards($commander,$guideInsights);
    $guideSimilar=deckGuideSimilar($guideInsights);
}
$oracle = substr(trim((string)($_GET['oracle']??($choosingCommander?'':($deck['terms']??'')))),0,1000);
$type = substr(trim((string)($_GET['type']??'')),0,120);
$match = ($_GET['match']??(str_contains((string)($_GET['oracle']??''),';')?'any':'all'))==='any' ? 'any' : 'all';
$defaultSort=$choosingCommander?'popular':($commander?'synergy':'relevance');
$sort = (string)($_GET['sort'] ?? ((($_GET['synergy']??'')==='1')?'synergy':$defaultSort));
if (!in_array($sort,['relevance','name','newest','owned','synergy','popular','fit'],true)) $sort=$defaultSort;
if($choosingCommander && in_array($sort,['synergy','fit'],true)) $sort='popular';
if(!$commander && $sort==='fit') $sort=$defaultSort;
// "Encaixa no deck": só cartas da coleção, na identidade, fora da seleção, ordenadas pelas relações com o deck.
$fitMode = $sort==='fit' && $commander && !$choosingCommander;
$rarity = (string)($_GET['rarity'] ?? '');
if (!in_array($rarity,['common','uncommon','rare','mythic','special'],true)) $rarity='';
$setFilter = strtoupper(substr(trim((string)($_GET['set'] ?? '')),0,16));
$cmcMin = is_numeric($_GET['cmc_min'] ?? null) ? max(0,(float)$_GET['cmc_min']):null;
$cmcMax = is_numeric($_GET['cmc_max'] ?? null) ? max(0,(float)$_GET['cmc_max']):null;
$availability=(string)($_GET['availability']??'');
if($availability==='' && ($_GET['owned']??'')==='1') $availability='owned';
if($availability==='' && ($_GET['exclude_owned']??'')==='1') $availability='missing';
if(!in_array($availability,['all','owned','free','missing'],true)) $availability=$choosingCommander?'owned':'all';
if($fitMode) $availability='owned';
$ownedOnly = in_array($availability,['owned','free'],true);
$excludeOwned = $availability==='missing';
// Com comandante escolhida, a busca começa limitada à identidade dela; o formulário envia colors_set para respeitar a escolha do usuário.
$colorsOnly = $fitMode || (array_key_exists('colors',$_GET) ? ($_GET['colors']==='1') : (isset($_GET['colors_set']) ? false : (bool)$commander));
// Esconder o que já está no deck ou nas candidatas: ligado por padrão com comandante (o formulário envia colors_set).
$hideSelected = $fitMode || (!$choosingCommander && $commander && (array_key_exists('hide_selected',$_GET) ? $_GET['hide_selected']==='1' : !isset($_GET['colors_set'])));
$commanderColors=array_values(array_intersect((array)($_GET['commander_colors']??[]),['W','U','B','R','G','C']));
$cardTypes = $choosingCommander ? [] : $cardTypesFrom($_GET['card_types'] ?? []);
// Função no deck (ramp, remoção…): usa o índice de funções do Índice de Encaixe.
$roleFilterOptions = array_diff_key(DECK_SCORE_ROLES, ['plan'=>1]);
$roleFilter = (string)($_GET['role'] ?? '');
if ($choosingCommander || !isset($roleFilterOptions[$roleFilter])) $roleFilter = '';
$catalogVisible = $deck && $view==='explore';
$setOptions = $catalogVisible ? catalogCached('deck-filter-sets-v1',fn()=>deckQuery("SELECT set_code,MAX(set_name) set_name FROM cards WHERE set_code IS NOT NULL AND set_code<>'' GROUP BY set_code ORDER BY MAX(set_name),set_code")->fetchAll()) : [];
$page = max(1,min(10000,(int)($_GET['page']??1)));
$terms = array_slice(deckTerms($oracle),0,12);
$highlightTerms=array_merge($terms,deckTerms($type),$q!==''?[$q]:[]);
$filterHidden = function() use($q,$oracle,$type,$match,$availability,$colorsOnly,$hideSelected,$sort,$rarity,$setFilter,$cmcMin,$cmcMax,$commanderColors,$cardTypes,$roleFilter,$page): void {
    foreach (['q'=>$q,'oracle'=>$oracle,'type'=>$type,'match'=>$match,'availability'=>$availability,'colors'=>$colorsOnly?'1':'','hide_selected'=>$hideSelected?'1':'0','sort'=>$sort,'rarity'=>$rarity,'set'=>$setFilter,'cmc_min'=>$cmcMin??'','cmc_max'=>$cmcMax??'','role'=>$roleFilter,'page'=>$page] as $k=>$v) echo '<input type="hidden" name="'.h($k).'" value="'.h((string)$v).'">';
    foreach($commanderColors as $color) echo '<input type="hidden" name="commander_colors[]" value="'.h($color).'">';
    foreach($cardTypes as $cardType) echo '<input type="hidden" name="card_types[]" value="'.h($cardType).'">';
};
$tokenFields = function(string $action, ?string $card = null) use($csrf,$id,$view,$selectionStage): void {
    echo '<input type="hidden" name="view" value="'.h($view).'"><input type="hidden" name="selection_stage" value="'.h($selectionStage).'">';
    echo '<input type="hidden" name="csrf" value="'.h($csrf).'"><input type="hidden" name="deck" value="'.$id.'"><input type="hidden" name="action" value="'.h($action).'">';
    if ($card) echo '<input type="hidden" name="card" value="'.h($card).'">';
};
$leader=$commander ? ($commander['oracle_id']?:$commander['id']) : null;
$selectionSynergyJoin = '';
$selectionSynergyParams = [];
$selectionSynergySelect = 'NULL::text synergy_metric,NULL::numeric synergy_score';
if ($leader) {
    $selectionSynergyJoin = " LEFT JOIN (SELECT DISTINCT ON(COALESCE(source.oracle_id,source.id)) s.metric,s.score,COALESCE(source.oracle_id,source.id) logical_id FROM deck_synergy s JOIN cards leader ON leader.id=s.commander_id JOIN cards source ON source.id=s.card_id WHERE COALESCE(leader.oracle_id,leader.id)=?::uuid ORDER BY COALESCE(source.oracle_id,source.id),s.synced_at DESC,s.score DESC) synergy ON synergy.logical_id=COALESCE(c.oracle_id,c.id)";
    $selectionSynergyParams[] = $leader;
    $selectionSynergySelect = 'synergy.metric synergy_metric,synergy.score synergy_score';
}
$items = $deck ? deckQuery(deckOwnedSql().", elsewhere AS (SELECT x.logical_id,SUM(x.quantity)::int used,COUNT(DISTINCT x.deck_id)::int decks FROM (
        SELECT oi.deck_id,COALESCE(oc.oracle_id,oc.id) logical_id,oi.quantity FROM builder_items oi JOIN builder_decks od ON od.id=oi.deck_id AND od.user_id={$userId} JOIN cards oc ON oc.id=oi.card_id WHERE oi.stage='deck' AND oi.deck_id<>".(int)$id."
        UNION ALL SELECT od.id,COALESCE(oc.oracle_id,oc.id),1 FROM builder_decks od JOIN cards oc ON oc.id=od.commander_id WHERE od.user_id={$userId} AND od.id<>".(int)$id."
    ) x GROUP BY x.logical_id)
    SELECT c.*,i.stage,i.quantity,i.role,i.notes,COALESCE(o.owned,0) owned,COALESCE(bc.quantity,0) owned_printing,COALESCE(bc.normal_quantity,0) normal_quantity,COALESCE(bc.foil_quantity,0) foil_quantity,{$selectionSynergySelect},COALESCE(el.used,0) other_used,COALESCE(el.decks,0) other_decks
    FROM builder_items i JOIN cards c ON c.id=i.card_id LEFT JOIN owned o ON o.logical_id=COALESCE(c.oracle_id,c.id) LEFT JOIN ".deckCollectionPrintingSql()." bc ON bc.scryfall_id=c.id LEFT JOIN elsewhere el ON el.logical_id=COALESCE(c.oracle_id,c.id){$selectionSynergyJoin} WHERE i.deck_id=? ORDER BY c.name",array_merge($selectionSynergyParams,[$id]))->fetchAll() : [];
$needPanel = [];
if ($commander && !$choosingCommander && in_array($view,['needs','overview'],true)) {
    try {
        $needConfig = deckScoreConfigFor($deck, $commander);
        $needPanel = deckNeedSuggestions($commander, deckScoreSelection($deck, $commander, $items, $needConfig), $items, $needConfig);
    } catch (Throwable $needError) {
        error_log('Painel de necessidades: '.$needError->getMessage());
        $needPanel = [];
    }
}
$selectedByLogical = [];
foreach ($items as $selected) $selectedByLogical[(string)($selected['oracle_id'] ?: $selected['id'])] = $selected;
$pendingUpgrade=null; $pendingUpgrades=[]; $upgradeCuts=[];
if($deck) {
    $pendingUpgrades=deckQuery("SELECT u.*,outc.name remove_name,inc.name add_name FROM deck_upgrades u JOIN cards outc ON outc.id=u.remove_card_id JOIN cards inc ON inc.id=u.add_card_id WHERE u.deck_id=? AND u.status='planned' ORDER BY u.created_at DESC",[$id])->fetchAll();
    $pendingUpgrade=$pendingUpgrades[0]??null;
    if($commander && !empty($_GET['upgrade_card'])) {
        $upgradeCuts=deckQuery("SELECT c.*,i.quantity,s.score cut_score FROM builder_items i JOIN cards c ON c.id=i.card_id LEFT JOIN deck_synergy s ON s.card_id=c.id AND s.commander_id=? WHERE i.deck_id=? AND i.stage='deck' AND c.id<>? ORDER BY (s.score IS NULL) ASC,s.score ASC,c.name",[$commander['id'],$id,$commander['id']])->fetchAll();
    }
}
/**
 * Divide a linha de tipo nas duas listas dos gráficos da análise.
 * Cada carta entra em um único tipo (o primeiro da ordem abaixo que aparecer)
 * para os totais fecharem com o tamanho do deck. Subtipos vêm das duas faces.
 *
 * @return array{0: string, 1: string[]}
 */
function deckTypeBuckets(string $typeLine): array
{
    $main = 'Outros';
    foreach (['Land'=>'Terrenos','Creature'=>'Criaturas','Planeswalker'=>'Planeswalkers','Battle'=>'Batalhas','Instant'=>'Instantâneas','Sorcery'=>'Feitiços','Artifact'=>'Artefatos','Enchantment'=>'Encantamentos'] as $type=>$label) {
        if (str_contains($typeLine, $type)) { $main = $label; break; }
    }
    $subtypes = [];
    foreach (explode('//', $typeLine) as $face) {
        $parts = preg_split('/[—–]/u', $face, 2);
        if (count($parts) < 2) continue;
        foreach (preg_split('/\s+/u', trim($parts[1])) ?: [] as $subtype) {
            $subtype = trim($subtype);
            if ($subtype !== '') $subtypes[$subtype] = true;
        }
    }
    return [$main, array_keys($subtypes)];
}

$finalCount = $commander ? 1 : 0; $landCount=0; $typeCounts=[]; $subtypeCounts=[]; $roles=[]; $shopping=[]; $warnings=[]; $pipCounts=array_fill_keys(['W','U','B','R','G'],0); $curveCounts=[]; $curveCards=array_fill(0,11,[]); $deckPriceTotal=0.0; $deckUnpriced=0;
foreach ($items as $item) {
    if ($item['stage']!=='deck') continue;
    $quantity=(int)$item['quantity']; $finalCount+=$quantity;
    if (str_contains($item['type_line'],'Land')) $landCount+=$quantity;
    if ($item['role']!=='') $roles[$item['role']]=($roles[$item['role']]??0)+$quantity;
    if (array_diff(json_decode($item['color_identity'],true)?:[],$identity) && $commander) $warnings[]=$item['name'].': fora da identidade de cor do comandante.';
    if ($quantity>1 && !str_contains($item['type_line'],'Basic') && !preg_match('/deck can have (any number|up to)/i',deckText($item))) $warnings[]=$item['name'].': confira a quantidade permitida para Commander.';
    $available=max(0,(int)$item['owned']-(int)$item['other_used']);$missing=max(0,$quantity-$available); if ($missing) $shopping[]=['name'=>$item['name'],'quantity'=>$missing];
    $itemPrice=deckSelectedPriceBrl($item); if($itemPrice===null)$deckUnpriced+=$quantity;else $deckPriceTotal+=$itemPrice*$quantity;
    foreach ($pipCounts as $color=>$_) $pipCounts[$color]+=substr_count((string)$item['mana_cost'],$color)*$quantity;
    if (!str_contains($item['type_line'],'Land')) { $cmc=min(10,max(0,(int)floor((float)($item['cmc']??0)))); $curveCounts[$cmc]=($curveCounts[$cmc]??0)+$quantity; $curveCards[$cmc][]=$item; }
    [$mainType,$subtypes]=deckTypeBuckets((string)$item['type_line']);
    $typeCounts[$mainType]=($typeCounts[$mainType]??0)+$quantity;
    // Subtipos de terreno (Plains, Island…) inundariam o gráfico e escondem os temas do deck.
    if ($mainType!=='Terrenos') foreach ($subtypes as $subtype) $subtypeCounts[$subtype]=($subtypeCounts[$subtype]??0)+$quantity;
}
if ($commander) {
    $commanderOwned=(int)deckQuery(deckOwnedSql().'SELECT COALESCE((SELECT owned FROM owned WHERE logical_id=?::uuid),0)',[$commander['oracle_id']?:$commander['id']])->fetchColumn();
    $commanderOtherUsed=(int)deckQuery("SELECT COALESCE(SUM(quantity),0) FROM (SELECT COALESCE(SUM(i.quantity),0)::int quantity FROM builder_items i JOIN builder_decks ud ON ud.id=i.deck_id AND ud.user_id=? JOIN cards c ON c.id=i.card_id WHERE i.stage='deck' AND i.deck_id<>? AND COALESCE(c.oracle_id,c.id)=?::uuid UNION ALL SELECT COUNT(*)::int FROM builder_decks d JOIN cards c ON c.id=d.commander_id WHERE d.user_id=? AND d.id<>? AND COALESCE(c.oracle_id,c.id)=?::uuid) reservations",[$userId,$id,$commander['oracle_id']?:$commander['id'],$userId,$id,$commander['oracle_id']?:$commander['id']])->fetchColumn();
    $commanderAvailable=max(0,$commanderOwned-$commanderOtherUsed);if (!$commanderAvailable) $shopping[]=['name'=>$commander['name'],'quantity'=>1];
    $commanderPrice=deckSelectedPriceBrl($commander);if($commanderPrice===null)$deckUnpriced++;else $deckPriceTotal+=$commanderPrice;
    foreach ($pipCounts as $color=>$_) $pipCounts[$color]+=substr_count((string)$commander['mana_cost'],$color);
    $cmc=min(10,max(0,(int)floor((float)($commander['cmc']??0)))); $curveCounts[$cmc]=($curveCounts[$cmc]??0)+1; $curveCards[$cmc][]=$commander;
    [$mainType,$subtypes]=deckTypeBuckets((string)$commander['type_line']);
    $typeCounts[$mainType]=($typeCounts[$mainType]??0)+1;
    foreach ($subtypes as $subtype) $subtypeCounts[$subtype]=($subtypeCounts[$subtype]??0)+1;
}
$isComplete = $finalCount === 100;
if ($deck && (($deck['status']==='ready') !== $isComplete)) { deckQuery('UPDATE builder_decks SET status=? WHERE id=? AND user_id=?',[$isComplete?'ready':'planning',$id,$userId]); $deck['status']=$isComplete?'ready':'planning'; }
$manaTotal=array_sum($pipCounts); $maxCurve=$curveCounts?max($curveCounts):0; ksort($curveCounts);
arsort($typeCounts); arsort($subtypeCounts);
$topSubtypes=array_slice(array_filter($subtypeCounts,fn($count)=>$count>1),0,12,true);
$recommendedLandTotal=$isComplete?36:null; $landRecommendation=[]; $colorNames=['W'=>'Brancos','U'=>'Azuis','B'=>'Pretos','R'=>'Vermelhos','G'=>'Verdes'];
if($recommendedLandTotal!==null){
    $rankedColors=array_keys($pipCounts); usort($rankedColors,fn($a,$b)=>$pipCounts[$b]<=>$pipCounts[$a]);
    $allocated=0;
    foreach($rankedColors as $color){$amount=$manaTotal?(int)floor($recommendedLandTotal*$pipCounts[$color]/$manaTotal):0; $landRecommendation[$color]=$amount; $allocated+=$amount;}
    for($i=0;$allocated<$recommendedLandTotal;$i++,$allocated++) $landRecommendation[$rankedColors[$i%count($rankedColors)]]++;
}
if ($deck && isset($_GET['export'])) {
    if($_GET['export']==='json'){
        // Exportação completa: deck, comandante, candidatas e todos os dados de cada carta.
        $jsonColumns=['colors','color_identity','keywords','prices','legalities','card_faces','raw'];
        $decodeCard=function(array $row) use($jsonColumns): array {
            $card=[];
            foreach(['id','oracle_id','lang','name','mana_cost','cmc','type_line','oracle_text','colors','color_identity','keywords','set_code','set_name','collector_number','rarity','artist','released_at','layout','image_uri','image_uri_back','prices','legalities','card_faces','edhrec_rank_cached','commander_eligible','imported_at','raw'] as $key){
                if(!array_key_exists($key,$row)) continue;
                $value=$row[$key];
                if(in_array($key,$jsonColumns,true) && is_string($value)) $value=json_decode($value,true);
                if($key==='cmc' && $value!==null) $value=(float)$value;
                if($key==='commander_eligible' && $value!==null) $value=in_array($value,[true,'t',1,'1'],true);
                $card[$key==='edhrec_rank_cached'?'edhrec_rank':$key]=$value;
            }
            return $card;
        };
        $jsonConfig=deckScoreConfigFor($deck,$commander?:null);
        $jsonScores=$commander ? deckScoreSelection($deck,$commander,$items,$jsonConfig) : null;
        $jsonSynergy=$commander ? deckQuery("SELECT COALESCE(card.oracle_id,card.id)::text,json_build_object('score',MAX(s.score),'inclusion',MAX(s.inclusion),'metric',MAX(s.metric),'synced_at',MAX(s.synced_at)) FROM deck_synergy s JOIN cards leader ON leader.id=s.commander_id JOIN cards card ON card.id=s.card_id WHERE COALESCE(leader.oracle_id,leader.id)=?::uuid GROUP BY 1",[$leader])->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        $gameChangerNames=array_flip(array_map('strtolower',deckScoreGameChangers()));
        $cardEntry=function(array $row,string $stage,int $quantity,string $role,string $notes,int $owned,int $otherUsed) use($decodeCard,$jsonScores,$jsonSynergy,$gameChangerNames): array {
            $logical=(string)($row['oracle_id']?:$row['id']); $fit=$jsonScores['cards'][$row['id']]??null;
            $synergy=isset($jsonSynergy[$logical]) ? json_decode((string)$jsonSynergy[$logical],true) : null;
            return [
                'name'=>$row['name'],
                'selection'=>['stage'=>$stage,'stage_label'=>$stage==='commander'?'Comandante':deckStageLabel($stage),'quantity'=>$quantity,'role'=>$role,'notes'=>$notes],
                'collection'=>['owned_total'=>$owned,'owned_this_printing'=>(int)($row['owned_printing']??0),'normal'=>(int)($row['normal_quantity']??0),'foil'=>(int)($row['foil_quantity']??0),'used_in_other_decks'=>$otherUsed,'available'=>max(0,$owned-$otherUsed),'missing'=>max(0,$quantity-max(0,$owned-$otherUsed))],
                'price_brl'=>deckSelectedPriceBrl($row),
                'game_changer'=>isset($gameChangerNames[strtolower((string)$row['name'])]),
                'images'=>['front'=>cardImageUrl($row),'back'=>cardImageUrl($row,'back')],
                'edhrec_synergy'=>$synergy,
                'relationships'=>$fit ? ['blocked'=>$fit['blocked'],'roles'=>$fit['roles'],'produces'=>$fit['produces'],'cares'=>$fit['cares'],'notes'=>$fit['notes'],'connections'=>$fit['relationships']] : null,
                'scryfall'=>$decodeCard($row),
            ];
        };
        $jsonCards=[];
        foreach($items as $row) $jsonCards[]=$cardEntry($row,(string)$row['stage'],(int)$row['quantity'],(string)$row['role'],(string)$row['notes'],(int)$row['owned'],(int)$row['other_used']);
        usort($jsonCards,fn($a,$b)=>[$a['selection']['stage']!=='deck',$a['name']]<=>[$b['selection']['stage']!=='deck',$b['name']]);
        $payload=[
            'format'=>'deckarium-deck','version'=>1,'exported_at'=>date(DATE_ATOM),
            'deck'=>['id'=>(int)$deck['id'],'name'=>$deck['name'],'status'=>$deck['status'],'strategy'=>$deck['strategy'],'terms'=>$deck['terms'],'created_at'=>$deck['created_at'],
                'card_count'=>$finalCount,'land_count'=>$landCount,'candidate_count'=>array_sum(array_map(fn($row)=>$row['stage']==='candidate'?(int)$row['quantity']:0,$items)),
                'color_identity'=>$identity,'price_total_brl'=>round($deckPriceTotal,2),'unpriced_cards'=>$deckUnpriced,
                'mana_curve'=>array_map('intval',$curveCounts),'mana_symbols'=>$pipCounts,'warnings'=>$warnings,'shopping_list'=>$shopping],
            'relationships'=>['config'=>$jsonConfig,'needs'=>$jsonScores['needs']??[],'open_slots'=>$jsonScores['open_slots']??null,'game_changers'=>$jsonScores['game_changers']??null],
            'commander'=>$commander ? $cardEntry($commander,'commander',1,'Comandante','',(int)($commanderOwned??0),(int)($commanderOtherUsed??0)) : null,
            'cards'=>$jsonCards,
            'upgrades'=>array_map(fn($u)=>['id'=>(int)$u['id'],'remove'=>$u['remove_name'],'add'=>$u['add_name'],'reason'=>$u['reason'],'status'=>$u['status'],'created_at'=>$u['created_at']],$pendingUpgrades),
        ];
        $fileName=trim((string)preg_replace('/[^a-z0-9]+/','-',strtolower(iconv('UTF-8','ASCII//TRANSLIT//IGNORE',(string)$deck['name'])?:'deck')),'-')?:'deck';
        header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="deckarium-'.$fileName.'-'.$id.'.json"');
        echo json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
    if($_GET['export']==='liga'){
        $scope=($_GET['liga_scope']??'missing')==='all'?'all':'missing';$ligaRows=[];
        $ligaPrinting=in_array($_GET['liga_printing']??'liga',['liga','exact','none'],true)?(string)$_GET['liga_printing']:'liga';
        $addLigaRow=function(array $row,int $quantity,int $owned) use(&$ligaRows,$scope):void{
            $exportQuantity=$scope==='all'?$quantity:max(0,$quantity-$owned);if($exportQuantity<1)return;
            $prices=deckPriceOptions($row);$normalOwned=(int)($row['normal_quantity']??0);$foilOwned=(int)($row['foil_quantity']??0);
            $foil=$normalOwned<1&&$foilOwned>0;
            if($normalOwned<1&&$foilOwned<1)$foil=$prices['foil']!==null&&($prices['normal']===null||$prices['foil']<$prices['normal']);
            $row['export_quantity']=$exportQuantity;$row['export_foil']=$foil;$ligaRows[]=$row;
        };
        if($commander)$addLigaRow($commander,1,$commanderAvailable??0);
        foreach($items as $row)if($row['stage']==='deck')$addLigaRow($row,(int)$row['quantity'],max(0,(int)$row['owned']-(int)$row['other_used']));
        header('Content-Type: text/csv; charset=Windows-1252');header('Content-Disposition: attachment; filename="liga-deck-'.$id.'-'.$scope.'.csv"');echo deckLigaCsv($ligaRows,$ligaPrinting);exit;
    }
    header('Content-Type: text/plain; charset=utf-8'); header('Content-Disposition: attachment; filename="'.($_GET['export']==='shopping'?'compras':'deck').'-'.$id.'.txt"');
    if ($_GET['export']==='shopping') foreach ($shopping as $row) echo $row['quantity'].' '.$row['name']."\n";
    else { if ($commander) echo '1 '.$commander['name']."\n"; foreach($items as $row) if($row['stage']==='deck') echo $row['quantity'].' '.$row['name']."\n"; }
    exit;
}
$results=[]; $hasMore=false; $resultTotal=0; $totalPages=1; $fitPreview=[]; $fitConnected=0;
if ($catalogVisible) {
    $where=[];$params=[];
    // Literal substring search: escaping prevents % and _ acting as wildcards.
    $like=fn($s)=>'%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$s).'%';
    if($q!==''){$where[]='c.name ILIKE ?';$params[]=$like($q);}
    // Cartas da Art Series não são jogáveis e não devem aparecer como possibilidades de deck.
    if (!$choosingCommander) $where[]="c.layout <> 'art_series'";
    $textExpr="COALESCE(c.oracle_text,'') || ' ' || COALESCE((SELECT string_agg(f->>'oracle_text',' ') FROM jsonb_array_elements(c.card_faces) f),'')";
    $typeConditions=[];
    foreach (array_slice(deckTerms($type),0,12) as $term) {
        $typeConditions[]="(COALESCE(c.type_line,'') || ' ' || {$textExpr}) ILIKE ?";
        $params[]=$like($term);
    }
    if ($typeConditions) $where[]='('.implode(' OR ',$typeConditions).')';
    // Tipo de carta: qualquer um dos tipos marcados (ex.: Instantânea ou Feitiço), em qualquer face.
    if ($cardTypes) {
        $where[]='('.implode(' OR ',array_fill(0,count($cardTypes),"COALESCE(c.type_line,'') ILIKE ?")).')';
        foreach ($cardTypes as $cardType) $params[]='%'.$cardTypeOptions[$cardType][0].'%';
    }
    $conditions=[];foreach($terms as $term){$conditions[]="({$textExpr}) ILIKE ?";$params[]=$like($term);}
    if($conditions)$where[]='('.implode($match==='any'?' OR ':' AND ',$conditions).')';
    // Só a coleção: parte das cartas lógicas da coleção (índice cards_logical_idx) em vez de varrer o catálogo.
    // No modo "Encaixa no deck" a consulta já parte da coleção (builder_collection).
    if($ownedOnly && !$fitMode)$where[]='COALESCE(c.oracle_id,c.id) IN (SELECT logical_id FROM owned)';
    // Cópia livre: sobra ao menos uma cópia depois do que outros decks já usam.
    if($availability==='free' && !$fitMode)$where[]='GREATEST(COALESCE(o.owned,0)-COALESCE(u.used,0),0)>0';
    if($excludeOwned)$where[]='COALESCE(o.owned,0)=0';
    if($colorsOnly && $commander){$where[]='c.color_identity <@ ?::jsonb';$params[]=json_encode($identity);}
    if($hideSelected && $commander){
        $hiddenLogical=array_values(array_unique(array_merge(array_map('strval',array_keys($selectedByLogical)),[(string)$leader])));
        $where[]='COALESCE(c.oracle_id,c.id) <> ALL(?::uuid[])'; $params[]='{'.implode(',',$hiddenLogical).'}';
    }
    if($fitMode){ $where[]="c.legalities->>'commander'='legal'"; $where[]="COALESCE(c.raw->>'digital','false')='false'"; }
    if($roleFilter!==''){
        // Com comandante, só a identidade dela; sem, qualquer cor.
        $roleIds = deckNeedRoleIds($roleFilter, $commander ? $identity : ['W','U','B','R','G']);
        $where[]='COALESCE(c.oracle_id,c.id) = ANY(?::uuid[])'; $params[]='{'.implode(',',$roleIds).'}';
    }
    if($rarity!==''){$where[]='c.rarity=?';$params[]=$rarity;}
    if($setFilter!==''){$where[]='upper(c.set_code)=?';$params[]=$setFilter;}
    if($cmcMin!==null){$where[]='COALESCE(c.cmc,0)>=?';$params[]=$cmcMin;}
    if($cmcMax!==null){$where[]='COALESCE(c.cmc,0)<=?';$params[]=$cmcMax;}
    if($choosingCommander && $commanderColors){
        $requiredColors=array_values(array_diff($commanderColors,['C']));
        if(in_array('C',$commanderColors,true)) $where[]=$requiredColors ? 'FALSE' : "c.color_identity='[]'::jsonb";
        else {$where[]='c.color_identity @> ?::jsonb';$params[]=json_encode($requiredColors);}
    }
    if($choosingCommander){
        $where[]=deckCommanderSql();
    }
    $whereSql=$where?'WHERE '.implode(' AND ',$where):'';
    $offset=($page-1)*24;
    $synergySelect='NULL::text synergy_metric,NULL::numeric synergy_score'; $synergyJoin=''; $resultParams=$params;
    if($commander && !$choosingCommander){
        $synergySelect='ds.metric synergy_metric,ds.score synergy_score';
        $synergyJoin=" LEFT JOIN (SELECT DISTINCT ON(COALESCE(source.oracle_id,source.id)) s.metric,s.score,COALESCE(source.oracle_id,source.id) logical_id FROM deck_synergy s JOIN cards leader ON leader.id=s.commander_id JOIN cards source ON source.id=s.card_id WHERE COALESCE(leader.oracle_id,leader.id)=?::uuid ORDER BY COALESCE(source.oracle_id,source.id),s.synced_at DESC,s.score DESC) ds ON ds.logical_id=r.logical_id";
        $resultParams[]=$leader;
    }
     $commanderOrder = "(GREATEST(COALESCE(o.owned,0)-COALESCE(u.used,0),0)>0 AND COALESCE(bc.quantity,0)>0) DESC,GREATEST(COALESCE(o.owned,0)-COALESCE(u.used,0),0) DESC,".deckCheapestPriceSql('c')." ASC NULLS LAST,(c.lang='en') DESC,(c.local_image IS NOT NULL) DESC,c.released_at DESC NULLS LAST,c.id";
     $resultOrder = match($sort) {
              'popular' => 'r.edhrec_rank ASC NULLS LAST,r.owned_printing DESC,r.name,r.id',
              'name' => 'r.name,r.id',
              'newest' => 'r.released_at DESC NULLS LAST,r.name,r.id',
              'owned' => 'r.owned_printing DESC,r.owned DESC,r.name,r.id',
              'synergy' => 'synergy_score DESC NULLS LAST,r.owned_printing DESC,r.name,r.id',
              default => 'r.owned_printing DESC,r.owned DESC,r.released_at DESC NULLS LAST,r.name,r.id'
     };
     $edhrecRankSelect=$choosingCommander ? 'c.edhrec_rank_cached' : 'NULL::int';
     if($fitMode){
        // Todas as cartas da coleção que passam nos filtros (limitado pelo tamanho da coleção), com relações calculadas em PHP.
        // Na coleção cada carta já tem uma impressão própria: parte de builder_collection (rápido e sem varrer o catálogo).
        $fitRows=deckQuery("SELECT * FROM (SELECT DISTINCT ON(COALESCE(c.oracle_id,c.id)) c.*,b.quantity owned_printing,NULL::int edhrec_rank,NULL::text synergy_metric,NULL::numeric synergy_score
            FROM builder_collection b JOIN cards c ON c.id=b.scryfall_id
            {$whereSql} AND b.user_id=".(int)$userId." ORDER BY COALESCE(c.oracle_id,c.id),b.quantity DESC,(c.lang='en') DESC,c.released_at DESC NULLS LAST) fit LIMIT 6000",$params)->fetchAll();
        $fitOwned=deckOwnedLogicalMap();
        foreach($fitRows as &$fitRow) $fitRow['owned']=(int)($fitOwned[(string)($fitRow['oracle_id']?:$fitRow['id'])]??0);
        unset($fitRow);
        $fitSelection=[(string)$commander['id']=>$commander+['stage'=>'commander']];
        foreach($items as $selectedItem) $fitSelection[(string)$selectedItem['id']]=$selectedItem;
        $fitOutsiders=[]; foreach($fitRows as $fitRow) $fitOutsiders[(string)$fitRow['id']]=$fitRow;
        $fitPreview=deckRelationPreview($fitOutsiders,$fitSelection,$commander);
        usort($fitRows,fn($a,$b)=>[($fitPreview[$b['id']]['score']??0),($fitPreview[$b['id']]['partners']??0),$b['owned_printing']] <=> [($fitPreview[$a['id']]['score']??0),($fitPreview[$a['id']]['partners']??0),$a['owned_printing']] ?: strcmp((string)$a['name'],(string)$b['name']));
        $resultTotal=count($fitRows); $fitConnected=count($fitPreview);
        $results=array_slice($fitRows,$offset,24);
        $totalPages=max(1,(int)ceil($resultTotal/24)); $hasMore=$page<$totalPages;
     } else {
     $outerOrder=str_replace(['r.','synergy_score'],['page.','page.synergy_score'],$resultOrder);
     $results=deckQuery(deckOwnedSql($id)."SELECT c.*,page.owned,page.owned_printing,page.edhrec_rank,page.synergy_metric,page.synergy_score,page.total_count FROM (
         SELECT r.*,{$synergySelect},COUNT(*) OVER() total_count FROM (SELECT DISTINCT ON(COALESCE(c.oracle_id,c.id)) c.id,c.name,c.released_at,COALESCE(c.oracle_id,c.id) logical_id,COALESCE(o.owned,0) owned,COALESCE(bc.quantity,0) owned_printing,{$edhrecRankSelect} edhrec_rank FROM cards c LEFT JOIN owned o ON o.logical_id=COALESCE(c.oracle_id,c.id) LEFT JOIN used u ON u.logical_id=COALESCE(c.oracle_id,c.id) LEFT JOIN ".deckCollectionPrintingSql()." bc ON bc.scryfall_id=c.id {$whereSql} ORDER BY COALESCE(c.oracle_id,c.id),{$commanderOrder}) r{$synergyJoin}
         ORDER BY {$resultOrder} LIMIT 25 OFFSET {$offset}
     ) page JOIN cards c ON c.id=page.id ORDER BY {$outerOrder}",$resultParams)->fetchAll();
    $resultTotal=(int)($results[0]['total_count']??0); $totalPages=max(1,(int)ceil($resultTotal/24));
    $hasMore=$page<$totalPages; $results=array_slice($results,0,24);
     }
    // Uso das cópias em outros decks, para mostrar se a cópia da coleção está livre.
    // Menor preço entre todas as impressões da carta, para aparecer em cada resultado.
    if($results){
        $logicalIds=array_values(array_unique(array_map(fn($row)=>(string)($row['oracle_id']?:$row['id']),$results)));
        $placeholders=implode(',',array_fill(0,count($logicalIds),'?::uuid'));
        $cheapest=deckQuery("SELECT COALESCE(c.oracle_id,c.id) logical_id, MIN(".deckCheapestPriceSql('c').") price, COUNT(*) printings
            FROM cards c WHERE COALESCE(c.oracle_id,c.id) IN ({$placeholders}) GROUP BY 1",$logicalIds)->fetchAll();
        $cheapestByLogical=[]; foreach($cheapest as $cheapRow) $cheapestByLogical[(string)$cheapRow['logical_id']]=$cheapRow;
        foreach($results as &$priceRow){
            $cheapRow=$cheapestByLogical[(string)($priceRow['oracle_id']?:$priceRow['id'])]??null;
            $priceRow['cheapest_price']=$cheapRow && $cheapRow['price']!==null?(float)$cheapRow['price']:null;
            $priceRow['printing_count']=(int)($cheapRow['printings']??0);
        }
        unset($priceRow);
    }
    if(!$choosingCommander && $results){
        $usageElsewhere=deckUsageElsewhere($id,array_map(fn($row)=>(string)($row['oracle_id']?:$row['id']),$results));
        foreach($results as &$resultRow){
            $resultUsage=$usageElsewhere[(string)($resultRow['oracle_id']?:$resultRow['id'])]??['used'=>0,'decks'=>[]];
            $resultRow['used_elsewhere']=$resultUsage['used']; $resultRow['used_decks']=$resultUsage['decks'];
        }
        unset($resultRow);
    }
}
pageHeader('Meus decks');
?>
<section class="hero"><div><h1><?= h($deck?$deck['name']:'Meus decks') ?></h1><p><?= $deck?'Escolha a impressão certa, organize a lista e registre a intenção de cada carta.':'Continue um planejamento ou abra um deck pronto. Cada carta é cruzada com as impressões da sua coleção.' ?></p></div><?php if($deck): ?><a class="text-link" href="/decks.php">Voltar aos decks</a><?php endif; ?></section>
<?php if($message): ?><p class="notice ok" role="status"><?= h($message) ?></p><?php endif; ?>
<?php if($error && ($deck || !in_array($_POST['action']??'',['create','import_deck'],true))): ?><p class="notice error" role="alert"><?= h($error) ?></p><?php endif; ?>
<?php if(!$deck): $failedForm=$error!=='' && in_array($_POST['action']??'',['create','import_deck'],true) ? (string)$_POST['action'] : ''; ?>
<section class="deck-library">
<div class="deck-library-heading"><p class="muted"><?= count($decks) ?> <?= count($decks)===1?'deck':'decks' ?> · <a href="/collection.php"><?= number_format((int)$collection['total'],0,',','.') ?> cartas na coleção</a></p><div class="deck-library-actions"><button type="button" class="secondary-link" data-dialog-open="deck-import-dialog">Importar lista</button><button type="button" class="primary-link" data-dialog-open="deck-create-dialog">Novo deck</button></div></div>
<?php if(!$decks): ?><div class="empty-state"><h2>Nenhum deck ainda</h2><p>Planeje do zero, escolhendo a comandante e as cartas aos poucos, ou importe uma lista pronta do Moxfield.</p><p class="deck-empty-actions"><button type="button" class="primary-link" data-dialog-open="deck-create-dialog">Planejar do zero</button><button type="button" class="secondary-link" data-dialog-open="deck-import-dialog">Importar uma lista</button></p></div><?php endif; ?>
<div class="deck-library-rows"><?php foreach($decks as $d): ?>
<article class="deck-library-row<?= $d['commander_art'] ? ' has-art-crop' : '' ?>" <?php if($d['commander_card_id']): ?>style="--deck-art:url('<?= h($d['commander_art'] ?: '/image.php?id='.$d['commander_card_id']) ?>')"<?php endif; ?>><a href="?deck=<?= $d['id'] ?>"><?php if($d['commander_card_id']): ?><img class="deck-library-commander" src="/image.php?id=<?= h($d['commander_card_id']) ?>" alt="" loading="lazy"><?php endif; ?><span class="deck-library-copy"><strong><?= h($d['name']) ?></strong><span><?= h($d['commander']?:'Comandante a escolher') ?></span></span></a>
<?php $listedValue=$deckValues[(int)$d['id']]??['total'=>0.0,'unpriced'=>0]; ?><span class="deck-library-meta"><span class="deck-library-count"><?= (int)$d['card_count'] ?> cartas</span><strong class="deck-library-value">R$ <?= number_format((float)$listedValue['total'],2,',','.') ?></strong><?php if($listedValue['unpriced']): ?><small><?= (int)$listedValue['unpriced'] ?> sem cotação</small><?php endif; ?></span><span class="deck-state <?= $d['status']==='ready'?'is-ready':'' ?>"><?= $d['status']==='ready'?'Finalizado':'Em planejamento' ?></span><?php if(in_array($d['is_public']??false,[true,'t',1,'1'],true)): ?><a class="deck-public-badge" href="/public_deck.php?id=<?= (int)$d['id'] ?>" title="Qualquer pessoa com o link pode ver">Público</a><?php endif; ?>
<button type="button" class="deck-delete-trigger" data-deck-delete="deck-delete-<?= $d['id'] ?>" aria-haspopup="dialog">Excluir</button><dialog class="deck-delete-dialog" id="deck-delete-<?= $d['id'] ?>" aria-labelledby="deck-delete-title-<?= $d['id'] ?>"><form method="dialog" class="deck-delete-cancel"><button type="submit" aria-label="Fechar confirmação">×</button></form><h3 id="deck-delete-title-<?= $d['id'] ?>">Excluir “<?= h($d['name']) ?>”?</h3><p>O deck e seus registros de upgrade serão excluídos. Sua coleção permanecerá salva.</p><div class="deck-delete-actions"><button type="button" class="secondary-link" data-dialog-close>Cancelar</button><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="deck" value="<?= $d['id'] ?>"><input type="hidden" name="action" value="delete_deck"><button class="deck-delete-confirm">Excluir deck</button></form></div></dialog></article>
<?php endforeach; ?></div></section>
<dialog class="deck-form-dialog" id="deck-create-dialog" aria-labelledby="deck-create-title"<?= $failedForm==='create'?' data-open-on-load':'' ?>>
<form method="dialog" class="deck-form-dialog-close"><button type="submit" aria-label="Fechar">×</button></form>
<form method="post" class="builder-form"><?php $tokenFields('create'); ?>
    <h2 id="deck-create-title">Planejar do zero</h2>
    <p class="muted">Monte aos poucos: escolha a comandante, filtre pela identidade de cor e acompanhe o que já existe na coleção.</p>
    <?php if($failedForm==='create'): ?><p class="notice error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <label>Nome do deck<input name="name" required maxlength="160" placeholder="Ex.: Dina — ganho e dreno" value="<?= $failedForm==='create'?h((string)($_POST['name']??'')):'' ?>"></label>
    <div class="deck-form-actions"><button type="button" class="secondary-link" data-dialog-close>Cancelar</button><button class="primary-link">Criar planejamento</button></div>
</form>
</dialog>
<dialog class="deck-form-dialog is-wide" id="deck-import-dialog" aria-labelledby="deck-import-title"<?= $failedForm==='import_deck'?' data-open-on-load':'' ?>>
<form method="dialog" class="deck-form-dialog-close"><button type="submit" aria-label="Fechar">×</button></form>
<form method="post" class="builder-form deck-import-form"><?php $tokenFields('import_deck'); ?>
    <h2 id="deck-import-title">Importar uma lista</h2>
    <p class="muted">O deck é criado como finalizado, com a impressão da sua coleção sempre que houver.</p>
    <?php if($failedForm==='import_deck'): ?><p class="notice error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <label>Nome do deck<input name="name" required maxlength="160" placeholder="Ex.: Hakbal — lista atual" value="<?= $failedForm==='import_deck'?h((string)($_POST['name']??'')):'' ?>"></label>
    <label>Comandante<input name="commander" maxlength="200" placeholder="Ex.: Hakbal of the Surging Soul" autocomplete="off" spellcheck="false" value="<?= $failedForm==='import_deck'?h((string)($_POST['commander']??'')):'' ?>"><small>Nome da carta em inglês ou português. Se ficar em branco, usamos a carta sob o cabeçalho “Commander” da lista, ou você escolhe depois.</small></label>
    <label>Restante do deck<textarea name="decklist" rows="12" required maxlength="200000" placeholder="1 Sol Ring&#10;1 Command Tower&#10;1 Rejuvenating Springs&#10;33 Forest"><?= $failedForm==='import_deck'?h((string)($_POST['decklist']??'')):'' ?></textarea><small>Uma carta por linha, no formato “1 Nome da carta”. Exportações do Moxfield funcionam como estão; se a comandante aparecer aqui também, ela não é duplicada.</small></label>
    <div class="deck-form-actions"><button type="button" class="secondary-link" data-dialog-close>Cancelar</button><button class="primary-link">Importar como finalizado</button></div>
</form>
</dialog>
<?php else: ?>
<?php
$needMissingCount = $needPanel ? count(array_filter($needPanel, fn($need) => $need['missing'] > 0)) : 0;
$candidateCount = array_sum(array_map(fn($row) => $row['stage']==='candidate' ? (int)$row['quantity'] : 0, $items));
?>
<?= deckSectionNav($id, ($choosingCommander && $view==='explore') ? 'explore' : $view, $commander && !$choosingCommander, $finalCount) ?>
<div class="deck-workflow-bar <?= $isComplete?'is-complete':'' ?>"><div><strong><?= $isComplete?'Deck finalizado automaticamente':'Planejamento em andamento' ?></strong><span><?= $isComplete?'100 cartas aprovadas na seleção.':'A seleção é finalizada automaticamente quando chegar a 100 cartas no deck.' ?></span></div><span class="deck-progress"><?= $finalCount ?>/100 cartas</span></div>
<?php if($view==='overview'): ?>
<section id="intent" class="builder-intro">
<div class="panel"><div class="panel-heading"><h2>Comandante</h2><a href="?deck=<?= $id ?>&choose=1#explore">Trocar</a></div><div class="builder-commander"><?php if($src=cardImageUrl($commander)): ?><img src="<?= h($src) ?>" alt="<?= h($commander['name']) ?>" width="146" height="204"><?php endif; ?><div><h3><?= h($commander['name']) ?></h3><p class="commander-identity"><strong>Identidade:</strong> <?= $identity?manaSymbols($identityMana):'<span class="muted">Incolor</span>' ?></p><p><?= oracleText(deckText($commander)) ?></p></div></div></div>
<form method="post" class="panel builder-form"><?php $tokenFields('strategy'); ?><h2>Minha intenção</h2><label>Estratégia e mecânicas<textarea name="strategy" rows="4" placeholder="Plano principal, temas secundários e o que quero evitar"><?= h($deck['strategy']) ?></textarea></label><label>Termos Oracle para explorar<input name="terms" value="<?= h($deck['terms']) ?>" placeholder="sacrifice; land; graveyard"></label><small>Separe palavras ou frases por ponto e vírgula. Estes termos são filtros escolhidos por você, não uma avaliação automática de sinergia.</small><button class="primary-link">Salvar intenção</button></form>
</section>
<?php $deckPublic=in_array($deck['is_public']??false,[true,'t',1,'1'],true); $publicUrl='/public_deck.php?id='.$id; ?>
<section class="panel deck-share <?= $deckPublic?'is-public':'' ?>" aria-labelledby="deck-share-title">
    <div><h2 id="deck-share-title">Compartilhar</h2><p class="muted"><?= $deckPublic ? 'Público: qualquer pessoa com o link vê a lista, a comandante e a intenção. Sua coleção, preços e anotações das cartas continuam privados.' : 'Privado: só você vê este deck. Ao tornar público, ele aparece em Comunidade e pode ser aberto por link.' ?></p>
    <?php if($deckPublic): ?><p class="deck-share-link"><input type="text" readonly value="<?= h($publicUrl) ?>" data-share-url aria-label="Link público do deck"><button type="button" class="secondary-link" data-copy-share>Copiar link</button><a href="<?= h($publicUrl) ?>">Ver página pública</a></p><?php endif; ?></div>
    <form method="post"><?php $tokenFields('visibility'); ?><input type="hidden" name="public" value="<?= $deckPublic?'0':'1' ?>"><button class="<?= $deckPublic?'secondary-link':'primary-link' ?>"><?= $deckPublic?'Tornar privado':'Tornar público' ?></button></form>
</section>
<section class="deck-overview-links" aria-label="Próximos passos">
    <a class="deck-overview-card" href="?deck=<?= $id ?>&amp;view=guide"><span>Guia da comandante</span><strong><?= count($guidePlans) ?> planos · <?= count($guideCombos) ?> combos</strong><small>Temas do EDHREC, combos, mecânicas e novidades.</small></a>
    <a class="deck-overview-card" href="?deck=<?= $id ?>&amp;view=needs"><span>O que falta</span><strong><?= $needMissingCount ? $needMissingCount.($needMissingCount===1?' função abaixo da meta':' funções abaixo da meta') : 'Metas atingidas' ?></strong><small>Metas por função calculadas para a comandante, com sugestões.</small></a>
    <a class="deck-overview-card" href="?deck=<?= $id ?>&amp;view=explore&amp;sort=fit"><span>Explorar</span><strong>Encaixa no deck</strong><small>Cartas da sua coleção que se ligam ao deck.</small></a>
    <a class="deck-overview-card" href="?deck=<?= $id ?>&amp;view=selection&amp;stage=candidate"><span>Minha seleção</span><strong><?= $finalCount ?>/100 no deck · <?= $candidateCount ?> candidata<?= $candidateCount===1?'':'s' ?></strong><small>Aprove candidatas e ajuste metas e regras.</small></a>
    <a class="deck-overview-card" href="?deck=<?= $id ?>&amp;view=selection&amp;stage=deck#deck-analysis"><span>Análise do deck</span><strong><?= $landCount ?> terrenos · R$ <?= number_format($deckPriceTotal,0,',','.') ?></strong><small>Curva de mana, cores, funções, alertas e exportação.</small></a>
    <a class="deck-overview-card" href="/deck_board.php?deck=<?= $id ?>"><span>Quadro de relações</span><strong>Setas entre as cartas</strong><small>Quem fornece e quem aproveita cada recurso.</small></a>
</section>
<?php elseif($view==='guide'): ?>
<?php require __DIR__.'/deck_guide_view.php'; ?>
<?php elseif($view==='needs'): ?>
<?php if($needPanel): require __DIR__.'/deck_needs_view.php'; else: ?><p class="empty-state">Não foi possível calcular as metas agora. Tente recarregar a página.</p><?php endif; ?>
<?php elseif($view==='explore'): ?>
<?php require __DIR__.'/deck_discovery_view.php'; ?>
<?php else: require __DIR__.'/deck_selection_view.php'; endif; ?>
<?php endif; if ($deck && $commander && !$choosingCommander): ?>
<?php $selectionMap=[]; foreach($items as $selected){$selectionMap[(string)$selected['id']]=['stage'=>$selected['stage'],'label'=>deckStageLabel($selected['stage']),'image'=>cardImageUrl($selected,'front','small')];} ?>
<script>window.builderSelection=<?= json_encode($selectionMap,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;</script>
<script>window.builderHasCommander=<?= $commander?'true':'false' ?>;</script>
<script>window.builderChoosingCommander=<?= $choosingCommander?'true':'false' ?>;</script>
<?php if($view==='explore'): $exploreSelection=[]; foreach($results as $exploreCard){$logical=(string)($exploreCard['oracle_id']?:$exploreCard['id']); if(isset($selectedByLogical[$logical])) $exploreSelection[(string)$exploreCard['id']]=['stage'=>$selectedByLogical[$logical]['stage'],'label'=>deckStageLabel($selectedByLogical[$logical]['stage'])];} ?>
<script>window.builderExploreSelection=<?= json_encode($exploreSelection,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>
<?php endif; pageFooter(); ?>
