# Dicionário de dados

Banco PostgreSQL do Deckarium (schema `public`). Gerado a partir do banco em execução em 17/09/2026; mantenha este arquivo atualizado ao criar ou alterar tabelas.

## Visão geral

| Grupo | Tabelas | Quem cria |
|---|---|---|
| Catálogo Scryfall | `cards`, `sync_status`, `sync_runs`, `sync_run_cards`, `card_tags` | `database/init.sql`, `app/sync_log.php`, `app/bin/sync_tagger.php` |
| Contas e acesso | `users`, `auth_attempts`, `auth_remember_tokens`, `app_migrations` | `app/auth.php` (migração automática na primeira requisição) |
| Coleção e decks | `builder_collection`, `builder_decks`, `builder_items`, `deck_upgrades` | `deckSchema()` em `app/deck_library.php` e `app/auth.php` |
| Cache de fontes externas | `deck_synergy`, `deck_commander_insights`, `deck_spellbook_cache` | `deckSchema()` |
| Meta dos formatos (MTGO) | `meta_formats`, `meta_events`, `meta_decks`, `meta_deck_cards` | `metaSchema()` em `app/deck_meta.php` |
| Mesa compartilhada | `playtest_tables`, `playtest_seats`, `playtest_events`, `app_secrets` | `playtestSchema()` em `app/playtest_lib.php` |
| Legado | `upgrade_items` | `database/init.sql` |

Não há ferramenta de migração: as tabelas novas usam `CREATE TABLE IF NOT EXISTS` e as colunas novas `ALTER TABLE … ADD COLUMN IF NOT EXISTS`, executados pelo próprio app. `database/init.sql` só roda na criação do volume do PostgreSQL.

`deckSchema()` roda **uma vez por versão**: depois de aplicar, grava `deck_schema_vN` em `app_migrations` e as próximas requisições pulam os `ALTER TABLE` (que travam as tabelas e, com a mesa compartilhada consultando o servidor sem parar, chegavam a causar deadlocks). Ao mudar o esquema dos decks, suba `DECK_SCHEMA_VERSION` em `app/deck_library.php`. `metaSchema()` e `playtestSchema()` só criam as tabelas quando elas ainda não existem (`to_regclass`).

### Conceitos usados em várias tabelas

- **Impressão**: uma linha de `cards`, identificada pelo `id` do Scryfall (UUID). Cada edição, arte, idioma e número de colecionador é uma impressão diferente.
- **Carta lógica**: o conjunto de impressões com o mesmo `oracle_id`. Nas consultas aparece como `COALESCE(oracle_id, id)`, porque algumas cartas (ex.: faces reversíveis) guardam o `oracle_id` só em `card_faces`.
- **Cópia livre**: cópias de uma carta lógica na coleção menos as usadas em decks (cartas aprovadas + comandantes). Candidatas não reservam cópias.

### Relacionamentos

```mermaid
erDiagram
    users ||--o{ builder_decks : "possui"
    users ||--o{ builder_collection : "possui"
    cards ||--o{ builder_collection : "scryfall_id"
    cards ||--o{ builder_decks : "commander_id"
    builder_decks ||--o{ builder_items : "deck_id"
    cards ||--o{ builder_items : "card_id"
    builder_decks ||--o{ deck_upgrades : "deck_id"
    builder_decks ||--o| deck_spellbook_cache : "deck_id"
    cards ||--o{ deck_synergy : "commander_id / card_id"
    cards ||--o| deck_commander_insights : "commander_id"
    sync_runs ||--o{ sync_run_cards : "run_id"
    cards ||--o{ sync_run_cards : "card_id (sem FK)"
```

`builder_collection.scryfall_id` e `sync_run_cards.card_id` não têm chave estrangeira: a coleção aceita impressões que ainda não estão no catálogo local, e o histórico continua válido se uma carta sair do catálogo.

---

## Catálogo Scryfall

### `cards`

