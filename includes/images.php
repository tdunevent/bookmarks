<?php

declare(strict_types=1);

const IMAGE_EXT = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'image/x-icon' => 'ico',
    'image/vnd.microsoft.icon' => 'ico',
];

function image_name_ok(string $name): bool
{
    return (bool) preg_match('/^[a-f0-9]{32}\.(jpg|png|gif|webp|ico)$/', $name);
}

function image_file_ok(string $name): bool
{
    return image_name_ok($name) && is_file(uploads_dir() . '/' . $name);
}

function image_kind_from_size(int $w, int $h, string $ext): string
{
    if ($ext === 'ico') {
        return 'icon';
    }
    if ($w < 1 || $h < 1) {
        return 'photo';
    }
    $ratio = $w / $h;
    if ($ratio >= 0.8 && $ratio <= 1.25 && max($w, $h) <= 512) {
        return 'icon';
    }
    return 'photo';
}

function kind_from_file(string $name): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'ico') {
        return 'icon';
    }
    $size = @getimagesize(uploads_dir() . '/' . $name);
    if (!is_array($size)) {
        return 'photo';
    }
    return image_kind_from_size((int) $size[0], (int) $size[1], $ext);
}

function save_image_bytes(string $bytes, string $mime): ?array
{
    $mime = strtolower(trim(explode(';', $mime)[0]));
    if (!isset(IMAGE_EXT[$mime])) {
        $sniffed = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        $mime = strtolower($sniffed);
    }
    if (!isset(IMAGE_EXT[$mime])) {
        return null;
    }
    if (strlen($bytes) < 32 || strlen($bytes) > 4 * 1024 * 1024) {
        return null;
    }

    $ext = IMAGE_EXT[$mime];
    $w = 0;
    $h = 0;
    $size = @getimagesizefromstring($bytes);
    if (is_array($size)) {
        $w = (int) $size[0];
        $h = (int) $size[1];
        if ($w < 16 || $h < 16 || $w > 8000 || $h > 8000) {
            return null;
        }
    } elseif ($ext !== 'ico') {
        return null;
    }

    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (file_put_contents(uploads_dir() . '/' . $name, $bytes) === false) {
        return null;
    }

    return [
        'path' => $name,
        'kind' => image_kind_from_size($w, $h, $ext),
    ];
}

function store_upload(array $file): array
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $message = match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Image must be 5 MB or smaller.',
            default => 'Image upload failed.',
        };
        throw new RuntimeException($message);
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Image must be 5 MB or smaller.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Image upload failed.');
    }
    $bytes = file_get_contents($tmp);
    if ($bytes === false) {
        throw new RuntimeException('Could not read the image.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    $saved = save_image_bytes($bytes, $mime);
    if ($saved === null) {
        throw new RuntimeException('Use a PNG, JPG, GIF, or WebP image.');
    }
    return $saved;
}

function image_used_by_other(string $name, ?int $exceptId): bool
{
    $stmt = db()->prepare('SELECT id FROM bookmarks WHERE image_path = ?');
    $stmt->execute([$name]);
    foreach ($stmt->fetchAll() as $row) {
        if ($exceptId === null || (int) $row['id'] !== $exceptId) {
            return true;
        }
    }
    return false;
}

function claim_image(string $name, ?int $ownerId): string
{
    if (!image_file_ok($name)) {
        throw new RuntimeException('That image is no longer available. Look it up again.');
    }
    if (!image_used_by_other($name, $ownerId)) {
        return $name;
    }
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $copy = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!copy(uploads_dir() . '/' . $name, uploads_dir() . '/' . $copy)) {
        throw new RuntimeException('Could not save the image.');
    }
    return $copy;
}

function maybe_discard(?string $name): void
{
    if ($name === null || !image_name_ok($name)) {
        return;
    }
    if (image_used_by_other($name, null)) {
        return;
    }
    $full = uploads_dir() . '/' . $name;
    if (is_file($full)) {
        @unlink($full);
    }
}

