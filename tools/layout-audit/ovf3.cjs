/*
 * Layout audit helper (#2203). A developer tool only: nothing in the app loads it.
 * Live: at 360px wide, finds which element makes a page scroll sideways. Usage: node tools/layout-audit/ovf3.cjs <page...>
 * It needs the local test rig: the site running on http://localhost:8000
 * (php -S with a router), and Playwright. Bootstrap's files come from a
 * folder of unpacked npm packages given in LAYOUT_AUDIT_NPM, so no internet
 * is needed (set LAYOUT_AUDIT_PLAYWRIGHT to a Playwright path if the repo copy has no matching browser). Order: rowscan + tables (static), then rowmeasure + ovf3 (live), before and after a change.
 */
const {chromium}=require(process.env.LAYOUT_AUDIT_PLAYWRIGHT||'playwright');const S=process.env.LAYOUT_AUDIT_NPM_ROOT||__dirname;
const pages=process.argv.slice(2);
(async()=>{const b=await chromium.launch();const c=await b.newContext({viewport:{width:360,height:800}});
await c.addCookies([{name:'ihymns_auth',value:'a'.repeat(64),url:'http://localhost:8000'}]);
await c.route(/cdn\.jsdelivr|gravatar|cdnjs|google/,r=>{const u=r.request().url();const N=(process.env.LAYOUT_AUDIT_NPM||S+'/npm');let f=null;if(/bootstrap@5.*min.css/.test(u))f=N+'/x_bootstrap-5.3.6.tgz/package/dist/css/bootstrap.min.css';else if(/bundle/.test(u))f=N+'/x_bootstrap-5.3.6.tgz/package/dist/js/bootstrap.bundle.min.js';else if(/icons.*css/.test(u))f=N+'/x_bootstrap-icons-1.11.3.tgz/package/font/bootstrap-icons.min.css';if(f)r.fulfill({path:f});else r.abort()});
if(process.env.LAYOUT_AUDIT_THEME)await c.addInitScript(t=>{try{localStorage.setItem('ihymns_theme',t)}catch(e){}},process.env.LAYOUT_AUDIT_THEME);
const p=await c.newPage();
for(const pg of pages){await p.goto('http://localhost:8000'+pg,{waitUntil:'load'});await p.waitForTimeout(300);
const o=await p.evaluate(()=>{const vw=document.documentElement.clientWidth;const sw=()=>document.documentElement.scrollWidth;const out=[];
const desc=e=>e.tagName.toLowerCase()+(e.id?'#'+e.id:'')+(typeof e.className==='string'&&e.className.trim()?'.'+e.className.trim().split(/\s+/).join('.'):'');
// descend: find a child whose removal fixes overflow
let node=document.body;const base=sw();out.push('base '+base);
for(let depth=0;depth<14;depth++){let found=null;for(const ch of node.children){const old=ch.style.display;ch.style.display='none';const s=sw();ch.style.display=old;if(s<=vw){found=ch;break}}
 if(!found){out.push('no single child of '+desc(node)+' fixes it; children: '+[...node.children].map(ch=>{const old=ch.style.display;ch.style.display='none';const s=sw();ch.style.display=old;return desc(ch).slice(0,50)+'->'+s}).join(' | ').slice(0,900));break}
 const r=found.getBoundingClientRect();const cs=getComputedStyle(found);out.push('  '.repeat(depth)+desc(found).slice(0,90)+' rect '+Math.round(r.left)+'..'+Math.round(r.right)+' pos='+cs.position+' ml='+cs.marginLeft+' mr='+cs.marginRight+' w='+cs.width);node=found}
return out});console.log(pg);o.forEach(l=>console.log('   '+l))}
await b.close()})()