Uma linha por impressão do Scryfall (`default_cards`, ~118 mil linhas). Preenchida por `app/bin/sync_scryfall.php` (upsert por `id`) e, para cartas avulsas buscadas por nome, por `upsertScryfallCard()` em `app/scryfall_local.php`.

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `id` | uuid | não | | **PK.** ID da impressão no Scryfall. |
| `oracle_id` | uuid | sim | | Identidade da carta lógica. Nulo em alguns layouts; use `COALESCE(oracle_id, id)`. |
| `lang` | varchar | não | `'en'` | Idioma da impressão (`en`, `pt`, `ja`…). |
| `name` | text | não | | Nome impresso. Cartas de duas faces usam `Face A // Face B`. |
| `mana_cost` | text | sim | | Custo no formato `{2}{G}{G}`. |
| `cmc` | numeric | sim | | Valor de mana. |
| `type_line` | text | sim | | Linha de tipo completa. |
| `oracle_text` | text | sim | | Texto Oracle (em inglês). |
| `colors` | jsonb | não | `'[]'` | Cores da carta, ex.: `["B","G"]`. |
| `color_identity` | jsonb | não | `'[]'` | Identidade de cor para Commander. |
| `keywords` | jsonb | não | `'[]'` | Palavras-chave (Flying, Ward…). |
| `set_code` | varchar | sim | | Código da edição (`eoc`, `dsk`…). |
| `set_name` | text | sim | | Nome da edição. |
| `collector_number` | varchar | sim | | Número de colecionador (pode ter letras, ex.: `2027-1`, `123★`). |
| `rarity` | varchar | sim | | `common`, `uncommon`, `rare`, `mythic`, `special`, `bonus`. |
| `artist` | text | sim | | Artista. |
| `released_at` | date | sim | | Data de lançamento da impressão. |
| `layout` | varchar | sim | | Layout do Scryfall (`normal`, `transform`, `token`, `art_series`…). |
| `image_uri` | text | sim | | URL da imagem da frente no Scryfall. |
| `image_uri_back` | text | sim | | URL da imagem do verso, quando existe. |
| `local_image` | text | sim | | Caminho de imagem local do formato antigo (v3); mantido por compatibilidade. |
| `local_image_back` | text | sim | | Idem, verso. |
| `prices` | jsonb | não | `'{}'` | Preços do Scryfall (`usd`, `usd_foil`, `eur`, `eur_foil`…). Convertidos para BRL por `USD_BRL_RATE`/`EUR_BRL_RATE`. |
| `legalities` | jsonb | não | `'{}'` | Legalidade por formato (`commander: legal`…). |
| `card_faces` | jsonb | não | `'[]'` | Faces da carta (nome, custo, tipo, texto e imagens de cada face). |
| `raw` | jsonb | não | | Registro completo do Scryfall; várias regras leem daqui (`digital`, `promo`, `all_parts`…). |
| `imported_at` | timestamptz | não | `now()` | Última vez que a linha foi inserida ou atualizada pela sincronização. |
| `edhrec_rank_cached` | integer | sim | *gerada* | `raw->>'edhrec_rank'` como inteiro. Popularidade geral no EDHREC (menor = mais popular). |
| `commander_eligible` | boolean | sim | *gerada* | Pode ser comandante: criatura lendária, veículo/espaçonave lendário com poder e resistência, ou texto “can be your commander” (face frontal). |

Índices: nome (`lower(name)`, primeira face, trigrama), texto Oracle (trigrama), `oracle_id`, carta lógica (`COALESCE(oracle_id,id)`), `set_code`, `released_at DESC`, identidade de cor (GIN) e `cards_commander_picker_idx` (parcial, só elegíveis a comandante).

Imagens baixadas não ficam no banco: estão em `storage/images/{small,normal}/<id>-{front,back}.jpg`.

### `sync_status`

