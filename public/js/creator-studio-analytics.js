/* Creator Studio real-data dashboard: no synthetic sales, hours or client records. */
(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-studio-analytics]');
    const payload = document.getElementById('studio-analytics-payload');
    if (!root || !payload) return;

    let report;
    try { report = JSON.parse(payload.textContent); } catch { return; }
    let period = report.period || 'month';
    let type = report.type || 'all';
    let pending = null;
    const error = root.querySelector('[data-studio-error]');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const n = v => Number.isFinite(Number(v)) ? Number(v) : 0;
    const money = v => new Intl.NumberFormat(undefined, {style:'currency',currency:'USD',maximumFractionDigits:2}).format(n(v));
    const decimal = v => new Intl.NumberFormat(undefined, {maximumFractionDigits:2}).format(n(v));
    const escape = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const el = key => root.querySelector('[data-chart="' + key + '"]');
    const toNumber = v => Math.max(0, n(v));
    const empty = (message) => '<p class="studio-chart-empty">' + escape(message) + '</p>';
    const labelsFor = d => Array.isArray(d.labels) ? d.labels : [];

    function svgLine(d, series, goal) {
      const labels = labelsFor(d);
      const width = 540, height = 225, left = 58, right = 12, top = 22, bottom = 42;
      if (!labels.length) return empty('No reporting periods available.');
      const values = series.map(s => s.data.map(toNumber));
      const upper = Math.max(1, ...values.flat(), goal === null ? 0 : toNumber(goal));
      const max = upper * 1.18;
      const x = i => left + ((width - left - right) * (labels.length === 1 ? 0.5 : i / (labels.length - 1)));
      const y = v => (height - bottom) - (toNumber(v) / max) * (height - bottom - top);
      let markup = '<svg viewBox="0 0 ' + width + ' ' + height + '" aria-hidden="true" focusable="false" class="studio-svg">';
      for (let k=0;k<=4;k++) {
        const value = max * k / 4;
        markup += '<line class="studio-grid-line" x1="' + left + '" x2="' + (width-right) + '" y1="' + y(value) + '" y2="' + y(value) + '"/>';
        markup += '<text class="studio-tick" x="' + (left-9) + '" y="' + (y(value)+4) + '" text-anchor="end">' + (max > 200 ? Math.round(value).toLocaleString() : decimal(value)) + '</text>';
      }
      labels.forEach((label,i) => {
        markup += '<text class="studio-tick" x="' + x(i) + '" y="' + (height-13) + '" text-anchor="middle">' + escape(label) + '</text>';
      });
      if (goal !== null) {
        markup += '<line class="studio-goal-line" x1="' + left + '" x2="' + (width-right) + '" y1="' + y(goal) + '" y2="' + y(goal) + '"/>';
      }
      for (const item of series) {
        const points = item.data.map((v,i) => x(i)+','+y(v)).join(' ');
        const fill = item.key === 'revenue' ? 'studio-line-area' : 'studio-line-area secondary-area';
        const firstX=x(0),lastX=x(item.data.length-1),baseline=y(0);
        markup += '<polygon class="' + fill + '" points="' + firstX + ',' + baseline + ' ' + points + ' ' + lastX + ',' + baseline + '"/>';
        markup += '<polyline class="studio-line studio-line-' + escape(item.key) + '" points="' + points + '"/>';
        item.data.forEach((v,i) => {
          markup += '<circle class="studio-dot studio-dot-' + escape(item.key) + '" cx="' + x(i) + '" cy="' + y(v) + '" r="3.8"><title>' +
            escape(labels[i]) + ': ' + decimal(v) + '</title></circle>';
        });
      }
      markup += '</svg>';
      return markup;
    }

    function svgHours(d) {
      const labels = labelsFor(d);
      if (!labels.length) return empty('No time records yet.');
      const bill = d.billable.map(toNumber), non = d.non_billable.map(toNumber);
      const max = Math.max(1,...bill.map((v,i)=>v+non[i]))*1.15;
      const w=540,h=225,left=45,top=25,bottom=42;
      const y = v => h-bottom-v/max*(h-bottom-top);
      const space=(w-left-12)/labels.length;
      let s='<svg class="studio-svg" viewBox="0 0 '+w+' '+h+'" aria-hidden="true" focusable="false">';
      for(let k=0;k<=4;k++){
        const val=max*k/4;
        s+='<line class="studio-grid-line" x1="'+left+'" x2="'+(w-12)+'" y1="'+y(val)+'" y2="'+y(val)+'"/>';
        s+='<text class="studio-tick" x="'+(left-8)+'" y="'+(y(val)+4)+'" text-anchor="end">'+decimal(val)+'h</text>';
      }
      labels.forEach((label,i)=>{
        const x=left+space*i+space*.3,bw=space*.4;
        const bh=Math.max(0,y(0)-y(bill[i])),nh=Math.max(0,y(0)-y(non[i]));
        s+='<rect class="studio-bar-billable" x="'+x+'" y="'+y(bill[i])+'" width="'+bw+'" height="'+bh+'" rx="3"/>';
        s+='<rect class="studio-bar-nonbillable" x="'+x+'" y="'+y(bill[i]+non[i])+'" width="'+bw+'" height="'+nh+'" rx="3"/>';
        s+='<text class="studio-tick" x="'+(x+bw/2)+'" y="'+(h-13)+'" text-anchor="middle">'+escape(label)+'</text>';
      });
      return s+'</svg>';
    }

    function donut(parts, center, subtitle, currency) {
      const total=parts.reduce((sum,p)=>sum+toNumber(p.value),0);
      let angle=0, slices=[];
      parts.forEach(p=>{
        const next=angle+(total?toNumber(p.value)/total*100:0);
        if(next>angle) slices.push(p.color+' '+angle+'% '+next+'%');
        angle=next;
      });
      const background=slices.length?'conic-gradient('+slices.join(',')+')':'conic-gradient(var(--studio-muted-arc) 0% 100%)';
      const rows=parts.map(p=>'<div class="studio-donut-row"><span class="studio-dot-label" style="--dot:'+p.color+'">'+escape(p.label)+
        '</span><strong>'+(currency?money(p.value):decimal(p.value))+'</strong></div>').join('');
      return '<div class="studio-donut" style="background:'+background+'"><div class="studio-donut-center"><strong>'+
        escape(center)+'</strong><small>'+escape(subtitle)+'</small></div></div><div class="studio-donut-legend">'+rows+'</div>';
    }

    function renderProjects(d) {
      const tbody=document.querySelector('.studio-dashboard [data-studio-project-rows]');
      const timeline=document.querySelector('.studio-dashboard [data-studio-timeline]');
      if (!tbody || !timeline) return;
      tbody.replaceChildren();
      const projects=Array.isArray(d.projects)?d.projects:[];
      if(!projects.length){
        const row=tbody.insertRow(),cell=row.insertCell();cell.colSpan=4;cell.className='studio-empty';
        cell.textContent='No matching freelance contracts yet.';
      }
      projects.forEach(p=>{
        const row=tbody.insertRow();
        const first=row.insertCell();
        const link=document.createElement('a');
        try { const url=new URL(String(p.url),window.location.href); if(url.origin===window.location.origin) link.href=url.href; } catch {}
        const title=document.createElement('strong');title.textContent=String(p.title||'Contract');
        link.append(title);first.append(link);
        const client=document.createElement('small');client.textContent=String(p.client||'Client');first.append(client);
        const status=row.insertCell(),pill=document.createElement('span');pill.textContent=p.status;
        pill.className='studio-status '+String(p.status||'').toLowerCase().replace(/[^a-z]+/g,'-');
        status.append(pill);
        const progress=row.insertCell();
        if(p.progress===null){
          const info=document.createElement('small');info.textContent='No milestones';progress.append(info);
        }else{
          const meter=document.createElement('progress');meter.max=100;meter.value=Math.min(100,toNumber(p.progress));
          const info=document.createElement('small');info.textContent=decimal(p.progress)+'%';
          progress.append(meter,info);
        }
        row.insertCell().textContent=p.value===null?String(p.currency||''):money(p.value);
      });
      timeline.replaceChildren();
      const heading=document.createElement('h4');heading.textContent='Contract timeline';timeline.append(heading);
      const entries=projects.flatMap(p=>(p.milestones||[]).map(m=>m)).sort((a,b)=>String(a.date).localeCompare(String(b.date))).slice(0,6);
      if(!entries.length){ const note=document.createElement('p');note.className='studio-empty';note.textContent='No dated milestones to display.';timeline.append(note); }
      entries.forEach(m=>{
        const item=document.createElement('div');item.className='studio-timeline-row';
        const date=document.createElement('time');date.dateTime=m.date;date.textContent=m.date;
        const title=document.createElement('strong');title.textContent=m.title;
        const status=document.createElement('small');status.textContent=String(m.status||'').replaceAll('_',' ');
        item.append(date,title,status);timeline.append(item);
      });
    }

    function render(d) {
      const totalRevenue=root.querySelector('[data-total-revenue]');
      const hoursTotal=root.querySelector('[data-hours-total]');
      if (totalRevenue) totalRevenue.textContent=money(d.total_revenue);
      if (hoursTotal) hoursTotal.textContent=decimal(n(d.billable_hours)+n(d.non_billable_hours))+' h';
      const windowStart=root.querySelector('[data-window-start]');
      if(windowStart) windowStart.textContent=d.start;
      const observedMargin=root.querySelector('[data-observed-margin]');
      if(observedMargin) observedMargin.textContent=d.observed_margin_percent === null
        ? 'Margin unavailable until sales exist; excludes fees and unrecorded costs.'
        : decimal(d.observed_margin_percent)+'% sales minus logged-expenses margin (not accounting profit).';
      const effectiveRate=root.querySelector('[data-effective-rate]');
      if(effectiveRate) effectiveRate.textContent=d.sales_per_logged_billable_hour === null
        ? 'Log billable work to calculate gross sales per recorded hour.'
        : money(d.sales_per_logged_billable_hour)+'/logged billable hour (not a contracted rate).';
      const kpis={total_revenue:money(d.total_revenue),billable_hours:decimal(d.billable_hours)+' h',
        sales_minus_expenses:money(d.sales_minus_expenses),projects:String((d.projects||[]).length)};
      Object.entries(kpis).forEach(([key,value])=>{
        const target=root.querySelector('[data-kpi="'+key+'"]');
        if (target) target.textContent=value;
      });

      const count=labelsFor(d).length||1;
      const goal=d.goal===null?null:n(d.goal)/count;
      el('revenue').innerHTML=svgLine(d,[{key:'revenue',data:d.revenue}],goal);
      el('hours').innerHTML=svgHours(d);
      el('expenses').innerHTML=svgLine(d,[
        {key:'revenue',data:d.revenue},{key:'expenses',data:d.expenses}
      ],null);
      el('orders').innerHTML=donut([
        {label:'Completed',value:d.orders.completed,color:'var(--studio-blue)'},
        {label:'Awaiting payment',value:d.orders.awaiting_payment,color:'var(--studio-light-blue)'},
        {label:'Refunded',value:d.orders.refunded,color:'var(--studio-orange)'}
      ],String(n(d.orders.completed)+n(d.orders.awaiting_payment)+n(d.orders.refunded)),'Orders',false);
      el('retention').innerHTML=donut([
        {label:'Repeat purchases',value:d.repeat_sales,color:'var(--studio-blue)'},
        {label:'Single purchases',value:d.single_sales,color:'var(--studio-orange)'}
      ],d.total_revenue>0?Math.round(n(d.repeat_sales)/n(d.total_revenue)*100)+'%':'0%',
      'Repeat share',true);

      el('workstreams').innerHTML=donut([
        {label:'Products',value:d.revenue_by_type.product,color:'var(--studio-blue)'},
        {label:'Projects',value:d.revenue_by_type.project,color:'var(--studio-orange)'},
        {label:'Freelance',value:d.revenue_by_type.freelance,color:'var(--studio-light-blue)'},
        {label:'Services',value:d.revenue_by_type.service,color:'var(--studio-fourth)'}
      ],money(d.total_revenue),'Verified USD',true);
      const capacity=d.weekly_capacity_hours===null?null:n(d.weekly_capacity_hours);
      const ratio=capacity>0?n(d.week_hours)/capacity*100:0;
      const percent=Math.min(100,Math.max(0,ratio));
      el('capacity').innerHTML='<svg viewBox="0 0 220 130" class="studio-gauge" aria-hidden="true"><path class="studio-gauge-track" d="M20 109 A90 90 0 0 1 200 109" pathLength="100"/>'+
        '<path class="studio-gauge-fill" d="M20 109 A90 90 0 0 1 200 109" pathLength="100" stroke-dasharray="'+percent+' 100"/></svg>'+
        '<div class="studio-gauge-value"><strong>'+(capacity===null?'—':Math.round(ratio)+'%')+'</strong>'+
        '<small>'+decimal(d.week_hours)+' / '+(capacity===null?'target not set':decimal(capacity)+' hours')+'</small></div>';
      const stages=[
        {label:'Client inquiries',value:n(d.pipeline.inquiries)},
        {label:'Job proposals',value:n(d.pipeline.proposals)},
        {label:'Contracts accepted',value:n(d.pipeline.accepted_contracts)}
      ],highest=Math.max(1,...stages.map(s=>s.value));
      el('pipeline').innerHTML=stages.map(s=>'<div class="studio-pipeline-row"><div><span>'+escape(s.label)+'</span><strong>'+decimal(s.value)+'</strong></div>'+
        '<span class="studio-progress-rail"><span style="width:'+(s.value/highest*100)+'%"></span></span></div>').join('');
      const heat=Array.isArray(d.heatmap)?d.heatmap:[];
      el('heatmap').innerHTML=heat.map(item=>'<span class="studio-heat-cell" data-heat="'+
        Math.min(4,Math.ceil(toNumber(item.hours)/2))+'" title="'+escape(item.date+' · '+decimal(item.hours)+' hours')+'"></span>').join('');
      renderProjects(d);
      if (!reduceMotion) root.querySelectorAll('.studio-chart-canvas,.studio-donut,.studio-gauge-fill,.studio-heat-cell').forEach(node=>{
        node.classList.remove('studio-animate');void node.offsetWidth;node.classList.add('studio-animate');
      });
    }

    root.querySelectorAll('[data-filter-type],[data-filter-period]').forEach(button=>{
      button.addEventListener('click', async()=>{
        const nextType=button.dataset.filterType||type;
        const nextPeriod=button.dataset.filterPeriod||period;
        if(nextType===type && nextPeriod===period) return;
        if(pending) pending.abort();
        const active=new AbortController();pending=active;
        root.setAttribute('aria-busy','true');error.hidden=true;
        root.querySelectorAll('[data-filter-type],[data-filter-period]').forEach(b=>b.disabled=true);
        try{
          const url=new URL(root.dataset.endpoint,window.location.origin);
          url.searchParams.set('period',nextPeriod);
          url.searchParams.set('type',nextType);
          const response=await fetch(url.toString(),{signal:active.signal,credentials:'same-origin',
            headers:{'Accept':'application/json'},cache:'no-store'});
          if(!response.ok)throw new Error('Unable to load your selected analytics.');
          const updated=await response.json();
          if(pending!==active)return;
          type=nextType;period=nextPeriod;report=updated;
          root.querySelectorAll('[data-filter-type]').forEach(b=>{
            const on=b.dataset.filterType===type;b.classList.toggle('is-active',on);b.setAttribute('aria-pressed',String(on));
          });
          root.querySelectorAll('[data-filter-period]').forEach(b=>{
            const on=b.dataset.filterPeriod===period;b.classList.toggle('is-active',on);b.setAttribute('aria-pressed',String(on));
          });
          render(report);
        }catch(e){
          if(e.name!=='AbortError'){error.hidden=false;error.textContent='The analytics could not be refreshed. Your previously loaded data is unchanged.';}
        }finally{
          if(pending===active){pending=null;root.removeAttribute('aria-busy');root.querySelectorAll('[data-filter-type],[data-filter-period]').forEach(b=>b.disabled=false);}
        }
      });
    });
    render(report);
  });
})();
