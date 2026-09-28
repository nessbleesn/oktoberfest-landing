/* Form-only preview: no request, storage, or coupon issuance is performed. */
(()=>{
  const dialog=document.getElementById('discount-dialog');
  const form=document.getElementById('discount-form');
  if(!dialog||!form||typeof dialog.showModal!=='function')return;
  const status=document.getElementById('discount-status');
  const openers=[...document.querySelectorAll('[data-discount-open]')];
  let opener=null;
  openers.forEach(link=>link.addEventListener('click',event=>{
    event.preventDefault();
    opener=link;
    form.reset();
    status.textContent='';
    if(!dialog.open)dialog.showModal();
    document.documentElement.classList.add('discount-open');
    requestAnimationFrame(()=>{
      const desktopInput=innerWidth>700&&matchMedia('(hover:hover) and (pointer:fine)').matches;
      (desktopInput?form.elements.name:dialog.querySelector('[data-discount-close]')).focus({preventScroll:true});
    });
  }));
  dialog.querySelectorAll('[data-discount-close]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
  dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});
  dialog.addEventListener('close',()=>{
    if(dialog.open)return;
    document.documentElement.classList.remove('discount-open');
    form.reset();
    status.textContent='';
    if(opener&&opener.isConnected)opener.focus({preventScroll:true});
  });
  form.addEventListener('submit',event=>{
    event.preventDefault();
    if(!form.reportValidity())return;
    status.textContent='Форма заполнена, но данные не отправлены. Подключим отправку после настройки почты и CRM.';
    status.scrollIntoView({block:'nearest'});
  });
})();