Última importação de cada conjunto de dados do Scryfall. Usada para saber se há atualização nova.

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `bulk_type` | text | não | | **PK.** Conjunto do Bulk Data (`default_cards`, `oracle_cards`…). |
| `scryfall_updated_at` | timestamptz | sim | | Data de publicação do arquivo no Scryfall. |
| `imported_at` | timestamptz | não | `now()` | Quando a importação local terminou. |
| `source_url` | text | sim | | URL baixada. |
| `card_count` | bigint | não | `0` | Registros processados na importação. |

### `sync_runs`

Uma linha por execução da sincronização do catálogo (painel Status ou terminal). Base do **Histórico de atualizações** (`/sync_history.php`).

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `id` | bigint | não | sequência | **PK.** |
| `bulk_type` | text | não | | Conjunto importado. |
| `remote_updated_at` | timestamptz | sim | | Data de publicação no Scryfall. |
| `started_at` | timestamptz | não | `now()` | Início da importação. |
| `finished_at` | timestamptz | sim | | Fim (nulo enquanto roda). |
| `state` | text | não | `'importing'` | `importing`, `completed`, `error` ou `interrupted`. Um `importing` sem processo ativo é exibido como interrompido. |
| `processed` | integer | não | `0` | Registros lidos do arquivo. |
| `added` | integer | não | `0` | Cartas inseridas pela primeira vez. |
| `initial_import` | boolean | não | `false` | O catálogo estava vazio no início (toda carta conta como nova). |
| `error` | text | não | `''` | Mensagem de erro ou interrupção. |

### `sync_run_cards`

Cartas que entraram no catálogo em cada execução. Gravadas na mesma transação do lote importado.

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `run_id` | bigint | não | | **PK**, FK → `sync_runs.id` (apaga em cascata). |
| `card_id` | uuid | não | | **PK.** Impressão inserida (`cards.id`, sem FK). |

### `auto_update_runs`

Uma linha por execução da rotina automática (cron às 06:10 e 18:10, ou o botão “Executar agora” em Status). Base do histórico em **Status → Atualização automática**.

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `id` | bigint | não | sequência | **PK.** |
| `trigger_source` | text | não | `'cron'` | `cron` (horário agendado) ou `manual` (botão em Status). |
| `state` | text | não | `'checking'` | `checking`, `syncing`, `downloading_images`, `up_to_date`, `completed`, `images_pending`, `skipped`, `error` ou `interrupted`. |
| `started_at` | timestamptz | não | `now()` | Início da rotina. |
| `finished_at` | timestamptz | sim | | Fim (nulo enquanto roda). |
| `bulk_type` | text | não | `''` | Conjunto consultado no Scryfall. |
| `remote_updated_at` | timestamptz | sim | | Publicação encontrada no manifesto. |
| `had_update` | boolean | não | `false` | Havia dados novos para importar. |
| `sync_run_id` | bigint | sim | | Execução correspondente em `sync_runs`. |
| `cards_imported` | integer | não | `0` | Registros lidos na importação. |
| `cards_added` | integer | não | `0` | Cartas novas no catálogo. |
| `images_downloaded` | integer | não | `0` | Imagens baixadas na passagem. |
| `images_failed` | integer | não | `0` | Imagens que falharam. |
| `images_bytes` | bigint | não | `0` | Bytes transferidos. |
| `error` | text | não | `''` | Mensagem de erro ou interrupção. |

### `card_tags`

Tags de função do Scryfall Tagger (opcional, `php bin/sync_tagger.php`). Complementam as relações detectadas no texto.

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `oracle_id` | uuid | não | | **PK.** Carta lógica. |
| `tag` | text | não | | **PK.** Nome da tag (índice próprio). |

---

## Contas e acesso

