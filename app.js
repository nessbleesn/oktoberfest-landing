/* Single content source for the selected day; all three days remain readable without JS. */
const dayLinks=[...document.querySelectorAll('[data-day]')];
const panels=[...document.querySelectorAll('[data-panel]')];
const reducedMotion=window.matchMedia('(prefers-reduced-motion: reduce)');
function selectDay(id,animate=false){
  if(!panels.some(p=>p.dataset.panel===id))return;
  panels.forEach(p=>{p.hidden=p.dataset.panel!==id;});
  dayLinks.forEach(a=>{if(a.dataset.day===id)a.setAttribute('aria-current','date');else a.removeAttribute('aria-current');});
  const current=panels.find(p=>p.dataset.panel===id);
  if(animate&&!reducedMotion.matches&&current.animate)current.animate([{opacity:0,transform:'translateY(12px)'},{opacity:1,transform:'translateY(0)'}],{duration:360,easing:'cubic-bezier(.16,1,.3,1)'});
}
selectDay(['friday','saturday','sunday'].includes(location.hash.slice(1))?location.hash.slice(1):'saturday');
dayLinks.forEach(a=>a.addEventListener('click',e=>{e.preventDefault();selectDay(a.dataset.day,true);track('day_select',{day:a.dataset.day});}));
dayLinks.forEach((a,index)=>a.addEventListener('keydown',e=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;e.preventDefault();let next=e.key==='Home'?0:e.key==='End'?2:(index+(e.key==='ArrowRight'?1:2))%3;dayLinks[next].focus();dayLinks[next].click();}));
window.addEventListener('hashchange',()=>selectDay(location.hash.slice(1)));
// Analytics is intentionally not installed in a private preview; events can be connected at release.
function track(name,params={}){window.dispatchEvent(new CustomEvent('festival:analytics',{detail:{name,...params}}));}
document.querySelectorAll('[data-event]').forEach(a=>a.addEventListener('click',()=>track(a.dataset.event)));

// Progressive motion: no scroll hijacking, no continuously animated copy.
let revealObserver;
const revealNodes=[...document.querySelectorAll('.experience h2,.reasons article,.program-heading,.food-photo,.food-copy,.activities h2,.activity-grid article,.family,.practical-grid>div,.faq>div,.closing h2')];
function stopMotion(){
  document.documentElement.classList.remove('motion-ready');
  if(revealObserver)revealObserver.disconnect();
  revealNodes.forEach(n=>n.classList.remove('reveal-pending'));
  document.querySelectorAll('[data-panel],details p').forEach(n=>{if(n.getAnimations)n.getAnimations().forEach(a=>a.cancel());});
}
function startMotion(){
  if(reducedMotion.matches||!('IntersectionObserver' in window))return;
  if(revealObserver)revealObserver.disconnect();
  revealObserver=new IntersectionObserver(entries=>entries.forEach(entry=>{if(entry.isIntersecting){entry.target.classList.remove('reveal-pending');entry.target.dataset.revealed='true';revealObserver.unobserve(entry.target);}}),{threshold:0,rootMargin:'0px 0px -15% 0px'});
  document.documentElement.classList.add('motion-ready');
  revealNodes.forEach((n,i)=>{n.classList.add('reveal-item');n.style.setProperty('--reveal-delay',`${n.matches('article')?(i%3)*120:0}ms`);if(n.dataset.revealed!=='true'&&n.getBoundingClientRect().top>=innerHeight*.85){n.classList.add('reveal-pending');revealObserver.observe(n);}else{n.classList.remove('reveal-pending');n.dataset.revealed='true';}});
}
startMotion();
const motionChange=()=>{if(reducedMotion.matches)stopMotion();else startMotion();};
if(reducedMotion.addEventListener)reducedMotion.addEventListener('change',motionChange);else if(reducedMotion.addListener)reducedMotion.addListener(motionChange);
document.addEventListener('visibilitychange',()=>document.documentElement.classList.toggle('page-hidden',document.hidden));
if('IntersectionObserver' in window){const heroVisibility=new IntersectionObserver(entries=>{document.documentElement.classList.toggle('hero-offscreen',!entries[0].isIntersecting);});heroVisibility.observe(document.querySelector('.hero-autumn'));}
document.querySelectorAll('details').forEach(d=>d.addEventListener('toggle',()=>{const text=d.querySelector('p');if(d.open&&text&&text.animate&&!reducedMotion.matches)text.animate([{opacity:0,transform:'translateY(-5px)'},{opacity:1,transform:'translateY(0)'}],{duration:240,easing:'ease-out'});}));
