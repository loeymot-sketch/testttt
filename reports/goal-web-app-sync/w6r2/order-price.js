const BASE='http://127.0.0.1:8766', KEY='b6d68vy2-m7g5-20r0-5275-h103w73453q120';
const TOKEN='6625|U2sYzBULk802OTteFA6IkmYtWA6Z5OSKYcF8Jvz3fac5b35e';
const V=[{id:497,quantity:1},{id:504,quantity:1},{id:513,quantity:1},{id:511,quantity:1}];
async function place(addons){
  const line={item_id:105,quantity:1,item_variations:V};
  if(addons) line.item_addons=addons;
  const body={branch_id:1,order_type:10,source:5,payment_method:1,is_advance_order:0,items:JSON.stringify([line])};
  const r=await fetch(BASE+'/api/frontend/order',{method:'POST',headers:{'X-API-Key':KEY,'Authorization':'Bearer '+TOKEN,'Content-Type':'application/json','X-Idempotency-Key':'wp-'+Math.random().toString(36).slice(2)+Date.now()},body:JSON.stringify(body)});
  const j=await r.json();
  const o=(j.data||j);
  const id=o.id||o.order_id||(o.order&&o.order.id);
  let total=o.grand_total??o.total_amount??o.paid_amount;
  if(total==null && id){
    const sr=await fetch(BASE+'/api/frontend/order/show/'+id,{headers:{'X-API-Key':KEY,'Authorization':'Bearer '+TOKEN}});
    const sj=await sr.json(); const so=sj.data||sj;
    total=so.grand_total??so.total_amount??so.paid_amount??so.order_amount;
  }
  return {status:r.status, keys:Object.keys(o), id, total, raw: total==null?JSON.stringify(o).slice(0,300):undefined};
}
(async()=>{
  const A78=(role)=>[{id:78,quantity:1,role}];
  console.log('BASE       ', JSON.stringify(await place(null)));
  console.log('menu_full  ', JSON.stringify(await place(A78('menu_full'))));
  console.log('menu_frites', JSON.stringify(await place(A78('menu_frites'))));
  console.log('menu_boisson',JSON.stringify(await place(A78('menu_boisson'))));
})();