### `users`

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `id` | bigint | não | sequência | **PK.** |
| `full_name` | text | não | | Nome completo. Nunca aparece nas páginas públicas. |
| `username` | text | não | | Nome de usuário, único sem diferenciar maiúsculas. Aparece como `@usuario` na Comunidade. |
| `email` | text | não | | Email, único sem diferenciar maiúsculas. Privado. |
| `password_hash` | text | não | | Hash `password_hash()` (bcrypt). |
| `role` | text | não | `'user'` | `user` ou `admin` (CHECK). |
| `is_active` | boolean | não | `true` | Conta desativada não entra e some das páginas públicas. |
| `created_at` | timestamptz | não | `now()` | Criação. |
| `updated_at` | timestamptz | não | `now()` | Última alteração do perfil. |
| `password_changed_at` | timestamptz | não | `now()` | Troca de senha; invalida outras sessões. |
| `last_login_at` | timestamptz | sim | | Último login. |
| `session_version` | int | não | `0` | Sobe em "Sair de todos os outros aparelhos"; entra no fingerprint e derruba as outras sessões. |
| `collection_public` | boolean | não | `false` | Coleção visível em `/public_collection.php?u=<usuario>`. |
| `display_name` | text | não | `''` | Nome de exibição do perfil público; vazio mostra `@username`. O nome completo nunca é público. |
| `bio` | text | não | `''` | Bio do perfil (até 600 caracteres). |
| `location` | text | não | `''` | Local mostrado no perfil. |
| `website` | text | não | `''` | Site ou rede social (sempre `http(s)://`). |
| `favorite_colors` | jsonb | não | `[]` | Cores favoritas (`W`,`U`,`B`,`R`,`G`,`C`). |
| `avatar_file` | text | sim | | Foto de perfil em `storage/profiles/<id>/`, servida por `profile_image.php`. |
| `cover_file` | text | sim | | Capa enviada em `storage/profiles/<id>/`. |
| `cover_card_id` | uuid | sim | | Carta cuja ilustração (`art_crop`) é a capa, quando não há capa enviada. |
| `cover_position` | smallint | não | `50` | Enquadramento vertical da capa (0–100 %). |

### `auth_attempts`

Tentativas de login e cadastro, para o bloqueio de 15 minutos após 8 falhas.

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `id` | bigint | não | sequência | **PK.** |
| `kind` | text | não | | `login` ou `register`. |
| `identifier` | text | não | | Usuário/email tentado (ou o IP, no cadastro). |
| `ip` | text | não | | IP do cliente. |
| `succeeded` | boolean | não | | Se a tentativa deu certo. |
| `created_at` | timestamptz | não | `now()` | Momento da tentativa (índice com `kind`). |

### `auth_remember_tokens`

"Manter conectado": um token por aparelho. O cookie `deckarium_remember` leva `seletor:validador`; o banco guarda só o hash do validador. Quando a sessão PHP some (deploy, reinício do container, navegador fechado), o token abre uma sessão nova e a validade de 90 dias é renovada a cada uso. Sair da conta apaga o token do aparelho; trocar a senha ou desativar a conta derruba todos. A sessão guarda o seletor e confere a cada requisição se o token ainda existe, então desconectar um aparelho em Minha conta → Aparelhos conectados derruba também a sessão aberta nele.

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `id` | bigint | não | sequência | **PK.** |
| `user_id` | bigint | não | | FK → `users.id` (apaga em cascata). |
| `selector` | text | não | | Parte pública do cookie, única. Localiza a linha. |
| `validator_hash` | text | não | | SHA-256 da parte secreta do cookie. |
| `fingerprint` | text | não | | Mesmo fingerprint da sessão (id, hash da senha, ativo, `session_version`); se mudar, o token não vale. |
| `user_agent` | text | não | `''` | Navegador que entrou, para identificar o aparelho. |
| `ip` | text | não | `''` | Último IP que usou o token. |
| `created_at` | timestamptz | não | `now()` | Quando o aparelho entrou. |
| `last_used_at` | timestamptz | não | `now()` | Última vez que o token reabriu a sessão. |
| `expires_at` | timestamptz | não | | Vencimento; renovado a cada uso. |

### `app_migrations`

Migrações já aplicadas por `authMigrate()`.

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `name` | text | não | | **PK.** Ex.: `auth_v1`, `ownership_v1`, `remember_v1`, `sessions_v1`. |
| `applied_at` | timestamptz | não | `now()` | Quando foi aplicada. |