function prune_orphan_images(): void
{
    $used = [];
    foreach (db()->query('SELECT image_path FROM bookmarks WHERE image_path IS NOT NULL') as $row) {
        if (is_string($row['image_path']) && $row['image_path'] !== '') {
            $used[$row['image_path']] = true;
        }
    }
    $dir = uploads_dir();
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (!is_file($file)) {
            continue;
        }
        $base = basename($file);
        if (!image_name_ok($base) || isset($used[$base])) {
            continue;
        }
        $mtime = filemtime($file);
        if ($mtime !== false && $mtime > time() - 86400) {
            continue;
        }
        @unlink($file);
    }
}

function curl_ca_bundle(): ?string
{
    foreach ([
        '/Applications/XAMPP/xamppfiles/share/curl/curl-ca-bundle.crt',
        '/etc/ssl/cert.pem',
    ] as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

function ip_is_public(string $ip): bool
{
    return (bool) filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
}

function is_blocked_host(string $host): bool
{
    $host = strtolower(trim($host, '[]'));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        return true;
    }
    return $host === 'metadata.google.internal' || str_ends_with($host, '.internal');
}

function classify_host(string $host): array
{
    $host = trim($host, '[]');
    if (is_blocked_host($host)) {
        return ['class' => 'private', 'ips' => []];
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return ip_is_public($host)
            ? ['class' => 'public', 'ips' => [$host]]
            : ['class' => 'private', 'ips' => []];
    }

    $ips = [];
    $v4 = @gethostbynamel($host);
    if (is_array($v4)) {
        $ips = array_merge($ips, $v4);
    }
    if (defined('DNS_AAAA')) {
        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
    }
    $ips = array_values(array_unique($ips));
    if (!$ips) {
        return ['class' => 'unresolved', 'ips' => []];
    }
    $public = [];
    foreach ($ips as $ip) {
        if (!ip_is_public($ip)) {
            return ['class' => 'private', 'ips' => []];
        }
        $public[] = $ip;
    }
    return ['class' => 'public', 'ips' => $public];
}

function resolve_url(string $base, string $rel): ?string
{
    $rel = trim($rel);
    if ($rel === '' || preg_match('/[\r\n]/', $rel) || preg_match('#^\s*data:#i', $rel)) {
        return null;
    }
    if (preg_match('#^https?://#i', $rel)) {
        return $rel;
    }
    $parts = parse_url($base);
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }
    $scheme = $parts['scheme'] ?? 'https';
    if (str_starts_with($rel, '//')) {
        return $scheme . ':' . $rel;
    }
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $prefix = $scheme . '://' . $parts['host'] . $port;
    if (str_starts_with($rel, '/')) {
        return $prefix . $rel;
    }
    $path = $parts['path'] ?? '/';
    $dir = preg_replace('#/[^/]*$#', '/', $path) ?? '/';
    return $prefix . $dir . $rel;
}

function url_port(string $url): int
{
    $port = parse_url($url, PHP_URL_PORT);
    if (is_int($port)) {
        return $port;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return $scheme === 'http' ? 80 : 443;
}

function http_fetch(string $url, int $maxBytes, string $accept): array
{
    $current = $url;
    for ($hop = 0; $hop < 5; $hop++) {
        if (!valid_http_url($current)) {
            throw new RuntimeException('That address can’t be fetched.');
        }
        $host = (string) parse_url($current, PHP_URL_HOST);
        $classified = classify_host($host);
        if ($classified['class'] !== 'public') {
            throw new RuntimeException('That address can’t be fetched.');
        }

        $headers = [];
        $body = '';
        $tooBig = false;
        $ch = curl_init($current);
        if ($ch === false) {
            throw new RuntimeException('Could not reach that site.');
        }
        $opts = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept, 'Accept-Language: en-US,en;q=0.9'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING => '',
        ];
        $ca = curl_ca_bundle();
        if ($ca !== null) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $ip = $classified['ips'][0];
        $addr = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        curl_setopt($ch, CURLOPT_RESOLVE, [$host . ':' . url_port($current) . ':' . $addr]);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        });
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, string $data) use (&$body, $maxBytes, &$tooBig): int {
            $body .= $data;
            if (strlen($body) > $maxBytes) {
                $tooBig = true;
                return 0;
            }
            return strlen($data);
        });
        curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($tooBig) {
            throw new RuntimeException('The response was too large.');
        }
        if ($errno !== 0) {
            throw new RuntimeException('Could not reach that site.');
        }
        if (in_array($code, [301, 302, 303, 307, 308], true)) {
            $location = $headers['location'] ?? '';
            $next = resolve_url($current, $location);
            if ($next === null) {
                throw new RuntimeException('Could not reach that site.');
            }
            $current = $next;
            continue;
        }

        $mime = $headers['content-type'] ?? 'application/octet-stream';
        return ['code' => $code, 'body' => $body, 'mime' => $mime, 'url' => $current];
    }

    throw new RuntimeException('Could not reach that site.');
}

