-- Idempotent indexes for the existing local catalog.
CREATE INDEX IF NOT EXISTS cards_oracle_text_trgm_idx ON cards USING gin (oracle_text gin_trgm_ops);
CREATE INDEX IF NOT EXISTS cards_lower_name_idx ON cards (lower(name));
CREATE INDEX IF NOT EXISTS cards_first_face_name_idx ON cards (lower(split_part(name, ' // ', 1)));
CREATE INDEX IF NOT EXISTS cards_logical_idx ON cards ((COALESCE(oracle_id,id)));
ANALYZE cards;