---

## Coleção e decks

### `builder_collection`

Cópias físicas de cada usuário, por impressão e acabamento. Importada por CSV em **Minha coleção**.

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `user_id` | bigint | não | | **PK**, FK → `users.id` (apaga em cascata). |
| `scryfall_id` | uuid | não | | **PK.** Impressão (`cards.id`, sem FK). |
| `foil` | boolean | não | `false` | **PK.** Acabamento foil. |
| `name` | text | não | | Nome informado no CSV (usado quando a impressão não está no catálogo). |
| `quantity` | integer | não | | Cópias (> 0). |

### `builder_decks`

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `id` | bigint | não | sequência | **PK.** |
| `user_id` | bigint | não | | Dono. FK → `users.id` (apaga em cascata). |
| `name` | text | não | | Nome do deck (até 160 caracteres). |
| `format` | text | não | `'commander'` | Formato do deck (`commander`, `paupercommander`, `brawl`, `standardbrawl`, `duel`, `oathbreaker`, `standard`, `pioneer`, `modern`, `legacy`, `vintage`, `pauper`, `premodern`). As regras de cada um ficam em `deckFormats()` (`app/deck_formats.php`). |
| `commander_id` | uuid | sim | | Impressão escolhida como comandante (ou oathbreaker). Nula nos formatos construídos. FK → `cards.id`. |
| `signature_id` | uuid | sim | | Oathbreaker: o feitiço de assinatura, na zona de comando. FK → `cards.id`. |
| `strategy` | text | não | `''` | “Minha intenção”: estratégia em texto livre. Aparece na página pública. |
| `terms` | text | não | `''` | Termos Oracle padrão do Explorar, separados por `;`. |
| `status` | text | não | `'planning'` | `planning` ou `ready` (tamanho do formato atingido: 100 ou 60 exatas com comandante; 60 ou mais e até 15 no sideboard nos construídos). |
| `scoring_config` | jsonb | sim | | Metas e regras personalizadas; nulo = automáticas. `land_fill` guarda a meta de “Completar com terrenos” (`total`, nulo = sugestão pelo deck) e `buy` (incluir terrenos fora da coleção). |
| `is_public` | boolean | não | `false` | Deck visível em `/public_deck.php?id=<id>` e na Comunidade. |
| `created_at` | timestamptz | sim | `now()` | Criação. |

### `builder_items`

Cartas de cada deck, nas etapas da seleção.

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `deck_id` | bigint | não | | **PK**, FK → `builder_decks.id` (apaga em cascata). |
| `card_id` | uuid | não | | **PK**, FK → `cards.id`. Impressão escolhida. |
| `stage` | text | não | | `candidate` (candidata), `deck` (aprovada) ou `sideboard` (construídos). `review` é legado e é convertido para `candidate`. |
| `quantity` | integer | não | | Cópias (> 0). Limite pelo formato (`deckCopyLimit()`): 1 nos formatos com comandante, 4 nos construídos, 1 para as restritas do Vintage; básicos e cartas como Relentless Rats sem limite. |
| `role` | text | não | `''` | Função informada pelo usuário (ramp, compra…). Privada. |
| `notes` | text | não | `''` | Avaliação do usuário. Privada. |

Só `stage = 'deck'`, a comandante e o feitiço de assinatura contam no tamanho do deck. Deck, sideboard, comandante e feitiço de assinatura reservam cópias da coleção e aparecem na página pública; o quadro de relações usa o deck e a zona de comando.

### `trade_lists`

