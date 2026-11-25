/* Creator/customer tracking micro-interactions; safe without JavaScript. */
(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const dashboard=document.querySelector('.project-tracking');
    if(!dashboard)return;
    const reduced=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const progress=dashboard.querySelector('.tracking-main-progress');
    if(progress&&!reduced) {
      const target=Number(progress.value)||0;
      const start=performance.now();
      const duration=650;
      function draw(now) {
        const ratio=Math.min(1,Math.max(0,(now-start)/duration));
        progress.value=target*(1-Math.pow(1-ratio,3));
        if(ratio<1)window.requestAnimationFrame(draw);
        else progress.value=target;
      }
      window.requestAnimationFrame(draw);
    }
    dashboard.querySelectorAll('[data-track-confirm]').forEach(button=>{
      button.addEventListener('click',e=>{
        if(!window.confirm(button.dataset.trackConfirm||'Confirm this action?'))e.preventDefault();
      });
    });
    // Keep chat visible after submission when browsers preserve #tracking-conversation.
    if(window.location.hash==='#tracking-conversation'){
      dashboard.querySelector('#tracking-conversation')?.scrollIntoView({
        behavior:reduced?'auto':'smooth',block:'start'
      });
    }
  });
})();
