<?php
declare(strict_types=1);
/** Sobre o site: o guia de cada módulo do Deckarium (era a página inicial antes de o jogo ir para "/"). */
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/catalog_cache.php';
require __DIR__ . '/card_actions.php';
$cardActionsUser = (int)(authUser()['id'] ?? 0);
cardActionHandlePost($cardActionsUser);
$GLOBALS['cardActionsContext'] = ['user_id' => $cardActionsUser, 'decks' => cardActionDecks($cardActionsUser),
    'wishlist' => wishlistLogicalIds($cardActionsUser), 'back' => authSafeNext((string)($_SERVER['REQUEST_URI'] ?? '/sobre.php'), '/sobre.php')];
$GLOBALS['isAbout'] = true;
require __DIR__ . '/home.php';