A lista de venda e troca de cada usuário e o link público dela (`/public_trade.php?t=token`).

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `user_id` | bigint | não | | **PK**, FK → `users.id` (apaga em cascata). Uma lista por conta. |
| `token` | text | não | | Único. Endereço do link público; trocá-lo derruba o link anterior. |
| `title` | text | não | `''` | Título da página pública. |
| `intro` | text | não | `''` | Recado para quem abre o link. |
| `contact` | text | não | `''` | Contato exibido na página. |
| `mode` | text | não | `'free'` | `free` (cópias soltas, calculadas a cada visita) ou `manual` (cartas escolhidas). |
| `is_public` | boolean | não | `false` | Link ligado. Desligado, só o dono vê a página (prévia). |
| `show_prices` | boolean | não | `true` | Mostrar preços na página pública. |
| `created_at` / `updated_at` | timestamptz | não | `now()` | Criação e última alteração. |

### `trade_items`

Cartas marcadas à mão no modo `manual`. No modo `free` a lista não usa esta tabela: ela é calculada da coleção menos o que os decks usam.

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `user_id` | bigint | não | | **PK**, FK → `users.id` (apaga em cascata). |
| `scryfall_id` | uuid | não | | **PK**, FK → `cards.id`. Impressão anunciada. |
| `foil` | boolean | não | `false` | **PK.** Acabamento, como na coleção. |
| `quantity` | integer | não | `1` | Cópias anunciadas (limitadas pelo que existe na coleção). |
| `price` | numeric(10,2) | sim | | Preço pedido em reais; nulo usa a referência do Scryfall ou “a combinar”. |
| `note` | text | não | `''` | Estado, idioma ou detalhe da cópia. |
| `added_at` | timestamptz | não | `now()` | Quando entrou na lista. |

### `deck_upgrades`

Trocas planejadas em decks fechados (100 + 1).

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `id` | bigint | não | sequência | **PK.** |
| `deck_id` | bigint | não | | FK → `builder_decks.id` (apaga em cascata). |
| `remove_card_id` | uuid | não | | Carta que sai. FK → `cards.id`. |
| `add_card_id` | uuid | não | | Carta que entra. FK → `cards.id`. |
| `reason` | text | não | `''` | Motivo da troca. |
| `status` | text | não | `'planned'` | `planned` ou `done` (CHECK). |
| `created_at` | timestamptz | não | `now()` | Criação. |

---

## Cache de fontes externas

### `deck_synergy`

Recomendações do EDHREC por comandante. Buscadas automaticamente na primeira visita ao deck (`deckEnsureEdhrec()`) ou pelo botão **Atualizar** do guia.

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `commander_id` | uuid | não | | **PK**, FK → `cards.id` (cascata). Impressão da comandante usada na busca; as consultas comparam pela carta lógica. |
| `card_id` | uuid | não | | **PK**, FK → `cards.id` (cascata). Carta recomendada. |
| `metric` | text | não | `'synergy'` | `synergy` (percentual) ou `lift` (escala diferente; não comparar entre si). |
| `score` | numeric | não | | Valor da métrica. |
| `inclusion` | numeric | sim | | Fração dos decks da comandante que usam a carta. |
| `deck_count` | integer | sim | | Número de decks com a carta. |
| `source_url` | text | não | | Página do EDHREC consultada. |
| `synced_at` | timestamptz | não | `now()` | Data da consulta. |

Falhas da busca automática criam um marcador em `storage/edhrec-misses/<oracle_id>` que evita nova tentativa por 6 horas.

### `deck_commander_insights`

Guia da comandante (planos, combos, mecânicas, cartas novas, estimativas de função) montado a partir do EDHREC.

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `commander_id` | uuid | não | | **PK**, FK → `cards.id` (cascata). |
| `source_url` | text | não | | Página consultada. |
| `payload` | jsonb | não | | Dados do guia: `version`, `themes`, `combos`, `role_estimates`… |
| `synced_at` | timestamptz | não | `now()` | Data da consulta. |

### `deck_spellbook_cache`

Resultado do *Find My Combos* do Commander Spellbook para um deck (quadro de relações).

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `deck_id` | bigint | não | | **PK**, FK → `builder_decks.id` (cascata). |
| `payload` | jsonb | não | | Combos `included` (completos) e `almost` (falta uma carta). |
| `synced_at` | timestamptz | não | `now()` | Data da consulta. |

