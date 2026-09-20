CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE TABLE IF NOT EXISTS cards (
    id UUID PRIMARY KEY,
    oracle_id UUID NULL,
    lang VARCHAR(12) NOT NULL DEFAULT 'en',
    name TEXT NOT NULL,
    mana_cost TEXT NULL,
    cmc NUMERIC NULL,
    type_line TEXT NULL,
    oracle_text TEXT NULL,
    colors JSONB NOT NULL DEFAULT '[]'::jsonb,
    color_identity JSONB NOT NULL DEFAULT '[]'::jsonb,
    keywords JSONB NOT NULL DEFAULT '[]'::jsonb,
    set_code VARCHAR(16) NULL,
    set_name TEXT NULL,
    collector_number VARCHAR(64) NULL,
    rarity VARCHAR(32) NULL,
    artist TEXT NULL,
    released_at DATE NULL,
    layout VARCHAR(64) NULL,
    image_uri TEXT NULL,
    image_uri_back TEXT NULL,
    local_image TEXT NULL,
    local_image_back TEXT NULL,
    prices JSONB NOT NULL DEFAULT '{}'::jsonb,
    legalities JSONB NOT NULL DEFAULT '{}'::jsonb,
    card_faces JSONB NOT NULL DEFAULT '[]'::jsonb,
    raw JSONB NOT NULL,
    edhrec_rank_cached INTEGER GENERATED ALWAYS AS (
        CASE WHEN COALESCE(raw->>'edhrec_rank','') ~ '^[0-9]+$' THEN (raw->>'edhrec_rank')::int END
    ) STORED,
    commander_eligible BOOLEAN GENERATED ALWAYS AS (
        ((COALESCE(raw->'card_faces'->0->>'type_line',type_line,'') LIKE '%Legendary%'
            AND (COALESCE(raw->'card_faces'->0->>'type_line',type_line,'') LIKE '%Creature%'
                OR ((COALESCE(raw->'card_faces'->0->>'type_line',type_line,'') LIKE '%Vehicle%'
                    OR COALESCE(raw->'card_faces'->0->>'type_line',type_line,'') LIKE '%Spacecraft%')
                    AND COALESCE(raw->'card_faces'->0->>'power',raw->>'power') IS NOT NULL
                    AND COALESCE(raw->'card_faces'->0->>'toughness',raw->>'toughness') IS NOT NULL)))
         OR COALESCE(raw->'card_faces'->0->>'oracle_text',oracle_text,'') ILIKE '%can be your commander%')
    ) STORED,
    imported_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS cards_name_trgm_idx ON cards USING gin (name gin_trgm_ops);
CREATE INDEX IF NOT EXISTS cards_oracle_id_idx ON cards (oracle_id);
CREATE INDEX IF NOT EXISTS cards_set_code_idx ON cards (set_code);
CREATE INDEX IF NOT EXISTS cards_released_at_idx ON cards (released_at DESC);
CREATE INDEX IF NOT EXISTS cards_commander_picker_idx ON cards (edhrec_rank_cached, lower(name), id) WHERE commander_eligible;

CREATE TABLE IF NOT EXISTS sync_status (
    bulk_type TEXT PRIMARY KEY,
    scryfall_updated_at TIMESTAMPTZ NULL,
    imported_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    source_url TEXT NULL,
    card_count BIGINT NOT NULL DEFAULT 0
);

-- Histórico de sincronizações e cartas inseridas em cada uma (mantido também por app/sync_log.php).
CREATE TABLE IF NOT EXISTS sync_runs (
    id BIGSERIAL PRIMARY KEY,
    bulk_type TEXT NOT NULL,
    remote_updated_at TIMESTAMPTZ NULL,
    started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    finished_at TIMESTAMPTZ NULL,
    state TEXT NOT NULL DEFAULT 'importing',
    processed INTEGER NOT NULL DEFAULT 0,
    added INTEGER NOT NULL DEFAULT 0,
    initial_import BOOLEAN NOT NULL DEFAULT false,
    error TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS sync_run_cards (
    run_id BIGINT NOT NULL REFERENCES sync_runs(id) ON DELETE CASCADE,
    card_id UUID NOT NULL,
    PRIMARY KEY (run_id, card_id)
);
CREATE INDEX IF NOT EXISTS sync_runs_started_idx ON sync_runs (started_at DESC);

-- Histórico da rotina automática do cron (mantido também por app/auto_update.php).
CREATE TABLE IF NOT EXISTS auto_update_runs (
    id BIGSERIAL PRIMARY KEY,
    trigger_source TEXT NOT NULL DEFAULT 'cron',
    state TEXT NOT NULL DEFAULT 'checking',
    started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    finished_at TIMESTAMPTZ NULL,
    bulk_type TEXT NOT NULL DEFAULT '',
    remote_updated_at TIMESTAMPTZ NULL,
    had_update BOOLEAN NOT NULL DEFAULT false,
    sync_run_id BIGINT NULL,
    cards_imported INTEGER NOT NULL DEFAULT 0,
    cards_added INTEGER NOT NULL DEFAULT 0,
    images_downloaded INTEGER NOT NULL DEFAULT 0,
    images_failed INTEGER NOT NULL DEFAULT 0,
    images_bytes BIGINT NOT NULL DEFAULT 0,
    error TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS auto_update_runs_started_idx ON auto_update_runs (started_at DESC);

CREATE TABLE IF NOT EXISTS builder_collection (
    scryfall_id UUID NOT NULL,
    name TEXT NOT NULL,
    quantity INTEGER NOT NULL CHECK(quantity > 0),
    foil BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (scryfall_id, foil)
);

CREATE TABLE IF NOT EXISTS upgrade_items (
    id BIGSERIAL PRIMARY KEY,
    deck_slug TEXT NOT NULL,
    deck_name TEXT NOT NULL,
    sort_order INTEGER NOT NULL,
    remove_name TEXT NOT NULL,
    add_name TEXT NOT NULL,
    reason TEXT NOT NULL,
    UNIQUE(deck_slug, sort_order)
);

INSERT INTO upgrade_items (deck_slug, deck_name, sort_order, remove_name, add_name, reason) VALUES
('dina', 'Dina, Essence Brewer', 1, 'Comforting Counsel', 'Ashnod''s Altar', 'Sac outlet gratuito que transforma fichas em mana e acelera a engine de sacrifício.'),
('dina', 'Dina, Essence Brewer', 2, 'Gyome, Master Chef', 'Bastion of Remembrance', 'Mais um payoff de Aristocrats que sobrevive a remoções de criatura.'),
('dina', 'Dina, Essence Brewer', 3, 'Ohran Frostfang', 'Enduring Tenacity', 'Converte ganho de vida em perda de vida, alinhando diretamente com a comandante.'),
('dina', 'Dina, Essence Brewer', 4, 'Blossoming Bogbeast', 'Tyvar, Jubilant Brawler', 'Dá pseudo-haste às habilidades com virar e ainda desvira Dina para uma ativação extra.'),
('dina', 'Dina, Essence Brewer', 5, 'Night''s Whisper', 'Jadar, Ghoulcaller of Nephalia', 'Gera corpos recorrentes para sacrificar em vez de apenas comprar duas cartas uma vez.'),
('dina', 'Dina, Essence Brewer', 6, 'Putrefy', 'Victimize', 'Transforma uma criatura pequena em duas criaturas recuperadas do cemitério.'),
('dina', 'Dina, Essence Brewer', 7, 'Creakwood Liege', 'Chatterfang, Squirrel General', 'Multiplica fichas e ainda fornece um sac outlet eficiente.'),
('dina', 'Dina, Essence Brewer', 8, 'My Precious', 'Parallel Lives', 'Dobra a produção de Pests, Saprolings, Snakes, Eldrazi e outras fichas.'),

('hakbal', 'Hakbal of the Surging Soul', 1, 'Commander''s Sphere', 'Deeproot Pilgrimage', 'Produz Merfolk continuamente e amplia a massa crítica tribal.'),
('hakbal', 'Hakbal of the Surging Soul', 2, 'Coralhelm Commander', 'Vodalian Hexcatcher', 'Lord eficiente que também transforma Merfolk em interação na pilha.'),
('hakbal', 'Hakbal of the Surging Soul', 3, 'Bygone Marvels', 'Roaming Throne', 'Duplica o gatilho tribal mais importante do comandante.'),
('hakbal', 'Hakbal of the Surging Soul', 4, 'Dissolve', 'Counterspell', 'Mesma função principal por menos mana.'),
('hakbal', 'Hakbal of the Surging Soul', 5, 'Chase Inspiration', 'Tishana''s Tidebinder', 'Interação flexível em um corpo Merfolk.'),
('hakbal', 'Hakbal of the Surging Soul', 6, 'Embrace the Paradox', 'Lord of Atlantis', 'Aumenta pressão tribal e fornece islandwalk.'),
('hakbal', 'Hakbal of the Surging Soul', 7, 'Prime Speaker Zegana', 'Oracle of Mul Daya', 'Converte o excesso de terrenos obtidos por explorar em land drops adicionais.'),
('hakbal', 'Hakbal of the Surging Soul', 8, 'Tatyova, Benthic Druid', 'Inspiring Call', 'Protege a mesa carregada de marcadores e compra cartas.'),
('hakbal', 'Hakbal of the Surging Soul', 9, 'Terrasymbiosis', 'Heroic Intervention', 'Proteção instantânea e eficiente contra remoções e wipes.'),
('hakbal', 'Hakbal of the Surging Soul', 10, 'Temple of the False God', 'Rejuvenating Springs', 'Base de mana mais consistente em multiplayer.'),

('hearthhull', 'Hearthhull, the Worldseed', 1, 'Groundskeeper', 'Ramunap Excavator', 'Permite jogar terrenos diretamente do cemitério e repetir landfall.'),
('hearthhull', 'Hearthhull, the Worldseed', 2, 'Centaur Vinecrasher', 'Scute Swarm', 'Transforma múltiplos landfalls em crescimento exponencial de fichas.'),
('hearthhull', 'Hearthhull, the Worldseed', 3, 'Uurg, Spawn of Turg', 'Tireless Provisioner', 'Converte landfall em Treasure ou Food, acelerando a engine.'),
('hearthhull', 'Hearthhull, the Worldseed', 4, 'Binding the Old Gods', 'Lotus Cobra', 'Cada terreno vira mana para continuar o turno explosivo.'),
('hearthhull', 'Hearthhull, the Worldseed', 5, 'Escape to the Wilds', 'Valakut Exploration', 'Vantagem de cartas diretamente ligada a landfall.'),
('hearthhull', 'Hearthhull, the Worldseed', 6, 'Hammer of Purphoros', 'Conduit of Worlds', 'Outra forma de reutilizar terrenos do cemitério.'),
('hearthhull', 'Hearthhull, the Worldseed', 7, 'Multani, Yavimaya''s Avatar', 'Azusa, Lost but Seeking', 'Aumenta drasticamente o número de land drops por turno.'),
('hearthhull', 'Hearthhull, the Worldseed', 8, 'Gnarlid Colony', 'Sylvan Safekeeper', 'Protege criaturas importantes enquanto sacrifica terrenos de forma útil.'),
('hearthhull', 'Hearthhull, the Worldseed', 9, 'Sprouting Goblin', 'Life from the Loam', 'Recupera terrenos e alimenta o cemitério continuamente.'),
('hearthhull', 'Hearthhull, the Worldseed', 10, 'Gaze of Granite', 'Scapeshift', 'Grande turno de landfall e sacrifício de terrenos em uma única carta.'),

('inspirit', 'Inspirit, Flagship Vessel', 1, 'Etched Oracle', 'Flux Channeler', 'Transforma mágicas não-criatura em proliferação repetível.'),
('inspirit', 'Inspirit, Flagship Vessel', 2, 'Mindless Automaton', 'Dreamtide Whale', 'Mais uma engine recorrente de proliferação.'),
('inspirit', 'Inspirit, Flagship Vessel', 3, 'Pull from Tomorrow', 'Inexorable Tide', 'Cada mágica conjurada passa a proliferar.'),
('inspirit', 'Inspirit, Flagship Vessel', 4, 'Threefold Thunderhulk', 'Contagion Engine', 'Controle de mesa e dupla proliferação por ativação.'),
('inspirit', 'Inspirit, Flagship Vessel', 5, 'Depthshaker Titan', 'Unwinding Clock', 'Desvira os artefatos a cada turno adversário e multiplica o valor das ativações.'),
('inspirit', 'Inspirit, Flagship Vessel', 6, 'Golem Foundry', 'Clock of Omens', 'Converte artefatos ociosos em untaps das peças importantes.'),
('inspirit', 'Inspirit, Flagship Vessel', 7, 'Titan Forge', 'Energy Chamber', 'Adiciona marcadores de carga automaticamente a cada manutenção.'),
('inspirit', 'Inspirit, Flagship Vessel', 8, 'Gavel of the Righteous', 'Filigree Vector', 'Coloca marcadores e depois prolifera a mesa.'),
('inspirit', 'Inspirit, Flagship Vessel', 9, 'Thirst for Knowledge', 'Coalition Relic', 'Mana rock que interage muito bem com charge counters.'),
('inspirit', 'Inspirit, Flagship Vessel', 10, 'Fumigate', 'All Will Be One', 'Converte colocação de marcadores em dano e fornece uma condição de fechamento.')
ON CONFLICT (deck_slug, sort_order) DO UPDATE SET
    remove_name = EXCLUDED.remove_name,
    add_name = EXCLUDED.add_name,
    reason = EXCLUDED.reason,
    deck_name = EXCLUDED.deck_name;
