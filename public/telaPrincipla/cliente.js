const SHINKA_CART_KEY = 'shinka_cart_v2';
const SHINKA_API = location.pathname.includes('/categoria/') ? '../../../index.php?route=' : '../index.php?route=';

window.ShinkaStore = (() => {
  function readCart(){ try { const v=JSON.parse(localStorage.getItem(SHINKA_CART_KEY)||'[]'); return Array.isArray(v)?v:[]; } catch { return []; } }
  function writeCart(cart){ localStorage.setItem(SHINKA_CART_KEY, JSON.stringify(cart)); updateBadges(); window.dispatchEvent(new CustomEvent('shinka-cart-change',{detail:cart})); }
  // Estoque conhecido do item (null = desconhecido). O servidor sempre revalida no checkout.
  function stockOf(item){const v=item&&item.estoque; if(v===undefined||v===null||v==='')return null; const n=Number(v); return Number.isFinite(n)?Math.max(0,Math.floor(n)):null;}
  // Total pedido do mesmo produto (variantes/cores compartilham o estoque).
  function productTotal(cart,id){return cart.filter(x=>Number(x.id)===Number(id)).reduce((s,x)=>s+Number(x.quantidade||0),0);}
  // Quanto esse item ainda pode ter, considerando as outras variantes do mesmo produto.
  function maxAllowed(cart,item){const stock=stockOf(item); if(stock===null)return 99; const others=productTotal(cart,item.id)-Number(item.quantidade||0); return Math.max(0,Math.min(99,stock-others));}
  function add(item){
    const cart=readCart();
    const key=`${item.id}:${item.variantId||0}`;
    const found=cart.find(x=>x.key===key);
    const stock=stockOf(item);
    const others=cart.filter(x=>x.key!==key&&Number(x.id)===Number(item.id)).reduce((s,x)=>s+Number(x.quantidade||0),0);
    const max=stock===null?99:Math.max(0,Math.min(99,stock-others));
    if(max<1) return cart; // sem estoque disponível: nada é adicionado
    if(found){
      if(stock!==null) found.estoque=stock;
      found.quantidade=Math.min(max, Number(found.quantidade||0)+Number(item.quantidade||1));
    } else cart.push({...item,key,quantidade:Math.min(max,Math.max(1,Number(item.quantidade||1)))});
    writeCart(cart); return cart;
  }
  function remove(key){writeCart(readCart().filter(x=>x.key!==key));}
  function setQty(key,qty){
    const cart=readCart(); const i=cart.find(x=>x.key===key);
    if(!i) return {ok:false,limited:false,max:0};
    const wanted=Math.max(1,Math.min(99,Number(qty)||1));
    const max=maxAllowed(cart,i);
    const final=max>=1?Math.min(wanted,max):Math.min(wanted,Number(i.quantidade||1));
    const limited=wanted>final;
    i.quantidade=final; writeCart(cart);
    return {ok:true,limited,max};
  }
  // Atualiza o estoque dos itens com os dados atuais da loja e reduz quantidades acima do disponível.
  async function syncStock(){
    const cart=readCart(); if(!cart.length) return [];
    const data=await json(`${SHINKA_API}api/produtos`);
    const stocks=new Map((Array.isArray(data.produtos)?data.produtos:[]).map(p=>[Number(p.id),Math.max(0,Number(p.estoque)||0)]));
    const changes=[]; let dirty=false; const used=new Map();
    cart.forEach(item=>{
      const existe=stocks.has(Number(item.id));
      const stock=existe?stocks.get(Number(item.id)):0; // produto inativo/removido = indisponível
      if(item.estoque!==stock){item.estoque=stock;dirty=true;}
      // Marca itens que saíram da loja (excluídos ou desativados) para o carrinho avisar com a mensagem certa.
      if(Boolean(item.indisponivel)!==!existe){item.indisponivel=!existe;dirty=true;}
      const room=stock-(used.get(Number(item.id))||0);
      if(stock>=1&&room>=1&&Number(item.quantidade)>room){
        changes.push({nome:item.nome,de:Number(item.quantidade),para:room});
        item.quantidade=room;dirty=true;
      }
      used.set(Number(item.id),(used.get(Number(item.id))||0)+Number(item.quantidade||0));
    });
    if(dirty) writeCart(cart);
    return changes;
  }
  function clear(){writeCart([])}
  function total(){return readCart().reduce((s,x)=>s+Number(x.preco||0)*Number(x.quantidade||0),0)}
  function count(){return readCart().reduce((s,x)=>s+Number(x.quantidade||0),0)}
  function updateBadges(){document.querySelectorAll('[data-cart-count]').forEach(el=>el.textContent=count())}
  async function json(url,options={}){const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...options,headers:{Accept:'application/json',...(options.headers||{})}});const t=await r.text();let d;try{d=JSON.parse(t)}catch{throw new Error('Resposta inválida do servidor.')}if(!r.ok||d.success===false){const e=new Error(d.message||'Não foi possível concluir a operação.');e.status=r.status;throw e}return d}
  async function csrf(){return json(`${SHINKA_API}api/csrf`)}
  async function post(route,form){const token=(await csrf()).token; if(form instanceof FormData) form.append('csrf_token',token); else form=new URLSearchParams({...form,csrf_token:token}); return json(`${SHINKA_API}${route}`,{method:'POST',body:form})}
  function formatBRL(v){return Number(v||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'})}
  function imageUrl(id,preset='medium'){return id?`${SHINKA_API}api/imagem&id=${encodeURIComponent(id)}&preset=${preset}`:''}
  document.addEventListener('DOMContentLoaded',updateBadges);
  return {readCart,add,remove,setQty,syncStock,stockOf,productTotal,maxAllowed,clear,total,count,updateBadges,json,post,formatBRL,imageUrl,API:SHINKA_API};
})();
