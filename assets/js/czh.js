/* CZ Highlights v1.0.0 */
(function () {
  'use strict';

  if (!window.CZH) return;

  const cfg = window.CZH;
  if (!cfg.user) return;

  const ctx      = cfg.context;   // { type, postId, volumeId, permalink }
  const i18n     = cfg.i18n;
  const rest     = cfg.rest;
  const sel      = cfg.selectors;

  // Touch/pointer detection
  const isTouch = window.matchMedia('(hover: none)').matches;

  // ── State ──────────────────────────────────────────────────────────────────
  let highlights = [];   // loaded from server
  let pending    = null; // { selectedText, prefixText, suffixText }

  // ── REST ───────────────────────────────────────────────────────────────────
  async function apiFetch(path, { method = 'GET', body } = {}) {
    // Normalise protocol so REST calls always go to the same origin as the page.
    // rest.root comes from rest_url() which uses the site's configured scheme (https://),
    // but volume pages may load over http:// — a cross-origin mismatch that prevents
    // cookie-based auth (credentials:'same-origin') and causes 401 responses.
    const root = rest.root.replace(/^https?:/i, window.location.protocol);
    const url = root + String(path).replace(/^\/+/, '');
    const headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': rest.nonce };
    const res = await fetch(url, {
      method,
      headers,
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined,
    });
    const text = await res.text();
    if (!res.ok) throw new Error(`${method} ${url} → ${res.status}`);
    return text ? JSON.parse(text) : {};
  }

  // ── Text fingerprint ───────────────────────────────────────────────────────

  /**
   * Collect all text content from a container, returning an array of
   * { node, start, end } so we can map string positions back to DOM nodes.
   */
  function collectTextNodes(container) {
    const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT);
    const nodes = [];
    let pos = 0;
    while (walker.nextNode()) {
      const node = walker.currentNode;
      const len  = node.textContent.length;
      nodes.push({ node, start: pos, end: pos + len });
      pos += len;
    }
    return nodes;
  }

  function getFullText(nodes) {
    return nodes.map(n => n.node.textContent).join('');
  }

  // Normalise any run of whitespace (including newlines) to a single space.
  function wsNorm(s) { return s.replace(/\s+/g, ' ').trim(); }

  /**
   * Extract fingerprint from a browser Selection range within a container.
   * Returns { selectedText, prefixText, suffixText } or null.
   *
   * selStart / selEnd are aligned to the TRIMMED selected text so that
   * prefix ends right before the first non-WS char and suffix starts
   * right after the last non-WS char.
   */
  function extractFingerprint(range, container) {
    const rawSelected = range.toString();
    const selected    = rawSelected.trim();
    if (!selected) return null;

    const nodes    = collectTextNodes(container);
    const fullText = getFullText(nodes);

    const preRange = document.createRange();
    preRange.setStart(container, 0);
    preRange.setEnd(range.startContainer, range.startOffset);

    const rawStart  = preRange.toString().length;
    const leadingWS = rawSelected.length - rawSelected.trimStart().length;

    const selStart = rawStart + leadingWS;       // position of first non-WS char
    const selEnd   = selStart + selected.length; // position right after last non-WS char

    const CONTEXT = 100;
    return {
      selectedText: selected,
      prefixText:   fullText.slice(Math.max(0, selStart - CONTEXT), selStart),
      suffixText:   fullText.slice(selEnd, selEnd + CONTEXT),
    };
  }

  // ── DOM range from fingerprint ─────────────────────────────────────────────

  /**
   * Given a fingerprint, find the DOM Range for the selected text.
   *
   * Strategy:
   *  1. Collect all occurrences of selected_text in fullText.
   *  2. For each, check if the surrounding context matches prefix/suffix
   *     using whitespace-insensitive comparison (handles data stored before
   *     the sanitize_text_field → sanitize_textarea_field fix).
   *  3. If exactly one contextual match → active.
   *  4. If zero contextual matches but text exists → displaced (first occurrence).
   *  5. Text not found → orphaned.
   *
   * Returns { range, displaced } or null if orphaned.
   */
  function findRange(highlight, container) {
    const { selected_text, prefix_text, suffix_text } = highlight;
    const nodes    = collectTextNodes(container);
    const fullText = getFullText(nodes);

    // Collect all positions of selected_text in fullText
    const occurrences = [];
    let from = 0;
    while (from <= fullText.length) {
      const idx = fullText.indexOf(selected_text, from);
      if (idx === -1) break;
      occurrences.push(idx);
      from = idx + 1;
    }

    if (occurrences.length === 0) return null; // orphaned

    let selStart  = -1;
    let displaced = false;

    if (occurrences.length === 1) {
      // Only one match in the whole text — no ambiguity, trust it.
      selStart = occurrences[0];
    } else if (prefix_text || suffix_text) {
      // Multiple occurrences: use whitespace-normalised context to disambiguate.
      const normPrefix = wsNorm(prefix_text);
      const normSuffix = wsNorm(suffix_text);
      const selLen     = selected_text.length;

      for (const idx of occurrences) {
        const actualPrefix = fullText.slice(Math.max(0, idx - prefix_text.length - 20), idx);
        const actualSuffix = fullText.slice(idx + selLen, idx + selLen + suffix_text.length + 20);
        const prefixOk = !normPrefix || wsNorm(actualPrefix).endsWith(normPrefix);
        const suffixOk = !normSuffix || wsNorm(actualSuffix).startsWith(normSuffix);
        if (prefixOk && suffixOk) { selStart = idx; break; }
      }

      if (selStart === -1) {
        // Context didn't match (e.g. article changed) — fall back to first occurrence.
        selStart  = occurrences[0];
        displaced = true;
      }
    } else {
      selStart  = occurrences[0];
      displaced = true;
    }

    const selEnd = selStart + selected_text.length;

    // Convert string positions to DOM Range
    const range = document.createRange();
    let startSet = false;

    for (const { node, start, end } of nodes) {
      if (!startSet && end > selStart) {
        range.setStart(node, selStart - start);
        startSet = true;
      }
      if (startSet && end >= selEnd) {
        range.setEnd(node, selEnd - start);
        break;
      }
    }

    if (!startSet) return null;

    return { range, displaced };
  }

  // ── Apply highlight mark in DOM ────────────────────────────────────────────

  function wrapRange(range, id, color, note) {
    // For ranges spanning multiple elements we split into per-text-node sub-ranges
    const mark = document.createElement('mark');
    mark.className = 'czh-hl';
    mark.dataset.id    = id;
    mark.dataset.color = color;
    if (note) mark.dataset.note = note;

    try {
      range.surroundContents(mark);
    } catch {
      // Range spans element boundaries — wrap each text node individually
      const frag = range.cloneContents();
      const nodes = [];
      const walker = document.createTreeWalker(frag, NodeFilter.SHOW_TEXT);
      while (walker.nextNode()) nodes.push(walker.currentNode);

      if (nodes.length === 0) return;

      // Re-create a range per text node using the live DOM
      const fullRange = range.cloneRange();
      const texts = [];
      const liveWalker = document.createTreeWalker(
        range.commonAncestorContainer.nodeType === Node.TEXT_NODE
          ? range.commonAncestorContainer.parentNode
          : range.commonAncestorContainer,
        NodeFilter.SHOW_TEXT
      );

      let inRange = false;
      while (liveWalker.nextNode()) {
        const n = liveWalker.currentNode;
        if (n === range.startContainer) inRange = true;
        if (inRange) texts.push(n);
        if (n === range.endContainer) break;
      }

      texts.forEach((textNode, i) => {
        const m = mark.cloneNode(false);
        const subRange = document.createRange();
        if (i === 0) {
          subRange.setStart(textNode, range.startOffset);
          subRange.setEnd(textNode, textNode.length);
        } else if (i === texts.length - 1) {
          subRange.setStart(textNode, 0);
          subRange.setEnd(textNode, range.endOffset);
        } else {
          subRange.selectNodeContents(textNode);
        }
        subRange.surroundContents(m);
      });
    }
  }

  function applyHighlightToDOM(highlight, container) {
    const result = findRange(highlight, container);
    if (!result) {
      highlight.status = 'orphaned';
      return;
    }
    const { range, displaced } = result;
    if (displaced) highlight.status = 'displaced';

    wrapRange(range, highlight.id, highlight.color, highlight.note);
  }

  // ── Remove highlight mark from DOM ─────────────────────────────────────────

  function removeHighlightFromDOM(id) {
    document.querySelectorAll(`.czh-hl[data-id="${id}"]`).forEach(mark => {
      const parent = mark.parentNode;
      while (mark.firstChild) parent.insertBefore(mark.firstChild, mark);
      parent.removeChild(mark);
      parent.normalize();
    });
  }

  // ── Tooltip (desktop only) ─────────────────────────────────────────────────

  let tooltip = null;

  function buildTooltip() {
    const el = document.createElement('div');
    el.className = 'czh-tooltip';
    el.setAttribute('role', 'dialog');
    el.innerHTML = `
      <button type="button" class="czh-tooltip__btn" data-czh-do-highlight>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
        </svg>
        ${i18n.highlight}
      </button>`;
    document.body.appendChild(el);
    el.querySelector('[data-czh-do-highlight]').addEventListener('mousedown', (e) => {
      e.preventDefault(); // keep selection alive
      onHighlightRequest();
    });
    return el;
  }

  function showTooltip(rect) {
    if (!tooltip) tooltip = buildTooltip();
    const x = rect.left + rect.width / 2 + window.scrollX;
    const y = rect.top + window.scrollY - 8; // 8px above selection
    tooltip.style.transform = `translate(calc(${x}px - 50%), calc(${y}px - 100%))`;
    tooltip.classList.add('is-visible');
  }

  function hideTooltip() {
    tooltip && tooltip.classList.remove('is-visible');
  }

  // ── Toolbar button (mobile only) ───────────────────────────────────────────

  let toolbarBtn = null;

  function injectToolbarButton() {
    const toolbar = document.querySelector(sel.toolbar);
    if (!toolbar) return null;

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'czcr-toolbar__btn czh-toolbar-btn';
    btn.setAttribute('aria-label', i18n.highlight);
    btn.setAttribute('data-czh-do-highlight', '');
    btn.hidden = true;
    btn.innerHTML = `
      <span class="czcr-icon" aria-hidden="true">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
        </svg>
      </span>`;
    btn.addEventListener('click', onHighlightRequest);
    toolbar.appendChild(btn);
    return btn;
  }

  // ── Note popover ───────────────────────────────────────────────────────────

  let popover = null;
  let currentPopoverHighlightId = null;

  function buildPopover() {
    const el = document.createElement('div');
    el.className = 'czh-popover';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', i18n.add_note);
    el.innerHTML = `
      <div class="czh-popover__colors"></div>
      <textarea class="czh-popover__note" placeholder="${escapeAttr(i18n.add_note)}" rows="3"></textarea>
      <div class="czh-popover__actions">
        <button type="button" class="czh-popover__save">${i18n.save_note}</button>
        <button type="button" class="czh-popover__delete">${i18n.delete}</button>
      </div>`;

    // Color buttons
    const colorsEl = el.querySelector('.czh-popover__colors');
    cfg.colors.forEach(color => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'czh-color-btn';
      btn.dataset.color = color;
      btn.setAttribute('aria-label', color);
      btn.addEventListener('click', () => {
        el.querySelectorAll('.czh-color-btn').forEach(b => b.classList.remove('is-active'));
        btn.classList.add('is-active');
        // Live-update mark color in DOM
        if (currentPopoverHighlightId) {
          document.querySelectorAll(`.czh-hl[data-id="${currentPopoverHighlightId}"]`).forEach(m => {
            m.dataset.color = color;
          });
        }
      });
      colorsEl.appendChild(btn);
    });

    el.querySelector('.czh-popover__save').addEventListener('click', async () => {
      const id    = currentPopoverHighlightId;
      const note  = el.querySelector('.czh-popover__note').value;
      const color = (el.querySelector('.czh-color-btn.is-active') || {}).dataset?.color || 'yellow';
      if (!id) return;
      try {
        const updated = await apiFetch(`highlights/${id}`, { method: 'PATCH', body: { note, color } });
        const hl = highlights.find(h => h.id === id);
        if (hl) { hl.note = updated.note; hl.color = updated.color; }
        document.querySelectorAll(`.czh-hl[data-id="${id}"]`).forEach(m => {
          m.dataset.note  = note;
          m.dataset.color = color;
        });
        refreshDrawerList();
      } catch (e) { console.error('[CZH] patch failed', e); }
      hidePopover();
    });

    el.querySelector('.czh-popover__delete').addEventListener('click', async () => {
      const id = currentPopoverHighlightId;
      if (!id) return;
      try {
        await apiFetch(`highlights/${id}`, { method: 'DELETE' });
        highlights = highlights.filter(h => h.id !== id);
        removeHighlightFromDOM(id);
        refreshDrawerList();
      } catch (e) { console.error('[CZH] delete failed', e); }
      hidePopover();
    });

    document.body.appendChild(el);
    return el;
  }

  function showPopover(anchorMark, highlight) {
    if (!popover) popover = buildPopover();

    currentPopoverHighlightId = highlight.id;

    // Set color
    popover.querySelectorAll('.czh-color-btn').forEach(btn => {
      btn.classList.toggle('is-active', btn.dataset.color === highlight.color);
    });

    // Set note
    popover.querySelector('.czh-popover__note').value = highlight.note || '';

    // Position near the mark
    const rect = anchorMark.getBoundingClientRect();
    const x = rect.left + rect.width / 2 + window.scrollX;
    const y = rect.bottom + window.scrollY + 8;
    popover.style.transform = `translate(calc(${x}px - 50%), ${y}px)`;
    popover.classList.add('is-visible');

    // Close on outside click
    setTimeout(() => {
      document.addEventListener('mousedown', onPopoverOutside, { once: true });
    }, 10);
  }

  function onPopoverOutside(e) {
    if (popover && !popover.contains(e.target)) {
      hidePopover();
    } else {
      document.addEventListener('mousedown', onPopoverOutside, { once: true });
    }
  }

  function hidePopover() {
    popover && popover.classList.remove('is-visible');
    currentPopoverHighlightId = null;
  }

  // ── Note popover for NEW highlight ────────────────────────────────────────

  function showNewHighlightPopover(highlight, markEl) {
    if (!popover) popover = buildPopover();

    currentPopoverHighlightId = highlight.id;

    // Reset to defaults
    popover.querySelectorAll('.czh-color-btn').forEach(btn => {
      btn.classList.toggle('is-active', btn.dataset.color === highlight.color);
    });
    popover.querySelector('.czh-popover__note').value = '';

    const rect = markEl ? markEl.getBoundingClientRect() : { left: window.innerWidth / 2, bottom: 100, width: 0 };
    const x = rect.left + rect.width / 2 + window.scrollX;
    const y = rect.bottom + window.scrollY + 8;
    popover.style.transform = `translate(calc(${x}px - 50%), ${y}px)`;
    popover.classList.add('is-visible');

    setTimeout(() => {
      document.addEventListener('mousedown', onPopoverOutside, { once: true });
    }, 10);
  }

  // ── Do Highlight ───────────────────────────────────────────────────────────

  function onHighlightRequest() {
    if (!cfg.user.loggedIn) {
      window.location.href = cfg.loginUrl || '';
      return;
    }
    doHighlight();
  }

  async function doHighlight() {
    if (!pending || !ctx || !ctx.postId) return;

    hideTooltip();
    if (toolbarBtn) toolbarBtn.hidden = true;

    const fp = pending;
    pending = null;

    // Clear selection
    window.getSelection()?.removeAllRanges();

    try {
      const created = await apiFetch('highlights', {
        method: 'POST',
        body: {
          post_id:       ctx.postId,
          selected_text: fp.selectedText,
          prefix_text:   fp.prefixText,
          suffix_text:   fp.suffixText,
          color:         'yellow',
          page_num:      ctx.pageNum || 1,
        },
      });

      highlights.push(created);
      applyHighlightToDOM(created, getContentEl());
      refreshDrawerList();

      // Open note popover on the new mark
      const markEl = document.querySelector(`.czh-hl[data-id="${created.id}"]`);
      showNewHighlightPopover(created, markEl);
    } catch (e) {
      console.error('[CZH] create failed', e);
    }
  }

  // ── Drawer ─────────────────────────────────────────────────────────────────

  function getDrawer() { return document.getElementById('czh-drawer'); }

  function openDrawer() {
    const drawer = getDrawer();
    if (!drawer) return;
    drawer.setAttribute('aria-hidden', 'false');
    drawer.classList.add('is-open');
    document.querySelector('.czh-drawer-backdrop')?.classList.add('is-visible');
    refreshDrawerList();
    // Close the nav-user details if open
    document.querySelector('details.nav-user')?.removeAttribute('open');
  }

  function closeDrawer() {
    const drawer = getDrawer();
    if (!drawer) return;
    drawer.setAttribute('aria-hidden', 'true');
    drawer.classList.remove('is-open');
    document.querySelector('.czh-drawer-backdrop')?.classList.remove('is-visible');
  }

  function refreshDrawerList() {
    const drawer = getDrawer();
    if (!drawer) return;

    const body = drawer.querySelector('.czh-drawer__body');
    if (!body) return;

    const active = highlights.filter(h => h.status !== 'orphaned');

    if (active.length === 0) {
      body.innerHTML = `<p class="czh-drawer__empty">${escapeHtml(i18n.no_highlights)}</p>`;
      return;
    }

    body.innerHTML = active.map(h => {
      const pageNum = h.page_num || 1;
      const samePageMark = pageNum === (ctx.pageNum || 1);
      return `
      <div class="czh-drawer__item" data-id="${h.id}" data-color="${escapeAttr(h.color)}">
        <div class="czh-drawer__item-text">${escapeHtml(h.selected_text)}</div>
        ${h.note ? `<div class="czh-drawer__item-note">${escapeHtml(h.note)}</div>` : ''}
        ${h.status === 'displaced' ? `<p class="czh-drawer__item-warning">${escapeHtml(i18n.displaced_msg)}</p>` : ''}
        <div class="czh-drawer__item-actions">
          <button type="button" class="czh-drawer__scroll-btn"
            data-czh-scroll-to="${h.id}"
            data-czh-goto-permalink="${escapeAttr(ctx.permalink || '')}"
            data-czh-goto-page="${pageNum}"
            data-czh-same-page="${samePageMark ? '1' : '0'}">Vai</button>
          <button type="button" class="czh-drawer__edit-btn" data-czh-edit="${h.id}">${i18n.add_note}</button>
          <button type="button" class="czh-drawer__del-btn" data-czh-delete="${h.id}">${i18n.delete}</button>
        </div>
      </div>`; }).join('');

    // Scroll-to / goto buttons
    body.querySelectorAll('[data-czh-scroll-to]').forEach(btn => {
      btn.addEventListener('click', () => {
        const id       = Number(btn.dataset.czhScrollTo);
        const samePage = btn.dataset.czhSamePage === '1';
        if (samePage) {
          const mark = document.querySelector(`.czh-hl[data-id="${id}"]`);
          if (mark) {
            closeDrawer();
            setTimeout(() => mark.scrollIntoView({ behavior: 'smooth', block: 'center' }), 280);
          }
        } else {
          window.location.href = buildGotoUrl(
            btn.dataset.czhGotoPermalink,
            Number(btn.dataset.czhGotoPage || 1),
            id
          );
        }
      });
    });

    // Edit buttons → open popover
    body.querySelectorAll('[data-czh-edit]').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = Number(btn.dataset.czhEdit);
        const hl = highlights.find(h => h.id === id);
        if (!hl) return;
        const mark = document.querySelector(`.czh-hl[data-id="${id}"]`);
        closeDrawer();
        setTimeout(() => showPopover(mark || document.body, hl), 280);
      });
    });

    // Delete buttons
    body.querySelectorAll('[data-czh-delete]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.dataset.czhDelete);
        try {
          await apiFetch(`highlights/${id}`, { method: 'DELETE' });
          highlights = highlights.filter(h => h.id !== id);
          removeHighlightFromDOM(id);
          refreshDrawerList();
        } catch (e) { console.error('[CZH] delete failed', e); }
      });
    });
  }

  // ── Click on existing mark ─────────────────────────────────────────────────

  function onMarkClick(e) {
    const mark = e.target.closest('.czh-hl');
    if (!mark) return;
    const id = Number(mark.dataset.id);
    const hl = highlights.find(h => h.id === id);
    if (hl) showPopover(mark, hl);
  }

  // ── Selection detection ────────────────────────────────────────────────────

  function getContentEl() {
    return document.querySelector(sel.content);
  }

  function selectionInContent(selection, container) {
    if (!selection || selection.isCollapsed || !selection.rangeCount) return false;
    const range = selection.getRangeAt(0);
    if (range.toString().trim().length === 0) return false;
    return container.contains(range.commonAncestorContainer);
  }

  function onSelectionChange() {
    const container = getContentEl();
    if (!container) return;

    const selection = window.getSelection();
    if (!selectionInContent(selection, container)) {
      pending = null;
      if (!isTouch) hideTooltip();
      if (toolbarBtn) toolbarBtn.hidden = true;
      return;
    }

    const range = selection.getRangeAt(0);
    const fp    = extractFingerprint(range, container);
    if (!fp) return;

    pending = fp;

    if (isTouch && toolbarBtn) {
      toolbarBtn.hidden = false;
    }
  }

  function onMouseUp() {
    if (isTouch || !pending) { hideTooltip(); return; }
    const selection = window.getSelection();
    if (!selection || selection.isCollapsed) { hideTooltip(); return; }
    const range = selection.getRangeAt(0);
    const rect  = range.getBoundingClientRect();
    showTooltip(rect);
  }

  // ── Helpers ────────────────────────────────────────────────────────────────

  function buildGotoUrl(permalink, pageNum, highlightId) {
    const url = new URL(permalink);
    if (pageNum > 1) url.searchParams.set('page', pageNum);
    url.searchParams.set('czh_hl', highlightId);
    return url.toString();
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function escapeAttr(str) {
    return String(str).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  // ── Notes page ─────────────────────────────────────────────────────────────

  function initNotesPage() {
    const app = document.getElementById('czh-notes-app');
    if (!app) return;

    const urlParams       = new URLSearchParams(window.location.search);
    const volumeIdParam   = Number(urlParams.get('volume_id'));
    const standaloneParam = urlParams.get('standalone') === '1';

    if (standaloneParam) {
      renderStandaloneDetail(app);
    } else if (volumeIdParam) {
      renderVolumeDetail(app, volumeIdParam);
    } else {
      renderVolumeList(app);
    }

    window.addEventListener('popstate', () => {
      const params     = new URLSearchParams(window.location.search);
      const vid        = Number(params.get('volume_id'));
      const standalone = params.get('standalone') === '1';
      if (standalone) {
        renderStandaloneDetail(app);
      } else if (vid) {
        renderVolumeDetail(app, vid);
      } else {
        renderVolumeList(app);
      }
    });
  }

  async function renderVolumeList(app) {
    app.innerHTML = `<p class="czh-notes__loading">${escapeHtml(i18n.loading)}</p>`;
    try {
      const volumes = await apiFetch('highlights/summary');
      if (!volumes.length) {
        app.innerHTML = `<p class="czh-notes__empty">${escapeHtml(i18n.no_notes_any)}</p>`;
        return;
      }
      const standaloneEntries = volumes.filter(v => v.standalone);
      const volumeEntries     = volumes.filter(v => !v.standalone);
      const cardHtml = v => `
        <div class="czh-notes__volume-card">
          <div class="czh-notes__volume-info">
            ${!v.standalone && v.author_name ? `<span class="czh-notes__volume-author">${escapeHtml(v.author_name)}</span>` : ''}
            <span class="czh-notes__volume-title">${v.standalone ? escapeHtml(i18n.standalone_title) : escapeHtml(v.title)}</span>
            <span class="czh-notes__volume-count">${v.count} ${v.count === 1 ? 'nota' : 'note'}</span>
          </div>
          <button type="button" class="czh-notes__volume-btn" ${v.standalone ? 'data-czh-standalone' : `data-czh-volume="${v.volume_id}"`}>${escapeHtml(i18n.open_volume)}</button>
        </div>`;

      let html = '';
      if (standaloneEntries.length) html += `<div class="czh-notes__volumes">${standaloneEntries.map(cardHtml).join('')}</div>`;
      if (standaloneEntries.length && volumeEntries.length) html += `<div class="czh-notes__section-divider"><span>Volumi</span></div>`;
      if (volumeEntries.length) html += `<div class="czh-notes__volumes">${volumeEntries.map(cardHtml).join('')}</div>`;
      app.innerHTML = html;

      app.querySelectorAll('[data-czh-volume]').forEach(btn => {
        btn.addEventListener('click', () => {
          const vid = Number(btn.dataset.czhVolume);
          const url = new URL(window.location.href);
          url.searchParams.set('volume_id', vid);
          history.pushState({ volume_id: vid }, '', url.toString());
          renderVolumeDetail(app, vid);
        });
      });

      app.querySelectorAll('[data-czh-standalone]').forEach(btn => {
        btn.addEventListener('click', () => {
          const url = new URL(window.location.href);
          url.searchParams.set('standalone', '1');
          history.pushState({ standalone: true }, '', url.toString());
          renderStandaloneDetail(app);
        });
      });
    } catch (e) {
      console.error('[CZH] summary failed', e);
      app.innerHTML = `<p class="czh-notes__empty">${escapeHtml(i18n.error_loading)}</p>`;
    }
  }

  async function renderVolumeDetail(app, volumeId) {
    app.innerHTML = `<p class="czh-notes__loading">${escapeHtml(i18n.loading)}</p>`;
    try {
      const rows = await apiFetch(`highlights/volume?volume_id=${volumeId}`);

      const backBtn = `
        <div class="czh-notes__detail-header">
          <button type="button" class="czh-notes__back-btn" data-czh-back>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
            ${escapeHtml(i18n.back_to_volumes)}
          </button>
          <button type="button" class="czh-notes__collapse-all-btn" data-czh-collapse-all>${escapeHtml(i18n.collapse_all)}</button>
        </div>`;

      if (!rows.length) {
        app.innerHTML = backBtn + `<p class="czh-notes__empty">${escapeHtml(i18n.no_notes_volume)}</p>`;
        wireBackButton(app);
        return;
      }

      // Group highlights by post_id preserving server order
      const byPost = {};
      const postOrder = [];
      rows.filter(h => h.status !== 'orphaned').forEach(h => {
        const pid = h.post_id;
        if (!byPost[pid]) {
          byPost[pid] = { post: h.post, items: [] };
          postOrder.push(pid);
        }
        byPost[pid].items.push(h);
      });

      let articlesHtml = postOrder.map(pid => {
        const { post, items } = byPost[pid];
        const title = post ? post.title : '—';
        return `
          <div class="collapsable-section" data-initial="open">
            <h3 class="collapsable-toggle">${escapeHtml(title)}</h3>
            <div class="collapsable-content">
              <div class="czh-notes__items">${items.map(h => buildNoteItemHtml(h)).join('')}</div>
            </div>
          </div>`;
      }).join('');

      app.innerHTML = backBtn + `<div class="czh-notes__articles">${articlesHtml}</div>`;
      wireBackButton(app);
      wireCollapseAllBtn(app);
      wireNoteActions(app);
    } catch (e) {
      console.error('[CZH] volume detail failed', e);
      app.innerHTML = `<p class="czh-notes__empty">${escapeHtml(i18n.error_loading)}</p>`;
    }
  }

  async function renderStandaloneDetail(app) {
    app.innerHTML = `<p class="czh-notes__loading">${escapeHtml(i18n.loading)}</p>`;
    try {
      const rows = await apiFetch('highlights/standalone');

      const backBtn = `
        <div class="czh-notes__detail-header">
          <button type="button" class="czh-notes__back-btn" data-czh-back>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
            ${escapeHtml(i18n.back_to_volumes)}
          </button>
          <button type="button" class="czh-notes__collapse-all-btn" data-czh-collapse-all>${escapeHtml(i18n.collapse_all)}</button>
        </div>`;

      if (!rows.length) {
        app.innerHTML = backBtn + `<p class="czh-notes__empty">${escapeHtml(i18n.no_notes_volume)}</p>`;
        wireBackButton(app);
        return;
      }

      const byPost = {};
      const postOrder = [];
      rows.filter(h => h.status !== 'orphaned').forEach(h => {
        const pid = h.post_id;
        if (!byPost[pid]) {
          byPost[pid] = { post: h.post, items: [] };
          postOrder.push(pid);
        }
        byPost[pid].items.push(h);
      });

      const articlesHtml = postOrder.map(pid => {
        const { post, items } = byPost[pid];
        const title = post ? post.title : '—';
        return `
          <div class="collapsable-section" data-initial="open">
            <h3 class="collapsable-toggle">${escapeHtml(title)}</h3>
            <div class="collapsable-content">
              <div class="czh-notes__items">${items.map(h => buildNoteItemHtml(h)).join('')}</div>
            </div>
          </div>`;
      }).join('');

      app.innerHTML = backBtn + `<div class="czh-notes__articles">${articlesHtml}</div>`;
      wireBackButton(app);
      wireCollapseAllBtn(app);
      wireNoteActions(app);
    } catch (e) {
      console.error('[CZH] standalone detail failed', e);
      app.innerHTML = `<p class="czh-notes__empty">${escapeHtml(i18n.error_loading)}</p>`;
    }
  }

  function wireBackButton(app) {
    app.querySelector('[data-czh-back]')?.addEventListener('click', () => {
      const url = new URL(window.location.href);
      url.searchParams.delete('volume_id');
      url.searchParams.delete('standalone');
      history.pushState({}, '', url.toString());
      renderVolumeList(app);
    });
  }

  function setAllCollapsables(container, open) {
    container.querySelectorAll('.collapsable-section').forEach(section => {
      const toggle  = section.querySelector('.collapsable-toggle');
      const content = section.querySelector('.collapsable-content');
      if (!toggle || !content) return;
      section.classList.toggle('is-open', open);
      toggle.setAttribute('aria-expanded', String(open));
      content.setAttribute('aria-hidden', String(!open));
      content.style.display = open ? '' : 'none';
      const chev = toggle.querySelector('.collapsable-chevron');
      if (chev) chev.style.transform = open ? 'rotate(0deg)' : 'rotate(-90deg)';
    });
  }

  function wireCollapseAllBtn(app) {
    const btn = app.querySelector('[data-czh-collapse-all]');
    if (!btn) return;

    const updateBtnLabel = () => {
      const sections = [...app.querySelectorAll('.collapsable-section')];
      if (!sections.length) return;
      if (sections.every(s =>  s.classList.contains('is-open'))) btn.textContent = i18n.collapse_all;
      if (sections.every(s => !s.classList.contains('is-open'))) btn.textContent = i18n.expand_all;
      // mixed state → no change
    };

    // Button action is determined by what it currently says, not by counting open sections
    btn.addEventListener('click', () => {
      const sections = app.querySelectorAll('.collapsable-section');
      if (!sections.length) return;
      const shouldExpand = btn.textContent.trim() === i18n.expand_all;
      setAllCollapsables(app, shouldExpand);
      updateBtnLabel();
    });

    // Watch manual toggles — defer so the theme handler runs first and .is-open is already updated
    app.querySelectorAll('.collapsable-toggle').forEach(toggle => {
      toggle.addEventListener('click', () => setTimeout(updateBtnLabel, 0));
    });
  }

  function buildNoteItemHtml(h) {
    const gotoBtn = h.post
      ? `<button type="button" class="czh-notes__goto-btn" data-czh-goto-permalink="${escapeAttr(h.post.permalink)}" data-czh-goto-page="${h.page_num || 1}" data-czh-goto-id="${h.id}">${escapeHtml(i18n.goto)}</button>`
      : '';
    const colorBtns = cfg.colors.map(c =>
      `<button type="button" class="czh-color-btn${c === h.color ? ' is-active' : ''}" data-color="${c}" aria-label="${c}"></button>`
    ).join('');

    return `
      <div class="czh-notes__item" data-id="${h.id}" data-color="${escapeAttr(h.color)}">
        <blockquote class="czh-notes__quote">${escapeHtml(h.selected_text)}</blockquote>
        <div class="czh-notes__item-read">
          ${h.status === 'displaced' ? `<p class="czh-notes__item-warning">${escapeHtml(i18n.displaced_msg)}</p>` : ''}
          ${h.note ? `<p class="czh-notes__annotation">${escapeHtml(h.note)}</p>` : ''}
          <div class="czh-notes__item-footer">
            ${gotoBtn}
            <button type="button" class="czh-notes__edit-btn" data-czh-notes-edit="${h.id}">${escapeHtml(i18n.edit)}</button>
            <button type="button" class="czh-notes__del-btn" data-czh-notes-del="${h.id}">${escapeHtml(i18n.delete)}</button>
          </div>
        </div>
        <div class="czh-notes__item-edit" hidden>
          <div class="czh-notes__edit-colors">${colorBtns}</div>
          <textarea class="czh-notes__edit-textarea" rows="3">${escapeHtml(h.note || '')}</textarea>
          <div class="czh-notes__item-footer">
            <button type="button" class="czh-notes__save-btn" data-czh-notes-save="${h.id}">${escapeHtml(i18n.save)}</button>
            <button type="button" class="czh-notes__cancel-btn" data-czh-notes-cancel="${h.id}">${escapeHtml(i18n.cancel)}</button>
          </div>
        </div>
        <div class="czh-notes__item-confirm" hidden>
          <p class="czh-notes__confirm-msg">${escapeHtml(i18n.confirm_delete)}</p>
          <div class="czh-notes__item-footer">
            <button type="button" class="czh-notes__del-btn" data-czh-notes-confirm-del="${h.id}">${escapeHtml(i18n.delete)}</button>
            <button type="button" class="czh-notes__cancel-btn" data-czh-notes-cancel-del="${h.id}">${escapeHtml(i18n.cancel)}</button>
          </div>
        </div>
      </div>`;
  }

  function wireNoteActions(app) {
    // Goto buttons
    app.querySelectorAll('[data-czh-goto-id]').forEach(btn => {
      btn.addEventListener('click', () => {
        window.location.href = buildGotoUrl(
          btn.dataset.czhGotoPermalink,
          Number(btn.dataset.czhGotoPage || 1),
          btn.dataset.czhGotoId
        );
      });
    });

    // Toggle read → edit
    app.querySelectorAll('[data-czh-notes-edit]').forEach(btn => {
      btn.addEventListener('click', () => {
        const id   = Number(btn.dataset.czhNotesEdit);
        const item = app.querySelector(`.czh-notes__item[data-id="${id}"]`);
        if (!item) return;
        item.querySelector('.czh-notes__item-read').hidden = true;
        item.querySelector('.czh-notes__item-edit').hidden = false;
        item.querySelector('.czh-notes__edit-textarea').focus();
      });
    });

    // Cancel edit → back to read
    app.querySelectorAll('[data-czh-notes-cancel]').forEach(btn => {
      btn.addEventListener('click', () => {
        const id   = Number(btn.dataset.czhNotesCancel);
        const item = app.querySelector(`.czh-notes__item[data-id="${id}"]`);
        if (!item) return;
        item.querySelector('.czh-notes__item-read').hidden = false;
        item.querySelector('.czh-notes__item-edit').hidden = true;
      });
    });

    // Color pick inside edit mode
    app.querySelectorAll('.czh-notes__item-edit .czh-color-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const editEl = btn.closest('.czh-notes__item-edit');
        editEl.querySelectorAll('.czh-color-btn').forEach(b => b.classList.remove('is-active'));
        btn.classList.add('is-active');
      });
    });

    // Save
    app.querySelectorAll('[data-czh-notes-save]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id     = Number(btn.dataset.czhNotesSave);
        const item   = app.querySelector(`.czh-notes__item[data-id="${id}"]`);
        if (!item) return;
        const editEl = item.querySelector('.czh-notes__item-edit');
        const note   = editEl.querySelector('.czh-notes__edit-textarea').value;
        const color  = (editEl.querySelector('.czh-color-btn.is-active') || {}).dataset?.color || 'yellow';
        try {
          const updated = await apiFetch(`highlights/${id}`, { method: 'PATCH', body: { note, color } });
          item.dataset.color = updated.color;

          const readEl = item.querySelector('.czh-notes__item-read');
          let annotEl  = readEl.querySelector('.czh-notes__annotation');
          if (updated.note) {
            if (annotEl) {
              annotEl.textContent = updated.note;
            } else {
              annotEl = document.createElement('p');
              annotEl.className = 'czh-notes__annotation';
              annotEl.textContent = updated.note;
              readEl.insertBefore(annotEl, readEl.querySelector('.czh-notes__item-footer'));
            }
          } else if (annotEl) {
            annotEl.remove();
          }

          // Keep textarea in sync for next edit
          editEl.querySelector('.czh-notes__edit-textarea').value = updated.note || '';

          readEl.hidden = false;
          editEl.hidden = true;
        } catch (e) {
          console.error('[CZH] save note failed', e);
        }
      });
    });

    // Delete — show inline confirmation panel
    app.querySelectorAll('[data-czh-notes-del]').forEach(btn => {
      btn.addEventListener('click', () => {
        const id   = Number(btn.dataset.czhNotesDel);
        const item = app.querySelector(`.czh-notes__item[data-id="${id}"]`);
        if (!item) return;
        item.querySelector('.czh-notes__item-read').hidden    = true;
        item.querySelector('.czh-notes__item-confirm').hidden = false;
      });
    });

    // Confirm delete
    app.querySelectorAll('[data-czh-notes-confirm-del]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.dataset.czhNotesConfirmDel);
        try {
          await apiFetch(`highlights/${id}`, { method: 'DELETE' });
          const item      = app.querySelector(`.czh-notes__item[data-id="${id}"]`);
          if (!item) return;
          const itemsEl   = item.closest('.czh-notes__items');
          const sectionEl = item.closest('.collapsable-section');
          item.remove();
          if (itemsEl && !itemsEl.querySelector('.czh-notes__item')) {
            sectionEl?.remove();
          }
          if (!app.querySelector('.czh-notes__item')) {
            const body = app.querySelector('.czh-notes__articles');
            if (body) body.innerHTML = `<p class="czh-notes__empty">${escapeHtml(i18n.no_notes_volume)}</p>`;
          }
        } catch (e) {
          console.error('[CZH] delete note failed', e);
        }
      });
    });

    // Cancel delete confirmation
    app.querySelectorAll('[data-czh-notes-cancel-del]').forEach(btn => {
      btn.addEventListener('click', () => {
        const id   = Number(btn.dataset.czhNotesCancelDel);
        const item = app.querySelector(`.czh-notes__item[data-id="${id}"]`);
        if (!item) return;
        item.querySelector('.czh-notes__item-confirm').hidden = true;
        item.querySelector('.czh-notes__item-read').hidden    = false;
      });
    });
  }

  // ── Boot ───────────────────────────────────────────────────────────────────

  document.addEventListener('DOMContentLoaded', async () => {
    // Only active on article pages (not volume pages — no text to highlight there)
    const container = getContentEl();
    const isArticle = !!(ctx && ctx.type === 'post' && ctx.postId && container);

    // Drawer wiring (always, even on volume page)
    const drawer = getDrawer();
    if (drawer) {
      drawer.querySelector('.czh-drawer__close')
            ?.addEventListener('click', closeDrawer);
      document.querySelector('.czh-drawer-backdrop')
              ?.addEventListener('click', closeDrawer);
    }

    // "Le mie Note" button in nav-user-menu
    document.querySelectorAll('[data-czh-open-drawer]').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();

        if (!isArticle && ctx && ctx.type === 'volume') {
          // Volume page: open drawer with all volume highlights
          openVolumeDrawer();
        } else {
          openDrawer();
        }
      });
    });

    // Guest: only selection + tooltip → redirect to login
    if (!cfg.user.loggedIn) {
      if (!isArticle) return;
      if (isTouch) toolbarBtn = injectToolbarButton();
      document.addEventListener('selectionchange', onSelectionChange);
      document.addEventListener('mouseup', onMouseUp);
      return;
    }

    // Notes page: hand off to its own init function
    if (ctx && ctx.type === 'notes-page') {
      initNotesPage();
      return;
    }

    if (!isArticle) return; // nothing more to set up on non-article pages

    // Load highlights for this article
    try {
      highlights = await apiFetch(`highlights?post_id=${ctx.postId}`);
    } catch (e) {
      console.error('[CZH] load failed', e);
      highlights = [];
    }

    // Render only highlights belonging to the current paginated page
    const currentPage = ctx.pageNum || 1;
    highlights.forEach(hl => {
      if ((hl.page_num || 1) === currentPage) applyHighlightToDOM(hl, container);
    });

    // If coming from volume drawer via ?czh_hl=ID, scroll to that mark
    const scrollTargetId = Number(new URLSearchParams(window.location.search).get('czh_hl'));
    if (scrollTargetId) {
      const cleanUrl = new URL(window.location.href);
      cleanUrl.searchParams.delete('czh_hl');
      history.replaceState(null, '', cleanUrl.toString());
      const mark = document.querySelector(`.czh-hl[data-id="${scrollTargetId}"]`);
      if (mark) {
        setTimeout(() => mark.scrollIntoView({ behavior: 'smooth', block: 'center' }), 300);
      }
    }

    // Mobile toolbar button
    if (isTouch) toolbarBtn = injectToolbarButton();

    // Selection events
    document.addEventListener('selectionchange', onSelectionChange);
    document.addEventListener('mouseup', onMouseUp);

    // Click on existing mark
    container.addEventListener('click', onMarkClick);
  });

  // ── Volume drawer (all highlights in volume) ───────────────────────────────

  async function openVolumeDrawer() {
    if (!ctx || !ctx.volumeId) return;

    const drawer = getDrawer();
    if (!drawer) return;

    drawer.setAttribute('aria-hidden', 'false');
    drawer.classList.add('is-open');
    document.querySelector('.czh-drawer-backdrop')?.classList.add('is-visible');
    document.querySelector('details.nav-user')?.removeAttribute('open');

    const body = drawer.querySelector('.czh-drawer__body');
    if (body) body.innerHTML = '<p class="czh-drawer__loading">Caricamento…</p>';

    try {
      const rows = await apiFetch(`highlights/volume?volume_id=${ctx.volumeId}`);
      if (!body) return;

      if (!rows.length) {
        body.innerHTML = `<p class="czh-drawer__empty">${escapeHtml(i18n.no_highlights)}</p>`;
        return;
      }

      body.innerHTML = rows.map(h => `
        <div class="czh-drawer__item" data-id="${h.id}" data-color="${escapeAttr(h.color)}">
          ${h.post ? `<div class="czh-drawer__item-post"><a href="${escapeAttr(h.post.permalink)}">${escapeHtml(h.post.title)}</a></div>` : ''}
          <div class="czh-drawer__item-text">${escapeHtml(h.selected_text)}</div>
          ${h.note ? `<div class="czh-drawer__item-note">${escapeHtml(h.note)}</div>` : ''}
          ${h.post ? `<div class="czh-drawer__item-actions"><button type="button" class="czh-drawer__scroll-btn" data-czh-goto-permalink="${escapeAttr(h.post.permalink)}" data-czh-goto-page="${h.page_num || 1}" data-czh-goto-id="${h.id}">Vai</button></div>` : ''}
        </div>`).join('');

      body.querySelectorAll('[data-czh-goto-id]').forEach(btn => {
        btn.addEventListener('click', () => {
          window.location.href = buildGotoUrl(
            btn.dataset.czhGotoPermalink,
            Number(btn.dataset.czhGotoPage || 1),
            btn.dataset.czhGotoId
          );
        });
      });
    } catch (e) {
      console.error('[CZH] volume highlights failed', e);
      if (body) body.innerHTML = '<p class="czh-drawer__empty">Errore nel caricamento.</p>';
    }
  }

})();
