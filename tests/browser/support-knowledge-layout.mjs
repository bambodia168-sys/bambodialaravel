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
          categoryStyles,
          assistanceBackground:getComputedStyle(document.querySelector('.support-contact-cta')).backgroundColor,
          excerptCount:document.querySelectorAll('.support-article-card p').length,
        };
      },theme);
      const {columns,documentWidth,viewportWidth,categoryStyles,assistanceBackground,excerptCount} = measurements;
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
      if(excerptCount !== 0) throw Error('Article cards still render summaries');
      if(viewport.width===1600 || viewport.width===390)
        await page.screenshot({path:output+'/support-knowledge-'+viewport.width+'-'+theme+'.png',fullPage:true});
      console.log('PASS public support layout '+viewport.width+'px '+theme+' ('+columns+' columns)');
    }
    await page.close();
  }
}finally{
  await browser.close();
}
