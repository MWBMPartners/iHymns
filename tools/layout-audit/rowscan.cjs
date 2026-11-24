/*
 * Layout audit helper (#2203). A developer tool only: nothing in the app loads it.
 * Static scan: lists every form row in manage/*.php that mixes columns oddly. Usage: node tools/layout-audit/rowscan.cjs appWeb/public_html/manage [end|all]
 * It needs the local test rig: the site running on http://localhost:8000
 * (php -S with a router), and Playwright. Bootstrap's files come from a
 * folder of unpacked npm packages given in LAYOUT_AUDIT_NPM, so no internet
 * is needed (set LAYOUT_AUDIT_PLAYWRIGHT to a Playwright path if the repo copy has no matching browser). Order: rowscan + tables (static), then rowmeasure + ovf3 (live), before and after a change.
 */
const fs=require('fs'),path=require('path');const root=process.argv[2];const mode=process.argv[3]||'end';
const walk=d=>fs.readdirSync(d,{withFileTypes:true}).flatMap(e=>e.isDirectory()?walk(path.join(d,e.name)):e.name.endsWith('.php')?[path.join(d,e.name)]:[]);
const strip=s=>s.replace(/<\?=\s*ihymns_slug_advanced_field\([\s\S]*?\?>/g,m=>"<details class=\"slug-advanced\"></details>".padEnd(m.length," ")).replace(/<\?php[\s\S]*?(\?>|$)/g,m=>m.replace(/[^\n]/g,' ')).replace(/<\?=[\s\S]*?\?>/g,m=>'X'.padEnd(m.length,' ').replace(/[^\nX ]/g,' ')).replace(/<!--[\s\S]*?-->/g,m=>m.replace(/[^\n]/g,' '));
const VOID=new Set(['input','br','img','hr','meta','link','source','wbr','col','area']);
function parse(t){const tagRe=/<\/?([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*>/g;const rootN={kids:[],tag:'#root'};const st=[rootN];let m;
 while(m=tagRe.exec(t)){const tag=m[1].toLowerCase();if(m[0][1]==='/'){for(let i=st.length-1;i>0;i--){if(st[i].tag===tag){st[i].end=m.index;st.length=i;break}}continue}
  const n={tag,open:m[0],start:m.index,kids:[],parent:st[st.length-1]};st[st.length-1].kids.push(n);if(!VOID.has(tag)&&!m[0].endsWith('/>'))st.push(n)}return rootN}
const cls=n=>((n.open.match(/class="([^"]*)"/)||[])[1]||'');
const all=(n,f,out=[])=>{for(const k of n.kids){if(f(k))out.push(k);all(k,f,out)}return out};
const isCtl=k=>['input','select','textarea'].includes(k.tag)&&!/type="(hidden|checkbox|radio)"/.test(k.open);
const res=[];
for(const f of walk(root)){const t=strip(fs.readFileSync(f,'utf8'));const tree=parse(t);
 for(const row of all(tree,k=>/(^|\s)row(\s|$)/.test(cls(k)))){const c=cls(row);const end=/\balign-items-end\b/.test(c);if(mode==='end'&&!end)continue;
  const cols=row.kids.filter(k=>/\bcol\b|\bcol-/.test(cls(k)));const withCtl=cols.filter(k=>all(k,isCtl).length||all(k,x=>/\bbtn\b/.test(cls(x))).length);if(withCtl.length<2)continue;
  const help=cols.filter(k=>{const ctl=all(k,isCtl)[0];return all(k,x=>/\bform-text\b/.test(cls(x))||(ctl&&x.start>ctl.start&&x.tag!=="label"&&x.tag!=="option"&&/\b(small|text-muted)\b/.test(cls(x))&&!/\bbtn\b/.test(cls(x)))).length});
  const labelled=cols.filter(k=>all(k,x=>x.tag==='label'||/\bform-label\b/.test(cls(x))).length);
  const det=cols.filter(k=>all(k,x=>x.tag==='details').length);
  const reasons=[];if(end&&help.length)reasons.push('help-text in '+help.length+'/'+cols.length);
  if(labelled.length&&labelled.length<withCtl.length)reasons.push('unlabelled '+(withCtl.length-labelled.length)+'/'+withCtl.length);
  if(det.length)reasons.push('details');
  if(reasons.length)res.push(path.relative(root,f)+':'+t.slice(0,row.start).split('\n').length+' ['+(end?'END':'top')+'] '+reasons.join('; '))}}
res.forEach(r=>console.log(r));console.log('total',res.length)
