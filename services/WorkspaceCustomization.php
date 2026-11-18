<?php
declare(strict_types=1);

final class WorkspaceCustomization
{
    public const WIDGETS = [
        'shortcuts' => ['label' => 'Workspace shortcuts', 'side' => 'left'],
        'channels' => ['label' => 'Text channels', 'side' => 'left'],
        'voice' => ['label' => 'Voice channels', 'side' => 'left'],
        'whiteboards' => ['label' => 'Whiteboard channels', 'side' => 'left'],
        'direct' => ['label' => 'Direct messages', 'side' => 'left'],
        'groups' => ['label' => 'Group messages', 'side' => 'left'],
        'activity' => ['label' => 'Active now', 'side' => 'right'],
        'matches' => ['label' => 'Suggested matches', 'side' => 'right'],
        'members' => ['label' => 'Members', 'side' => 'right'],
    ];
    public const COLORS = [
        'page' => ['label' => 'Background', 'hint' => 'Page and main chat background'],
        'surface' => ['label' => 'Panels', 'hint' => 'Sidebars, cards, and input surfaces'],
        'primary' => ['label' => 'Primary buttons', 'hint' => 'Send and other main actions'],
        'accent' => ['label' => 'Highlights', 'hint' => 'Selected items, links, and focus outlines'],
        'text' => ['label' => 'Text', 'hint' => 'Message text, headings, and labels'],
    ];

    public static function normalize(string $key, mixed $value): ?string
    {
        if ($value === '') return '';
        if (!is_string($value)) return null;
        if ($key === 'custom_palette') {
            return preg_match('/^#[0-9a-f]{6}(,#[0-9a-f]{6}){4}$/iD', $value) ? strtolower($value) : null;
        }

        $rows = json_decode($value, true, 8);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== count(self::WIDGETS)) return null;
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !array_is_list($row) || count($row) !== 3) return null;
            [$id, $side, $visible] = $row;
            if (!is_string($id) || !isset(self::WIDGETS[$id]) || isset($seen[$id])
                || !in_array($side, ['left', 'right'], true) || !is_bool($visible)) return null;
            $seen[$id] = true;
        }
        return json_encode($rows, JSON_THROW_ON_ERROR);
    }
}
