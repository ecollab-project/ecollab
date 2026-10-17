<?php
declare(strict_types=1);
final class ColorPaletteService {
    public const DEFAULT = 'forest-mint';
    public static function all(): array {
        static $palettes;
        return $palettes ??= json_decode(file_get_contents(dirname(__DIR__).'/assets/data/color-palettes.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    public static function valid(string $id): bool {
        return in_array($id, array_column(self::all(), 'id'), true);
    }
}
