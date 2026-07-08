'use strict';
const fs=require('fs'),path=require('path'),vm=require('vm');
const MOBILE='/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile';
const FIX=require('/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/goal-web-app-sync/e2e-fixtures.json');
const _ls={};global.localStorage={getItem:k=>k in _ls?_ls[k]:null,setItem:(k,v)=>_ls[k]=String(v),removeItem:k=>delete _ls[k]};
const window={};global.window=window;global.crypto=require('crypto').webcrypto;global.fetch=fetch;
window.LC={config:{apiBase:FIX.base,apiKey:FIX.apiKey,branchId:1,onlineCardEnabled:false}};
const load=r=>vm.runInThisContext(fs.readFileSync(path.join(MOBILE,r),'utf8'),{filename:r});
load('api/storage.js');load('data/menu.js');load('api/client.js');
const api=window.LC.mobileApi,storage=window.LC.storage,lcMenu=window.LC.menu;
storage.setAuth({token:FIX.clients[1].token,phone:FIX.clients[1].phone,user_id:FIX.clients[1].user_id});
function buildLine(slug,sel){const item=lcMenu.findItem(slug);const cids=sel.cruditeIds||lcMenu.defaultCruditeIds();return Object.assign({},item,{painId:sel.painId||null,meatIds:sel.meatIds||[],extraMeatIds:sel.extraMeatIds||[],sauceIds:sel.sauceIds||[],cruditeIds:cids,cruditeRemoved:lcMenu.crudites.filter(c=>!cids.includes(c.id)).map(c=>c.name),supplementIds:sel.supplementIds||[],bolSupplementIds:sel.bolSupplementIds||[],bolDrinkId:sel.bolDrinkId!==undefined?sel.bolDrinkId:null,menuChoice:sel.menuChoice||'none',qty:sel.qty||1});}
(async()=>{
  await api.buildItemIndex();
  // bol avec bolDrinkId d-ice-tea-peche (slug backend = 'ice-tea', PAS 'ice-tea-peche')
  const bol=buildLine('bol-frites',{meatIds:['m-tenders'],sauceIds:['bs-fromagere'],bolDrinkId:'d-ice-tea-peche'});
  const items=await api.resolveOrderItems([bol]);
  console.log('ICE-TEA edge resolveOrderItems:',JSON.stringify(items),'| lignes:',items.length);
  console.log('  drink line item_id =', items[1] && items[1].item_id, '(attendu 121 via name-fallback)');
  // Test TOUS les drinks du pool formuleDrinks comme bolDrink -> repérer un id null
  let fails=[];
  for(const d of lcMenu.formuleDrinks){
    const b=buildLine('bol-riz',{meatIds:['m-nuggets'],sauceIds:['bs-spicy'],bolDrinkId:d.id});
    try{const it=await api.resolveOrderItems([b]);const drink=it[1];if(!drink||drink.item_id==null)fails.push(d.id+'/'+d.name+' -> NO LINE');else if(it.length<2)fails.push(d.id+' -> missing');}
    catch(e){fails.push(d.id+' THROW '+(e.message||JSON.stringify(e)));}
  }
  console.log('\nALL formuleDrinks as bolDrink -> failures:', fails.length?JSON.stringify(fails):'NONE (tous résolus)');
})().catch(e=>console.log('FATAL',e.stack||e));
