/* Local preview only: this module never transmits form data or creates a coupon. */
(()=>{
  const dialog=document.getElementById('discount-dialog');
  const form=document.getElementById('discount-form');
  if(!dialog||!form||typeof dialog.showModal!=='function')return;
  const status=document.getElementById('discount-status');
  const submit=form.querySelector('[type="submit"]');
  const openers=[...document.querySelectorAll('[data-discount-open]')];
  let opener=null;
  submit.disabled=false;
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
  dialog.querySelector('[data-discount-close]').addEventListener('click',()=>dialog.close());
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
    status.textContent='Отправка формы будет доступна на сайте парка';
    window.dispatchEvent(new CustomEvent('festival:analytics',{detail:{name:'discount_submit_preview'}}));
  });
})();
