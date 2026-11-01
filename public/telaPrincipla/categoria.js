document.addEventListener('DOMContentLoaded', async () => {
  const params = new URLSearchParams(location.search);
  const slug = (params.get('slug') || '').trim().toLowerCase();
  const title = document.getElementById('categoryTitle');
  const hero = document.getElementById('categoryHero');
  const highlight = document.getElementById('heroHighlight');
  const breadcrumb = document.getElementById('categoryBreadcrumb');
  const grid = document.getElementById('grid');
  const search = document.getElementById('search');
  const sort = document.getElementById('sort');
  const notice = document.getElementById('categoryNotice');
  let products = [];
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = value => ShinkaStore.formatBRL(value);
  const image = id => id ? ShinkaStore.imageUrl(id, 'medium') : '';

  if (!slug) {
    notice.hidden = false;
    notice.textContent = 'Categoria não informada.';
    return;
  }

  try {
    const catResponse = await ShinkaStore.json(`${ShinkaStore.API}api/categorias`);
    const categoria = (catResponse.categorias || []).find(item => item.slug === slug);
    if (!categoria) throw new Error('Categoria não encontrada ou desativada.');
    document.title = `${categoria.nome} — SHINKA`;
    title.textContent = categoria.nome;
    breadcrumb.textContent = categoria.nome;
    highlight.textContent = categoria.destaque_texto || 'CATÁLOGO SHINKA';
    hero.textContent = categoria.hero_texto || categoria.descricao || 'Confira os produtos disponíveis.';
    document.body.dataset.category = categoria.tema || 'default';

    const response = await ShinkaStore.json(`${ShinkaStore.API}api/produtos&categoria=${encodeURIComponent(slug)}`);
    products = response.produtos || [];
    render();
  } catch (error) {
    notice.hidden = false;
    notice.textContent = error.message || 'Não foi possível carregar a categoria.';
  }

  function render() {
    const term = search.value.trim().toLowerCase();
    let rows = products.filter(item => !term || `${item.nome} ${item.familia || ''} ${item.categoria_nome || ''}`.toLowerCase().includes(term));
    if (sort.value === 'menor') rows.sort((a,b) => price(a)-price(b));
    if (sort.value === 'maior') rows.sort((a,b) => price(b)-price(a));
    if (sort.value === 'nome') rows.sort((a,b) => String(a.nome).localeCompare(String(b.nome), 'pt-BR'));
    grid.innerHTML = rows.length ? rows.map(card).join('') : '<div class="empty-state">Nenhum produto encontrado nesta categoria.</div>';
  }

  function price(p) { return Number(p.preco_promocional || p.preco || 0); }
  function card(p) {
    const img = Number(p.imagem_id || 0);
    return `<article class="category-card">
      <a class="product-visual" href="produto.html?id=${Number(p.id)}">${img ? `<img src="${image(img)}" alt="${escapeHtml(p.nome)}" loading="lazy">` : '<span>SHINKA</span>'}</a>
      <div class="product-info"><span class="product-tag">${escapeHtml(p.familia || p.categoria_nome || 'SHINKA')}</span><h2>${escapeHtml(p.nome)}</h2><p>${escapeHtml(p.descricao || '')}</p><strong>${money(price(p))}</strong>
      <div class="product-actions"><a href="produto.html?id=${Number(p.id)}" class="secondary-btn">Ver produto e comprar</a></div></div>
    </article>`;
  }

  search.addEventListener('input', render);
  sort.addEventListener('change', render);
});
