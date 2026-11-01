<?php

/**
 * Front controller, small and rarely changed. The code lives in vendor/: Composer's, or the one in
 * runtime/<version>/ that runtime/current names on a site that updates itself, so an update goes live
 * with one rename. With neither, this is a fresh upload and setup.php fetches the release.
 */

declare(strict_types=1);

define('CMS_START', microtime(true));

if (PHP_VERSION_ID < 80400) {
    http_response_code(500);
    exit('This CMS needs PHP 8.4 or newer. This server runs PHP '.PHP_VERSION.'.');
}

$site = __DIR__;
$pointer = $site.'/runtime/current';

// Only ever writes a complete pointer: a short write on a full disk leaves it alone.
$flip = static function (string $to) use ($pointer): bool {
    $tmp = dirname($pointer).'/.current.'.bin2hex(random_bytes(4));
    $ok = @file_put_contents($tmp, $to."\n") === strlen($to) + 1 && @rename($tmp, $pointer);
    $ok || @unlink($tmp);

    return $ok;
};

$vf = $site.'/storage/update/verify.json';
$v = is_file($vf) ? json_decode((string) @file_get_contents($vf), true) : null;

if (is_array($v) && ($v['state'] ?? '') === 'migrating' && time() >= ($v['gate_until'] ?? 0) && $flip((string) ($v['previous'] ?? ''))) {
    @unlink($vf); // Whoever was driving the update died: go back.
    @touch($site.'/storage/update/purge');
    $v = null;
} elseif (is_array($v) && ($v['state'] ?? '') !== 'migrating' && time() >= ($v['until'] ?? 0)) {
    @unlink($vf); // The update has proved itself.
    $v = null;
}

$release = @file_get_contents($pointer);
$release = $release !== false ? trim($release) : '';
$vendor = preg_match('/^\d+\.\d+\.\d+(-[a-z0-9.]+)?$/', $release) === 1 && is_dir("{$site}/runtime/{$release}/vendor") ? "{$site}/runtime/{$release}/vendor" : $site.'/vendor';
$path = (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
$tick = is_array($v) && str_ends_with($path, '/_cms/update/tick');

if (is_array($v)) {
    if (($v['state'] ?? '') === 'migrating' && ! $tick) {
        http_response_code(503);
        header('Retry-After: 10');
        header('Cache-Control: no-store');
        exit('Updating. This takes a few seconds.');
    }

    // Three fatals in the new release's own code flip back. A plugin's, a memory limit's or the old tree's don't count.
    register_shutdown_function(static function () use ($v, $vf, $vendor, $site, $flip): void {
        $e = error_get_last();

        if (! $e || ! in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) || preg_match('/^(Allowed memory size|Maximum execution time)/', $e['message']) === 1) {
            return;
        }

        if (! str_starts_with($e['file'], $vendor.'/') || time() >= ($v['until'] ?? 0)) {
            return;
        }

        $count = $site.'/storage/update/fatals';
        @file_put_contents($count, 'x', FILE_APPEND);
        clearstatcache();

        if ((int) @filesize($count) >= 3 && $flip((string) ($v['previous'] ?? ''))) {
            @unlink($vf);
            @touch($site.'/storage/update/purge');
        }
    });
}

if (! is_file($vendor.'/autoload.php')) {
    require $site.'/setup.php';

    exit;
}

require $vendor.'/autoload.php';

$cms = $vendor.'/letterncms/cms';
$tick ? Cms\Foundation\Bootstrap::upgrade($cms, $site) : Cms\Foundation\Bootstrap::http($cms, $site);
