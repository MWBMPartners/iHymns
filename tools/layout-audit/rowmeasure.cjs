/*
 * Layout audit helper (#2203). A developer tool only: nothing in the app loads it.
 * Live: prints every .row whose controls on one visual line sit at different heights. Usage: node tools/layout-audit/rowmeasure.cjs <width> <page...>
 * It needs the local test rig: the site running on http://localhost:8000
 * (php -S with a router), and Playwright. Bootstrap's files come from a
 * folder of unpacked npm packages given in LAYOUT_AUDIT_NPM, so no internet
 * is needed (set LAYOUT_AUDIT_PLAYWRIGHT to a Playwright path if the repo copy has no matching browser). Order: rowscan + tables (static), then rowmeasure + ovf3 (live), before and after a change.
 */
// Usage: node rowmeasure.js <width> <page...>  — prints every .row whose controls on one visual line have tops differing by >3px
const {chromium}=require(process.env.LAYOUT_AUDIT_PLAYWRIGHT||'playwright');const S=process.env.LAYOUT_AUDIT_NPM_ROOT||__dirname;const W=+process.argv[2];const pages=process.argv.slice(3);
(async()=>{const b=await chromium.launch();const c=await b.newContext({viewport:{width:W,height:800}});
await c.addCookies([{name:'ihymns_auth',value:'a'.repeat(64),url:'http://localhost:8000'}]);
await c.route(/cdn\.jsdelivr|gravatar|cdnjs|google/,r=>{const u=r.request().url();const N=(process.env.LAYOUT_AUDIT_NPM||S+'/npm');let f=null;if(/bootstrap@5.*min.css/.test(u))f=N+'/x_bootstrap-5.3.6.tgz/package/dist/css/bootstrap.min.css';else if(/bundle/.test(u))f=N+'/x_bootstrap-5.3.6.tgz/package/dist/js/bootstrap.bundle.min.js';else if(/icons.*css/.test(u))f=N+'/x_bootstrap-icons-1.11.3.tgz/package/font/bootstrap-icons.min.css';if(f)r.fulfill({path:f});else r.abort()});
const p=await c.newPage();
for(const pg of pages){await p.goto('http://localhost:8000'+pg);await p.waitForTimeout(200);
const o=await p.evaluate(()=>{const sel='input:not([type=hidden]):not([type=checkbox]):not([type=radio]),select,textarea,.btn,summary,.form-check';const out=[];
for(const row of document.querySelectorAll('.row')){const items=[];for(const col of row.children){const cr=col.getBoundingClientRect();if(!cr.height)continue;const ctl=col.querySelector(sel);if(!ctl||!ctl.getBoundingClientRect().width)continue;items.push({colTop:cr.top,colBot:cr.bottom,top:ctl.getBoundingClientRect().top,n:ctl.id||ctl.name||ctl.tagName.toLowerCase()+'.'+(ctl.className.split(' ')[0]||'')})}
 // same visual line = column boxes overlap vertically
 const lines=[];for(const it of items){const L=lines.find(l=>l.some(x=>!(x.colBot<=it.colTop||it.colBot<=x.colTop)));L?L.push(it):lines.push([it])}
 for(const L of lines){if(L.length<2)continue;const ts=L.map(x=>x.top);const dy=Math.max(...ts)-Math.min(...ts);if(dy>3)out.push('['+getComputedStyle(row).alignItems+'] '+L.map(x=>x.n+'@'+Math.round(x.top)).join(' ').slice(0,140)+' dy='+Math.round(dy))}}
return out});o.forEach(l=>console.log(pg,l))}
await b.close()})()
