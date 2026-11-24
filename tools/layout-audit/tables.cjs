/*
 * Layout audit helper (#2203). A developer tool only: nothing in the app loads it.
 * Static scan: lists every table with 4+ header cells that is not inside .table-responsive and has no data-col-priority. Usage: node tools/layout-audit/tables.cjs appWeb/public_html/manage
 * It needs the local test rig: the site running on http://localhost:8000
 * (php -S with a router), and Playwright. Bootstrap's files come from a
 * folder of unpacked npm packages given in LAYOUT_AUDIT_NPM, so no internet
 * is needed (set LAYOUT_AUDIT_PLAYWRIGHT to a Playwright path if the repo copy has no matching browser). Order: rowscan + tables (static), then rowmeasure + ovf3 (live), before and after a change.
 */
const fs=require('fs'),path=require('path');const root=process.argv[2];
const walk=d=>fs.readdirSync(d,{withFileTypes:true}).flatMap(e=>e.isDirectory()?walk(path.join(d,e.name)):e.name.endsWith('.php')?[path.join(d,e.name)]:[]);
let n=0;for(const f of walk(root)){const t=fs.readFileSync(f,'utf8');const re=/<table\b[^>]*>/g;let m;while(m=re.exec(t)){const end=t.indexOf('</table>',m.index);const body=t.slice(m.index,end<0?m.index+4000:end);
 const thead=(body.match(/<thead[\s\S]*?<\/thead>/)||[''])[0];const ths=(thead.match(/<th\b/g)||[]).length;const prio=/data-col-priority/.test(thead);
 const before=t.slice(Math.max(0,m.index-400),m.index);const wrapped=/table-responsive/.test(before.slice(before.lastIndexOf('<div')));
 const resp=/admin-table-responsive/.test(m[0]);
 if(ths>=4&&!wrapped&&!prio){n++;console.log(path.relative(root,f)+':'+t.slice(0,m.index).split('\n').length+' ths='+ths+' adminResp='+resp)}}}
console.log('total',n)
