<?php

declare(strict_types=1);

ini_set('display_errors', '0');

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/images.php';

try {
    assert_same_origin();
    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
    if ($action !== 'list' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_out(['ok' => false, 'error' => 'Use POST for this action.'], 405);
    }

    switch ($action) {
        case 'list':
            prune_orphan_images();
            json_out([
                'ok' => true,
                'folders' => all_folders(),
                'bookmarks' => all_bookmarks(),
            ]);

        case 'preview':
            $url = normalize_url((string) ($_POST['url'] ?? ''));
            json_out(['ok' => true] + lookup_site($url));

        case 'save':
            save_bookmark();

        case 'delete':
            delete_bookmark();

        case 'folder_save':
            save_folder();

        case 'folder_delete':
            delete_folder();

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 404);
    }
} catch (Throwable $e) {
    $status = ($e instanceof RuntimeException && !$e instanceof PDOException) ? 400 : 500;
    json_out(['ok' => false, 'error' => public_error($e)], $status);
}

function all_folders(): array
{
    $rows = db()->query('SELECT id, name, position FROM folders ORDER BY position ASC, name ASC')->fetchAll();
    return array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
        ];
    }, $rows);
}

function all_bookmarks(): array
{
    $rows = db()->query(
        'SELECT id, folder_id, title, url, image_path, image_kind, created_at
         FROM bookmarks
         ORDER BY created_at DESC, id DESC'
    )->fetchAll();
    return array_map('bookmark_payload', $rows);
}

function bookmark_by_id(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT id, folder_id, title, url, image_path, image_kind, created_at
         FROM bookmarks WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bookmark_payload(array $row): array
{
    $path = is_string($row['image_path'] ?? null) && $row['image_path'] !== '' ? $row['image_path'] : null;
    $kind = (string) ($row['image_kind'] ?? 'none');
    if ($path === null) {
        $kind = 'none';
    }
    return [
        'id' => (int) $row['id'],
        'folder_id' => $row['folder_id'] !== null ? (int) $row['folder_id'] : null,
        'title' => (string) $row['title'],
        'url' => (string) $row['url'],
        'host' => display_host((string) $row['url']),
        'date_label' => format_bookmark_date((string) $row['created_at']),
        'image_url' => $path ? 'uploads/' . $path : null,
        'image_kind' => $kind,
    ];
}

function folder_exists(int $id): bool
{
    $stmt = db()->prepare('SELECT 1 FROM folders WHERE id = ?');
    $stmt->execute([$id]);
    return (bool) $stmt->fetchColumn();
}

function posted_folder_id(): ?int
{
    $folderId = (int) ($_POST['folder_id'] ?? 0);
    if ($folderId <= 0) {
        return null;
    }
    if (!folder_exists($folderId)) {
        throw new RuntimeException('That folder no longer exists.');
    }
    return $folderId;
}

function resolve_image(?array $existing): array
{
    $ownerId = $existing ? (int) $existing['id'] : null;
    $file = $_FILES['image'] ?? null;
    if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        return store_upload($file);
    }
    if (!empty($_POST['clear_image'])) {
        return ['path' => null, 'kind' => 'none'];
    }
    $posted = (string) ($_POST['image_path'] ?? '');
    if ($posted !== '') {
        $path = claim_image($posted, $ownerId);
        return ['path' => $path, 'kind' => kind_from_file($path)];
    }
    if ($existing) {
        return [
            'path' => $existing['image_path'],
            'kind' => $existing['image_kind'] ?: 'none',
        ];
    }
    try {
        $found = lookup_site((string) ($_POST['url'] ?? ''));
        return ['path' => $found['image_path'], 'kind' => $found['image_kind']];
    } catch (RuntimeException $e) {
        if (!valid_http_url(normalize_url((string) ($_POST['url'] ?? '')))) {
            throw $e;
        }
        return ['path' => null, 'kind' => 'none'];
    }
}

function save_bookmark(): never
{
    $id = (int) ($_POST['id'] ?? 0);
    $title = clip(clean_text((string) ($_POST['title'] ?? '')), 300);
    $url = normalize_url((string) ($_POST['url'] ?? ''));
    if ($title === '') {
        throw new RuntimeException('Title is required.');
    }
    if (!valid_http_url($url)) {
        throw new RuntimeException('Enter a valid http or https link.');
    }
    $folderId = posted_folder_id();

    $existing = null;
    if ($id > 0) {
        $existing = bookmark_by_id($id);
        if (!$existing) {
            throw new RuntimeException('Bookmark not found.');
        }
    }

    $image = resolve_image($existing);
    $path = $image['path'] ?: null;
    $kind = $path ? ($image['kind'] ?: 'photo') : 'none';
    if (!in_array($kind, ['photo', 'icon', 'none'], true)) {
        $kind = 'photo';
    }

    if ($existing) {
        $stmt = db()->prepare(
            'UPDATE bookmarks
             SET folder_id = ?, title = ?, url = ?, image_path = ?, image_kind = ?
             WHERE id = ?'
        );
        $stmt->execute([$folderId, $title, $url, $path, $kind, $id]);
    } else {
        $stmt = db()->prepare(
            'INSERT INTO bookmarks (folder_id, title, url, image_path, image_kind)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$folderId, $title, $url, $path, $kind]);
        $id = (int) db()->lastInsertId();
    }

    if ($existing && !empty($existing['image_path']) && $existing['image_path'] !== $path) {
        maybe_discard((string) $existing['image_path']);
    }
    $discard = (string) ($_POST['discard_path'] ?? '');
    if ($discard !== '' && $discard !== $path) {
        maybe_discard($discard);
    }

    $row = bookmark_by_id($id);
    json_out(['ok' => true, 'bookmark' => bookmark_payload($row ?? [])]);
}

function delete_bookmark(): never
{
    $id = (int) ($_POST['id'] ?? 0);
    $row = bookmark_by_id($id);
    if (!$row) {
        throw new RuntimeException('Bookmark not found.');
    }
    $stmt = db()->prepare('DELETE FROM bookmarks WHERE id = ?');
    $stmt->execute([$id]);
    if (!empty($row['image_path'])) {
        maybe_discard((string) $row['image_path']);
    }
    json_out(['ok' => true]);
}

function save_folder(): never
{
    $id = (int) ($_POST['id'] ?? 0);
    $name = clip(clean_text((string) ($_POST['name'] ?? '')), 120);
    if ($name === '') {
        throw new RuntimeException('Folder name is required.');
    }
    if ($id > 0) {
        if (!folder_exists($id)) {
            throw new RuntimeException('Folder not found.');
        }
        $stmt = db()->prepare('UPDATE folders SET name = ? WHERE id = ?');
        $stmt->execute([$name, $id]);
    } else {
        $position = (int) db()->query('SELECT COALESCE(MAX(position), 0) + 1 FROM folders')->fetchColumn();
        $stmt = db()->prepare('INSERT INTO folders (name, position) VALUES (?, ?)');
        $stmt->execute([$name, $position]);
        $id = (int) db()->lastInsertId();
    }
    json_out(['ok' => true, 'folder' => ['id' => $id, 'name' => $name]]);
}

function delete_folder(): never
{
    $id = (int) ($_POST['id'] ?? 0);
    if (!folder_exists($id)) {
        throw new RuntimeException('Folder not found.');
    }
    $stmt = db()->prepare('DELETE FROM folders WHERE id = ?');
    $stmt->execute([$id]);
    json_out(['ok' => true]);
}
