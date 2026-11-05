(() => {
  'use strict';

  const ICONS = {
    folder: '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M3.5 6.8A1.8 1.8 0 0 1 5.3 5h4.2l1.6 1.8h7.6a1.8 1.8 0 0 1 1.8 1.8v8.6a1.8 1.8 0 0 1-1.8 1.8H5.3a1.8 1.8 0 0 1-1.8-1.8V6.8z"/></svg>',
    edit: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>',
    trash: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V5h6v2"/><path d="M6 7l1 13h10l1-13"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>'
  };

  const state = { folders: [], bookmarks: [] };
  let activeFolder = storedFolder();
  let query = '';
  let titleAuto = true;
  let imageState = blankImage();
  let lookupToken = 0;
  let lookupTimer = 0;
  let toastTimer = 0;

  const search = document.getElementById('search');
  const clearSearch = document.getElementById('clear-search');
  const bookmarkDialog = document.getElementById('bookmark-dialog');
  const folderDialog = document.getElementById('folder-dialog');
  const urlInput = document.getElementById('bookmark-url');
  const titleInput = document.getElementById('bookmark-title');
  const fileInput = document.getElementById('image-file');
  const hint = document.getElementById('image-hint');
  const preview = document.getElementById('image-preview');

  function storedFolder() {
    try {
      return sessionStorage.getItem('bookmarks.folder') || 'all';
    } catch (err) {
      return 'all';
    }
  }

  function rememberFolder(id) {
    try {
      sessionStorage.setItem('bookmarks.folder', id);
    } catch (err) {
      /* storage can be blocked; the filter still works for this view */
    }
  }

  function blankImage() {
    return { mode: 'auto', path: '', discard: '', lookupUrl: '' };
  }

  function el(tag, attrs, children) {
    const node = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([key, value]) => {
      if (value == null || value === false) return;
      if (key === 'class') node.className = value;
      else if (typeof value === 'function' && key.startsWith('on')) node.addEventListener(key.slice(2).toLowerCase(), value);
      else node.setAttribute(key, value === true ? '' : String(value));
    });
    (children || []).forEach((child) => {
      if (child == null || child === false) return;
      node.append(child instanceof Node ? child : document.createTextNode(String(child)));
    });
    return node;
  }

  function icon(name) {
    const span = document.createElement('span');
    span.className = 'icon';
    span.innerHTML = ICONS[name];
    return span;
  }

  function toast(message) {
    const node = document.getElementById('toast');
    node.textContent = message;
    node.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => node.classList.remove('show'), 3400);
  }

  async function post(action, body) {
    let response;
    try {
      response = await fetch('api.php?action=' + encodeURIComponent(action), {
        method: 'POST',
        body
      });
    } catch (err) {
      throw new Error('Can’t reach the server.');
    }
    const data = await response.json().catch(() => null);
    if (!data || !data.ok) {
      throw new Error((data && data.error) || 'Something went wrong.');
    }
    return data;
  }

  function normalizeUrl(value) {
    const trimmed = value.trim();
    if (!trimmed) return '';
    if (/^[a-z][a-z0-9+.-]*:\/\//i.test(trimmed)) return trimmed;
    return 'https://' + trimmed;
  }

  function safeHttpUrl(value) {
    try {
      const url = new URL(value);
      if (url.protocol === 'http:' || url.protocol === 'https:') return url.href;
    } catch (err) {
      return null;
    }
    return null;
  }

  function folderName(id) {
    if (id == null) return '';
    const folder = state.folders.find((item) => String(item.id) === String(id));
    return folder ? folder.name : '';
  }

  function filtered() {
    const q = query.trim().toLowerCase();
    return state.bookmarks.filter((bookmark) => {
      if (activeFolder === 'none' && bookmark.folder_id != null) return false;
      if (activeFolder !== 'all' && activeFolder !== 'none' && String(bookmark.folder_id) !== String(activeFolder)) {
        return false;
      }
      if (!q) return true;
      return [bookmark.title, bookmark.url, bookmark.host, folderName(bookmark.folder_id)]
        .join('\n')
        .toLowerCase()
        .includes(q);
    });
  }

  function noun(count) {
    return count === 1 ? 'bookmark' : 'bookmarks';
  }

  function folderCounts() {
    const counts = { all: state.bookmarks.length, none: 0 };
    state.folders.forEach((folder) => {
      counts[folder.id] = 0;
    });
    state.bookmarks.forEach((bookmark) => {
      if (bookmark.folder_id == null) counts.none += 1;
      else if (counts[bookmark.folder_id] != null) counts[bookmark.folder_id] += 1;
    });
    return counts;
  }

  function selectFolder(id) {
    activeFolder = String(id);
    rememberFolder(activeFolder);
    render();
  }

  function currentFolder() {
    if (activeFolder === 'all' || activeFolder === 'none') return null;
    return state.folders.find((folder) => String(folder.id) === String(activeFolder)) || null;
  }

  function renderCount() {
    const shown = filtered().length;
    const inFolder = folderCounts()[activeFolder === 'none' ? 'none' : (currentFolder() ? currentFolder().id : 'all')] || 0;
    document.getElementById('count').textContent = query
      ? shown + ' of ' + inFolder
      : shown + ' ' + noun(shown);
  }

  function renderFolders() {
    const nav = document.getElementById('folders');
    const counts = folderCounts();
    if (activeFolder === 'none' && counts.none === 0) {
      activeFolder = 'all';
      rememberFolder('all');
    }
    const items = [folderButton('all', 'All bookmarks', counts.all)];
    state.folders.forEach((folder) => {
      items.push(folderButton(String(folder.id), folder.name, counts[folder.id] || 0));
    });
    if (counts.none > 0 || activeFolder === 'none') {
      items.push(folderButton('none', 'Unsorted', counts.none));
    }
    nav.replaceChildren(...items);
  }

  function folderButton(id, label, count) {
    const pressed = String(activeFolder) === String(id);
    return el('button', {
      type: 'button',
      class: 'folder-item' + (pressed ? ' active' : ''),
      'aria-pressed': pressed ? 'true' : 'false',
      onclick: () => selectFolder(id)
    }, [
      icon('folder'),
      el('span', { class: 'folder-label' }, [label]),
      el('span', { class: 'count' }, [String(count)])
    ]);
  }

  function renderPane() {
    const folder = currentFolder();
    document.getElementById('pane-title').textContent = folder
      ? folder.name
      : (activeFolder === 'none' ? 'Unsorted' : 'All bookmarks');
    const tools = document.getElementById('pane-tools');
    if (!folder) {
      tools.replaceChildren();
      return;
    }
    tools.replaceChildren(
      el('button', {
        type: 'button',
        class: 'icon-btn',
        'aria-label': 'Rename ' + folder.name,
        onclick: () => openFolder(folder)
      }, [icon('edit')]),
      el('button', {
        type: 'button',
        class: 'icon-btn danger',
        'aria-label': 'Delete ' + folder.name,
        onclick: () => removeFolder(folder)
      }, [icon('trash')])
    );
  }

  function render() {
    renderFolders();
    renderPane();
    renderList();
    renderCount();
  }

  function renderList() {
    const list = document.getElementById('list');
    const items = filtered();
    list.replaceChildren();

    if (!query && !state.bookmarks.length && !state.folders.length) {
      list.append(welcome());
      return;
    }

    if (!items.length) {
      list.append(emptyLine());
      return;
    }

    items.forEach((bookmark) => list.append(bookmarkRow(bookmark, activeFolder === 'all')));
  }

  function welcome() {
    return el('div', { class: 'welcome' }, [
      el('h2', {}, ['Save a link']),
      el('p', {}, ['Add a bookmark and it opens in a new tab. If the site has an image, that image is kept with it.']),
      el('button', { type: 'button', class: 'btn primary', onclick: () => openBookmark(null) }, ['Add bookmark'])
    ]);
  }

  function emptyLine() {
    const line = el('p', { class: 'empty' });
    if (query) {
      line.append('No bookmarks match “', query.trim(), '”.');
      return line;
    }
    line.textContent = activeFolder === 'all' ? 'No bookmarks yet.' : 'Nothing in this folder yet.';
    return line;
  }

  function metaText(bookmark, showFolder) {
    const parts = [bookmark.host];
    if (showFolder) {
      const name = folderName(bookmark.folder_id);
      if (name) parts.push(name);
    }
    if (bookmark.date_label) parts.push(bookmark.date_label);
    return parts.join(' · ');
  }

  function bookmarkRow(bookmark, showFolder) {
    const href = safeHttpUrl(bookmark.url);
    const link = el('a', {
      class: 'open',
      href: href || undefined,
      target: href ? '_blank' : undefined,
      rel: href ? 'noopener noreferrer' : undefined,
      title: bookmark.url
    });
    const img = el('img', {
      class: 'thumb ' + thumbClass(bookmark.image_kind),
      src: bookmark.image_url || 'assets/img/placeholder.svg',
      alt: '',
      width: '48',
      height: '48'
    });
    img.addEventListener('error', () => {
      if (img.dataset.fallback === '1') return;
      img.dataset.fallback = '1';
      img.src = 'assets/img/placeholder.svg';
      img.className = 'thumb none';
    });
    link.append(
      img,
      el('span', { class: 'text' }, [
        el('span', { class: 'title' }, [bookmark.title]),
        el('span', { class: 'meta' }, [metaText(bookmark, showFolder)])
      ])
    );
    return el('article', { class: 'bookmark', id: 'bookmark-' + bookmark.id }, [
      link,
      el('div', { class: 'actions' }, [
        el('button', {
          type: 'button',
          class: 'icon-btn',
          'aria-label': 'Edit ' + bookmark.title,
          onclick: () => openBookmark(bookmark)
        }, [icon('edit')]),
        el('button', {
          type: 'button',
          class: 'icon-btn danger',
          'aria-label': 'Delete ' + bookmark.title,
          onclick: () => removeBookmark(bookmark)
        }, [icon('trash')])
      ])
    ]);
  }

  function thumbClass(kind) {
    if (kind === 'photo') return 'photo';
    if (kind === 'icon') return 'icon';
    return 'none';
  }

  async function load() {
    let response;
    try {
      response = await fetch('api.php?action=list');
    } catch (err) {
      showLoadError('Can’t reach the server.');
      return;
    }
    const data = await response.json().catch(() => null);
    if (!data || !data.ok) {
      showLoadError((data && data.error) || 'Could not load bookmarks.');
      return;
    }
    state.folders = data.folders || [];
    state.bookmarks = data.bookmarks || [];
    if (activeFolder !== 'all' && activeFolder !== 'none' && !state.folders.some((folder) => String(folder.id) === String(activeFolder))) {
      activeFolder = 'all';
      rememberFolder('all');
    }
    render();
  }

  function showLoadError(message) {
    document.getElementById('list').replaceChildren(el('p', { class: 'empty' }, [message]));
  }

  function fillFolders(selected) {
    const select = document.getElementById('bookmark-folder');
    select.replaceChildren(new Option('No folder', ''));
    state.folders.forEach((folder) => {
      select.append(new Option(folder.name, String(folder.id)));
    });
    select.value = selected ? String(selected) : '';
    if (select.value !== (selected ? String(selected) : '')) select.value = '';
  }

  function showImage(url, kind) {
    preview.className = 'preview ' + thumbClass(kind);
    preview.src = url || 'assets/img/placeholder.svg';
    document.getElementById('clear-image').hidden = !url;
  }

  function setHint(message, isError) {
    hint.textContent = message || '';
    hint.classList.toggle('error', Boolean(isError));
  }

  function openBookmark(bookmark) {
    const preset = !bookmark && activeFolder !== 'all' && activeFolder !== 'none' ? activeFolder : '';
    document.getElementById('bookmark-heading').textContent = bookmark ? 'Edit bookmark' : 'Add bookmark';
    document.getElementById('bookmark-save').textContent = bookmark ? 'Save' : 'Add bookmark';
    document.getElementById('bookmark-id').value = bookmark ? bookmark.id : '';
    urlInput.value = bookmark ? bookmark.url : '';
    titleInput.value = bookmark ? bookmark.title : '';
    fillFolders(bookmark ? bookmark.folder_id : preset);
    fileInput.value = '';
    titleAuto = !bookmark;
    imageState = bookmark && bookmark.image_url
      ? { mode: 'existing', path: '', discard: '', lookupUrl: safeHttpUrl(bookmark.url) || '' }
      : blankImage();
    if (bookmark) imageState.lookupUrl = safeHttpUrl(bookmark.url) || '';
    showImage(bookmark && bookmark.image_url, bookmark ? bookmark.image_kind : 'none');
    setHint(bookmark ? '' : 'Paste a link and the title and image are filled in when the site has them.', false);
    bookmarkDialog.showModal();
    urlInput.focus();
  }

  function openFolder(folder) {
    document.getElementById('folder-heading').textContent = folder ? 'Rename folder' : 'New folder';
    document.getElementById('folder-id').value = folder ? folder.id : '';
    const input = document.getElementById('folder-name');
    input.value = folder ? folder.name : '';
    folderDialog.showModal();
    input.focus();
    input.select();
  }

  async function lookup(force) {
    const href = safeHttpUrl(normalizeUrl(urlInput.value));
    if (!href) {
      if (force) setHint('Enter a valid link first.', true);
      return;
    }
    if (!force && (imageState.mode === 'upload' || imageState.mode === 'existing')) return;
    if (!force && imageState.lookupUrl === href && imageState.mode !== 'auto') return;

    const token = ++lookupToken;
    const previous = imageState.mode === 'site' ? imageState.path : '';
    imageState.lookupUrl = href;
    setHint('Looking up the site…', false);
    document.getElementById('use-site').disabled = true;
    const body = new FormData();
    body.set('url', href);
    try {
      const data = await post('preview', body);
      if (token !== lookupToken) return;
      if ((titleAuto || !titleInput.value.trim()) && data.title) {
        titleInput.value = data.title;
        titleAuto = true;
      }
      if (data.image_url) {
        if (previous && previous !== data.image_path) imageState.discard = previous;
        imageState.mode = 'site';
        imageState.path = data.image_path || '';
        fileInput.value = '';
        showImage(data.image_url, data.image_kind);
        setHint('Using the image from the site. You can upload a different one.', false);
      } else {
        if (previous) imageState.discard = previous;
        imageState.mode = 'cleared';
        imageState.path = '';
        showImage(null, 'none');
        setHint(data.notice || 'No image found. You can upload one, or save with a placeholder.', false);
      }
    } catch (err) {
      if (token !== lookupToken) return;
      imageState.lookupUrl = '';
      setHint(err.message, true);
    } finally {
      if (token === lookupToken) document.getElementById('use-site').disabled = false;
    }
  }

  function useUploadedFile(file) {
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) {
      setHint('Image must be 5 MB or smaller.', true);
      fileInput.value = '';
      return;
    }
    if (file.type && !/^image\/(png|jpeg|gif|webp)$/.test(file.type)) {
      setHint('Use a PNG, JPG, GIF, or WebP image.', true);
      fileInput.value = '';
      return;
    }
    const reader = new FileReader();
    reader.onload = () => {
      const probe = new Image();
      probe.onload = () => {
        const ratio = probe.naturalWidth / probe.naturalHeight;
        const kind = ratio >= 0.8 && ratio <= 1.25 && Math.max(probe.naturalWidth, probe.naturalHeight) <= 512
          ? 'icon'
          : 'photo';
        if (imageState.mode === 'site' && imageState.path) imageState.discard = imageState.path;
        imageState.mode = 'upload';
        imageState.path = '';
        showImage(String(reader.result), kind);
        setHint('Using your image.', false);
      };
      probe.src = String(reader.result);
    };
    reader.readAsDataURL(file);
  }

  function clearImage() {
    if (imageState.mode === 'site' && imageState.path) imageState.discard = imageState.path;
    imageState.mode = 'cleared';
    imageState.path = '';
    fileInput.value = '';
    showImage(null, 'none');
    setHint('A placeholder will be used unless you add an image.', false);
  }

  async function saveBookmark(event) {
    event.preventDefault();
    const href = safeHttpUrl(normalizeUrl(urlInput.value));
    const title = titleInput.value.trim();
    if (!href || !title) {
      setHint('Title and link are required.', true);
      return;
    }
    const body = new FormData();
    body.set('id', document.getElementById('bookmark-id').value);
    body.set('url', href);
    body.set('title', title);
    body.set('folder_id', document.getElementById('bookmark-folder').value);
    if (imageState.mode === 'site' && imageState.path) body.set('image_path', imageState.path);
    if (imageState.mode === 'cleared') body.set('clear_image', '1');
    if (imageState.mode === 'upload' && fileInput.files[0]) body.set('image', fileInput.files[0]);
    if (imageState.discard && imageState.discard !== imageState.path) body.set('discard_path', imageState.discard);

    const button = document.getElementById('bookmark-save');
    button.disabled = true;
    try {
      const data = await post('save', body);
      const savedFolder = document.getElementById('bookmark-folder').value;
      if (savedFolder) activeFolder = savedFolder;
      else if (activeFolder !== 'all') activeFolder = 'none';
      rememberFolder(activeFolder);
      bookmarkDialog.close();
      await load();
      document.getElementById('bookmark-' + data.bookmark.id)?.scrollIntoView({ block: 'nearest' });
    } catch (err) {
      setHint(err.message, true);
    } finally {
      button.disabled = false;
    }
  }

  async function removeBookmark(bookmark) {
    if (!window.confirm('Delete “' + bookmark.title + '”?')) return;
    const body = new FormData();
    body.set('id', String(bookmark.id));
    try {
      await post('delete', body);
      await load();
    } catch (err) {
      toast(err.message);
    }
  }

  async function saveFolder(event) {
    event.preventDefault();
    const folderId = document.getElementById('folder-id').value;
    const body = new FormData();
    body.set('id', folderId);
    body.set('name', document.getElementById('folder-name').value.trim());
    const button = document.getElementById('folder-save');
    button.disabled = true;
    try {
      const data = await post('folder_save', body);
      if (!folderId && data.folder) {
        activeFolder = String(data.folder.id);
        rememberFolder(activeFolder);
      }
      folderDialog.close();
      await load();
    } catch (err) {
      toast(err.message);
    } finally {
      button.disabled = false;
    }
  }

  async function removeFolder(folder) {
    const count = state.bookmarks.filter((bookmark) => String(bookmark.folder_id) === String(folder.id)).length;
    const extra = count ? ' ' + count + ' ' + noun(count) + ' will move to Unsorted.' : '';
    if (!window.confirm('Delete folder “' + folder.name + '”?' + extra)) return;
    const body = new FormData();
    body.set('id', String(folder.id));
    try {
      await post('folder_delete', body);
      if (String(activeFolder) === String(folder.id)) {
        activeFolder = 'all';
        rememberFolder('all');
      }
      await load();
    } catch (err) {
      toast(err.message);
    }
  }

  function closeOnBackdrop(dialog) {
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) dialog.close();
    });
  }

  document.getElementById('add-bookmark').addEventListener('click', () => openBookmark(null));
  document.getElementById('add-folder').addEventListener('click', () => openFolder(null));
  document.getElementById('bookmark-form').addEventListener('submit', saveBookmark);
  document.getElementById('folder-form').addEventListener('submit', saveFolder);
  document.getElementById('bookmark-cancel').addEventListener('click', () => bookmarkDialog.close());
  document.getElementById('folder-cancel').addEventListener('click', () => folderDialog.close());
  document.getElementById('use-site').addEventListener('click', () => lookup(true));
  document.getElementById('clear-image').addEventListener('click', clearImage);
  closeOnBackdrop(bookmarkDialog);
  closeOnBackdrop(folderDialog);

  urlInput.addEventListener('input', () => {
    clearTimeout(lookupTimer);
    lookupTimer = setTimeout(() => lookup(false), 650);
  });
  urlInput.addEventListener('blur', () => {
    const href = safeHttpUrl(normalizeUrl(urlInput.value));
    if (href) urlInput.value = href;
  });
  titleInput.addEventListener('input', () => {
    titleAuto = titleInput.value.trim() === '';
  });
  fileInput.addEventListener('change', () => useUploadedFile(fileInput.files && fileInput.files[0]));
  preview.addEventListener('dragover', (event) => event.preventDefault());
  preview.addEventListener('drop', (event) => {
    event.preventDefault();
    const file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0];
    if (!file) return;
    const transfer = new DataTransfer();
    transfer.items.add(file);
    fileInput.files = transfer.files;
    useUploadedFile(file);
  });

  search.addEventListener('input', () => {
    query = search.value;
    clearSearch.hidden = query === '';
    renderList();
    renderCount();
  });
  clearSearch.addEventListener('click', () => {
    search.value = '';
    query = '';
    clearSearch.hidden = true;
    renderList();
    renderCount();
    search.focus();
  });

  load();
})();
