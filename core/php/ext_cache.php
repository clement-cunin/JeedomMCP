<?php

// ---------------------------------------------------------------------------
// Plugin extension cache — shared between api/mcp.php and configuration.php
// ---------------------------------------------------------------------------

define('EXT_CACHE_TTL', 3600); // 1 hour

function ext_cache_path(): string {
    return __DIR__ . '/../../cache/ext_tools.json';
}

function ext_cache_load(): ?array {
    $path = ext_cache_path();
    if (!file_exists($path)) return null;
    $raw = file_get_contents($path);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['generated_at'])) return null;
    if ((time() - $data['generated_at']) > EXT_CACHE_TTL) return null;
    return $data['extensions'] ?? [];
}

function ext_cache_build(): array {
    $extensions = [];
    ob_start();
    $all_plugins = plugin::listPlugin(true);
    ob_end_clean();
    foreach ($all_plugins as $plugin) {
        $plugin_id = $plugin->getId();
        $file = __DIR__ . "/../../../{$plugin_id}/mcp/McpExtension.php";
        if (!file_exists($file)) continue;
        require_once $file;
        $class = $plugin_id . 'McpExtension';
        if (!class_exists($class)) continue;
        $tools = $class::getTools();
        if (!is_array($tools) || empty($tools)) continue;
        $extensions[] = ['plugin_id' => $plugin_id, 'tools' => $tools];
    }

    $cache_dir = dirname(ext_cache_path());
    if (!is_dir($cache_dir)) {
        mkdir($cache_dir, 0755, true);
    }
    file_put_contents(ext_cache_path(), json_encode([
        'generated_at' => time(),
        'extensions'   => $extensions,
    ]));

    return $extensions;
}

function ext_cache_invalidate(): void {
    $path = ext_cache_path();
    if (file_exists($path)) {
        unlink($path);
    }
}