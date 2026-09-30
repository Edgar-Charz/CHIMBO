<?php

/**
 * Small global helper functions used by web and admin pages.
 * Loaded once by bootstrap.php.
 */

/** Escapes text before printing it in HTML, so user data can never run as code. Use for EVERY printed value. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Full URL inside the project, e.g. url('admin/login.php') → http://localhost/chimbo/admin/login.php */
function url(string $path = ''): string
{
    return baseUrl() . '/' . ltrim($path, '/');
}

/**
 * The site address used in links (image URLs, redirects).
 * It uses the address the request came in on, so every device gets links it can open:
 * the Android emulator gets http://10.0.2.2/chimbo, a phone on the Wi-Fi gets http://192.168.x.x/chimbo,
 * the PC browser gets http://localhost/chimbo, production gets its domain.
 * The request's host is used only if it is trusted (see isTrustedHost()); otherwise, and in
 * command-line scripts (cron, tests), APP_URL is used. The folder part ("/chimbo") always comes from APP_URL.
 */
function baseUrl(): string
{
    $app_url      = rtrim((string) Env::get('APP_URL', ''), '/');
    $request_host = $_SERVER['HTTP_HOST'] ?? null;

    if ($request_host === null || !isTrustedHost($request_host)) {
        return $app_url;
    }

    $scheme      = isHttpsRequest() ? 'https' : 'http';
    $folder_path = rtrim((string) parse_url($app_url, PHP_URL_PATH), '/');

    return "{$scheme}://{$request_host}{$folder_path}";
}

/**
 * Hosts we accept from the request's "Host" header (it is sent by the client, so it can't be trusted blindly —
 * otherwise someone could make the server build links pointing to their own site):
 * - the host of APP_URL, and any host in APP_TRUSTED_HOSTS (comma-separated)
 * - on a developer's computer (APP_ENV=local) also localhost and private-network addresses
 *   (127.0.0.1, the emulator's 10.0.2.2, 192.168.x.x on the Wi-Fi)
 */
function isTrustedHost(string $host_header): bool
{
    $host = strtolower((string) preg_replace('/:\d+$/', '', $host_header)); // without the port

    $trusted_hosts   = configuredTrustedHosts();
    $trusted_hosts[] = strtolower((string) parse_url((string) Env::get('APP_URL', ''), PHP_URL_HOST));
    if (in_array($host, $trusted_hosts, true)) {
        return true;
    }

    if (Env::get('APP_ENV') !== 'local') {
        return false;
    }
    $is_private_ip = filter_var($host, FILTER_VALIDATE_IP) !== false
        && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

    return $host === 'localhost' || $is_private_ip;
}

/** The hosts listed in APP_TRUSTED_HOSTS (lower-case, without spaces). */
function configuredTrustedHosts(): array
{
    return array_values(array_filter(array_map(
        fn (string $host) => strtolower(trim($host)),
        explode(',', (string) Env::get('APP_TRUSTED_HOSTS', ''))
    )));
}

/**
 * True when the current request came over HTTPS.
 * A tunnel or proxy (ngrok, a load balancer) receives the HTTPS request and passes it on as plain HTTP,
 * adding "X-Forwarded-Proto: https". That note is trusted only for hosts listed in APP_TRUSTED_HOSTS,
 * because anyone can send the header.
 */
function isHttpsRequest(): bool
{
    if (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }

    $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    $came_through_trusted_proxy = in_array($host, configuredTrustedHosts(), true);

    return $came_through_trusted_proxy && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Sends the browser to another page and stops the script. */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** True when the page was submitted with POST (a form was sent). */
function isPostRequest(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** A database date (UTC) in the standard format the apps expect: "2026-09-28T09:15:00+00:00". */
function isoDate(?string $database_date): ?string
{
    if ($database_date === null) {
        return null;
    }
    return (new DateTimeImmutable($database_date, new DateTimeZone('UTC')))->format(DATE_ATOM);
}

/** A time typed by staff in Tanzania time ("2026-10-01 08:30:00") → UTC for the database. */
function localTimeToUtc(?string $local_time): ?string
{
    if ($local_time === null) {
        return null;
    }
    $timezone = new DateTimeZone((string) Env::get('APP_TIMEZONE', 'Africa/Dar_es_Salaam'));
    return (new DateTimeImmutable($local_time, $timezone))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Text → web-address form: "Hair Food 500ml (Dark & Lovely)" → "hair-food-500ml-dark-lovely". */
function slugify(string $text): string
{
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
    return $slug !== '' ? $slug : 'item';
}

/** The visitor's IP address. */
function clientIp(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}
