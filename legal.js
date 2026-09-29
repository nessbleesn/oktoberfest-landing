(() => {
  const dialog = document.querySelector('#legal-dialog');
  const title = document.querySelector('#legal-dialog-title');
  const body = document.querySelector('#legal-dialog-body');
  const closeButton = dialog?.querySelector('[data-legal-close]');
  const cookieTemplate = document.querySelector('#cookie-settings-template');
  const cookieNotice = document.querySelector('#cookie-notice');
  if (!dialog || !title || !body || !closeButton || !cookieTemplate) return;

  const noticeKey = 'oktoberfest_cookie_notice_seen_v1';
  let opener = null;

  const noticeSeen = () => {
    try { return localStorage.getItem(noticeKey) === '1'; } catch { return false; }
  };
  const hideCookieNotice = () => {
    if (cookieNotice) cookieNotice.hidden = true;
    document.body.classList.remove('cookie-notice-visible');
  };
  if (cookieNotice && !noticeSeen()) {
    cookieNotice.hidden = false;
    document.body.classList.add('cookie-notice-visible');
  }
  const openDialog = trigger => {
    opener = trigger;
    if (!dialog.open) dialog.showModal();
    document.documentElement.classList.add('legal-open');
    closeButton.focus({ preventScroll: true });
  };
  const openCookieInfo = trigger => {
    title.textContent = 'О cookie';
    body.replaceChildren(cookieTemplate.content.cloneNode(true));
    openDialog(trigger);
  };
  const openDocument = trigger => {
    const url = trigger.dataset.legalUrl || trigger.href;
    const documentTitle = trigger.dataset.legalTitle || trigger.textContent.trim();
    title.textContent = documentTitle;
    body.replaceChildren();

    const note = document.createElement('p');
    note.className = 'legal-document-note';
    note.append('Актуальная редакция загружается с parkskazka.ru');
    const external = document.createElement('a');
    external.href = url;
    external.target = '_blank';
    external.rel = 'noopener';
    external.textContent = 'Открыть отдельно ↗';
    note.append(external);

    const frameWrap = document.createElement('div');
    frameWrap.className = 'legal-document-frame-wrap';
    const loading = document.createElement('p');
    loading.className = 'legal-document-loading';
    loading.textContent = 'Загружаем документ…';
    const frame = document.createElement('iframe');
    frame.className = 'legal-document-frame';
    frame.title = documentTitle;
    frame.loading = 'eager';
    frame.referrerPolicy = 'strict-origin-when-cross-origin';
    frame.setAttribute('sandbox', 'allow-same-origin');
    frame.src = url;
    frame.addEventListener('load', () => frameWrap.classList.add('is-ready'), { once: true });
    frameWrap.append(loading, frame);
    body.append(note, frameWrap);
    openDialog(trigger);
  };
  const closeDialog = () => {
    if (dialog.open) dialog.close();
  };

  document.addEventListener('click', event => {
    const cookieTrigger = event.target.closest('[data-legal-cookie]');
    if (cookieTrigger) {
      event.preventDefault();
      openCookieInfo(cookieTrigger);
      return;
    }
    const dismissButton = event.target.closest('[data-cookie-dismiss]');
    if (dismissButton && cookieNotice?.contains(dismissButton)) {
      try { localStorage.setItem(noticeKey, '1'); } catch {}
      hideCookieNotice();
      return;
    }
    const legalTrigger = event.target.closest('[data-legal-url]');
    if (legalTrigger) {
      event.preventDefault();
      openDocument(legalTrigger);
      return;
    }
  });
  closeButton.addEventListener('click', closeDialog);
  dialog.addEventListener('click', event => {
    if (event.target === dialog) closeDialog();
  });
  dialog.addEventListener('close', () => {
    document.documentElement.classList.remove('legal-open');
    body.querySelector('iframe')?.remove();
    if (opener?.isConnected) opener.focus({ preventScroll: true });
    opener = null;
  });
})();
