<?php
/**
 * Runtime performance tuning
 * Included early in the request lifecycle via config/database.php
 */

// OPcache — ensure it's enabled and tuned (php.ini overrides preferred, but set here as fallback)
if (function_exists('opcache_get_status')) {
    // These ini_set calls are no-ops if opcache.enable_cli=0 or already locked,
    // but harmless to call.
    @ini_set('opcache.enable',              1);
    @ini_set('opcache.memory_consumption',  128);
    @ini_set('opcache.max_accelerated_files', 4000);
    @ini_set('opcache.revalidate_freq',     60);  // check for file changes every 60s
    @ini_set('opcache.fast_shutdown',       1);
}

// APCu — user-land cache for API responses (no Redis required)
// Enable via php.ini: extension=apcu  apc.enable_cli=1
// No runtime config needed here; just used via apcu_fetch/apcu_store in API files.
