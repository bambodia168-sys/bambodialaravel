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
        // The ambient hero is the only new homepage layer: background adapts to
        // theme, retains the original container geometry and has no slide controls.
        const hero=page.locator('.marketplace-hero-scene');
        if(await hero.count()!==1 ||
           await hero.locator('.marketplace-home-hero').count()!==1 ||
           await hero.locator('h1').count()!==1 ||
           await hero.locator('.hero-search').count()!==1 ||
           await hero.locator('.hero-links a').count()!==4 ||
           await hero.locator('.hero-quick-actions a').count()<2)
          throw new Error(device.name+' homepage hero structure changed');
        const heroResults=[];
        for(const theme of ['light','dark']){
          const result=await hero.evaluate((element,theme)=>{
            document.documentElement.dataset.theme=theme;
            const rect=element.getBoundingClientRect();
            const inner=element.querySelector('.marketplace-home-hero').getBoundingClientRect();
            const backdrop=getComputedStyle(element);
            const effect=getComputedStyle(element,'::before');
            const grid=getComputedStyle(element,'::after');
            return {
              theme,
              base:backdrop.backgroundColor,
              image:effect.backgroundImage,
              animation:effect.animationName,
              grid:grid.backgroundImage,
              left:rect.left,right:rect.right,
              innerLeft:inner.left,innerRight:inner.right,
              rootWidth:document.documentElement.clientWidth,
              scrollWidth:document.documentElement.scrollWidth,
            };
          },theme);
          if(!result.image.includes('radial-gradient') ||
             !result.grid.includes('linear-gradient') ||
             result.animation!=='bambodia-hero-ambient-drift' ||
             Math.abs(result.left)>1 ||
             Math.abs(result.right-result.rootWidth)>4 ||
             result.scrollWidth>result.rootWidth+5 ||
             result.innerLeft<0 || result.innerRight>result.rootWidth+4)
            throw new Error(device.name+' '+theme+' ambient hero regression '+JSON.stringify(result));
          heroResults.push(result);
        }
        if(heroResults[0].base===heroResults[1].base ||
           !heroResults[0].base.includes('246, 249, 255') ||
           !heroResults[1].base.includes('12, 24, 43'))
          throw new Error(device.name+' hero background does not respond to theme');
        await page.emulateMedia({reducedMotion:'reduce'});
        const still=await hero.evaluate(element=>getComputedStyle(element,'::before').animationName);
        if(still!=='none')throw new Error(device.name+' hero animation ignores reduced motion');
        await page.emulateMedia({reducedMotion:'no-preference'});
        await page.evaluate(()=>{document.documentElement.dataset.theme=localStorage.getItem('bambodia-theme')||'light';});
        checks+=5;
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
        // All eight approved homepage previews remain visible at every viewport.
        // Small-screen discovery uses two columns without hiding item types.
        const service=page.getByText('Browser QA Published Service').first();
        if(!(await service.count()) || !(await service.isVisible()))
          throw new Error(device.name+' homepage missing approved listing');
        // Shared listing cards: clickable artwork/title, two-line text and
        // four / three / two responsive columns without clipped or hidden links.
        const cards=await page.locator('.marketplace-home-feed .marketplace-card').count();
        if(cards<1 || cards>8)
          throw new Error(device.name+' homepage must show at most eight balanced cards; saw '+cards);
        const cardMetrics=await page.evaluate(()=>{
          const grid=document.querySelector('.marketplace-home-feed');
          const card=grid?.querySelector('.marketplace-card');
          const art=card?.querySelector('.marketplace-card-art');
          const title=card?.querySelector('h3 a');
          const creator=card?.querySelector('.marketplace-card-creator');
          const price=card?.querySelector('.marketplace-card-price');
          if(!grid || !card || !art || !title || !creator || !price)
            return {missing:true};
          const titleStyle=getComputedStyle(title);
          return {
            columns:getComputedStyle(grid).gridTemplateColumns.split(' ').length,
            visibleCards:[...grid.querySelectorAll('.marketplace-card')]
              .filter(item=>getComputedStyle(item).display!=='none').length,
            cardRight:card.getBoundingClientRect().right,
            titleClamp:titleStyle.webkitLineClamp,
            fullTitleWidth:Math.abs(title.getBoundingClientRect().width
              - card.querySelector('h3').getBoundingClientRect().width)<2,
            sameDestination:art.href===title.href,
            hasSummary:!!card.querySelector('.marketplace-card-summary'),
            hasCreator:creator.textContent.trim().length>0,
            priceSymbol:price.textContent.trim().startsWith('$'),
            hasUnsupportedRatings:!!card.querySelector('[data-rating],.rating-stars'),
            viewDetails:!!card.querySelector('.marketplace-card-actions a'),
          };
        });
        const expectedColumns=device.viewport.width>=1200?4:device.viewport.width>700?3:2;
        if(cardMetrics.missing || cardMetrics.columns!==expectedColumns
          || cardMetrics.visibleCards!==cards || cardMetrics.cardRight>device.viewport.width+3
          || cardMetrics.titleClamp!=='2' || !cardMetrics.fullTitleWidth
          || !cardMetrics.sameDestination || cardMetrics.hasSummary || !cardMetrics.hasCreator
          || !cardMetrics.priceSymbol || cardMetrics.hasUnsupportedRatings || cardMetrics.viewDetails)
          throw new Error(device.name+' compact listing card regression '+JSON.stringify(cardMetrics));
        const featuredProduct=page.locator('.marketplace-home-feed .marketplace-card')
          .filter({hasText:'Browser QA Published Product'});
        if(!(await featuredProduct.locator('.marketplace-card-featured').isVisible()) ||
           !(await featuredProduct.locator('.marketplace-card-live-preview').isVisible()))
          throw new Error(device.name+' published product Featured/Live Preview missing');
        const previewHref=await featuredProduct.locator('.marketplace-card-live-preview').getAttribute('href');
        if(previewHref!=='https://example.com/original-browser-product-preview')
          throw new Error(device.name+' product demo link must be creator-provided, not invented');
        // Opted-in public freelancers only; clickable avatars and no redundant CTAs.
        const freelancerCards=page.locator('.marketplace-freelancer-grid .marketplace-freelancer-card');
        if(await freelancerCards.count()!==4)
          throw new Error(device.name+' expected 4 public freelancer cards');
        if(!(await page.getByRole('heading',{name:'Freelancer',exact:true}).isVisible()))
          throw new Error(device.name+' freelancer heading missing');
        if(await page.locator('#marketplace-category-track').count())
          throw new Error(device.name+' retired category slider is still rendered');
        if(await page.getByText('Browser QA Private Freelancer').count())
          throw new Error(device.name+' private profile leaked');
        const freelancerGeometry=await page.locator('.marketplace-freelancer-grid').evaluate(grid=>({
          columns:getComputedStyle(grid).gridTemplateColumns.split(' ').length,
          items:[...grid.children].map(card=>({
            href:card.querySelector('h3 a')?.getAttribute('href'),
            avatarHref:card.querySelector('a.marketplace-freelancer-avatar')?.getAttribute('href'),
            avatarTag:card.querySelector('.marketplace-freelancer-avatar')?.tagName,
            portfolioCta:!!card.querySelector('.marketplace-freelancer-card-footer a'),
            publicProfileLabel:card.textContent.includes('Public profile'),
            right:card.getBoundingClientRect().right,
            avatarWidth:card.querySelector('.marketplace-freelancer-avatar')?.getBoundingClientRect().width,
            avatarOffset:(()=>{
              const avatar=card.querySelector('.marketplace-freelancer-avatar')?.getBoundingClientRect();
              const rect=card.getBoundingClientRect();
              return avatar?Math.abs((avatar.left+avatar.right)/2-(rect.left+rect.right)/2):999;
            })(),
            hasBiography:!!card.querySelector('.marketplace-freelancer-bio'),
          })),
        }));
        const freelancerColumns=device.viewport.width>=1400?5:device.viewport.width>=1100?4:device.viewport.width>=700?3:2;
        if(freelancerGeometry.columns!==freelancerColumns ||
           freelancerGeometry.items.some(c=>!/^https?:\/\/[^/]+\/creators\/[0-9]{8}$/.test(c.href??'') ||
             c.href!==c.avatarHref || c.avatarTag!=='A' ||
             c.portfolioCta || c.publicProfileLabel ||
             c.right>device.viewport.width+3 ||
             c.avatarWidth<(device.viewport.width<700?90:110) ||
             c.avatarOffset>2 || c.hasBiography))
          throw new Error(device.name+' freelancer grid regression '+JSON.stringify(freelancerGeometry));
        const avatarCard=freelancerCards.filter({hasText:'Browser QA Creator'}).first();
        if(await avatarCard.count()!==1)
          throw new Error(device.name+' missing uploaded-avatar test creator');
        const uploadedAvatar=avatarCard.locator('a.marketplace-freelancer-avatar img');
        if(await uploadedAvatar.count()!==1)
          throw new Error(device.name+' uploaded creator avatar was replaced by an initial');
        const avatarRender=await uploadedAvatar.evaluate(img=>({
          complete:img.complete,
          naturalWidth:img.naturalWidth,
          src:img.getAttribute('src')||'',
          alt:img.getAttribute('alt')||'',
          objectFit:getComputedStyle(img).objectFit,
          objectPosition:getComputedStyle(img).objectPosition,
          paddingTop:parseFloat(getComputedStyle(img).paddingTop),
          paddingBottom:parseFloat(getComputedStyle(img).paddingBottom),
        }));
        if(!avatarRender.complete || avatarRender.naturalWidth<1 ||
           !avatarRender.src.includes('/storage/profile-avatars/browser-qa-creator.webp') ||
           avatarRender.alt!=='Browser QA Creator profile photo' ||
           avatarRender.objectFit!=='cover' ||
           avatarRender.objectPosition!=='50% 15%' ||
           avatarRender.paddingTop!==0 || avatarRender.paddingBottom!==0)
          throw new Error(device.name+' uploaded creator avatar did not load '+JSON.stringify(avatarRender));
        const target=await avatarCard.locator('a.marketplace-freelancer-avatar').getAttribute('href');
        const profileResponse=await page.request.get(target);
        if(!profileResponse.ok() || !(await profileResponse.text()).includes('Browser QA Creator'))
          throw new Error(device.name+' uploaded avatar links to an inaccessible creator profile');
        const firstFreelancer=freelancerCards.first();
        if(!(await firstFreelancer.getByText(/approved listings?/).count()))
          throw new Error(device.name+' approved listing count missing');
        if(await page.locator('.marketplace-freelancer-card [data-rating],.marketplace-freelancer-card .rating-stars').count())
          throw new Error(device.name+' unverified rating surfaced');
        checks+=4;
        if(!(await page.getByText('Browser QA Published Freelance Job').first().isVisible()))
          throw new Error(device.name+' homepage missing approved job');
        const jobRows=page.locator('#bambodia-jobs .bambodia-job-row');
        if(await jobRows.count()!==1 || await page.locator('#bambodia-jobs .marketplace-jobs-grid').count())
          throw new Error(device.name+' homepage job list markup regression');
        const homeJob=await jobRows.first().evaluate(row=>({
          title:row.querySelector('h3 a')?.textContent,
          name:row.querySelector('.bambodia-job-meta')?.textContent,
          budget:row.querySelector('.bambodia-job-budget strong')?.textContent,
          action:row.querySelector('.bambodia-job-button')?.getAttribute('href'),
          width:row.getBoundingClientRect().width,
          right:row.getBoundingClientRect().right,
        }));
        if(!homeJob.title.includes('Browser QA Published Freelance Job') ||
           !homeJob.name.includes('Browser QA Client') ||
           !homeJob.budget.includes('$240.00') ||
           !homeJob.action?.includes('/jobs/') ||
           homeJob.right>device.viewport.width+4)
          throw new Error(device.name+' job row incorrect '+JSON.stringify(homeJob));
        checks+=3;
        // Ten newest real posts; a responsive scroll rail exposes five cards
        // per desktop view, with older posts moving right-to-left on Next.
        const blog=page.locator('#bambodia-blog');
        const blogTrack=blog.locator('[data-home-blog-track]');
        const blogCards=blog.locator('.home-blog-card');
        if(await blog.count()!==1 || await blogCards.count()!==10 ||
           !(await blog.getByRole('heading',{name:'Latest from the Blog'}).isVisible()))
          throw new Error(device.name+' homepage blog is not ten published cards');
        if(await blog.getByText('Browser QA Hidden Draft Article').count() ||
           await blog.getByText('Browser QA Blog Article 11').count())
          throw new Error(device.name+' hidden or older blog article leaked');
        if(!(await blogCards.first().getByRole('link',{name:/Browser QA Blog Article 01/}).count()) ||
           !(await blogCards.first().locator('.blog-card-media img').count()))
          throw new Error(device.name+' homepage blog card media missing');
        const blogMetrics=await blogTrack.evaluate(track=>{
          const cards=[...track.querySelectorAll('.home-blog-card')];
          const rect=track.getBoundingClientRect();
          return {
            left:rect.left,right:rect.right,
            width:track.clientWidth,scrollWidth:track.scrollWidth,
            count:cards.length,
            visible:Math.round((track.clientWidth+1)/(cards[1].offsetLeft-cards[0].offsetLeft)),
            firstLink:cards[0].querySelector('h3 a')?.getAttribute('href'),
            lastLink:cards[9].querySelector('h3 a')?.getAttribute('href'),
          };
        });
        const expectedBlogCards=device.viewport.width>=1200?5:device.viewport.width>=1024?4:device.viewport.width>=720?3:device.viewport.width>=520?2:1;
        if(blogMetrics.visible!==expectedBlogCards || blogMetrics.left < -1 ||
           blogMetrics.right>device.viewport.width+4 ||
           blogMetrics.scrollWidth<=blogMetrics.width ||
           !blogMetrics.firstLink?.match(/\/[0-9]{9}_[a-z0-9-]+$/) ||
           !blogMetrics.lastLink?.match(/\/[0-9]{9}_[a-z0-9-]+$/))
          throw new Error(device.name+' blog responsive slider regression '+JSON.stringify(blogMetrics));
        const indicator=blog.locator('[data-home-blog-position]');
        if(!(await indicator.textContent()).startsWith('1–') ||
           !(await blog.locator('[data-home-blog-prev]').isDisabled()))
          throw new Error(device.name+' blog first position incorrect');
        await blog.locator('[data-home-blog-next]').click();
        await page.waitForFunction(()=>{
          const track=document.querySelector('[data-home-blog-track]');
          return track && track.scrollLeft>8;
        },null,{timeout:4000});
        if(await blog.locator('[data-home-blog-prev]').isDisabled())
          throw new Error(device.name+' blog Previous did not enable');
        await blogTrack.focus();
        await page.keyboard.press('ArrowLeft');
        await page.waitForFunction(()=>{
          const track=document.querySelector('[data-home-blog-track]');
          return track && track.scrollLeft<6;
        },null,{timeout:4000});
        checks+=7;
        // CTA buttons are real guest routes; full-bleed Advantage still aligns
        // its cards to the same global content container.
        const join=page.locator('#bambodia-join');
        const joinPanel=join.locator('.bambodia-join-panel');
        const advantage=page.locator('#bambodia-advantage');
        if(await join.count()!==1 || await advantage.count()!==1)
          throw new Error(device.name+' missing homepage CTA or Advantage section');
        const joinLink=join.getByRole('link',{name:/Join Now/i});
        const signInLink=join.getByRole('link',{name:/Sign In/i});
        if(!(await joinLink.isVisible()) || !(await signInLink.isVisible()) ||
           !(await joinLink.getAttribute('href')).includes('/register') ||
           !(await signInLink.getAttribute('href')).includes('/login'))
          throw new Error(device.name+' guest CTA links are missing or incorrect');
        const layout=await page.evaluate(()=>{
          const panel=document.querySelector('#bambodia-join .bambodia-join-panel');
          const section=document.querySelector('#bambodia-advantage');
          const cards=[...section.querySelectorAll('.bambodia-advantage-card')];
          const content=section.querySelector('.bambodia-advantage-inner');
          const bounds=el=>el.getBoundingClientRect();
          return {
            panelWidth:bounds(panel).width,
            advantageLeft:bounds(section).left,
            advantageRight:bounds(section).right,
            viewportWidth:document.documentElement.clientWidth,
            contentLeft:bounds(content).left,
            cardCount:cards.length,
            cardRight:Math.max(...cards.map(c=>bounds(c).right)),
            cardColumns:getComputedStyle(section.querySelector('.bambodia-advantage-grid')).gridTemplateColumns.split(' ').length,
            actionColumns:getComputedStyle(panel.querySelector('.bambodia-join-actions')).gridTemplateColumns.split(' ').length,
            advantageRadius:getComputedStyle(section).borderTopLeftRadius,
            joinRadius:getComputedStyle(document.querySelector('#bambodia-join')).borderTopLeftRadius,
            joinPanelRadius:getComputedStyle(panel).borderTopLeftRadius,
            joinLeft:bounds(document.querySelector('#bambodia-join')).left,
            joinRight:bounds(document.querySelector('#bambodia-join')).right,
            footerStart:document.querySelector('footer.site-footer')?.getBoundingClientRect().top,
            joinStart:bounds(document.querySelector('#bambodia-join')).top,
            joinEnd:bounds(document.querySelector('#bambodia-join')).bottom,
            advStart:bounds(section).top,
            advEnd:bounds(section).bottom,
            jobsEnd:bounds(document.querySelector('#bambodia-jobs')).bottom,
            iconAlignment:[...panel.querySelectorAll('.bambodia-join-button')].every(link=>{
              const icon=link.querySelector('svg');
              const labelText=link.textContent.trim();
              const box=bounds(link), svg=bounds(icon);
              return svg.width<=17 && Math.abs((svg.top+svg.height/2)-(box.top+box.height/2))<=4
                && link.getAttribute('href') && labelText.length>0;
            }),
          };
        });
        const expectedAdvantageColumns=device.viewport.width>720?3:1;
        if(layout.advantageLeft!==0 || Math.abs(layout.advantageRight-layout.viewportWidth)>4 ||
           layout.cardCount!==3 || layout.cardColumns!==expectedAdvantageColumns ||
           layout.actionColumns!==2 || layout.cardRight>layout.viewportWidth+4 ||
           layout.contentLeft<-1 || layout.panelWidth>layout.viewportWidth+4 ||
           layout.advantageRadius!=='0px' || layout.joinRadius!=='0px' ||
           layout.joinPanelRadius!=='0px' || Math.abs(layout.joinLeft)>1 ||
           Math.abs(layout.joinRight-layout.viewportWidth)>4 || !layout.iconAlignment ||
           layout.advStart < layout.jobsEnd-1 || layout.joinEnd > layout.footerStart+1 ||
           Math.abs(layout.joinEnd-layout.footerStart)>2 ||
           layout.joinStart < layout.advEnd-1)
          throw new Error(device.name+' CTA/Advantage responsive regression '+JSON.stringify(layout));
        if(await advantage.getByText(/escrow|instant khqr|verified cambodian entities|enforceable digital contracts/i).count())
          throw new Error(device.name+' unavailable service claims were introduced');
        const advantageContainer=await page.locator('#bambodia-advantage .bambodia-advantage-inner').evaluate(el=>{const rect=el.getBoundingClientRect();return {left:rect.left,right:rect.right};});
        const navContainer=await page.locator('.site-header .container').first().evaluate(el=>{const rect=el.getBoundingClientRect();return {left:rect.left,right:rect.right};});
        if(Math.abs(advantageContainer.left-navContainer.left)>3 ||
           Math.abs(advantageContainer.right-navContainer.right)>3)
          throw new Error(device.name+' Advantage inner content no longer matches global layout');
        checks+=6;
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
      if(path==='/jobs'){
        const jobRows=page.locator('.bambodia-job-list .bambodia-job-row');
        if(await jobRows.count()!==1)
          throw new Error(device.name+' public jobs directory must show approved rows');
        if(await page.locator('.marketplace-jobs-grid').count())
          throw new Error(device.name+' legacy job grid still renders on /jobs');
        const jobLink=jobRows.first().locator('.bambodia-job-button');
        if(!(await jobLink.isVisible()) ||
           !(await jobLink.getAttribute('href')).includes('/jobs/'))
          throw new Error(device.name+' job action must link to genuine detail page');
        const geometry=await jobRows.first().evaluate(row=>({
          right:row.getBoundingClientRect().right,
          left:row.getBoundingClientRect().left,
          avatar:row.querySelector('.bambodia-job-avatar')?.getBoundingClientRect().width,
          budget:row.querySelector('.bambodia-job-budget strong')?.textContent,
        }));
        if(geometry.right>device.viewport.width+4 || geometry.left < -1 ||
           geometry.avatar<44 || !geometry.budget?.includes('$240.00'))
          throw new Error(device.name+' responsive job row overflow '+JSON.stringify(geometry));
        checks+=3;
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
      const result=kind==='project'
        ? page.locator('article.marketplace-card').filter({hasText:'Browser QA Related Project 08'})
            .locator('a.marketplace-card-art')
        : page.locator('article.marketplace-card a.marketplace-card-art').first();
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
      if(await page.locator('.marketplace-breadcrumb').count() ||
         await page.locator('.premium-detail-intro').count())
        throw new Error(device.name+' '+kind+' old duplicate breadcrumb/header visible');
      const lowerTitle=primary.locator('.premium-detail-below-title');
      const floating=preview.locator('.premium-detail-floating-type');
      if(!(await lowerTitle.isVisible()) || !(await floating.isVisible()) ||
         await page.locator('.premium-detail-tabs a[href*="/comments"]').count() ||
         await page.locator('.premium-detail-tabs a[href*="/support"]').count())
        throw new Error(device.name+' '+kind+' new guest detail layout is incomplete');
      const structure=await page.evaluate(()=>{
        const preview=document.querySelector('.premium-showcase').getBoundingClientRect();
        const title=document.querySelector('.premium-detail-below-title').getBoundingClientRect();
        const label=document.querySelector('.premium-detail-floating-type').getBoundingClientRect();
        return {titleTop:title.top,previewBottom:preview.bottom,labelLeft:label.left,previewLeft:preview.left,
          titleHeight:title.height,right:title.right,viewport:document.documentElement.clientWidth};
      });
      if(structure.titleTop < structure.previewBottom-3 || structure.titleHeight<24 ||
         structure.right>structure.viewport+3 ||
         structure.labelLeft < structure.previewLeft-1)
        throw new Error(device.name+' '+kind+' title placement or label regression '+JSON.stringify(structure));
      checks+=3;
      const mediaGeometry=await preview.evaluate(stage=>{
        const box=stage.getBoundingClientRect();
        const child=stage.querySelector('.premium-detail-media-content');
        const media=child?.getBoundingClientRect();
        const style=getComputedStyle(stage);
        return {
          width:box.width,height:box.height,
          ratio:box.width/box.height,
          childWidth:media?.width,childHeight:media?.height,
          overflow:style.overflow,animation:style.animationName,
          scrollWidth:document.documentElement.scrollWidth,
          viewport:window.innerWidth,
        };
      });
      if(!await preview.evaluate(node=>node.classList.contains('premium-detail-media')) ||
         Math.abs(mediaGeometry.ratio-16/9)>0.08 ||
         mediaGeometry.width<200 || mediaGeometry.childWidth<mediaGeometry.width-5 ||
         mediaGeometry.childHeight<mediaGeometry.height-5 ||
         mediaGeometry.scrollWidth>mediaGeometry.viewport+5)
        throw new Error(device.name+' '+kind+' featured media aspect/overflow '+JSON.stringify(mediaGeometry));
      if(kind==='project'){
        const publisher=page.locator('#publisher.premium-side-creator');
        if(!(await publisher.isVisible()) ||
           await page.locator('#seller').count() ||
           await page.getByRole('heading',{name:'About the creator'}).count())
          throw new Error(device.name+' project must use a single sidebar creator panel');
        const creatorTab=page.locator('.premium-detail-tabs a[href="#publisher"]');
        const profileLink=publisher.getByRole('link',{name:'View creator profile'});
        if(!(await creatorTab.isVisible()) || !(await profileLink.isVisible()))
          throw new Error(device.name+' publisher sidebar or Creator tab has no valid profile link');
        const profileHref=await profileLink.getAttribute('href');
        if(!/^https?:\/\/[^/]+\/creators\/[0-9]{8}$/.test(profileHref??''))
          throw new Error(device.name+' invalid public creator profile URL: '+profileHref);
        const profileResponse=await page.request.get(profileHref);
        if(profileResponse.status()!==200 ||
           !(await profileResponse.text()).includes('Creator achievements'))
          throw new Error(device.name+' listing publisher profile does not resolve publicly');
        checks+=4;
        const youtube=preview.locator('[data-featured-youtube-play]');
        if(!(await youtube.isVisible()) || await preview.locator('iframe').count())
          throw new Error(device.name+' YouTube must be click-to-load and third-party free by default');
        await youtube.click();
        const frame=preview.locator('iframe.premium-detail-youtube');
        if(!(await frame.count()) ||
           !(await frame.getAttribute('src')).startsWith('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'))
          throw new Error(device.name+' YouTube player did not load after a deliberate click');
      }
      const relatedGrid=page.locator('.premium-related-grid');
      if(kind==='project'){
        const grid=await relatedGrid.evaluate(node=>{
          const style=getComputedStyle(node);
          return {count:node.querySelectorAll('.marketplace-card').length,
            columns:style.gridTemplateColumns.split(' ').length,
            visible:[...node.querySelectorAll('.marketplace-card')]
              .filter(card=>getComputedStyle(card).display!=='none').length,
            right:node.getBoundingClientRect().right,
            viewport:window.innerWidth};
        });
        const cols=device.viewport.width>1100?4:device.viewport.width>610?2:1;
        if(grid.count!==8 || grid.columns!==cols || grid.visible!==cols*2 ||
           grid.right>grid.viewport+5)
          throw new Error(device.name+' related grid must be 4/2/1 columns and two rows '+JSON.stringify(grid));
      }
      if(device.name==='desktop'){
        const motion=await preview.evaluate(node=>{
          const s=getComputedStyle(node);
          return {animation:s.animationName,transition:s.transitionProperty};
        });
        if(!motion.animation.includes('bambodia-media-shadow') || !motion.transition.includes('transform'))
          throw new Error('Desktop media shadow motion not active '+JSON.stringify(motion));
        await page.emulateMedia({reducedMotion:'reduce'});
        const reduced=await preview.evaluate(node=>getComputedStyle(node).animationName);
        if(reduced!=='none') throw new Error('Reduced-motion accessibility not honored');
        await page.emulateMedia({reducedMotion:'no-preference'});
      }
      checks+=4;
      if(!(await page.locator('#overview.premium-content-section').isVisible()) ||
         !(await page.locator('#publisher.premium-side-creator').isVisible()))
        throw new Error(device.name+' '+kind+' missing overview or publisher sidebar');
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
        if(!(await page.locator('select[data-detail-license-tier]').isVisible()) ||
           !(await page.locator('a[data-detail-license-terms]').isVisible()) ||
           await page.getByRole('button',{name:/buy now/i}).count())
          throw new Error(device.name+' product license selector or checkout safety regression');
        const card=page.locator('.premium-offer-v3');
        if(!(await card.isVisible()) ||
           !(await card.locator('.premium-feature-checklist li').count() >= 3) ||
           !(await card.locator('details.premium-license-scope').isVisible()) ||
           await card.getByText(/free support|future product updates|quality checked by codester/i).count())
          throw new Error(device.name+' product premium benefit list or license scope regression');
        await card.locator('details.premium-license-scope summary').click();
        if(!(await card.locator('[data-detail-license-description]').isVisible()))
          throw new Error(device.name+' product license scope disclosure does not expand');
        const tier=card.locator('select[data-detail-license-tier]');
        const options=await tier.locator('option').count();
        if(options>1){
          await tier.selectOption('extended');
          const selected=await tier.locator('option:checked').getAttribute('data-price');
          if((await card.locator('[data-detail-license-price]').innerText()).trim()!==selected)
            throw new Error(device.name+' license tier price did not update');
        }
        checks+=3;
      }else{
        if(!(await page.locator('#workflow').isVisible()))
          throw new Error(device.name+' '+kind+' missing private inquiry workflow');
        if(!(await page.getByRole('link',{name:/sign in to contact/i}).isVisible()))
          throw new Error(device.name+' '+kind+' missing guest sign-in action');
        if(!(await page.locator('.premium-offer-v3').isVisible()) ||
           await page.locator('.premium-offer-v3 .premium-feature-checklist li').count()<3)
          throw new Error(device.name+' '+kind+' professional checkmark list missing');
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
      const actions=admin.locator('.studio-choice-link');
      if(await actions.count()!==5 || await actions.locator('svg').count()!==0)
        throw new Error('Builder Studio choice links must have labels without trailing arrow icons');
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

      // Tab navigation uses real deep-linkable server URLs but switches only
      // the resource detail shell (no full-page redirect in JavaScript mode).
      await creator.goto(origin+'/search?type=service',{waitUntil:'networkidle'});
      const serviceLink=creator.locator('article.marketplace-card a.marketplace-card-art').first();
      if(!(await serviceLink.isVisible()))
        throw new Error('Authenticated detail test service missing');
      await serviceLink.click();
      await creator.waitForURL(/\/service\?=/);
      await creator.locator('.premium-detail-tabs a', {hasText:'Comment'}).click();
      await creator.waitForURL(/\/detail\/service\/[^/]+\/comments/);
      if(!(await creator.locator('.premium-detail-tabs a[aria-current="page"]',{hasText:'Comment'}).isVisible()) ||
         !(await creator.getByRole('heading',{name:'Community conversation'}).isVisible()))
        throw new Error('Comment tab did not retain shared detail navigation');
      await creator.locator('.premium-detail-tabs a',{hasText:'Support'}).click();
      await creator.waitForURL(/\/detail\/service\/[^/]+\/support/);
      if(!(await creator.getByRole('heading',{name:'Support resources'}).isVisible()) ||
         !(await creator.locator('.premium-detail-tabs a[aria-current="page"]',{hasText:'Support'}).isVisible()))
        throw new Error('Support tab lost the shared navigation or resources');
      await creator.locator('.premium-detail-tabs a',{hasText:'Overview'}).click();
      await creator.waitForURL(/\/service\?=/);
      if(!(await creator.locator('.premium-showcase').isVisible()))
        throw new Error('Overview tab failed to restore featured media');
      checks+=5;

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