function try_fetch(string $url, int $maxBytes, string $accept): ?array
{
    try {
        $response = http_fetch($url, $maxBytes, $accept);
    } catch (Throwable $e) {
        return null;
    }
    if ($response['code'] < 200 || $response['code'] >= 300 || $response['body'] === '') {
        return null;
    }
    return $response;
}

function load_html_document(string $html): ?DOMDocument
{
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $loaded ? $dom : null;
}

function page_details(string $html, string $pageUrl): array
{
    $dom = load_html_document($html);
    $title = '';
    $siteName = '';
    if (!$dom) {
        return ['title' => '', 'site_name' => '', 'photos' => [], 'icons' => []];
    }

    $meta = [];
    foreach ($dom->getElementsByTagName('meta') as $node) {
        $key = strtolower($node->getAttribute('property') ?: $node->getAttribute('name'));
        $content = trim($node->getAttribute('content'));
        if ($key !== '' && $content !== '') {
            $meta[$key][] = $content;
        }
    }
    if (!empty($meta['og:site_name'][0])) {
        $siteName = clean_text($meta['og:site_name'][0]);
    }
    foreach (['og:title', 'twitter:title'] as $key) {
        if (!empty($meta[$key][0])) {
            $title = clean_text($meta[$key][0]);
            break;
        }
    }
    if ($title === '') {
        $nodes = $dom->getElementsByTagName('title');
        if ($nodes->length > 0) {
            $title = clean_text($nodes->item(0)->textContent ?? '');
        }
    }

    $photos = [];
    foreach (['og:image', 'og:image:secure_url', 'twitter:image'] as $key) {
        foreach ($meta[$key] ?? [] as $candidate) {
            $abs = resolve_url($pageUrl, $candidate);
            if ($abs !== null) {
                $photos[] = $abs;
            }
        }
    }

    $icons = [];
    foreach ($dom->getElementsByTagName('link') as $node) {
        $rel = strtolower($node->getAttribute('rel'));
        $href = trim($node->getAttribute('href'));
        if ($href === '' || !str_contains($rel, 'icon')) {
            continue;
        }
        if (preg_match('#\.svg($|\?)#i', $href)) {
            continue;
        }
        $abs = resolve_url($pageUrl, $href);
        if ($abs === null) {
            continue;
        }
        $score = 0;
        if (preg_match('/(\d+)\s*x\s*(\d+)/i', $node->getAttribute('sizes'), $m)) {
            $score = (int) $m[1] * (int) $m[2];
        }
        if (str_contains($rel, 'apple') && $score < 180 * 180) {
            $score = 180 * 180;
        }
        $icons[] = ['url' => $abs, 'score' => $score];
    }
    usort($icons, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

    return [
        'title' => clip($title, 300),
        'site_name' => clip($siteName, 120),
        'photos' => $photos,
        'icons' => array_map(static fn (array $icon): string => $icon['url'], $icons),
    ];
}

function drop_image_file(string $name): void
{
    if (!image_name_ok($name)) {
        return;
    }
    $full = uploads_dir() . '/' . $name;
    if (is_file($full)) {
        @unlink($full);
    }
}

function with_image_size(array $saved): array
{
    $size = @getimagesize(uploads_dir() . '/' . $saved['path']);
    $saved['w'] = is_array($size) ? (int) $size[0] : 0;
    $saved['h'] = is_array($size) ? (int) $size[1] : 0;
    return $saved;
}

function lookup_result(string $title, ?string $path, string $kind, ?string $notice): array
{
    return [
        'title' => $title,
        'image_path' => $path,
        'image_kind' => $path ? $kind : 'none',
        'image_url' => $path ? 'uploads/' . $path : null,
        'notice' => $notice,
    ];
}

function download_image(string $url, bool $fromGoogle): ?array
{
    $response = try_fetch($url, 4 * 1024 * 1024, 'image/avif,image/webp,image/png,image/jpeg,image/*,*/*;q=0.8');
    if ($response === null) {
        return null;
    }
    if ($fromGoogle) {
        $size = @getimagesizefromstring($response['body']);
        if (!is_array($size) || $size[0] < 48 || $size[1] < 48) {
            return null;
        }
    }
    return save_image_bytes($response['body'], $response['mime']);
}

function lookup_site(string $url): array
{
    $url = normalize_url($url);
    if (!valid_http_url($url)) {
        throw new RuntimeException('Enter a valid http or https link.');
    }

    $host = (string) parse_url($url, PHP_URL_HOST);
    $title = display_host($url);
    $class = classify_host($host);
    if ($class['class'] === 'private') {
        return lookup_result(
            $title,
            null,
            'none',
            'This looks like a local address, so there’s no site image to fetch. You can upload one.'
        );
    }

    $deadline = microtime(true) + 18;
    $photos = [];
    $icons = [];
    $pageReached = false;
    if ($class['class'] === 'public' && microtime(true) < $deadline) {
        $page = try_fetch($url, 1500000, 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8');
        if ($page !== null) {
            $pageReached = true;
            $details = page_details($page['body'], $page['url']);
            $path = (string) (parse_url($page['url'], PHP_URL_PATH) ?? '');
            $isHome = $path === '' || $path === '/';
            if ($isHome && $details['site_name'] !== '') {
                $title = $details['site_name'];
            } elseif ($details['title'] !== '') {
                $title = $details['title'];
            }
            $photos = $details['photos'];
            $icons = $details['icons'];
            $finalHost = (string) parse_url($page['url'], PHP_URL_HOST);
            $origin = parse_url($page['url'], PHP_URL_SCHEME) . '://' . $finalHost;
            $icons[] = $origin . '/favicon.ico';
            $host = $finalHost;
        }
    }

    $chosen = null;
    foreach (array_slice(array_values(array_unique($icons)), 0, 4) as $candidate) {
        if (microtime(true) > $deadline || !valid_http_url($candidate)) {
            continue;
        }
        $saved = download_image($candidate, false);
        if ($saved === null) {
            continue;
        }
        $info = with_image_size($saved);
        $info['decent'] = $info['kind'] === 'icon' && min($info['w'], $info['h']) >= 48;
        if ($chosen !== null && ($info['decent'] || min($info['w'], $info['h']) > min($chosen['w'], $chosen['h']))) {
            drop_image_file($chosen['path']);
            $chosen = $info;
        } elseif ($chosen === null) {
            $chosen = $info;
        } else {
            drop_image_file($info['path']);
        }
        if (!empty($chosen['decent'])) {
            break;
        }
    }

    if ($chosen === null || empty($chosen['decent'])) {
        foreach (array_slice(array_values(array_unique($photos)), 0, 2) as $candidate) {
            if (microtime(true) > $deadline || !valid_http_url($candidate)) {
                continue;
            }
            $saved = download_image($candidate, false);
            if ($saved === null) {
                continue;
            }
            if ($chosen !== null) {
                drop_image_file($chosen['path']);
            }
            $info = with_image_size($saved);
            if (max($info['w'], $info['h']) >= 128) {
                $info['kind'] = 'photo';
            }
            $chosen = $info;
            break;
        }
    }

    if ($chosen === null && $class['class'] !== 'private' && $host !== '' && microtime(true) <= $deadline) {
        $google = 'https://www.google.com/s2/favicons?domain=' . rawurlencode($host) . '&sz=128';
        $saved = download_image($google, true);
        if ($saved !== null) {
            $chosen = with_image_size($saved);
        }
    }

    if ($chosen !== null) {
        return lookup_result($title, $chosen['path'], $chosen['kind'], null);
    }

    $notice = $pageReached || $class['class'] === 'unresolved'
        ? 'No image found on that site. You can upload one, or save with a placeholder.'
        : 'Couldn’t reach that site. You can upload an image, or save with a placeholder.';
    if ($class['class'] === 'unresolved' && !$pageReached) {
        $notice = 'Couldn’t reach that site. You can upload an image, or save with a placeholder.';
    }

    return lookup_result($title, null, 'none', $notice);
}
