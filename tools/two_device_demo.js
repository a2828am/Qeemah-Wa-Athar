/**
 * tools/two_device_demo.js — end-to-end demo of the exact scenario:
 *
 *   1. create an account on a desktop browser   (فرد)
 *   2. create an account on a phone browser     (فرد / متجر / جمعية)
 *   3. publish a product from the desktop account
 *   4. request it from the phone account
 *   5. the owner accepts, both chat on the product
 *   6. both confirm the agreement, then the delivery
 *
 * Needs a running server and Playwright:
 *   cd /tmp/pw && node /home/user/qeemah-platform/tools/two_device_demo.js
 */
const { chromium } = require('/tmp/pw/node_modules/playwright-core');
const BASE='http://127.0.0.1:8080/qeemah-platform';
const EXE='/home/user/.cache/ms-playwright/chromium_headless_shell-1255/chrome-headless-shell-linux64/chrome-headless-shell';
const S=String(Date.now()).slice(-5);
const A={n:'سالم المقطري',e:`salem${S}@test.ye`}, B={n:'نجاة الحداد',e:`najat${S}@test.ye`};
const log=console.log;
async function mk(b,vp,ua){const c=await b.newContext({viewport:vp,userAgent:ua,isMobile:vp.width<600,hasTouch:vp.width<600});return c.newPage();}
async function reg(p,u){await p.goto(BASE+'/register.php');await p.check('input[name=user_type][value=individual]');
 await p.fill('#full_name',u.n);await p.fill('#email',u.e);await p.fill('#phone','77'+Math.floor(10000000+Math.random()*89999999));
 const o=await p.$('select[name=city] option:nth-of-type(3)');await p.selectOption('select[name=city]',await o.getAttribute('value'));
 await p.fill('#password','Test1234!');await p.fill('#password_confirm','Test1234!');
 if(await p.$('input[name=agree]'))await p.check('input[name=agree]');
 await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),p.click('button[type=submit]')]);}
async function act(p,re){for(const el of await p.$$('button[type=submit], a.btn')){
 const t=((await el.evaluate(e=>e.textContent))||'').replace(/\s+/g,' ').trim();
 if(re.test(t)&&await el.isVisible()){const f=await el.evaluateHandle(e=>e.closest('form'));
  for(const i of await f.asElement().$$('input[type=text][required]')){if(!(await i.inputValue()))await i.fill('اتفاق على التسليم');}
  await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}).catch(()=>{}),el.click()]);
  await p.waitForTimeout(300);return true;}}return false;}
async function send(p,text,convId){
 if(!(await p.$('#chatInput'))){
   await p.goto(BASE+'/chat.php?c='+convId); await p.waitForTimeout(500);
 }
 await p.fill('#chatInput',text);
 await p.click('#chatSend');
 // wait for the message to appear in the thread
 await p.waitForFunction(t=>document.body.innerText.includes(t),text,{timeout:8000}).catch(()=>{});
 const shown=(await p.innerText('body')).includes(text);
 log('   '+(shown?'✓':'✗')+' أُرسلت: '+text);
}
(async()=>{
 const b=await chromium.launch({args:['--no-sandbox'],executablePath:EXE});
 const pc=await mk(b,{width:1366,height:900},'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131');
 await reg(pc,A);
 const ph=await mk(b,{width:390,height:844},'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148');
 await reg(ph,B);
 log('✓ حسابان جديدان');

 await pc.goto(BASE+'/add_product.php');
 await pc.fill('#title','آلة قهوة إسبريسو منزلية'); await pc.fill('input[name=price]','52000');
 await pc.fill('#description','آلة إسبريسو منزلية، استعمال خفيف، مع المصفاة الأصلية. التسليم في صنعاء.');
 await pc.selectOption('select[name=category_id]','1').catch(()=>{});
 await Promise.all([pc.waitForNavigation({waitUntil:'domcontentloaded'}),pc.click('#addProductForm button[type=submit]')]);
 const purl=pc.url(); log('✓ نُشر المنتج');

 await ph.goto(purl); await ph.click('[data-open-request]');
 const ta=await ph.$('#requestModal textarea[name=message]'); if(ta) await ta.fill('هل يمكن خفض السعر إلى 48 ألفاً؟');
 await Promise.all([ph.waitForNavigation({waitUntil:'domcontentloaded'}),ph.click('#requestModal button[type=submit]')]);
 const durl=ph.url(); log('✓ أُنشئ الطلب '+durl.replace(BASE,''));

 await pc.goto(durl); await act(pc,/^قبول الطلب$/); log('✓ قبل البائع الطلب');

 log('── المحادثة الحقيقية ──');
 await pc.goto(BASE+'/chat.php'); await pc.waitForTimeout(600);
 const convId=await pc.evaluate(()=>{const a=document.querySelector('a[href*="chat.php?c="]');return a?a.getAttribute('href').split('c=')[1]:'';}).catch(()=>'');
 log('   رقم المحادثة:', convId);
 await send(pc,'أهلاً بك، يمكنني 49 ألفاً والتسليم غداً بعد العصر.',convId);
 await ph.goto(BASE+'/chat.php'); await ph.waitForTimeout(600);
 await send(ph,'تمام، 49 ألفاً مقبولة. سأحضر غداً إن شاء الله.',convId);
 await pc.goto(BASE+'/chat.php?c='+convId); await pc.waitForTimeout(800);
 const thread=await pc.innerText('body');
 log('   عدد الرسائل الظاهرة للبائع:', (thread.match(/أهلاً بك|تمام، 49/g)||[]).length);
 await pc.screenshot({path:'/tmp/x4-chat.png'});

 log('── الاتفاق والتسليم ──');
 await ph.goto(durl); await act(ph,/أؤكد الاتفاق/); log('✓ المشتري أكّد الاتفاق');
 await pc.goto(durl); await act(pc,/أؤكد الاتفاق/); log('✓ البائع أكّد الاتفاق');
 await pc.goto(durl); await act(pc,/بدء التسليم/); log('✓ بدء التسليم');
 await ph.goto(durl); await act(ph,/أؤكد التسليم|استلمت/); log('✓ المشتري أكّد الاستلام');
 await pc.goto(durl); await act(pc,/أؤكد التسليم|استلمت/); log('✓ البائع أكّد التسليم');
 await pc.goto(durl);
 const fin=await pc.innerText('body');
 log('النتيجة:', /تم التسليم/.test(fin)?'✓ تم التسليم':'✗');
 await pc.screenshot({path:'/tmp/x4-final.png',fullPage:true});
 await b.close();
})();
