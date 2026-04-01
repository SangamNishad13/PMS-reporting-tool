<?php
/**
 * Runtime performance tuning
 */

// OPcache — ensure it's enabled and tuned (php.ini overrides preferred)
if (function_exists('opcache_get_status')) {
    // Ye lines live server par crash kar rahi hain, isliye inhein comment kar diya:
    /*
    @ini_set('opcache.enable',              1);
    @ini_set('opcache.memory_consumption',  128);
    @ini_set('opcache.max_accelerated_files', 4000);
    @ini_set('opcache.revalidate_freq',     60);
    @ini_set('opcache.fast_shutdown',       1);
    */
}

// APCu — baaki sab normal rehne dein