## Meta dos formatos (MTGO)

Listas públicas dos torneios do MTGO (`www.mtgo.com/decklists`), buscadas por `bin/sync_meta.php` em segundo plano quando o meta de um formato tem mais de 36 horas (ou pelo botão **Atualizar agora** da aba Meta do formato). Guarda os últimos 60 dias.

### `meta_formats`

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `format` | text | não | | **PK.** Formato do Deckarium (`modern`, `pauper`…). |
| `synced_at` | timestamptz | sim | | Última busca concluída. |
| `attempted_at` | timestamptz | sim | | Última tentativa (uma falha espera 30 minutos para tentar de novo). |
| `decks`, `events` | integer | não | `0` | Totais guardados. |
| `message` | text | não | `''` | Resumo da última busca ou o erro. |

### `meta_events`

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `id` | text | não | | **PK.** Nome da página no MTGO (`modern-challenge-32-2026-09-2712854927`). |
| `format` | text | não | | Formato. |
| `name` | text | não | | Nome do evento (“Modern Challenge 32”). |
| `kind` | text | não | `'league'` | `challenge`, `qualifier`, `league` ou `other`. |
| `event_date` | date | sim | | Data do evento. |
| `url` | text | não | | Página do evento. |
| `deck_count` | integer | não | `0` | Decks guardados. |
| `synced_at` | timestamptz | não | `now()` | Quando foi buscado. |

### `meta_decks`

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `id` | bigint | não | sequência | **PK.** |
| `event_id` | text | não | | FK → `meta_events.id` (cascata). |
| `format` | text | não | | Formato. |
| `player`, `rank`, `wins`, `losses` | | | | Jogador, colocação (Challenges) e campanha (ligas). |
| `colors` | text | não | `''` | Cores das mágicas (`UB`, `WUG`…). |
| `signature` | text | não | `''` | A carta que define o arquétipo: a de mais cópias que aparece em menos decks do formato. |
| `archetype` | text | não | `''` | Nome montado: cores + carta (“Izzet · Murktide Regent”). |
| `main_count`, `core_count`, `side_count` | integer | não | `0` | Cartas no deck, no deck sem básicos e no sideboard. |

### `meta_deck_cards`

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `deck_id` | bigint | não | | **PK**, FK → `meta_decks.id` (cascata). |
| `name` | text | não | | **PK.** Nome da carta como o MTGO publica. |
| `sideboard` | boolean | não | `false` | **PK.** Carta do sideboard. |
| `quantity` | integer | não | | Cópias (o MTGO repete a carta por impressão; aqui já vem somada). |
| `basic` | boolean | não | `false` | Terreno básico (fica fora das comparações entre decks). |
| `logical_id` | uuid | sim | | Carta lógica do catálogo (`oracle_id`), resolvida pelo nome ou pela face da frente. |

## Mesa compartilhada

A página é `deck_playtest.php?mesa=CÓDIGO`. O PHP (`playtest_table.php`) abre a mesa e senta os jogadores; a partida corre no WebSocket do serviço `realtime/`, que lê e grava as mesmas tabelas. Mesas paradas há mais de dois dias são apagadas (com assentos e eventos).

### `app_secrets`

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `name` | text | não | | **PK.** `realtime`: chave dos bilhetes do WebSocket. |
| `value` | text | não | | Segredo aleatório (64 hex), criado pelo PHP na primeira mesa e lido pelo serviço de tempo real. |
| `created_at` | timestamptz | não | `now()` | |

