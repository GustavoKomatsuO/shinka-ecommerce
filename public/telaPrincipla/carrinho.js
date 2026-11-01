let stockNotices=[];

document.addEventListener('DOMContentLoaded', async () => {
  render();
  try {
    // Confere o estoque atual da loja; o servidor ainda revalida no checkout.
    const changes = await ShinkaStore.syncStock();
    if (changes.length) { stockNotices = changes; render(); }
  } catch (error) {
    console.warn('Não foi possível conferir o estoque agora:', error);
  }
});
window.addEventListener('shinka-cart-change', render);

function stockState(cart,item){
  const stock=ShinkaStore.stockOf(item);
  if(stock===null) return {stock:null,soldOut:false,over:false,atMax:false};
  const total=ShinkaStore.productTotal(cart,item.id);
  return {stock,soldOut:stock<1,over:stock>=1&&total>stock,atMax:stock>=1&&total>=stock};
}

function stockLine(cart,item){
  const s=stockState(cart,item);
  if(s.stock===null) return '';
  if(item.indisponivel) return '<div class="cart-stock-warning">Este produto não está mais disponível na loja. Remova este item para finalizar a compra.</div>';
  if(s.soldOut) return '<div class="cart-stock-warning">Esgotado no momento. Remova este item para finalizar a compra.</div>';
  if(s.over) return `<div class="cart-stock-warning">${s.stock===1?'Há apenas 1 unidade disponível':`Há apenas ${s.stock} unidades disponíveis`}. Reduza a quantidade para continuar.</div>`;
  if(s.atMax) return `<div class="cart-stock-hint">Quantidade máxima disponível: ${s.stock}.</div>`;
  return '';
}

function stockNoticeHtml(){
  if(!stockNotices.length) return '';
  return `<div class="cart-stock-notice" role="status"><strong>Ajustamos seu carrinho ao estoque disponível.</strong> ${stockNotices.map(c=>`${esc(c.nome)}: de ${c.de} para ${c.para} unidade${c.para===1?'':'s'}`).join(' · ')}</div>`;
}

function render(){
  const root=document.getElementById('root');
  const cart=ShinkaStore.readCart();
  const wasAdded=new URLSearchParams(location.search).get('added') === '1';
  const notice=wasAdded ? `<div class="cart-added-notice" role="status"><strong>Produto adicionado ao carrinho.</strong><span>Revise a quantidade e finalize sua compra quando estiver pronto.</span></div>` : '';
  if(wasAdded){
    history.replaceState({},document.title,location.pathname);
  }
  if(!cart.length){
    root.innerHTML=`${notice}<div class="panel empty"><h2>Seu carrinho está vazio</h2><p>Escolha um produto e clique em <strong>Comprar agora</strong> dentro da página do produto.</p><a class="btn btn-primary" href="index.html">Voltar para a loja</a></div>`;
    return;
  }
  const total=ShinkaStore.total();
  const blocked=cart.some(i=>{const st=stockState(cart,i);return st.soldOut||st.over;});
  root.innerHTML=`${notice}${stockNoticeHtml()}<div class="cart-layout"><section class="panel panel-pad"><div id="items">${cart.map(item=>`<div class="cart-row"><img src="${ShinkaStore.imageUrl(item.imagemId||0)}" alt="${esc(item.nome)}"><div><strong>${esc(item.nome)}</strong><div class="muted">${esc(item.variantName||'Padrão')}</div><div class="qty" style="margin-top:10px"><button aria-label="Diminuir quantidade" data-minus="${esc(item.key)}">−</button><strong>${item.quantidade}</strong><button aria-label="Aumentar quantidade" data-plus="${esc(item.key)}"${stockState(cart,item).atMax||stockState(cart,item).soldOut?' disabled':''}>+</button><button class="btn btn-danger" style="min-height:30px;margin-left:6px" data-remove="${esc(item.key)}">Remover</button></div>${stockLine(cart,item)}</div><div><div class="price">${ShinkaStore.formatBRL(item.preco*item.quantidade)}</div><div class="muted">${ShinkaStore.formatBRL(item.preco)} un.</div></div></div>`).join('')}</div></section><aside class="panel panel-pad summary"><div class="eyebrow">RESUMO</div><div class="summary-line"><span>Produtos</span><strong>${ShinkaStore.formatBRL(total)}</strong></div><div class="summary-line"><span>Frete</span><span>Calculado no checkout</span></div><div class="summary-line total"><span>Total</span><strong>${ShinkaStore.formatBRL(total)}</strong></div><button id="checkoutButton" class="btn btn-primary btn-block" style="margin-top:15px" type="button"${blocked?' disabled':''}>Finalizar compra</button>${blocked?'<p class="cart-stock-warning" style="margin:10px 0 0">Ajuste os itens sem estoque para continuar.</p>':''}<button id="clear" class="btn btn-secondary btn-block" style="margin-top:8px" type="button">Limpar carrinho</button></aside></div>`;
  root.querySelectorAll('[data-remove]').forEach(b=>b.onclick=()=>ShinkaStore.remove(b.dataset.remove));
  root.querySelectorAll('[data-plus]').forEach(b=>b.onclick=()=>{const i=cart.find(x=>x.key===b.dataset.plus); if(i) ShinkaStore.setQty(i.key,i.quantidade+1)});
  root.querySelectorAll('[data-minus]').forEach(b=>b.onclick=()=>{const i=cart.find(x=>x.key===b.dataset.minus); if(i){if(i.quantidade<=1)ShinkaStore.remove(i.key);else ShinkaStore.setQty(i.key,i.quantidade-1)}});
  document.getElementById('clear').onclick=()=>ShinkaStore.clear();

  const checkoutButton = document.getElementById('checkoutButton');
  checkoutButton.onclick = async () => {
    checkoutButton.disabled = true;
    checkoutButton.textContent = 'Verificando acesso...';

    try {
      await ShinkaStore.json(`${ShinkaStore.API}api/cliente/me`);
      location.href = 'checkout.html';
      return;
    } catch (error) {
      if (Number(error.status) !== 401) {
        showLoginRequiredNotice('Não foi possível verificar sua sessão. Tente novamente.');
        checkoutButton.disabled = false;
        checkoutButton.textContent = 'Finalizar compra';
        return;
      }

      showLoginRequiredNotice('Para finalizar sua compra, faça login na sua conta. Você será redirecionado para a tela de login.');

      window.setTimeout(() => {
        location.href = '../login_cadastro.html?redirect=telaPrincipla%2Fcheckout.html';
      }, 1600);
    }
  };
}

function showLoginRequiredNotice(message) {
  const root = document.getElementById('root');
  if (!root) return;

  root.querySelector('.login-required-notice')?.remove();

  const notice = document.createElement('div');
  notice.className = 'login-required-notice';
  notice.setAttribute('role', 'alert');
  notice.innerHTML = `<div><strong>Login necessário</strong><span>${esc(message)}</span></div><span class="login-required-spinner" aria-hidden="true"></span>`;
  root.prepend(notice);
  notice.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function esc(v){return String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]))}
