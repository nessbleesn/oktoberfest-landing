/* Preview only: this module never transmits personal data or creates a coupon. */
(()=>{
  const dialog=document.getElementById('discount-dialog');
  if(!dialog||typeof dialog.showModal!=='function')return;
  const openers=[...document.querySelectorAll('[data-discount-open]')];
  let opener=null;
  openers.forEach(link=>link.addEventListener('click',event=>{
    event.preventDefault();
    opener=link;
    if(!dialog.open)dialog.showModal();
    document.documentElement.classList.add('discount-open');
    requestAnimationFrame(()=>dialog.querySelector('[data-discount-close]').focus({preventScroll:true}));
  }));
  dialog.querySelectorAll('[data-discount-close]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
  dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});
  dialog.addEventListener('close',()=>{
    if(dialog.open)return;
    document.documentElement.classList.remove('discount-open');
    if(opener&&opener.isConnected)opener.focus({preventScroll:true});
  });
})();