### `playtest_tables`

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `id` | text | não | | **PK.** Código de 12 caracteres do link. |
| `host_user_id` | bigint | não | | Quem abriu a mesa (passa para o próximo jogador se sair). FK → `users.id` (cascata). |
| `format` | text | não | `'commander'` | Formato: só entram decks dele. |
| `status` | text | não | `'lobby'` | `lobby` ou `playing`. |
| `game` | integer | não | `0` | Número da partida (sobe a cada “Começar partida”). |
| `starting_seat`, `turn_seat` | smallint | sim | | Quem começou (sorteado) e de quem é a vez. |
| `turn_number` | integer | não | `0` | Turno da mesa (1 = primeiro turno da partida). |
| `phase` | smallint | não | `0` | Fase de quem está jogando (0 desvirar … 6 final). |
| `version` | bigint | não | `1` | Sobe quando assentos, vez ou fase mudam. |
| `created_at`, `updated_at` | timestamptz | não | `now()` | |

### `playtest_seats`

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `table_id` | text | não | | **PK**, FK → `playtest_tables.id` (cascata). |
| `seat` | smallint | não | | **PK.** 0 a 3. |
| `user_id` | bigint | não | | Jogador (um assento por pessoa na mesa). FK → `users.id`. |
| `deck_id` | bigint | não | | Deck do jogador. FK → `builder_decks.id`. |
| `public_state` | jsonb | sim | | O que todos veem: campo, cemitério, exílio, zona de comando, contagens de mão e grimório, vida, veneno, dano de comandante recebido e as cartas visíveis (`defs`). Gravado pelo serviço de tempo real, agrupado a cada 0,8 s. |
| `private_state` | jsonb | sim | | A partida completa, devolvida só ao dono para retomar ao recarregar. |
| `state_version` | bigint | não | `0` | Sobe a cada estado público recebido. |
| `eliminated` | boolean | não | `false` | Concedeu ou saiu durante a partida (fica fora da ordem de turno). |
| `joined_at`, `last_seen` | timestamptz | não | `now()` | `last_seen`: atualizado pelo serviço de tempo real ao conectar, ao sair e a cada 30 s com o socket aberto (menos de 40 s = conectado). |

### `playtest_events`

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `id` | bigint | não | sequência | **PK.** Ordem dos eventos. |
| `table_id` | text | não | | FK → `playtest_tables.id` (cascata). |
| `seat` | smallint | sim | | Quem gerou. |
| `kind` | text | não | | `join`, `leave`, `start`, `lobby`, `turn`, `log`, `chat`, `damage`, `life`, `poison`, `reveal`, `dice`, `attack`, `concede`. |
| `payload` | jsonb | não | `'{}'` | Dados do evento (ex.: `damage` → `to`, `amount`, `commander`, `combat`, `sources`). |
| `created_at` | timestamptz | não | `now()` | |

---

## Legado

### `upgrade_items`

Lista fixa de trocas da página antiga de upgrades, semeada por `database/init.sql`. O fluxo atual usa `deck_upgrades`.

| Coluna | Tipo | Nulo | Padrão | Descrição |
|---|---|---|---|---|
| `id` | bigint | não | sequência | **PK.** |
| `deck_slug` | text | não | | Identificador do deck (único com `sort_order`). |
| `deck_name` | text | não | | Nome do deck. |
| `sort_order` | integer | não | | Ordem da troca. |
| `remove_name` | text | não | | Nome da carta que sai. |
| `add_name` | text | não | | Nome da carta que entra. |
| `reason` | text | não | | Motivo. |

---

## O que é público

Com `builder_decks.is_public` ou `users.collection_public` ligados, as páginas abertas sem login mostram apenas:

- **Deck:** nome, `@username`, comandante, `strategy` e as cartas com `stage = 'deck'` (nome, imagem, quantidade).
- **Coleção:** impressões, quantidades, edição, número, idioma e foil.

Com `trade_lists.is_public` ligado, o link `/public_trade.php?t=token` mostra as cartas à venda: impressão, quantidade disponível, acabamento, idioma, preço (quando `show_prices`), observação da cópia e o `contact` que o dono escreveu. Continuam fora os decks em que as cartas estão, o restante da coleção e os dados da conta.

Nunca são expostos: `full_name`, `email`, candidatas, `role`/`notes` das cartas, preços, nem em quais decks cada carta está.
