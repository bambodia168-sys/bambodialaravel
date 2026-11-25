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
    // Presets populate the existing, validated task-title input; they do not
    // introduce a new write endpoint or alter the creator-only task policy.
    const presetForm=dashboard.querySelector('[data-task-preset-form]');
    if(presetForm){
      const picker=presetForm.querySelector('[data-task-preset-select]');
      const title=presetForm.querySelector('[data-task-title]');
      const hint=presetForm.querySelector('[data-task-preset-hint]');
      const custom=presetForm.querySelector('[data-task-custom]');
      const chips=Array.from(presetForm.querySelectorAll('[data-task-preset]'));
      const synchronize=()=>{
        const value=title.value.trim();
        if(picker && picker.value!==title.value) picker.value='';
        const presetIsSelected=Boolean(picker && picker.value && picker.value===title.value);
        chips.forEach(chip=>{
          chip.setAttribute('aria-pressed',String(chip.dataset.taskPreset===title.value && value!==''));
        });
        if(hint){
          hint.textContent=presetIsSelected
            ? 'Preset selected. You can edit the title before saving it.'
            : value
              ? 'Custom milestone title ready. Add a target date if useful.'
              : 'Choose a suggested milestone or enter a custom task title.';
        }
      };
      const applyPreset=value=>{
        if(!value)return;
        title.value=value;
        synchronize();
        title.focus();
        title.setSelectionRange(title.value.length,title.value.length);
      };
      picker?.addEventListener('change',()=>applyPreset(picker.value));
      chips.forEach(chip=>{
        chip.addEventListener('click',()=>applyPreset(chip.dataset.taskPreset||''));
      });
      custom?.addEventListener('click',()=>{
        title.value='';
        if(picker)picker.value='';
        synchronize();
        title.focus();
      });
      title.addEventListener('input',synchronize);
      synchronize();
    }
    // Keep chat visible after submission when browsers preserve #tracking-conversation.
    if(window.location.hash==='#tracking-conversation'){
      dashboard.querySelector('#tracking-conversation')?.scrollIntoView({
        behavior:reduced?'auto':'smooth',block:'start'
      });
    }
  });
})();
