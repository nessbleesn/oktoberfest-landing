(() => {
  const dialog = document.querySelector('#legal-dialog');
  const title = document.querySelector('#legal-dialog-title');
  const body = document.querySelector('#legal-dialog-body');
  const closeButton = dialog?.querySelector('[data-legal-close]');
  const cookieTemplate = document.querySelector('#cookie-settings-template');
  if (!dialog || !title || !body || !closeButton || !cookieTemplate) return;

  const cookieKey = 'oktoberfest_cookie_choice_v1';
  let opener = null;

  const getCookieChoice = () => {
    try { return localStorage.getItem(cookieKey); } catch { return null; }
  };
  const cookieLabel = choice => choice === 'analytics' ? 'аналитика разрешена' : choice === 'essential' ? 'только необходимые' : 'не выбрана';
  const updateCookieStatus = () => {
    const status = body.querySelector('[data-cookie-status]');
    if (status) status.textContent = cookieLabel(getCookieChoice());
  };
  const openDialog = trigger => {
    opener = trigger;
    if (!dialog.open) dialog.showModal();
    document.documentElement.classList.add('legal-open');
    closeButton.focus({ preventScroll: true });
  };
  const openCookieSettings = trigger => {
    title.textContent = 'Настройки cookie';
    body.replaceChildren(cookieTemplate.content.cloneNode(true));
    updateCookieStatus();
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
      openCookieSettings(cookieTrigger);
      return;
    }
    const legalTrigger = event.target.closest('[data-legal-url]');
    if (legalTrigger) {
      event.preventDefault();
      openDocument(legalTrigger);
      return;
    }
    const choiceButton = event.target.closest('[data-cookie-choice]');
    if (choiceButton && dialog.contains(choiceButton)) {
      const choice = choiceButton.dataset.cookieChoice;
      try { localStorage.setItem(cookieKey, choice); } catch {}
      document.documentElement.dataset.cookieChoice = choice;
      updateCookieStatus();
      document.dispatchEvent(new CustomEvent('cookie-choice', { detail: { choice } }));
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
  const storedChoice = getCookieChoice();
  if (storedChoice) document.documentElement.dataset.cookieChoice = storedChoice;
})();
