<?php
declare(strict_types=1);

/** Merge Excalidraw revisions, preserving concurrent additions and deletion tombstones. */
final class WhiteboardSceneService
{
    public static function merge(array $current, array $incoming): array
    {
        $elements = [];
        foreach ([$current['elements'] ?? [], $incoming['elements'] ?? []] as $scene) {
            foreach ($scene as $element) {
                if (!is_array($element) || !is_string($element['id'] ?? null)) continue;
                $id = $element['id'];
                $old = $elements[$id] ?? null;
                $version = (int)($element['version'] ?? 0);
                if ($old === null || $version > (int)($old['version'] ?? 0)
                    || ($version === (int)($old['version'] ?? 0)
                        && (int)($element['versionNonce'] ?? 0) < (int)($old['versionNonce'] ?? 0))) {
                    $elements[$id] = $element;
                }
            }
        }
        return [
            'format' => 'excalidraw', 'version' => 1,
            'elements' => array_values($elements),
            'appState' => array_replace($current['appState'] ?? [], $incoming['appState'] ?? []),
            'files' => array_replace($current['files'] ?? [], $incoming['files'] ?? []),
        ];
    }
}
