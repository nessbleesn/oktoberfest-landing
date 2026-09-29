/* Oktoberfest analytics: counters load on page view; the cookie notice is informational. */
(()=>{
  const metrikaId=107188789;
  const vkId=3794718;
  let started=false;
  const addScript=(id,src)=>{
    if(document.getElementById(id))return;
    const script=document.createElement('script');
    script.id=id;
    script.async=true;
    script.src=src;
    document.head.append(script);
  };
  const start=()=>{
    if(started)return;
    started=true;
    window.ym=window.ym||function(){(window.ym.a=window.ym.a||[]).push(arguments);};
    window.ym.l=window.ym.l||Date.now();
    addScript('oktoberfest-metrika','https://mc.yandex.ru/metrika/tag.js?id=107188789');
    window.ym(metrikaId,'init',{
      ssr:true,webvisor:true,clickmap:true,ecommerce:'dataLayer',
      referrer:document.referrer,url:location.href,accurateTrackBounce:true,trackLinks:true
    });
    window._tmr=window._tmr||[];
    window._tmr.push({id:String(vkId),type:'pageView',start:Date.now()});
    addScript('tmr-code','https://top-fwz1.mail.ru/js/code.js');
  };
  const vkGoal=goal=>{
    if(started)window._tmr.push({type:'reachGoal',id:vkId,goal});
  };
  const metrikaGoal=goal=>{
    if(started)window.ym(metrikaId,'reachGoal',goal);
  };
  window.addEventListener('festival:analytics',event=>{
    if(event.detail?.name==='discount_open')vkGoal('click-get-sale');
  });
  window.addEventListener('festival:bitrix-lead-confirmed',event=>{
    if(event.detail?.success!==true||event.detail?.newLead!==true)return;
    metrikaGoal('send_get_sale');
    vkGoal('send-get-sale');
  });
  start();
})();
