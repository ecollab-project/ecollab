-- Restore the original pink/violet default without changing other saved palettes or modes.
ALTER TABLE user_settings ALTER COLUMN color_palette SET DEFAULT 'ecollab-original';
UPDATE user_settings SET color_palette = 'ecollab-original' WHERE color_palette = 'forest-mint';
