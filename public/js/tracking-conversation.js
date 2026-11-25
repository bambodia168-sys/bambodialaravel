/* Bambodia private order chat: AJAX, progressive HTML fallback and single-use handovers. */
(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('.project-tracking-detail');
    if (!root) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const jsonHeaders = {'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest', 'X-CSRF-TOKEN':csrf};
    const requestJson = async (url, options={}) => {
      const response = await fetch(url, {...options, credentials:'same-origin', cache:'no-store',
        headers: {...jsonHeaders, ...(options.headers || {})}});
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) {
        const fieldErrors = Object.values(payload.errors || {}).flat();
        throw new Error(fieldErrors[0] || payload.message || 'Request failed. Please try again.');
      }
      return payload;
    };
    const errorFor = (element, message) => {
      if (!element) return;
      element.textContent = message || '';
      element.hidden = !message;
    };

    const chat = root.querySelector('#tracking-conversation');
    const thread = chat?.querySelector('[data-chat-thread]');
    const chatForm = chat?.querySelector('[data-tracking-chat-form]');
    const older = chat?.querySelector('[data-chat-older]');
    const chatError = chat?.querySelector('[data-chat-error]');
    const chatCount = chat?.querySelector('[data-chat-count]');
    const chatCountLabel = chat?.querySelector('[data-chat-count-label]');
    const chatCounter = chat?.querySelector('[data-chat-counter]');
    let checking = false;
    let sending = false;

    const appendMessage = markup => {
      const template = document.createElement('template');
      template.innerHTML = markup;
      for (const node of Array.from(template.content.children)) {
        const id = node.getAttribute('data-message-id');
        if (!id || thread.querySelector('[data-message-id="' + id + '"]')) continue;
        thread.querySelector('[data-chat-empty]')?.remove();
        thread.append(node);
      }
    };
    const refreshCount = value => {
      const number = Number(value);
      if (!Number.isSafeInteger(number) || number < 0) return;
      const word = number === 1 ? 'message' : 'messages';
      if (chatCount) chatCount.textContent = String(number);
      if (chatCountLabel) chatCountLabel.textContent = word;
      if (chatCounter) chatCounter.setAttribute('aria-label', number + ' ' + word);
    };
    const nearBottom = () => thread && (thread.scrollHeight-thread.scrollTop-thread.clientHeight) < 100;
    const scrollDown = force => {
      if (!thread || !force) return;
      thread.scrollTo({top:thread.scrollHeight,behavior:reducedMotion?'auto':'smooth'});
    };
    const updateCursors = () => {
      if (!thread) return;
      const all = Array.from(thread.querySelectorAll('[data-message-id]'));
      if (!all.length) return;
      thread.dataset.firstId = all[0].dataset.messageId;
      thread.dataset.lastId = all[all.length-1].dataset.messageId;
    };
    const pollMessages = async () => {
      if (!thread || checking || sending || document.hidden) return;
      checking=true;
      try {
        const last=Number(thread.dataset.lastId||0);
        const url=new URL(chat.dataset.conversationUrl,window.location.href);
        url.searchParams.set('after',String(last));
        const keepBottom=nearBottom();
        const payload=await requestJson(url.toString());
        if (payload.html) appendMessage(payload.html);
        updateCursors();
        refreshCount(payload.total);
        scrollDown(keepBottom);
        // Large backlogs are fetched on the next poll without a full reload.
      } catch (error) {
        // Transient network interruptions must not erase drafts or disrupt typing.
      } finally { checking=false; }
    };
    older?.addEventListener('click',async()=>{
      if (!thread || older.disabled) return;
      older.disabled=true;
      try{
        const url=new URL(chat.dataset.conversationUrl,window.location.href);
        url.searchParams.set('before',thread.dataset.firstId||'0');
        const previousHeight=thread.scrollHeight;
        const payload=await requestJson(url.toString());
        const template=document.createElement('template');
        template.innerHTML=payload.html||'';
        for(const node of Array.from(template.content.children).reverse()){
          const id=node.dataset.messageId;
          if(id&&!thread.querySelector('[data-message-id="'+id+'"]'))thread.prepend(node);
        }
        thread.scrollTop+=thread.scrollHeight-previousHeight;
        updateCursors();
        older.hidden=!payload.has_more;
        refreshCount(payload.total);
      }catch(error){errorFor(chatError,error.message);}
      finally{older.disabled=false;}
    });
    if (thread) {
      thread.scrollTop=thread.scrollHeight;
      window.setInterval(pollMessages,7000);
      document.addEventListener('visibilitychange',()=>{if(!document.hidden)pollMessages();});
    }

    if (chatForm && thread) {
      const submit=chatForm.querySelector('[data-chat-submit]');
      const picker=chatForm.querySelector('[data-chat-attachments]');
      const attachButton=chatForm.querySelector('[data-chat-attach-button]');
      const fileNames=chatForm.querySelector('[data-chat-attachment-names]');
      attachButton?.addEventListener('click',()=>picker?.click());
      picker?.addEventListener('change',()=>{
        const files=Array.from(picker.files||[]);
        if (!fileNames) return;
        fileNames.replaceChildren();
        for (const file of files) {
          const item=document.createElement('span');
          item.textContent=file.name+' ('+Math.round(file.size/1024)+' KB)';
          fileNames.append(item);
        }
        fileNames.hidden=!files.length;
      });
      chatForm.addEventListener('submit',async event=>{
        if(event.defaultPrevented)return;
        event.preventDefault();
        if(sending)return;
        errorFor(chatError,'');
        const body=chatForm.querySelector('[data-ticket-message]');
        const surface=chatForm.querySelector('[data-ticket-surface]');
        const sourcePanel=chatForm.querySelector('[data-ticket-source-panel]');
        const sourceToggle=chatForm.querySelector('[data-ticket-toggle-source]');
        // The shared support editor syncs its HTML source before this listener.
        const text=new DOMParser().parseFromString(body.value,'text/html').body.textContent?.trim()||'';
        const count=picker?.files?.length||0;
        if(text.length<2&&count===0){
          errorFor(chatError,'Write a message or choose a reference attachment.');
          return;
        }
        if(count>4||Array.from(picker.files||[]).reduce((s,f)=>s+f.size,0)>20*1024*1024){
          errorFor(chatError,'Choose up to four attachments, 20 MB combined.');
          return;
        }
        sending=true;
        submit.disabled=true;
        submit.setAttribute('aria-busy','true');
        try{
          const payload=await requestJson(chatForm.action,{method:'POST',body:new FormData(chatForm)});
          if(payload.message)appendMessage(payload.message);
          updateCursors();
          refreshCount(payload.total);
          scrollDown(true);
          if(surface){
            surface.replaceChildren();
            surface.dispatchEvent(new Event('input',{bubbles:true}));
          }
          if(sourcePanel) sourcePanel.value='';
          if(sourceToggle?.getAttribute('aria-pressed')==='true'){
            sourceToggle.click();
          }
          body.value='';
          if(picker)picker.value='';
          if(fileNames){fileNames.replaceChildren();fileNames.hidden=true;}
          errorFor(chatError,'');
        }catch(error){errorFor(chatError,error.message);}
        finally{sending=false;submit.disabled=false;submit.removeAttribute('aria-busy');}
      });
    }

    const oncePanel=root.querySelector('#tracking-one-time');
    const onceForm=oncePanel?.querySelector('[data-once-form]');
    const onceError=oncePanel?.querySelector('[data-once-error]');
    const onceList=oncePanel?.querySelector('[data-once-list]');
    const onceStatus=oncePanel?.querySelector('[data-once-status]');
    const onceTimers=new WeakMap();

    onceForm?.addEventListener('submit',async event=>{
      event.preventDefault();
      const button=onceForm.querySelector('button[type="submit"]');
      if(button.disabled)return;
      button.disabled=true;errorFor(onceError,'');
      try{
        const payload=await requestJson(onceForm.action,{method:'POST',body:new FormData(onceForm)});
        onceList.querySelector('[data-once-empty]')?.remove();
        if(payload.note)onceList.insertAdjacentHTML('afterbegin',payload.note);
        onceForm.reset();
        onceForm.closest('details')?.removeAttribute('open');
        if(onceStatus)onceStatus.textContent='One-time note shared securely.';
      }catch(error){errorFor(onceError,error.message);}
      finally{button.disabled=false;}
    });

    oncePanel?.addEventListener('click',async event=>{
      const button=event.target.closest('[data-once-open],[data-once-revoke]');
      if(!button||button.disabled)return;
      const wrapper=button.closest('[data-once-id]');
      const reveal=wrapper?.querySelector('[data-once-reveal]');
      if(!wrapper)return;
      const opening=button.hasAttribute('data-once-open');
      if(opening&&!window.confirm('This note can only be opened once. Its contents will disappear from the server immediately after opening. Open now?'))return;
      if(!opening&&!window.confirm('Revoke this one-time note? It will no longer be accessible.'))return;
      button.disabled=true;errorFor(onceError,'');
      try{
        const payload=await requestJson(button.dataset.url,{method:'POST'});
        if(opening){
          if(reveal){
            reveal.textContent=payload.content||'';
            reveal.hidden=false;
            reveal.classList.add('is-visible');
            onceTimers.set(wrapper,window.setTimeout(()=>{
              reveal.textContent='';reveal.hidden=true;
              reveal.classList.remove('is-visible');
            },30000));
          }
          wrapper.querySelectorAll('[data-once-open],[data-once-revoke]').forEach(el=>el.remove());
          const meta=wrapper.querySelector('.tracking-once-item-top small');
          if(meta)meta.textContent='Opened · one-time access used';
          if(onceStatus)onceStatus.textContent='Opened. The note is now consumed and cannot be retrieved again.';
        }else{
          wrapper.querySelectorAll('[data-once-open],[data-once-revoke]').forEach(el=>el.remove());
          const meta=wrapper.querySelector('.tracking-once-item-top small');
          if(meta)meta.textContent='Revoked';
          if(onceStatus)onceStatus.textContent='One-time access revoked.';
        }
      }catch(error){
        errorFor(onceError,error.message);
        button.disabled=false;
      }
    });
    document.addEventListener('visibilitychange',()=>{
      if(!document.hidden)return;
      oncePanel?.querySelectorAll('[data-once-reveal]').forEach(element=>{
        element.textContent='';element.hidden=true;element.classList.remove('is-visible');
      });
    });
  });
})();
