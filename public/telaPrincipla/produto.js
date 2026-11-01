document.addEventListener('DOMContentLoaded', async () => {
  const id = new URLSearchParams(location.search).get('id');
  const loading = document.getElementById('loading');
  const content = document.getElementById('content');
  const error = document.getElementById('error');

  if (!id) {
    loading.classList.add('hidden');
    error.textContent = 'Produto não informado.';
    error.classList.remove('hidden');
    return;
  }

  try {
    const data = await ShinkaStore.json(`${ShinkaStore.API}api/produtos`);
    const product = (data.produtos || []).find(item => String(item.id) === String(id));
    if (!product) throw new Error('Produto não encontrado.');

    const variants = Array.isArray(product.variantes) && product.variantes.length
      ? product.variantes
      : [{
          id: 0,
          nome: 'Padrão',
          cor_nome: 'Padrão',
          cor_hex: '#d5d9e0',
          imagens: product.imagem_id ? [{ id: product.imagem_id, principal: 1 }] : []
        }];

    let selected = variants[0];
    let selectedImage = Number(selected.imagens?.find(x => Number(x.principal) === 1)?.id || selected.imagens?.[0]?.id || product.imagem_id || 0);
    const productStock = Number(product.estoque || 0);
    const price = Number(product.preco_promocional || product.preco || 0);
    const oldPrice = Number(product.preco_anterior || 0);

    content.innerHTML = `
      <nav class="product-breadcrumb" aria-label="Navegação do produto">
        <a href="index.html">Início</a><span>›</span>
        <span>${esc(product.categoria_nome || 'Tecnologia')}</span><span>›</span>
        <strong>${esc(product.nome)}</strong>
      </nav>

      <section class="product-detail-shell">
        <div class="product-gallery-panel">
          <div class="product-detail-badge">${esc(product.badge || 'SHINKA')}</div>
          <div class="product-main-media">
            <img id="mainImage" src="${ShinkaStore.imageUrl(selectedImage)}" alt="${esc(product.nome)}">
            <div id="stockOverlay" class="stock-overlay${productStock > 0 ? ' hidden' : ''}">ESGOTADO</div>
          </div>
          <div id="gallery" class="product-thumbnails" aria-label="Imagens do produto"></div>
        </div>

        <aside class="product-purchase-panel">
          <div class="product-family-label">${esc(product.familia || product.categoria_nome || 'Tecnologia SHINKA')}</div>
          <h1>${esc(product.nome)}</h1>
          <p class="product-lead">${esc(product.descricao || 'Tecnologia SHINKA criada para acompanhar o seu ritmo.')}</p>

          <div class="product-price-block">
            ${oldPrice > 0 ? `<span class="product-old-price">${ShinkaStore.formatBRL(oldPrice)}</span>` : ''}
            <strong>${ShinkaStore.formatBRL(price)}</strong>
            ${oldPrice > price && price > 0 ? `<span class="product-price-note">Oferta SHINKA</span>` : ''}
          </div>

          <div class="product-selector-section">
            <div class="selector-head"><strong>Escolha a cor</strong><span id="variantName">${esc(selected.cor_nome || selected.nome || 'Padrão')}</span></div>
            <div id="variants" class="product-variant-grid"></div>
          </div>

          <div id="stockNotice" class="stock-notice ${productStock > 0 ? 'in-stock' : 'sold-out'}">
            <span class="stock-dot"></span>
            <div>
              <strong>${productStock > 0 ? 'Disponível para compra' : 'Produto esgotado'}</strong>
              <small id="stockText">${productStock > 0 ? `${productStock} unidade${productStock === 1 ? '' : 's'} disponível${productStock === 1 ? '' : 'is'}` : 'No momento não há unidades disponíveis.'}</small>
            </div>
          </div>

          <div class="product-spec-list">
            ${String(product.especificacoes || '').split('•').map(x => x.trim()).filter(Boolean).slice(0, 6).map(x => `<span>${esc(x)}</span>`).join('')}
          </div>

          <button id="buyButton" class="product-buy-button${productStock < 1 ? ' is-disabled' : ''}" type="button" ${productStock < 1 ? 'disabled aria-disabled="true"' : ''}>
            ${productStock > 0 ? 'Comprar agora' : 'Esgotado'}
          </button>
          <p class="product-buy-note">A compra só é adicionada ao carrinho nesta página, depois da escolha da variante.</p>
        </aside>
      </section>

    `;

    const main = document.getElementById('mainImage');
    const gallery = document.getElementById('gallery');
    const variantsEl = document.getElementById('variants');
    const stockOverlay = document.getElementById('stockOverlay');
    const stockNotice = document.getElementById('stockNotice');
    const stockText = document.getElementById('stockText');
    const variantName = document.getElementById('variantName');
    const buyButton = document.getElementById('buyButton');

    function getStock() {
      // O schema atual controla estoque no produto; a variante define mídia/SKU.
      return productStock;
    }

    function renderVariant() {
      const images = Array.isArray(selected.imagens) ? selected.imagens : [];
      selectedImage = Number(images.find(x => Number(x.principal) === 1)?.id || images[0]?.id || product.imagem_id || 0);
      if (selectedImage) {
        main.src = ShinkaStore.imageUrl(selectedImage);
      }
      main.alt = `${product.nome} — ${selected.cor_nome || selected.nome || 'Padrão'}`;
      variantName.textContent = selected.cor_nome || selected.nome || 'Padrão';

      gallery.innerHTML = images.length
        ? images.map((item, index) => `
            <button class="product-thumb ${index === 0 ? 'active' : ''}" type="button" data-image-id="${Number(item.id)}" aria-label="Imagem ${index + 1}">
              <img src="${ShinkaStore.imageUrl(Number(item.id), 'thumb')}" alt="" loading="lazy">
            </button>`).join('')
        : '';

      gallery.querySelectorAll('[data-image-id]').forEach(button => {
        button.addEventListener('click', () => {
          selectedImage = Number(button.dataset.imageId);
          main.src = ShinkaStore.imageUrl(selectedImage);
          gallery.querySelectorAll('.product-thumb').forEach(item => item.classList.toggle('active', item === button));
        });
      });

      variantsEl.querySelectorAll('[data-variant-id]').forEach(button => {
        button.classList.toggle('active', String(button.dataset.variantId) === String(selected.id));
      });
    }

    variantsEl.innerHTML = variants.map(variant => `
      <button type="button" class="product-variant ${String(variant.id) === String(selected.id) ? 'active' : ''}" data-variant-id="${Number(variant.id || 0)}">
        <span class="variant-swatch" style="background:${/^#[0-9A-Fa-f]{6}$/.test(variant.cor_hex || '') ? variant.cor_hex : '#d5d9e0'}"></span>
        <span>${esc(variant.cor_nome || variant.nome || 'Padrão')}</span>
      </button>
    `).join('');

    variantsEl.querySelectorAll('[data-variant-id]').forEach(button => {
      button.addEventListener('click', () => {
        selected = variants.find(item => String(item.id) === String(button.dataset.variantId)) || selected;
        renderVariant();
      });
    });

    function updateBuyState() {
      const stock = getStock();
      const available = stock > 0;
      buyButton.disabled = !available;
      buyButton.setAttribute('aria-disabled', String(!available));
      buyButton.classList.toggle('is-disabled', !available);
      buyButton.textContent = available ? 'Comprar agora' : 'Esgotado';
      stockNotice.classList.toggle('in-stock', available);
      stockNotice.classList.toggle('sold-out', !available);
      stockOverlay.classList.toggle('hidden', available);
      stockText.textContent = available
        ? `${stock} unidade${stock === 1 ? '' : 's'} disponível${stock === 1 ? '' : 'is'}`
        : 'No momento não há unidades disponíveis.';
      const strong = stockNotice.querySelector('strong');
      if (strong) strong.textContent = available ? 'Disponível para compra' : 'Produto esgotado';
    }

    buyButton.addEventListener('click', () => {
      if (buyButton.disabled || getStock() < 1) return;
      ShinkaStore.add({
        id: Number(product.id),
        nome: product.nome,
        preco: price,
        imagemId: selectedImage,
        variantId: Number(selected.id || 0),
        variantName: selected.cor_nome || selected.nome || 'Padrão',
        estoque: getStock(),
        quantidade: 1
      });
      // Só aqui o fluxo de compra envia para o carrinho.
      location.href = 'carrinho.html?added=1';
    });

    renderVariant();
    updateBuyState();
    loading.classList.add('hidden');
    content.classList.remove('hidden');
  } catch (e) {
    loading.classList.add('hidden');
    error.textContent = e.message || 'Não foi possível carregar o produto.';
    error.classList.remove('hidden');
  }

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[char]));
  }
});
