<?php

class jeedomMCP extends eqLogic {

    /**
     * Generate a cryptographically secure API key and persist it.
     */
    public static function generateApiKey() {
        $key = bin2hex(random_bytes(24)); // 48 hex characters
        config::save('mcpApiKey', $key, __CLASS__);
        return $key;
    }

    /**
     * Called when the plugin is activated.
     * Generates an API key if none exists yet.
     */
    public static function activate() {
        if (config::byKey('mcpApiKey', __CLASS__) === '') {
            self::generateApiKey();
        }
    }

    public static function cron() {
        // Scheduled tasks (if needed)
    }
}
