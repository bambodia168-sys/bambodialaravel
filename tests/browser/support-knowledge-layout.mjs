import {chromium} from 'playwright';
import fs from 'node:fs/promises';

const origin = process.env.BASE_URL || 'http://127.0.0.1:8094';
if(origin !== 'http://127.0.0.1:8094')
  throw Error('Support layout QA only runs against isolated localhost');
const output = process.env.BROWSER_ARTIFACT_DIR || 'browser-artifacts';
await fs.mkdir(output,{recursive:true});

const browser = await chromium.launch({headless:true});
const cases = [
  {width:1600,height:900,columns:5},
  {width:1280,height:900,columns:4},
  {width:1024,height:900,columns:3},
  {width:390,height:844,columns:2},
];
try{
  for(const viewport of cases){
    const page = await browser.newPage({viewport,deviceScaleFactor:1});
    const response = await page.goto(origin+'/support-center',{waitUntil:'networkidle',timeout:30000});
    if(response?.status() !== 200) throw Error('Public Support Center returned '+response?.status());
    for(const theme of ['light','dark']){
      await page.evaluate(async theme=>{
        document.documentElement.dataset.theme = theme;
        const visible=document.querySelector(theme==='dark' ? '.support-agent-photo-dark' : '.support-agent-photo-light');
        if(!visible) throw Error('Missing theme-specific support portrait');
        await visible.decode();
      },theme);
      const measurements = await page.evaluate(theme=>{
        document.documentElement.dataset.theme = theme;
        const articleGrid = document.querySelector('.support-article-grid');
        const categoryCards = [...document.querySelectorAll('.support-category-card')];
        const categoryStyles = categoryCards.map(el=>({
          number:el.querySelector('.support-category-number')?.textContent.trim(),
          icon:el.querySelector('.support-category-glyph svg')?.innerHTML,
          iconWidth:el.querySelector('.support-category-glyph svg')?.getBoundingClientRect().width,
          numberOpacity:Number(getComputedStyle(el.querySelector('.support-category-number')).opacity),
        }));
        return {
          columns:getComputedStyle(articleGrid).gridTemplateColumns.split(/\s+/).filter(Boolean).length,
          documentWidth:document.documentElement.scrollWidth,
          viewportWidth:window.innerWidth,
          heroAgent:(()=>{
            const hero=document.querySelector('.support-knowledge-hero').getBoundingClientRect();
            const visual=document.querySelector('.support-hero-agent-art');
            const day=visual.querySelector('.support-agent-photo-light');
            const night=visual.querySelector('.support-agent-photo-dark');
            const img=theme==='dark'?night:day;
            const agent=visual.getBoundingClientRect();
            return {
              loaded:img.complete && img.naturalWidth>=570 && img.naturalHeight>=478,
              dayVisible:getComputedStyle(day).display!=='none',
              nightVisible:getComputedStyle(night).display!=='none',
              source:img.currentSrc,
              rightDistance:hero.right-agent.right,
              visibleWidth:agent.width,
              imageAlt:img.getAttribute('alt'),
              layerOpacity:Number(getComputedStyle(visual).opacity),
              imageFilter:getComputedStyle(img).filter,
              maskImage:getComputedStyle(visual).maskImage,
              webkitMaskImage:getComputedStyle(visual).webkitMaskImage,
              pointerEvents:getComputedStyle(visual).pointerEvents,
              haloBackground:getComputedStyle(visual,'::before').backgroundImage,
              haloOpacity:Number(getComputedStyle(visual,'::before').opacity),
              haloLayer:getComputedStyle(visual,'::before').zIndex,
            };
          })(),
          categoryStyles,
          assistanceBackground:getComputedStyle(document.querySelector('.support-contact-cta')).backgroundColor,
          cards:[...document.querySelectorAll('.support-article-card')].map(card=>{
            const excerpt=card.querySelector('.support-article-excerpt');
            const icon=card.querySelector('.support-article-card-glyph');
            const number=card.querySelector('.support-article-card-number');
            const cardRect=card.getBoundingClientRect();
            const excerptStyle=excerpt?getComputedStyle(excerpt):null;
            const iconStyle=icon?getComputedStyle(icon):null;
            return {
              hasSummary:!!excerpt?.textContent.trim(),
              lineClamp:excerptStyle?.webkitLineClamp,
              excerptHeight:excerpt?.getBoundingClientRect().height,
              lineHeight:excerptStyle?parseFloat(excerptStyle.lineHeight):0,
              number:number?.textContent.trim(),
              iconOpacity:iconStyle?Number(iconStyle.opacity):1,
              numberOpacity:number?Number(getComputedStyle(number).opacity):1,
              iconRight:icon?.getBoundingClientRect().right-cardRect.left,
              iconBottom:cardRect.bottom-(icon?.getBoundingClientRect().bottom||0),
              iconTop:(icon?.getBoundingClientRect().top||0)-cardRect.top,
              numberBottom:(number?.getBoundingClientRect().bottom||0)-cardRect.top,
              iconWidth:icon?.getBoundingClientRect().width,
              cardWidth:cardRect.width,
            };
          }),
        };
      },theme);
      const {columns,documentWidth,viewportWidth,heroAgent,categoryStyles,assistanceBackground,cards} = measurements;
      const expectedImage=theme==='dark' ? '/images/support-agent-photo-dark.webp' : '/images/support-agent-photo.webp';
      const expectedContrast=theme==='dark' ? 'contrast(1.08)' : 'contrast(1.13)';
      if(!heroAgent.loaded || !heroAgent.source.endsWith(expectedImage) ||
         heroAgent.dayVisible !== (theme==='light') ||
         heroAgent.nightVisible !== (theme==='dark') ||
         heroAgent.rightDistance > 60 || heroAgent.visibleWidth<190 ||
         heroAgent.pointerEvents!=='none' || heroAgent.imageAlt!=='' ||
         heroAgent.layerOpacity<.15 || heroAgent.layerOpacity>1 ||
         (viewport.width>=1280 && heroAgent.layerOpacity<.99) ||
         !heroAgent.imageFilter.includes(expectedContrast) ||
         !heroAgent.maskImage.includes('radial-gradient') ||
         !heroAgent.webkitMaskImage.includes('radial-gradient'))
        throw Error('Photographic support-agent hero background missing or obstructing interactions: '+JSON.stringify(heroAgent));
      const goldenColor=theme==='dark' ? /242,\\s*177,\\s*76/ : /255,\\s*220,\\s*141/;
      if(!heroAgent.haloBackground.includes('radial-gradient') ||
         !goldenColor.test(heroAgent.haloBackground) ||
         heroAgent.haloLayer!=='0' ||
         heroAgent.haloOpacity < .40)
        throw Error('Golden agent halo missing or incorrect in '+theme+' at '+viewport.width+': '+JSON.stringify(heroAgent));
      if(columns !== viewport.columns)
        throw Error('Expected '+viewport.columns+' article columns at '+viewport.width+', found '+columns);
      if(documentWidth > viewportWidth+5)
        throw Error('Horizontal overflow in Support Center at '+viewport.width+'/'+theme);
      if(categoryStyles.length !== 8 || new Set(categoryStyles.map(c=>c.icon)).size !== 8)
        throw Error('Categories do not have eight distinct SVG icons');
      if(categoryStyles.some((c,i)=>c.number !== String(i+1).padStart(2,'0') || c.iconWidth < 45 || c.numberOpacity > .16))
        throw Error('Category numbering or icon size incorrect at '+viewport.width+'/'+theme);
      if(!/rgba\([^)]*,\s*0\)/.test(assistanceBackground) && assistanceBackground !== 'transparent')
        throw Error('Personal Assistance still has a section background: '+assistanceBackground);
      if(cards.length !== 2 || cards.some((card,i)=>!
        card.hasSummary || card.lineClamp !== '2' ||
        card.excerptHeight > card.lineHeight*2+3 ||
        card.number !== String(i+1).padStart(2,'0') ||
        card.iconOpacity > .18 || card.numberOpacity > .16 ||
        card.iconWidth < 60 || card.iconRight > card.cardWidth+2 ||
        card.iconBottom < 8 || card.iconBottom > 21 ||
        card.iconTop <= card.numberBottom + 5))
        throw Error('Article summary or blended icon/number acceptance failed at '+viewport.width+'/'+theme+': '+JSON.stringify(cards));
      if(viewport.width===1600 || viewport.width===390)
        await page.screenshot({path:output+'/support-knowledge-'+viewport.width+'-'+theme+'.png',fullPage:true});
      console.log('PASS public support layout '+viewport.width+'px '+theme+' ('+columns+' columns)');
    }
    const cardLink = await page.locator('.support-article-card').first().getAttribute('href');
    const articleResponse = await page.goto(new URL(cardLink,origin).href,{waitUntil:'networkidle'});
    if(articleResponse?.status()!==200 || await page.locator('.support-related-link').count()!==1)
      throw Error('Published article sidebar must show another published help guide');
    const sidebarNumbers = await page.locator('.support-related-link .support-related-number').allTextContents();
    if(sidebarNumbers.map(n=>n.trim()).join(',') !== '01' ||
       await page.locator('.support-related-link svg').count() !== 0)
      throw Error('Article navigation must use continuous numbering instead of file icons');
    const firstTitle = (await page.locator('.support-related-link').first().textContent()).trim();
    if(!firstTitle.includes('Browser QA'))
      throw Error('Sidebar link title missing or incorrect');
    console.log('PASS single article sidebar has a cross-category published guide at '+viewport.width);
    await page.close();
  }
}finally{
  await browser.close();
}
