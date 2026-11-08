import {chromium} from 'playwright';
import fs from 'node:fs/promises';

const origin = process.env.BASE_URL || 'http://127.0.0.1:8094';
const out = process.env.BROWSER_ARTIFACT_DIR || 'browser-artifacts';
await fs.mkdir(out,{recursive:true});

const devices = [
  {name:'desktop',viewport:{width:1440,height:900},mobile:false},
  {name:'tablet',viewport:{width:820,height:1180},mobile:true},
  {name:'iphone-emulation',viewport:{width:390,height:844},mobile:true},
  {name:'android-emulation',viewport:{width:412,height:915},mobile:true},
];
const paths = ['/', '/search', '/jobs', '/creators', '/login', '/register', '/forgot-password'];
const browser = await chromium.launch({headless:true});
let checks=0;
try {
  for(const device of devices){
    const context=await browser.newContext({
      viewport:device.viewport,deviceScaleFactor:device.mobile?2:1,
      isMobile:device.mobile,hasTouch:device.mobile,
    });
    const page=await context.newPage();
    for(const path of paths){
      const response=await page.goto(origin+path,{waitUntil:'networkidle',timeout:30000});
      if(!response || response.status()!==200)
        throw new Error(device.name+' '+path+' returned '+response?.status());
      if(!(await page.locator('main, .site-body, .auth-body').count()))
        throw new Error(device.name+' '+path+' has no renderable page root');
      const measured=await page.evaluate(()=>({
        documentWidth:document.documentElement.scrollWidth,
        viewport:window.innerWidth,
        pageHeight:document.documentElement.scrollHeight,
      }));
      if(measured.documentWidth>measured.viewport+5)
        throw new Error(device.name+' '+path+' horizontal overflow '+JSON.stringify(measured));
      const typeSize=await page.evaluate(()=>parseFloat(getComputedStyle(document.body).fontSize));
      if(typeSize!==14)
        throw new Error(device.name+' '+path+' body font must use 14px platform token, saw '+typeSize);
      checks++;
      if(path==='/'){
        // Validate readable type sizing and WCAG AA color tokens on both theme palettes.
        const textMetrics=await page.evaluate(()=>{
          const heading=document.querySelector('.hero h1');
          const style=getComputedStyle(heading);
          const heroButton=document.querySelector('.hero-search .btn');
          const placeholder=document.querySelector('.hero-search input');
          return {
            fontSize:parseFloat(style.fontSize),
            lineHeight:parseFloat(style.lineHeight),
            letterSpacing:style.letterSpacing,
            headingWidth:heading.scrollWidth,
            availableWidth:heading.clientWidth,
            buttonSize:heroButton?parseFloat(getComputedStyle(heroButton).fontSize):14,
            placeholderSize:placeholder?parseFloat(getComputedStyle(placeholder,'::placeholder').fontSize):12,
          };
        });
        if(textMetrics.fontSize>56 || textMetrics.lineHeight<textMetrics.fontSize*1.17
            || (textMetrics.letterSpacing!=='normal' && parseFloat(textMetrics.letterSpacing)<-.25)
            || textMetrics.headingWidth>textMetrics.availableWidth+3
            || textMetrics.buttonSize!==14 || textMetrics.placeholderSize>12)
          throw new Error(device.name+' inconsistent or overlapping hero typography: '+JSON.stringify(textMetrics));
        for(const theme of ['light','dark']){
          const ratios=await page.evaluate(theme=>{
            const root=document.documentElement;
            const originalTheme=root.dataset.theme;
            root.dataset.theme=theme;
            const style=getComputedStyle(root);
            const color=key=>style.getPropertyValue(key).trim();
            const lum=hex=>{
              const channels=[1,3,5].map(i=>{
                const c=parseInt(hex.slice(i,i+2),16)/255;
                return c<=.04045?c/12.92:((c+.055)/1.055)**2.4;
              });
              return .2126*channels[0]+.7152*channels[1]+.0722*channels[2];
            };
            const ratio=(a,b)=>{
              const x=lum(color(a)),y=lum(color(b));
              return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);
            };
            const result={
              bodyOnSurface:ratio('--text','--surface'),
              bodyOnBackground:ratio('--text','--bg'),
              helperOnSurface:ratio('--muted','--surface'),
              helperOnBackground:ratio('--muted','--bg'),
              accentOnSurface:ratio('--accent','--surface'),
              primaryButton:ratio('--accent-contrast','--accent'),
              secondaryButton:ratio('--secondary-contrast','--secondary'),
            };
            root.dataset.theme=originalTheme;
            return result;
          },theme);
          for(const [name,value] of Object.entries(ratios)){
            if(value<4.5)
              throw new Error(device.name+' '+theme+' '+name+' insufficient contrast ratio '+value.toFixed(2));
          }
        }
        // The compact phone homepage renders only two previews; remaining
        // approved types stay available through the category/search pages.
        const service=page.getByText('Browser QA Published Service').first();
        if(!(await service.count()) || (device.viewport.width>550 && !(await service.isVisible())))
          throw new Error(device.name+' homepage missing approved listing');
        // Shared listing cards: clickable artwork/title, two-line text and
        // four / two / one responsive columns without clipped or hidden links.
        const cards=await page.locator('.marketplace-home-feed .marketplace-card').count();
        if(cards<1 || cards>8)
          throw new Error(device.name+' homepage must show at most eight balanced cards; saw '+cards);
        const cardMetrics=await page.evaluate(()=>{
          const grid=document.querySelector('.marketplace-home-feed');
          const card=grid?.querySelector('.marketplace-card');
          const art=card?.querySelector('.marketplace-card-art');
          const title=card?.querySelector('h3 a');
          const summary=card?.querySelector('.marketplace-card-summary');
          if(!grid || !card || !art || !title || !summary)
            return {missing:true};
          const titleStyle=getComputedStyle(title), summaryStyle=getComputedStyle(summary);
          return {
            columns:getComputedStyle(grid).gridTemplateColumns.split(' ').length,
            titleClamp:titleStyle.webkitLineClamp,
            summaryClamp:summaryStyle.webkitLineClamp,
            fullTitleWidth:Math.abs(title.getBoundingClientRect().width
              - card.querySelector('h3').getBoundingClientRect().width)<2,
            sameDestination:art.href===title.href,
            viewDetails:!!card.querySelector('.marketplace-card-actions a'),
            summaryHeight:summary.getBoundingClientRect().height,
            summaryLineHeight:parseFloat(summaryStyle.lineHeight),
          };
        });
        const expectedColumns=device.viewport.width>=1200?4:device.viewport.width>550?2:1;
        if(cardMetrics.missing || cardMetrics.columns!==expectedColumns
          || cardMetrics.titleClamp!=='2' || cardMetrics.summaryClamp!=='2'
          || !cardMetrics.fullTitleWidth || !cardMetrics.sameDestination
          || cardMetrics.viewDetails
          || cardMetrics.summaryHeight>cardMetrics.summaryLineHeight*2+3)
          throw new Error(device.name+' listing card layout regression '+JSON.stringify(cardMetrics));
        // All active categories stay available in one native horizontal rail.
        const rail=page.locator('#marketplace-category-track');
        const sliderCategories=rail.locator('a.marketplace-category-tile');
        if(await sliderCategories.count()!==13)
          throw new Error(device.name+' slider must contain all 13 active categories');
        if(await rail.getByText('Hidden Browser QA Category').count())
          throw new Error(device.name+' inactive category leaked into slider');
        const sliderGeometry=await rail.evaluate(track=>{
          const children=[...track.querySelectorAll('.marketplace-category-tile')];
          const section=track.closest('.marketplace-category-section');
          const view=parseInt(getComputedStyle(section).getPropertyValue('--category-visible'),10);
          const rect=children[0].getBoundingClientRect();
          return {
            visibleCount:view,
            cardWidth:rect.width,
            maxScroll:track.scrollWidth-track.clientWidth,
            viewportWidth:track.clientWidth,
            status:section.querySelector('[data-category-position]')?.textContent,
            nextDisabled:section.querySelector('[data-category-next]')?.disabled,
          };
        });
        const expectedVisible=device.viewport.width>=1400?6:device.viewport.width>=1200?5:
          device.viewport.width>=980?4:device.viewport.width>=680?3:2;
        const expectedWidth=(sliderGeometry.viewportWidth-(expectedVisible-1)*(expectedVisible===2?10:14))/expectedVisible;
        if(sliderGeometry.visibleCount!==expectedVisible ||
           Math.abs(sliderGeometry.cardWidth-expectedWidth)>6 ||
           sliderGeometry.maxScroll<100 ||
           sliderGeometry.nextDisabled ||
           !sliderGeometry.status?.includes('of 13'))
          throw new Error(device.name+' responsive category rail mismatch: '+JSON.stringify(sliderGeometry));
        const forward=page.locator('[data-category-next]');
        const back=page.locator('[data-category-prev]');
        const before=await rail.evaluate(el=>el.scrollLeft);
        await forward.click();
        await page.waitForFunction(
          value=>document.querySelector('#marketplace-category-track').scrollLeft>value+5,
          before,{timeout:5000}
        );
        await page.waitForFunction(
          ()=>!document.querySelector('[data-category-prev]').disabled,
          null,{timeout:5000}
        );
        const afterForward=await rail.evaluate(el=>el.scrollLeft);
        await back.click();
        await page.waitForFunction(
          value=>document.querySelector('#marketplace-category-track').scrollLeft<value-5,
          afterForward,{timeout:5000}
        );
        const firstCategory=await sliderCategories.first.getAttribute('href');
        const finalCategory=await sliderCategories.last.getAttribute('href');
        if(!firstCategory?.includes('category=') || !finalCategory?.includes('category='))
          throw new Error(device.name+' slider category links must preserve filters');
        checks+=6;
        if(!(await page.getByText('Browser QA Published Freelance Job').first().isVisible()))
          throw new Error(device.name+' homepage missing approved job');
        await page.screenshot({path:out+'/'+device.name+'-homepage.png',fullPage:true});
        const compactHeader=await page.locator('.site-header').evaluate(el=>{
          const logo=el.querySelector('.brand-logo-pair');
          return {
            height:el.getBoundingClientRect().height,
            logoCount:logo?.querySelectorAll('img').length ?? 0,
          };
        });
        if(compactHeader.logoCount!==2 || (device.name==='desktop' && compactHeader.height>64))
          throw new Error(device.name+' inconsistent compact header or logo pair '+JSON.stringify(compactHeader));
        const publicMenu=page.locator('.site-header .profile-dropdown');
        await publicMenu.locator('summary').click();
        const menuLinks=publicMenu.locator('.profile-panel > a');
        if((await menuLinks.count())!==2)
          throw new Error(device.name+' guest profile links are missing');
        for(const name of ['Sign In','Sign Up']){
          const entry=publicMenu.getByRole('link',{name});
          if(!(await entry.isVisible()) || !(await entry.locator('svg').isVisible()))
            throw new Error(device.name+' icon or label missing for '+name);
        }
        for(const theme of ['light','dark']){
          const metrics=await page.evaluate(theme=>{
            document.documentElement.dataset.theme=theme;
            const pair=document.querySelector('.site-header .brand-logo-pair');
            const asset=pair?.querySelector(theme==='dark'?'.brand-logo-dark':'.brand-logo-light');
            const other=pair?.querySelector(theme==='dark'?'.brand-logo-light':'.brand-logo-dark');
            const label=document.querySelector('.profile-panel [data-theme-label]');
            const s=getComputedStyle(label);
            return {
              assetVisible:asset && getComputedStyle(asset).display!=='none',
              loaded:asset && asset.complete && asset.naturalWidth>0,
              otherHidden:other && getComputedStyle(other).display==='none',
              labelHeight:label.getBoundingClientRect().height,
              lineHeight:parseFloat(s.lineHeight),
              whiteSpace:s.whiteSpace,
            };
          },theme);
          if(!metrics.assetVisible || !metrics.loaded || !metrics.otherHidden ||
             metrics.whiteSpace!=='nowrap' || metrics.labelHeight>metrics.lineHeight+2)
            throw new Error(device.name+' theme logo/label issue '+theme+' '+JSON.stringify(metrics));
        }
        if(device.name==='desktop'){
          // An SVG can have naturalWidth even if its embedded lettering fails to render.
          // Check actual rasterized pixels in the brand mark and light wordmark.
          const logoPixels=await page.evaluate(async ()=>{
            const img=document.querySelector('.site-header .brand-logo-dark');
            await img.decode();
            const canvas=document.createElement('canvas');
            canvas.width=550;canvas.height=144;
            const context=canvas.getContext('2d');
            context.drawImage(img,0,0,550,144);
            const data=context.getImageData(0,0,550,144).data;
            let lightWordmark=0,goldSymbol=0;
            for(let y=10;y<140;y+=4){
              for(let x=0;x<550;x+=4){
                const n=(y*550+x)*4;
                const red=data[n],green=data[n+1],blue=data[n+2],alpha=data[n+3];
                if(alpha<128)continue;
                if(x>118 && red>205 && green>205 && blue>205)lightWordmark++;
                if(x<105 && red>170 && green>85 && blue<175)goldSymbol++;
              }
            }
            return {lightWordmark,goldSymbol};
          });
          if(logoPixels.lightWordmark<100 || logoPixels.goldSymbol<35)
            throw new Error('Dark-theme logo has unreadable letters or missing authentic gold mark '+JSON.stringify(logoPixels));
        }
        // Restore the persisted theme before continuing the existing browser tests.
        await page.evaluate(()=>{document.documentElement.dataset.theme=localStorage.getItem('bambodia-theme')||'light';});
        await publicMenu.locator('summary').click();

        // Categories own the four marketplace types; language is in the header.
        if(await page.locator('.nav-marketplace-links').count())
          throw new Error(device.name+' duplicate marketplace type links remain in the header');
        if(!(await page.locator('.site-header .nav-language-menu summary .locale-flag').isVisible()))
          throw new Error(device.name+' active language flag missing in header');
        if(!(await page.locator('.site-nav .nav-category-menu summary svg').isVisible()))
          throw new Error(device.name+' category grid icon is not visible');

        const geometry=await page.evaluate(()=>{
          const box=selector=>{
            const node=document.querySelector(selector);
            if(!node)return null;
            const r=node.getBoundingClientRect();
            return {left:r.left,right:r.right,centerY:(r.top+r.bottom)/2,
              visible:getComputedStyle(node).display!=='none'};
          };
          return {
            headerHeight:document.querySelector('.site-header').getBoundingClientRect().height,
            documentWidth:document.documentElement.scrollWidth,
            controls:[
              box('.site-header .site-nav>.brand'),
              box('.site-header .nav-category-trigger'),
              box('.site-header .nav-mobile-search-trigger'),
              box('.site-header .nav-language-menu summary'),
              box('.site-header .profile-trigger')
            ],
            desktopSearch:box('.site-header .nav-search'),
            mobileSearch:box('.site-header .nav-mobile-search'),
            languageName:box('.site-header .nav-language-menu .language-current'),
          };
        });
        if(geometry.documentWidth>device.viewport.width+5)
          throw new Error(device.name+' header horizontal overflow '+JSON.stringify(geometry));
        if(device.viewport.width<=720){
          const controls=geometry.controls;
          if(geometry.headerHeight>80 || controls.some(x=>!x?.visible)
             || geometry.desktopSearch?.visible || !geometry.mobileSearch?.visible
             || geometry.languageName?.visible)
            throw new Error(device.name+' expected a single-row compact header '+JSON.stringify(geometry));
          for(let i=1;i<controls.length;i++){
            if(controls[i-1].right>controls[i].left+3)
              throw new Error(device.name+' mobile controls collide '+JSON.stringify(controls));
          }
          if(Math.max(...controls.map(x=>x.centerY))-Math.min(...controls.map(x=>x.centerY))>12)
            throw new Error(device.name+' mobile controls wrapped '+JSON.stringify(controls));
          await page.locator('.nav-mobile-search-trigger').click();
          if(!(await page.locator('#mobile-marketplace-query').isVisible()))
            throw new Error(device.name+' search icon did not open input panel');
          const searchBounds=await page.locator('.nav-mobile-search-panel').boundingBox();
          if(!searchBounds || searchBounds.x < -3 ||
             searchBounds.x+searchBounds.width > device.viewport.width+3)
            throw new Error(device.name+' mobile search panel leaves viewport '+JSON.stringify(searchBounds));
          await page.locator('#mobile-marketplace-query').fill('Browser QA');
          await page.locator('.nav-mobile-search-form button').click();
          await page.waitForLoadState('networkidle');
          if(!page.url().includes('/search') || !page.url().includes('q=Browser'))
            throw new Error(device.name+' mobile search was not submitted');
          await page.goto(origin+'/',{waitUntil:'networkidle'});
        }else if(geometry.mobileSearch?.visible || !geometry.desktopSearch?.visible
                || !geometry.languageName?.visible){
          throw new Error(device.name+' desktop search/language visibility is wrong '+JSON.stringify(geometry));
        }

        await page.locator('.nav-category-menu summary').click();
        const categoryBounds=await page.locator('.nav-category-panel').boundingBox();
        if(!categoryBounds || categoryBounds.x < -3 ||
           categoryBounds.x+categoryBounds.width > device.viewport.width+3)
          throw new Error(device.name+' category dropdown leaves viewport '+JSON.stringify(categoryBounds));
        if(!(await page.locator('.nav-category-panel .nav-category-entry')
          .getByText('Browser QA Consulting Category').isVisible()))
          throw new Error(device.name+' active category missing');
        if(await page.locator('.nav-category-panel').getByText('Hidden Browser QA Category').count())
          throw new Error(device.name+' inactive category leaked');
        await page.locator('.nav-category-entry').getByText('Browser QA Consulting Category').click();
        await page.waitForLoadState('networkidle');
        if(!page.url().includes('category=') || !page.url().includes('type=service'))
          throw new Error(device.name+' category selection failed');
        checks+=10;

        await page.goto(origin+'/',{waitUntil:'networkidle'});
        const footer=page.locator('.footer-actions');
        if(await footer.locator('.language-menu').count())
          throw new Error(device.name+' language switcher must not be duplicated in footer');
        const social=await footer.locator('.footer-social a').evaluateAll(anchors=>anchors.map(x=>({
          top:x.getBoundingClientRect().top,
          background:getComputedStyle(x).backgroundColor,
        })));
        if(social.length!==2 || Math.abs(social[0].top-social[1].top)>3
            || social.some(x=>x.background!=='rgba(0, 0, 0, 0)'))
          throw new Error(device.name+' footer social layout broken '+JSON.stringify(social));
        const footerColumns=await page.locator('.footer-columns').evaluate(el=>
          getComputedStyle(el).gridTemplateColumns.trim().split(/\s+/).length);
        if(footerColumns!==(device.viewport.width>1020?4:2))
          throw new Error(device.name+' responsive footer grid has '+footerColumns+' columns');

        const footerLayout=await page.evaluate(()=>{
          const footer=document.querySelector('.site-footer');
          const bounds=(selector)=>{
            const node=footer?.querySelector(selector);if(!node)return null;
            const box=node.getBoundingClientRect();
            return {left:box.left,right:box.right,centerY:(box.top+box.bottom)/2};
          };
          const logo=footer.querySelector('.footer-brand');
          const logoImages=[...logo.querySelectorAll('.brand-logo-pair img')];
          return {
            legacyBadge:!!footer.querySelector('.footer-brand .brand-mark'),
            logoImages:logoImages.map(img=>({src:img.getAttribute('src'),naturalWidth:img.naturalWidth})),
            themeVisibility:(()=>{
              const root=document.documentElement;
              const initial=root.dataset.theme;
              const visible=(theme)=>{
                root.dataset.theme=theme;
                return logoImages.filter(img=>
                  getComputedStyle(img).display!=='none' &&
                  img.getBoundingClientRect().width>0).map(img=>img.getAttribute('src'));
              };
              try{return {light:visible('light'),dark:visible('dark')};}
              finally{root.dataset.theme=initial;}
            })(),
            copyright:bounds('.footer-copyright'),
            social:bounds('.footer-actions'),
            copyrightRowDirection:getComputedStyle(footer.querySelector('.footer-bottom')).flexDirection,
            width:document.documentElement.scrollWidth
          };
        });
        if(footerLayout.legacyBadge || footerLayout.logoImages.length!==2 ||
           !footerLayout.logoImages.some(x=>x.src.includes('bambodia-logo.webp')) ||
           !footerLayout.logoImages.some(x=>x.src.includes('bambodia-logo-dark.svg')) ||
           footerLayout.themeVisibility.light.length!==1 ||
           !footerLayout.themeVisibility.light[0].includes('bambodia-logo.webp') ||
           footerLayout.themeVisibility.dark.length!==1 ||
           !footerLayout.themeVisibility.dark[0].includes('bambodia-logo-dark.svg'))
          throw new Error(device.name+' duplicate/missing footer logo in light or dark mode '+JSON.stringify(footerLayout));
        if(footerLayout.copyrightRowDirection!=='row' ||
           Math.abs(footerLayout.copyright.centerY-footerLayout.social.centerY)>12 ||
           footerLayout.copyright.right>footerLayout.social.left+3 ||
           footerLayout.width>device.viewport.width+5)
          throw new Error(device.name+' copyright/social must remain one row '+JSON.stringify(footerLayout));

        const backToTop=page.locator('[data-back-to-top]');
        if(await backToTop.isVisible())
          throw new Error(device.name+' Back to Top should be hidden at page start');
        await page.evaluate(()=>window.scrollTo({top:document.documentElement.scrollHeight,behavior:'instant'}));
        await page.waitForFunction(()=>window.scrollY>360,{timeout:3000}).catch(()=>{
          throw new Error(device.name+' homepage is unexpectedly not scrollable');
        });
        await backToTop.waitFor({state:'visible',timeout:3000});
        const floatBounds=await backToTop.boundingBox();
        const verticalLabel=await backToTop.locator('.back-to-top-label').evaluate(el=>{
          const style=getComputedStyle(el);
          const bounds=el.getBoundingClientRect();
          return {text:el.textContent.trim(),writingMode:style.writingMode,
            transform:style.transform,width:bounds.width,height:bounds.height,
            children:el.children.length};
        });
        if(!floatBounds || floatBounds.height<=floatBounds.width*1.7 ||
           verticalLabel.text!=='Back to Top' ||
           verticalLabel.writingMode!=='vertical-rl' ||
           verticalLabel.transform==='none' ||
           verticalLabel.height<=verticalLabel.width*2 ||
           verticalLabel.children!==0)
          throw new Error(device.name+' Back to Top text must be truly vertical, not multi-line '+JSON.stringify({floatBounds,verticalLabel}));
        const socialBounds=await footer.boundingBox();
        if(floatBounds && socialBounds && socialBounds.y<device.viewport.height &&
           floatBounds.y+floatBounds.height>socialBounds.y-4)
          throw new Error(device.name+' Back to Top overlaps footer social links');
        await backToTop.click();
        await page.waitForFunction(()=>window.scrollY<20,null,{timeout:7000});
        await backToTop.waitFor({state:'hidden',timeout:3000});
        checks+=8;

      }
      if(path==='/search'){
        if(!(await page.getByText('Browser QA Published Service').first().isVisible()))
          throw new Error(device.name+' marketplace missing published service');
      }
      if(['/login','/register','/forgot-password'].includes(path)){
        if(await page.locator('.auth-brand').count())
          throw new Error(device.name+' '+path+' unexpectedly renders brand outside auth modal');
        if(!(await page.locator('.auth-tools .auth-home-tool svg').isVisible()))
          throw new Error(device.name+' '+path+' missing Home SVG');
        if(!(await page.locator('.auth-tools [data-theme-toggle]').isVisible()))
          throw new Error(device.name+' '+path+' missing theme control');
        if(!(await page.locator('.auth-tools .language-menu summary .locale-flag').isVisible()))
          throw new Error(device.name+' '+path+' missing language SVG flag');
      }
      if(path==='/register'){
        if(!(await page.locator('input[name="terms_accepted"][required]').isVisible()))
          throw new Error(device.name+' registration Terms checkbox is missing');
        if(!(await page.getByText('Terms of Service').first().isVisible()))
          throw new Error(device.name+' registration has no published Terms');
      }
      if(path==='/creators'){
        if(!(await page.getByText('Original full-stack Laravel consulting').first().isVisible()))
          throw new Error(device.name+' missing opt-in creator');
      }
    }
    // Real approved Product, Project, Service and Freelance detail routes.
    // Test the same layout on desktop, tablet and both mobile emulations.
    for(const kind of ['product','project','service','freelance']){
      const listPath='/search?type='+kind;
      const listResponse=await page.goto(origin+listPath,{waitUntil:'networkidle'});
      if(listResponse?.status()!==200)
        throw new Error(device.name+' '+kind+' listing search failed with '+listResponse?.status());
      const filterForm=page.locator('.marketplace-filter-sidebar form.marketplace-filter-form');
      if(!(await filterForm.isVisible()))
        throw new Error(device.name+' '+kind+' premium search sidebar missing');
      const result=page.locator('article.marketplace-card a.marketplace-card-art').first();
      if(!(await result.isVisible()))
        throw new Error(device.name+' '+kind+' original approved QA listing not found');
      const detailHref=await result.getAttribute('href');
      const detailResponse=await page.goto(detailHref,{waitUntil:'networkidle'});
      if(detailResponse?.status()!==200)
        throw new Error(device.name+' '+kind+' detail failed with '+detailResponse?.status());
      const primary=page.locator('.premium-detail-layout');
      const preview=primary.locator('.premium-showcase');
      const aside=primary.locator('aside.premium-detail-sidebar');
      if(!(await primary.isVisible())||!(await preview.isVisible())||!(await aside.isVisible()))
        throw new Error(device.name+' '+kind+' premium detail split layout missing');
      for(const section of ['overview','seller']){
        if(!(await page.locator('#'+section+'.premium-content-section').isVisible()))
          throw new Error(device.name+' '+kind+' missing real '+section+' section');
      }
      if(await page.getByRole('button',{name:/buy now/i}).count())
        throw new Error(device.name+' '+kind+' invented checkout button on payment-disabled platform');
      if(kind==='product'){
        if(!(await page.locator('#license').isVisible()))
          throw new Error(device.name+' product license details absent');
        if(!(await preview.locator('img.premium-showcase-cover').isVisible()))
          throw new Error(device.name+' product approved preview absent');
        const image=await preview.locator('img').evaluate(img=>({loaded:img.complete,w:img.naturalWidth}));
        if(!image.loaded||image.w<=0)
          throw new Error(device.name+' approved product preview failed to load '+JSON.stringify(image));
        if(!(await page.getByText('Direct checkout is not enabled').isVisible()))
          throw new Error(device.name+' product missing truthful checkout restriction');
      }else{
        if(!(await page.locator('#workflow').isVisible()))
          throw new Error(device.name+' '+kind+' missing private inquiry workflow');
        if(!(await page.getByRole('link',{name:/sign in to contact/i}).isVisible()))
          throw new Error(device.name+' '+kind+' missing guest sign-in action');
      }
      for(const theme of ['light','dark']){
        await page.evaluate(theme=>{document.documentElement.dataset.theme=theme;},theme);
        const dimensions=await page.evaluate(()=>{
          const main=document.querySelector('.premium-detail-main').getBoundingClientRect();
          const sidebar=document.querySelector('.premium-detail-sidebar').getBoundingClientRect();
          return {
            scrollWidth:document.documentElement.scrollWidth,
            viewport:window.innerWidth,
            mainLeft:main.left,mainRight:main.right,
            asideLeft:sidebar.left,asideTop:sidebar.top,mainTop:main.top
          };
        });
        if(dimensions.scrollWidth>dimensions.viewport+5)
          throw new Error(device.name+' '+kind+' '+theme+' horizontal overflow '+JSON.stringify(dimensions));
        if(device.viewport.width>=761 && dimensions.asideLeft<dimensions.mainRight-4)
          throw new Error(device.name+' '+kind+' '+theme+' sidebar overlaps main area '+JSON.stringify(dimensions));
        if(device.viewport.width<=760 && dimensions.asideTop<dimensions.mainTop)
          throw new Error(device.name+' '+kind+' '+theme+' sidebar appears before content');
        checks+=4;
        if(device.name==='desktop')
          await page.screenshot({path:out+'/desktop-'+kind+'-'+theme+'-detail.png',fullPage:true});
      }
      await page.evaluate(()=>{document.documentElement.dataset.theme=localStorage.getItem('bambodia-theme')||'light';});
    }
    checks+=8;

    if(device.name==='desktop'){
      await page.goto(origin+'/register',{waitUntil:'networkidle'});
      const colors=await page.evaluate(()=>({
        primary:getComputedStyle(document.documentElement).getPropertyValue('--accent').trim(),
        secondary:getComputedStyle(document.documentElement).getPropertyValue('--secondary').trim(),
      }));
      if(!['#c2410c','#fb923c'].includes(colors.primary) || !['#1d4ed8','#93c5fd'].includes(colors.secondary))
        throw new Error('Expected orange/blue platform colors: '+JSON.stringify(colors));
      const language=page.locator('.auth-tools .language-menu');
      await language.locator('summary').click();
      const flags=language.locator('.language-options .locale-flag');
      if(await flags.count()!==8)
        throw new Error('Expected 8 supported locale SVG flags');
      await language.locator('button[name="locale"][value="km"]').click();
      await page.waitForLoadState('networkidle');
      if(!(await page.locator('.auth-tools .language-menu summary').getByText('ខ្មែរ').isVisible()))
        throw new Error('Locale POST did not persist selected Khmer locale');
      await page.locator('.auth-tools [data-theme-toggle]').click();
      if(!['dark','light'].includes(await page.locator('html').getAttribute('data-theme')))
        throw new Error('Theme did not toggle');
      checks+=4;
      await page.goto(origin+'/login',{waitUntil:'networkidle'});
      await page.locator('input[name="email"]').fill('browser.client@example.test');
      await page.locator('input[name="password"]').fill('BrowserOnlyPassword123!');
      await page.locator('form.auth-form button[type="submit"]').click();
      await page.waitForURL(/\/account\?/,{timeout:15000});
      await page.goto(origin+'/en',{waitUntil:'networkidle'});
      const profileMenu=page.locator('.site-header .profile-dropdown');
      await profileMenu.locator('summary').click();
      if(!(await profileMenu.locator('.profile-theme-row [data-theme-toggle]').isVisible()))
        throw new Error('Theme control is not the last profile tray action');
      const beforeTheme=await page.locator('html').getAttribute('data-theme');
      await profileMenu.locator('[data-theme-toggle]').click();
      if(beforeTheme===await page.locator('html').getAttribute('data-theme'))
        throw new Error('Profile-menu theme action did not toggle');
      checks+=2;
      await page.goto(origin+'/search',{waitUntil:'networkidle'});
      const save=page.locator('article.marketplace-card')
        .filter({hasText:'Browser QA Published Service'})
        .locator('form[action*="/account/saved-listings/"] button[type="submit"]').first();
      if(!(await save.isVisible()))throw new Error('Saved-listing action missing for signed-in customer');
      await save.click();
      await page.goto(origin+'/account/saved-listings',{waitUntil:'networkidle'});
      if(!(await page.getByText('Browser QA Published Service').first().isVisible()))
        throw new Error('Saved listing not persisted for real browser customer session');
      checks+=3;

      // The Account shell must keep its sidebar and content scroll independent.
      await page.goto(origin+'/en/account/notifications?filter=read',{waitUntil:'networkidle'});
      if(!(await page.locator('#account-notification-event').isVisible()))
        throw new Error('Account notification event filter is missing');
      if(!(await page.getByText('Read notifications').first().isVisible()))
        throw new Error('Account read-state notification filter failed');
      await page.goto(origin+'/en/contracts?status=active',{waitUntil:'networkidle'});
      if(!(await page.locator('select[name="status"] option[value="active"]:checked').count()))
        throw new Error('Private contract status filter is missing');
      checks+=3;
      await page.goto(origin+'/en/account',{waitUntil:'networkidle'});
      if(!(await page.getByRole('heading',{name:'Agreements needing attention'}).isVisible()))
        throw new Error('Account operational agreements section missing');
      if(!(await page.locator('a.account-metric').first().isVisible()))
        throw new Error('Account personal activity cards missing');
      if(await page.locator('.workspace-overview-columns').count())
        throw new Error('Account workspace unexpectedly renders creator private operations');
      checks+=3;
      if(await page.locator('body').getAttribute('data-workspace')!=='account')
        throw new Error('Account workspace did not use shared premium shell');
      if(!(await page.locator('.workspace-menu-search input').isVisible()))
        throw new Error('Account header search is missing');
      await page.locator('[data-workspace-toggle]').click();
      if(!(await page.locator('body.workspace-collapsed').count()))
        throw new Error('Desktop sidebar did not collapse to icons');
      await page.goto(origin+'/en/account/saved-jobs',{waitUntil:'networkidle'});
      if(!(await page.locator('body.workspace-collapsed').count()))
        throw new Error('Sidebar collapse preference was not persisted between pages');
      // Expand labels before checking visibility of matching menu text.
      await page.locator('[data-workspace-toggle]').click();
      await page.locator('[data-workspace-search]').fill('Notifications');
      if(!(await page.locator('.workspace-nav-link:visible').getByText('Notifications').isVisible()))
        throw new Error('Workspace navigation search not functional');
      await page.locator('[data-workspace-search]').fill('');
      checks+=5;

      // Admin and Developer have separate role-governed menus, not account clones.
      const adminContext=await browser.newContext({viewport:device.viewport});
      const admin=await adminContext.newPage();
      await admin.goto(origin+'/en/login',{waitUntil:'networkidle'});
      await admin.locator('input[name="email"]').fill('browser.admin@example.test');
      await admin.locator('input[name="password"]').fill('BrowserOnlyPassword123!');
      await admin.locator('form.auth-form button[type="submit"]').click();
      await admin.waitForURL(/\/dashboard/,{timeout:15000});
      await admin.goto(origin+'/en/dashboard',{waitUntil:'networkidle'});
      const adminHeader=await admin.locator('.workspace-topbar').evaluate(el=>({
        height:el.getBoundingClientRect().height,
        logos:document.querySelectorAll('.workspace-brand-pair img').length
      }));
      if(adminHeader.height>64 || adminHeader.logos!==2)
        throw new Error('Admin workspace header is not compact or has missing theme logos '+JSON.stringify(adminHeader));
      if(!(await admin.getByRole('heading',{name:'Queues requiring attention'}).isVisible()))
        throw new Error('Admin operational queues missing');
      if(!(await admin.locator('.workspace-overview-metric[href*="user-manager"]').count()))
        throw new Error('Super-admin account metrics do not lead to User Manager');
      if(!(await admin.getByText('Latest pending marketplace submissions').isVisible()))
        throw new Error('Pending moderation overview missing');
      checks+=3;
      if(await admin.locator('body').getAttribute('data-workspace')!=='admin')
        throw new Error('Admin shell missing');
      const sidebar=admin.locator('#workspace-sidebar');
      const main=admin.locator('#workspace-main');
      const scrollBefore=await main.evaluate(node=>node.scrollTop);
      await sidebar.evaluate(node=>node.scrollTo({top:node.scrollHeight,behavior:'instant'}));
      await admin.waitForTimeout(200);
      const scrollAfter=await main.evaluate(node=>node.scrollTop);
      if(scrollBefore!==scrollAfter)
        throw new Error('Scrolling admin sidebar moved main content');
      if(!(await admin.getByText('Feature Manager').first().count()))
        throw new Error('Admin menu lacks real controls');
      await admin.screenshot({path:out+'/desktop-admin-workspace.png',fullPage:false});
      // License Manager: a top-level manual-issuance popup, not an expanded row
      // inside the horizontally scrollable ten-column issued-license table.
      await admin.goto(origin+'/en/dashboard/license-manager',{waitUntil:'networkidle'});
      const issueTrigger=admin.locator('[data-modal-open="license-issue-dialog"]');
      if(!(await issueTrigger.isVisible())) throw new Error('Manual License header action missing');
      const licenseHeaders=await admin.locator('.license-issued-table thead th').allTextContents();
      if(licenseHeaders.map(x=>x.trim()).join('|')!=='No|License|Product|Customer|Terms|Period|Support|Status|Domain|Action')
        throw new Error('Issued License Manager columns have changed: '+licenseHeaders.join(', '));
      const tableWidth=await admin.locator('.license-issued-scroll').evaluate(el=>({viewport:el.clientWidth,table:el.scrollWidth}));
      if(tableWidth.table<=tableWidth.viewport)
        throw new Error('Issued licenses table does not support horizontal scrolling');
      // Both legal starter definitions are draft-only and differentiated by type.
      const regularPreset=admin.locator('[data-license-preset="regular"]');
      await regularPreset.click();
      const definitionDialog=admin.locator('#license-create');
      if(!(await definitionDialog.isVisible()) || await definitionDialog.locator('#license-new-tier').inputValue()!=='regular')
        throw new Error('Regular License draft preset did not populate');
      if(await definitionDialog.locator('#license-new-status').inputValue()!=='draft')
        throw new Error('Regular License preset should not publish without review');
      await definitionDialog.locator('[data-modal-close]').click();
      await admin.locator('[data-license-preset="extended"]').click();
      if(await definitionDialog.locator('#license-new-tier').inputValue()!=='extended')
        throw new Error('Extended License draft preset did not populate');
      await definitionDialog.locator('[data-modal-close]').click();
      checks+=2;
      await issueTrigger.click();
      const issuanceDialog=admin.locator('#license-issue-dialog');
      if(!(await issuanceDialog.isVisible()) || !(await issuanceDialog.evaluate(el=>el.open)))
        throw new Error('Manual License modal is not functional');
      if(await issuanceDialog.locator('select[name="support_term"]').inputValue()!=='six_months')
        throw new Error('Default complimentary product support must be six months');
      await issuanceDialog.locator('[data-modal-close]').first().click();
      if(await issuanceDialog.isVisible()) throw new Error('Manual License modal failed to close');
      await admin.locator('#issued-license-search').fill('nonexistent-license-qa');
      await admin.locator('.license-issued-search button[type="submit"]').click();
      await admin.waitForLoadState('networkidle');
      if(!(await admin.getByText('No issued licenses match your search.').isVisible()))
        throw new Error('Issued License Manager search did not filter results');
      checks+=6;
      await admin.goto(origin+'/en/dashboard/review-queue?type=service',{waitUntil:'networkidle'});
      if(!(await admin.locator('[aria-label="Pending submissions by type"] a[aria-current="page"]').count()))
        throw new Error('Admin moderation type filter is missing');
      if(!(await admin.getByText('Recent moderation audit').isVisible()))
        throw new Error('Admin moderation audit history missing');
      await admin.goto(origin+'/en/dashboard/support-center?status=open',{waitUntil:'networkidle'});
      if(!(await admin.locator('input[aria-label="Search support tickets"]').isVisible()))
        throw new Error('Support triage search missing');
      checks+=3;
      await admin.goto(origin+'/en/developer',{waitUntil:'networkidle'});
      if(!(await admin.locator('[aria-label="Your API credential health"]').count()))
        throw new Error('Developer token-health overview missing');
      if(await admin.locator('.workspace-token-filters a').count()!==5)
        throw new Error('Developer token filters missing');
      await admin.locator('.workspace-token-filters a[href*="status=active"]').click();
      await admin.waitForLoadState('networkidle');
      if(!admin.url().includes('status=active'))
        throw new Error('Developer active-token filter did not navigate');
      checks+=3;
      if(await admin.locator('body').getAttribute('data-workspace')!=='developer')
        throw new Error('Developer workspace is not distinct');
      if(await admin.locator('.workspace-nav-link').getByText('Digital File Vault').count())
        throw new Error('Developer navigation leaked administration modules');

      // Builder Studio is a public-layout editor, not a private workspace shell.
      await admin.goto(origin+'/en/builder',{waitUntil:'networkidle'});
      if(!(await admin.locator('.site-header').isVisible()) || !(await admin.locator('.site-footer').count()))
        throw new Error('Builder Studio is missing the public Bambodia header and footer');
      if(await admin.locator('#workspace-shell').count())
        throw new Error('Builder Studio must not render inside the Creator/Account workspace');
      if(await admin.locator('.studio-choice').count()!==5)
        throw new Error('Builder Studio did not show all five creator/admin content types');
      if(!(await admin.locator('.studio-landing-hero').isVisible()))
        throw new Error('Builder Studio landing Trust-shield hero is missing');
      if(await admin.locator('.studio-breadcrumb').count())
        throw new Error('Builder Studio landing still shows the breadcrumb');
      if(!(await admin.locator('.studio-landing-website svg').isVisible()))
        throw new Error('Builder Studio Website link is not in the type tabs with an SVG icon');
      if(!(await admin.getByRole('heading',{name:'Regular License'}).isVisible())
        || !(await admin.getByRole('heading',{name:'Extended License'}).isVisible()))
        throw new Error('Builder Studio license guidance missing');
      const actions=admin.locator('.studio-choice-link svg');
      if(await actions.count()!==5)
        throw new Error('Builder Studio listing cards missing correct navigation icons');
      checks+=8;
      for(const kind of ['product','project','service','freelance','job']){
        const response=await admin.goto(origin+'/en/builder?type='+kind,{waitUntil:'networkidle'});
        if(response?.status()!==200)
          throw new Error('Builder Studio '+kind+' page failed with '+response?.status());
        if(!(await admin.locator('.studio-editor-toolbar').isVisible()))
          throw new Error('Builder Studio '+kind+' rich-text toolbar failed to initialize');
        if(!(await admin.locator('input#studio-title').isVisible()))
          throw new Error('Builder Studio '+kind+' has no title input');
        if(!(await admin.locator('[data-featured-preview]').isVisible()))
          throw new Error('Builder Studio '+kind+' missing featured media preview');
        if(await admin.locator('.studio-breadcrumb').count())
          throw new Error('Builder Studio '+kind+' still has a breadcrumb');
        if(!(await admin.locator('.studio-editor-hero .studio-landing-shield').isVisible()))
          throw new Error('Builder Studio '+kind+' is missing the centered Trust Shield hero');
        if(!(await admin.locator('#studio-terms-popup').count()) || !(await admin.locator('[data-builder-terms-check]').count()))
          throw new Error('Builder Studio '+kind+' terms confirmation is missing');
        if(await admin.getByText('Headings · Lists · Links · Tables · Formatting').count())
          throw new Error('Builder Studio editor still shows redundant formatting text');
        if(!(await admin.getByRole('heading',{name:'Builder Studio',exact:true}).isVisible()))
          throw new Error('Builder Studio '+kind+' centered heading is missing');
        if(!(await admin.locator('.studio-type-actions [data-builder-preview-toggle]').isVisible())
          || !(await admin.locator('.studio-type-actions').getByRole('link',{name:'My Content'}).isVisible())
          || !(await admin.locator('.studio-type-actions').getByRole('link',{name:'Website'}).isVisible())
          || !(await admin.locator('.studio-type-actions a svg').last().isVisible()))
          throw new Error('Builder Studio '+kind+' tools are not in the right-side type tabs');
        checks+=7;
      }
      await admin.goto(origin+'/en/builder?type=product',{waitUntil:'networkidle'});
      if(!(await admin.locator('#studio-regular-price').isVisible())
        || !(await admin.locator('#studio-extended-price').isVisible())
        || !(await admin.locator('#studio-original-title').isVisible())
        || !(await admin.locator('[data-gallery-grid]').isVisible()))
        throw new Error('Product license pricing, screenshot gallery or original upload instructions missing');
      await admin.locator('[data-builder-terms-check]').click();
      if(!(await admin.locator('#studio-terms-popup').isVisible()))
        throw new Error('Clicking the terms checkbox did not open the professional terms dialog');
      const popup=await admin.locator('#studio-terms-popup').boundingBox();
      const viewportSize=admin.viewportSize();
      if(!popup || Math.abs(popup.x+popup.width/2-viewportSize.width/2)>32
        || Math.abs(popup.y+popup.height/2-viewportSize.height/2)>32)
        throw new Error('Builder Studio Terms dialog is not centered: '+JSON.stringify({popup,viewportSize}));
      await admin.locator('[data-builder-terms-accept]').click();
      if(!(await admin.locator('[data-builder-terms-check]').isChecked()))
        throw new Error('Accepting terms failed to check the required consent control');
      checks+=4;
      if(!(await admin.locator('input#studio-software-version').isVisible())
        || !(await admin.locator('input#studio-software-published').isVisible())
        || !(await admin.locator('input#studio-software-updated').isVisible()))
        throw new Error('Product release version and date metadata missing from Basic Information');
      if(!(await admin.locator('[data-editor-resize-handle]').isVisible()))
        throw new Error('Lower-left editor resize grip missing');
      const grip=admin.locator('[data-editor-resize-handle]');
      const originalEditorHeight=await admin.locator('[data-editor-surface]').evaluate(el=>el.getBoundingClientRect().height);
      await grip.focus();
      await grip.press('ArrowDown');
      const resizedEditorHeight=await admin.locator('[data-editor-surface]').evaluate(el=>el.getBoundingClientRect().height);
      if(resizedEditorHeight<=originalEditorHeight)
        throw new Error('Keyboard resize did not expand rich-text editor');
      checks+=2;
      const editable=admin.locator('[data-editor-surface]');
      await editable.fill('A professionally documented original product with accessible designs, licensing information and maintained deliverables.');
      if(!(await admin.locator('[data-editor-html]').inputValue()).includes('A professionally documented'))
        throw new Error('Builder Studio formatted text failed to synchronize');
      admin.once('dialog', dialog=>dialog.accept('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
      await admin.locator('[data-editor-action="youtube"]').click();
      const video=admin.locator('[data-editor-surface] iframe');
      if(!(await video.count()) || !(await video.getAttribute('src')).includes('youtube-nocookie.com/embed/dQw4w9WgXcQ'))
        throw new Error('YouTube link did not produce an allowlisted embedded preview');
      await admin.locator('[data-editor-action="source"]').click();
      await admin.locator('[data-editor-source]').fill(
        '<p>Professionally documented original software application for verified customers and owners.</p>'
        +'<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>'
        +'<script>alert(1)</script>');
      await admin.locator('[data-editor-action="apply-source"]').click();
      try {
        await admin.locator('[data-editor-source]').waitFor({state:'hidden',timeout:15000});
      } catch (_) {
        const failure=await admin.locator('[data-editor-source-status]').textContent();
        throw new Error('Sanitized HTML preview failed: '+failure);
      }
      if(!(await admin.locator('[data-editor-surface]').isVisible())
        || await admin.locator('[data-editor-surface] script').count()
        || !(await admin.locator('[data-editor-surface] iframe').count()))
        throw new Error('HTML source apply did not safely sanitize and preview the YouTube frame');
      checks+=3;
      await admin.locator('input[name="featured_kind"][value="color"]').check({force:true});
      await admin.locator('input[data-featured-color]').evaluate(el=>{el.value='#126abc';el.dispatchEvent(new Event('input',{bubbles:true}));});
      if(!(await admin.locator('[data-featured-preview]').getByText('#126ABC').isVisible()))
        throw new Error('Builder Studio chosen color is not visible in preview');
      if(!(await admin.locator('button[data-builder-copy-url]').isDisabled()))
        throw new Error('Unsaved builder item should not invent a shareable URL');
      checks+=3;

      for(const viewport of [{width:1440,height:900},{width:820,height:1180},{width:390,height:844}]){
        await admin.setViewportSize(viewport);
        for(const theme of ['light','dark']){
          await admin.evaluate(value=>document.documentElement.dataset.theme=value,theme);
          const dimensions=await admin.evaluate(()=>({
            scroll:document.documentElement.scrollWidth,
            width:window.innerWidth,
            editor:!!document.querySelector('.studio-editor-toolbar:not([hidden])'),
          }));
          if(!dimensions.editor || dimensions.scroll>dimensions.width+5)
            throw new Error('Builder Studio overflow/editor regression: '+JSON.stringify({viewport,theme,dimensions}));
          checks++;
          if((viewport.width===1440 || viewport.width===390) && theme==='dark')
            await admin.screenshot({path:out+'/builder-studio-'+viewport.width+'-'+theme+'.png',fullPage:true});
        }
      }
      await admin.setViewportSize(device.viewport);
      // Verify the same native modal positioning in every public/private layout.
      // These temporary dialogs never change application data.
      for(const workspacePath of ['/en','/en/dashboard','/en/account','/en/developer','/en/studio']){
        await admin.goto(origin+workspacePath,{waitUntil:'networkidle'});
        for(const width of [1440,390]){
          await admin.setViewportSize({width,height:900});
          const bounds=await admin.evaluate(()=>{
            const dialog=document.createElement('dialog');
            dialog.className='config-dialog';
            dialog.innerHTML='<h2>Modal alignment check</h2><p>Dialog should remain centered in the viewport.</p>';
            document.body.appendChild(dialog);
            dialog.showModal();
            const box=dialog.getBoundingClientRect();
            const value={centerX:box.x+box.width/2,centerY:box.y+box.height/2,screenX:innerWidth/2,screenY:innerHeight/2};
            dialog.close();
            dialog.remove();
            return value;
          });
          if(Math.abs(bounds.centerX-bounds.screenX)>32 || Math.abs(bounds.centerY-bounds.screenY)>32)
            throw new Error('Dialog off-center in '+workspacePath+': '+JSON.stringify(bounds));
          checks++;
        }
      }
      await admin.setViewportSize(device.viewport);
      // Check visual distinction of the light sidebar and selected menu,
      // then sign out through the real CSRF-protected account dropdown.
      await admin.goto(origin+'/en/dashboard',{waitUntil:'networkidle'});
      await admin.evaluate(() => {
        localStorage.setItem('bambodia-theme','light');
        document.documentElement.dataset.theme='light';
      });
      const visual=await admin.evaluate(() => {
        const aside=document.querySelector('.workspace-sidebar');
        const item=document.querySelector('.workspace-nav-link.active');
        const styles=(el)=>getComputedStyle(el);
        return {
          sidebar:styles(aside).backgroundColor,
          sidebarText:styles(aside).color,
          active:styles(item).backgroundColor,
          activeText:styles(item).color,
        };
      });
      if(visual.sidebar===visual.active || visual.sidebar==='rgba(0, 0, 0, 0)')
        throw new Error('Light mode menu and active item lack distinct backgrounds: '+JSON.stringify(visual));

      await admin.locator('.workspace-account-menu summary').click();
      if(!(await admin.locator('.workspace-account-panel form button[type="submit"]').isVisible()))
        throw new Error('Secure Sign out is missing in profile dropdown');
      await admin.locator('.workspace-account-panel form button[type="submit"]').click();
      await admin.waitForLoadState('networkidle');
      await admin.goto(origin+'/en/developer',{waitUntil:'networkidle'});
      if(!admin.url().includes('/login'))
        throw new Error('Developer route did not require authentication after logout');
      checks+=8;
      await adminContext.close();

      const creatorContext=await browser.newContext({viewport:device.viewport});
      const creator=await creatorContext.newPage();
      await creator.goto(origin+'/en/login',{waitUntil:'networkidle'});
      await creator.locator('input[name="email"]').fill('browser.creator@example.test');
      await creator.locator('input[name="password"]').fill('BrowserOnlyPassword123!');
      await creator.locator('form.auth-form button[type="submit"]').click();
      await creator.waitForURL(/\/account\?/,{timeout:15000});
      await creator.goto(origin+'/en/creator',{waitUntil:'networkidle'});
      if(!(await creator.getByRole('heading',{name:'Your recent submissions'}).isVisible()))
        throw new Error('Creator private submissions overview missing');
      if(!(await creator.getByRole('heading',{name:'Open client conversations'}).isVisible()))
        throw new Error('Creator private client conversation overview missing');
      if(await creator.locator('.workspace-overview-metric').count()<5)
        throw new Error('Creator dashboard stats incomplete');
      checks+=3;
      if(await creator.locator('body').getAttribute('data-workspace')!=='creator')
        throw new Error('Creator workspace route did not render correctly');
      await creator.screenshot({path:out+'/desktop-creator-workspace.png',fullPage:false});
      await creator.goto(origin+'/en/studio/products?status=draft',{waitUntil:'networkidle'});
      if(!(await creator.locator('[aria-label="Your products by status"] a[aria-current="page"]').count()))
        throw new Error('Creator product status filter missing');
      const inboxResponse=await creator.goto(origin+'/en/studio/inquiries?status=open',{waitUntil:'networkidle'});
      if(inboxResponse?.status()!==200)
        throw new Error('Localized creator inquiry inbox failed: HTTP '+inboxResponse?.status()+' at '+creator.url());
      if(!(await creator.locator('[aria-label="Private conversation status"] a[aria-current="page"]').count()))
        throw new Error('Creator inquiry inbox filter missing at '+creator.url());
      checks+=4;
      await creatorContext.close();
    }
    await context.close();
  }
  console.log('PASS: '+checks+' responsive page and authenticated-collection checks (browser emulation only).');
} finally{
  await browser.close();
}
