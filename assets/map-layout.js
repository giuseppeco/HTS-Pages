(function(root,factory){const api=factory();if(typeof module==='object'&&module.exports)module.exports=api;else root.HTSPMapLayout=api;})(typeof globalThis!=='undefined'?globalThis:this,function(){
 'use strict';
 const W=240,H=318,GAP=52,ROW=100,MARGIN=50,MAXCOLS=4;
 function compute(nodes,edges,rootId){
  const byId=new Map(nodes.map(n=>[Number(n.id),n]));const outgoing=new Map(),incoming=new Map();
  byId.forEach((_,id)=>{outgoing.set(id,[]);incoming.set(id,[]);});
  edges.forEach(e=>{const a=Number(e.source),b=Number(e.target);if(a!==b&&byId.has(a)&&byId.has(b)){outgoing.get(a).push(b);incoming.get(b).push(a);}});
  const order=[...byId.keys()].sort((a,b)=>(Number(byId.get(a).link_counts?.in??incoming.get(a).length)-Number(byId.get(b).link_counts?.in??incoming.get(b).length))||String(byId.get(a).title).localeCompare(String(byId.get(b).title))||a-b);
  const level=new Map(),roots=[];
  function visit(start){roots.push(start);level.set(start,0);const queue=[start];for(let i=0;i<queue.length;i++){const id=queue[i];for(const next of outgoing.get(id)){if(!level.has(next)){level.set(next,level.get(id)+1);queue.push(next);}}}}
  if(byId.has(Number(rootId))&&incoming.get(Number(rootId)).length===0)visit(Number(rootId));
  while(level.size<byId.size){const remaining=order.filter(id=>!level.has(id));const source=remaining.find(id=>incoming.get(id).every(parent=>!remaining.includes(parent)))??remaining[0];visit(source);}
  const rows=[];level.forEach((rank,id)=>{(rows[rank]??=[]).push(id);});
  rows.forEach(row=>row.sort((a,b)=>order.indexOf(a)-order.indexOf(b)));
  // Group children around their predecessors while retaining one node per page.
  for(let i=1;i<rows.length;i++){
   const prior=new Map(rows[i-1].map((id,index)=>[id,index]));
   const bary=id=>{const values=incoming.get(id).filter(p=>prior.has(p)).map(p=>prior.get(p));return values.length?values.reduce((a,b)=>a+b,0)/values.length:Number.MAX_SAFE_INTEGER;};
   rows[i].sort((a,b)=>bary(a)-bary(b)||order.indexOf(a)-order.indexOf(b));
  }
  const cols=Math.max(1,Math.min(MAXCOLS,Math.max(1,...rows.map(row=>row.length))));const width=cols*(W+GAP)-GAP+2*MARGIN;
  const positions={};let line=0;rows.forEach((row,rank)=>{for(let offset=0;offset<row.length;offset+=MAXCOLS){const part=row.slice(offset,offset+MAXCOLS);const left=(width-(part.length*(W+GAP)-GAP))/2;part.forEach((id,index)=>{positions[id]={x:left+index*(W+GAP),y:MARGIN+line*(H+ROW),width:W,height:H,rank};});line++;}});
  const height=Math.max(1,line)*(H+ROW)-ROW+2*MARGIN;
  return {positions,width,height,roots,backEdges:edges.filter(e=>positions[e.source]&&positions[e.target]&&positions[e.source].rank>=positions[e.target].rank).length};
 }
 function edgePath(a,b){
  const ax=a.x+W/2,ay=a.y+H,bx=b.x+W/2,by=b.y-6;
  if(b.y>a.y){const mid=(ay+by)/2;return `M ${ax} ${ay} C ${ax} ${mid}, ${bx} ${mid}, ${bx} ${by}`;}
  const right=Math.max(a.x,b.x)+W+35;return `M ${a.x+W} ${a.y+H/2} C ${right} ${a.y+H/2}, ${right} ${b.y+H/2}, ${b.x+W+5} ${b.y+H/2}`;
 }
 return {compute,edgePath,W,H};
});
