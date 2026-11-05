<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$dbError = null;
try {
    db();
} catch (Throwable $e) {
    $dbError = db_error_message($e);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="robots" content="noindex">
  <title>Bookmarks</title>
  <link rel="icon" href="assets/img/placeholder.svg">
  <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>">
</head>
<body>
<?php if ($dbError !== null): ?>
  <main class="gate">
    <h1>Bookmarks</h1>
    <p><?= e($dbError) ?></p>
  </main>
<?php else: ?>
  <div class="app">
    <aside class="sidebar">
      <div class="side-head">
        <h1>Folders</h1>
        <button type="button" class="btn ghost" id="add-folder">New</button>
      </div>
      <nav id="folders" class="folder-nav" aria-label="Folders"></nav>
    </aside>
    <div class="main">
      <header class="top">
        <div class="top-row">
          <div class="title-line">
            <h2 id="pane-title">Bookmarks</h2>
            <span id="count" class="total"></span>
          </div>
          <div class="top-actions">
            <span id="pane-tools" class="pane-tools"></span>
            <button type="button" class="btn primary" id="add-bookmark">Add bookmark</button>
          </div>
        </div>
        <label class="search">
          <span class="icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.2-3.2"/></svg>
          </span>
          <input id="search" type="search" placeholder="Search" autocomplete="off" spellcheck="false" aria-label="Search bookmarks">
          <button type="button" id="clear-search" class="clear-search" hidden aria-label="Clear search">×</button>
        </label>
      </header>
      <main id="list" class="list" aria-live="polite">
        <p class="empty">Loading…</p>
      </main>
    </div>
  </div>

  <dialog id="bookmark-dialog" aria-labelledby="bookmark-heading">
    <form id="bookmark-form" method="post" action="api.php?action=save">
      <h2 id="bookmark-heading">Add bookmark</h2>
      <label class="field">
        <span>Link</span>
        <input id="bookmark-url" name="url" type="text" inputmode="url" maxlength="2048" required autocomplete="off" spellcheck="false" placeholder="https://example.com">
      </label>
      <label class="field">
        <span>Title</span>
        <input id="bookmark-title" name="title" type="text" maxlength="300" required autocomplete="off">
      </label>
      <label class="field">
        <span>Folder</span>
        <select id="bookmark-folder" name="folder_id">
          <option value="">No folder</option>
        </select>
      </label>
      <div class="field">
        <span>Image</span>
        <div class="image-row">
          <img id="image-preview" class="preview none" alt="" width="72" height="72" src="assets/img/placeholder.svg">
          <div class="image-actions">
            <button type="button" class="btn ghost" id="use-site">Use site image</button>
            <label class="btn ghost file-btn">
              Upload image
              <input id="image-file" type="file" accept="image/png,image/jpeg,image/gif,image/webp,.png,.jpg,.jpeg,.gif,.webp" hidden>
            </label>
            <button type="button" class="btn ghost" id="clear-image" hidden>Remove</button>
          </div>
        </div>
        <p id="image-hint" class="hint" aria-live="polite"></p>
      </div>
      <input type="hidden" id="bookmark-id" name="id" value="">
      <div class="dialog-actions">
        <button type="button" class="btn ghost" id="bookmark-cancel">Cancel</button>
        <button type="submit" class="btn primary" id="bookmark-save">Save</button>
      </div>
    </form>
  </dialog>

  <dialog id="folder-dialog" aria-labelledby="folder-heading">
    <form id="folder-form" method="post" action="api.php?action=folder_save">
      <h2 id="folder-heading">New folder</h2>
      <label class="field">
        <span>Name</span>
        <input id="folder-name" name="name" type="text" maxlength="120" required autocomplete="off">
      </label>
      <input type="hidden" id="folder-id" name="id" value="">
      <div class="dialog-actions">
        <button type="button" class="btn ghost" id="folder-cancel">Cancel</button>
        <button type="submit" class="btn primary" id="folder-save">Save</button>
      </div>
    </form>
  </dialog>

  <div id="toast" class="toast" role="status" aria-live="polite"></div>
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
<?php endif; ?>
</body>
</html>
