/* Discount form: contacts are sent to the same-origin server endpoint. */
(()=>{
  const dialog=document.getElementById('discount-dialog');
  const form=document.getElementById('discount-form');
  if(!dialog||!form||typeof dialog.showModal!=='function')return;
  const status=document.getElementById('discount-status');
  const openers=[...document.querySelectorAll('[data-discount-open]')];
  let opener=null;
  let pending=false;
  let submissionKey=null;
  openers.forEach(link=>link.addEventListener('click',event=>{
    event.preventDefault();
    opener=link;
    form.reset();
    status.textContent='';
    submissionKey=window.crypto?.randomUUID?.()||null;
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
  form.addEventListener('submit',async event=>{
    event.preventDefault();
    if(pending||!form.reportValidity())return;
    pending=true;
    const submit=form.querySelector('[type="submit"]');
    const initialLabel=submit.innerHTML;
    submit.disabled=true;
    submit.textContent='Отправляем…';
    status.textContent='';
    const payload={
      name:form.elements.namedItem('name').value,
      phone:form.elements.namedItem('phone').value,
      email:form.elements.namedItem('email').value,
      company:form.elements.namedItem('company').value,
      personal_consent:form.elements['personal-consent'].checked,
      marketing_consent:form.elements['marketing-consent'].checked
    };
    if(submissionKey)payload.idempotency_key=submissionKey;
    try{
      const response=await fetch(form.action,{
        method:'POST',
        headers:{
          'Accept':'application/json',
          'Content-Type':'application/json'
        },
        body:JSON.stringify(payload),
        credentials:'same-origin'
      });
      const result=await response.json().catch(()=>({}));
      if(!response.ok||result.success!==true){
        const failure=new Error(result.error||'server_error');
        failure.validation=response.status===422;
        throw failure;
      }
      status.textContent=result.delivery_pending
        ?`Ваш промокод ${result.promo_code}. Сохраните его: отправка письма пока задерживается.`
        :`Ваш промокод ${result.promo_code}. Сохраните его.`;
      track('discount_subscribe_success');
      if(result.bitrix_success===true&&result.bitrix_new_lead===true){
        window.dispatchEvent(new CustomEvent('festival:bitrix-lead-confirmed',{
          detail:{success:true,newLead:true}
        }));
      }
    }catch(error){
      status.textContent=error.validation
        ?error.message
        :error.message==='rate_limited'
          ?'Слишком много попыток. Попробуйте позже.'
          :error.message==='contact_conflict'
            ?'Почта и телефон относятся к разным заявкам. Позвоните в парк для проверки.'
            :'Не удалось отправить заявку. Попробуйте позже.';
      track('discount_subscribe_error');
    }finally{
      pending=false;
      submit.disabled=false;
      submit.innerHTML=initialLabel;
      status.scrollIntoView({block:'nearest'});
    }
  });
})();
