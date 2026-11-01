document.addEventListener('DOMContentLoaded', () => {
  /* =========================================================
     INTRODUÇÃO DA MARCA
     ---------------------------------------------------------
     - Exibe o SHINKA no carregamento.
     - Após 800 ms inicia o efeito de gota/ripple.
     - Após 2500 ms libera a navegação da página.
     ========================================================= */
  const introLoader = document.getElementById('intro-loader');

  if (introLoader) {
    setTimeout(() => introLoader.classList.add('ripple-active'), 800);
    setTimeout(() => {
      introLoader.classList.add('fade-out');
      document.body.classList.remove('no-scroll');
    }, 2500);
  }

  /* =========================================================
     ELEMENTOS DO CARROSSEL
     ========================================================= */
  let slides = [...document.querySelectorAll('.slide')];
  let dots = [...document.querySelectorAll('.dot')];
  const carousel = document.getElementById('carousel');
  const prevBtn = document.getElementById('prevBtn');
  const nextBtn = document.getElementById('nextBtn');

  /* Categorias administráveis: os nomes/URLs ativos são refletidos na navegação. */
  const categoryPageMap = {
    smartphones: 'categoria/smartphones/smartphones.html',
    notebooks: 'categoria/notebooks/notebooks.html',
    tablets: 'categoria/tablets/tablets.html',
    tv: 'categoria/tv/tv.html'
  };
  fetch('../index.php?route=api/categorias', { credentials: 'same-origin', cache: 'no-store' })
    .then(response => response.json())
    .then(data => {
      const categorias = Array.isArray(data.categorias) ? data.categorias : [];
      const nav = document.querySelector('.nav-list');
      if (!nav || !categorias.length) return;
      const known = new Set();
      nav.querySelectorAll('a.nav-link').forEach(link => {
        const match = link.getAttribute('href')?.match(/categoria\/([^/?]+)/i);
        if (match) known.add(match[1].toLowerCase());
      });
      categorias.forEach((categoria) => {
        const link = nav.querySelector(`a.nav-link[href*="categoria/${categoria.slug}/"]`) ||
          Array.from(nav.querySelectorAll('a.nav-link')).find(a => a.textContent.trim().toLowerCase() === categoria.slug.toLowerCase());
        if (link) {
          link.textContent = categoria.nome;
          link.href = categoryPageMap[categoria.slug]
            ? categoryPageMap[categoria.slug]
            : `categoria.html?slug=${encodeURIComponent(categoria.slug)}`;
          return;
        }
        if (known.has(categoria.slug.toLowerCase())) return;
        const li = document.createElement('li');
        li.className = 'nav-item';
        li.innerHTML = `<a href="categoria.html?slug=${encodeURIComponent(categoria.slug)}" class="nav-link">${escapeHtmlHome(categoria.nome)}</a>`;
        nav.appendChild(li);
      });
    })
    .catch(() => {});

  function escapeHtmlHome(value) {
    return String(value ?? '').replace(/[&<>\"']/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[char]));
  }

  /*
   * Carrossel 100% administrável:
   * a Home não possui mais slides fixos no HTML.
   * Todos os banners vêm exclusivamente de Admin > Campanhas > Banner da Home.
   */
  async function carregarBannersPublicos() {
    if (!carousel) return { ok: false, banners: [] };

    const loading = document.getElementById('carouselLoading');
    const indicator = document.getElementById('indicators');

    const mostrarEstado = (mensagem) => {
      carousel.querySelectorAll('.slide').forEach(slide => slide.remove());
      if (loading) {
        loading.hidden = false;
        loading.textContent = mensagem;
      }
      if (prevBtn) prevBtn.hidden = true;
      if (nextBtn) nextBtn.hidden = true;
      if (indicator) indicator.innerHTML = '';
      slides = [];
      dots = [];
    };

    try {
      const response = await fetch('../index.php?route=api/banners', {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' }
      });

      if (!response.ok) {
        mostrarEstado('Não foi possível carregar os banners da Home.');
        return { ok: false, banners: [] };
      }

      const data = await response.json();
      const banners = Array.isArray(data.banners)
        ? data.banners.filter(banner => Number(banner.ativo ?? 1) === 1)
        : [];

      if (!banners.length) {
        mostrarEstado('Nenhum banner ativo foi cadastrado no Admin.');
        return { ok: true, banners: [] };
      }

      if (loading) loading.hidden = true;
      if (prevBtn) prevBtn.hidden = banners.length <= 1;
      if (nextBtn) nextBtn.hidden = banners.length <= 1;

      const fragment = document.createDocumentFragment();
      banners.forEach((banner, index) => {
        const slide = document.createElement('article');
        slide.className = `slide${index === 0 ? ' active' : ''}`;

        const imagemId = Number(banner.imagem_id || 0);
        const desktop = imagemId
          ? `../index.php?route=api/imagem&id=${imagemId}&preset=banner_desktop`
          : '';
        const mobile = imagemId
          ? `../index.php?route=api/imagem&id=${imagemId}&preset=banner_mobile`
          : '';
        const link = String(banner.link || '').trim();

        const visual = document.createElement('div');
        visual.className = 'dynamic-banner-visual';

        if (desktop) {
          visual.innerHTML = `
            <picture class="dynamic-banner-picture">
              <source media="(max-width: 700px)" srcset="${mobile}">
              <img class="dynamic-banner-media" src="${desktop}" alt="${escapeHtmlHome(banner.titulo || 'Banner SHINKA')}">
            </picture>
            <span class="dynamic-banner-shade" aria-hidden="true"></span>
          `;
        }

        const caption = document.createElement('div');
        caption.className = 'slide-caption';
        const subtitle = banner.subtitulo
          ? `<span class="slide-subtitle">${escapeHtmlHome(banner.subtitulo)}</span>`
          : '';
        caption.innerHTML = `
          ${subtitle}
          <h2 class="slide-title">${escapeHtmlHome(banner.titulo || 'Destaque SHINKA')}</h2>
        `;

        slide.append(visual, caption);
        fragment.appendChild(slide);
      });

      carousel.querySelectorAll('.slide').forEach(slide => slide.remove());
      carousel.insertBefore(fragment, prevBtn);

      if (indicator) {
        indicator.innerHTML = banners.map((_, index) =>
          `<button class="dot${index === 0 ? ' active' : ''}" type="button" data-slide="${index}" aria-label="Slide ${index + 1}"></button>`
        ).join('');
      }

      slides = [...carousel.querySelectorAll('.slide')];
      dots = [...carousel.querySelectorAll('.dot')];
      return { ok: true, banners };
    } catch (_) {
      mostrarEstado('Não foi possível conectar ao servidor para carregar os banners.');
      return { ok: false, banners: [] };
    }
  }

  /* Filtros do grid inicial: quando o cliente escolhe um tipo, o grid deixa de ser apenas a vitrine curta e passa a mostrar todos os produtos daquela categoria. */
  const gridTabs = [...document.querySelectorAll('[data-grid-filter]')];
  const gridCards = [...document.querySelectorAll('[data-grid-category]')];
  const curatedGrid = document.querySelector('.grid-container');
  const dynamicGrid = document.getElementById('catalogFilterResults');
  let catalogProducts = null;

  const escapeHtmlCatalog = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const productPrice = product => Number(product.preco_promocional || product.preco || 0);
  const productImage = product => {
    const variant = (product.variantes || []).find(v => Number(v.id) !== 0) || product.variantes?.[0];
    const id = Number(variant?.imagens?.find(i => Number(i.principal) === 1)?.id || variant?.imagens?.[0]?.id || product.imagem_id || 0);
    return id ? ShinkaStore.imageUrl(id, 'medium') : '';
  };

  async function loadCatalogForGrid() {
    if (Array.isArray(catalogProducts)) return catalogProducts;
    const response = await ShinkaStore.json(`${ShinkaStore.API}api/produtos`);
    catalogProducts = Array.isArray(response.produtos) ? response.produtos : [];
    return catalogProducts;
  }

  function renderDynamicGrid(filter) {
    if (!dynamicGrid) return;
    const products = (catalogProducts || []).filter(p => filter === 'todas' || p.categoria_slug === filter);
    dynamicGrid.hidden = false;
    dynamicGrid.innerHTML = products.length ? products.map(p => {
      const img = productImage(p);
      return `<article class="catalog-filter-card">
        <a class="catalog-filter-card-image" href="produto.html?id=${Number(p.id)}">${img ? `<img src="${img}" alt="${escapeHtmlCatalog(p.nome)}" loading="lazy">` : '<span>SHINKA</span>'}</a>
        <div class="catalog-filter-card-body"><span>${escapeHtmlCatalog(p.familia || p.categoria_nome || 'SHINKA')}</span><h3>${escapeHtmlCatalog(p.nome)}</h3><p>${escapeHtmlCatalog(p.descricao || 'Tecnologia SHINKA para o seu dia a dia.')}</p><strong>${ShinkaStore.formatBRL(productPrice(p))}</strong><a class="btn-buy" href="produto.html?id=${Number(p.id)}">Ver produto</a></div>
      </article>`;
    }).join('') : '<div class="catalog-filter-empty">Nenhum produto encontrado nesta categoria.</div>';
  }

  /*
   * Vitrine curada (grid fixo da Home): as imagens ficam estáticas em HTML
   * como referência visual, mas assim que o produto correspondente já tiver
   * uma imagem cadastrada no banco (Admin > Produtos), essa imagem do banco
   * substitui a estática automaticamente. O casamento é feito pelo nome do
   * produto exibido no card; se não houver nome igual, cai para a "família"
   * do produto (ex.: card "SHINKA Mirai Z" casa com a família "Mirai Z").
   */
  async function sincronizarImagensGridCurado() {
    if (!curatedGrid) return;
    let products;
    try {
      products = await loadCatalogForGrid();
    } catch (_) {
      return; // sem catálogo disponível, mantém as imagens estáticas de referência
    }

    curatedGrid.querySelectorAll('[data-grid-category]').forEach(card => {
      const nameEl = card.querySelector('.product-name, .product-name-sm');
      const imgEl = card.querySelector('.card-image img');
      if (!nameEl || !imgEl) return;

      const nomeCartao = nameEl.textContent.trim().toLowerCase();
      const familiaCartao = nomeCartao.replace(/^shinka\s+/, '').trim();

      let produto = products.find(p => (p.nome || '').trim().toLowerCase() === nomeCartao);
      if (!produto) {
        const candidatos = products.filter(p => (p.familia || '').trim().toLowerCase() === familiaCartao);
        produto = candidatos.find(p => Number(p.destaque) === 1) || candidatos[0];
      }
      if (!produto) return;

      const img = productImage(produto);
      if (img) {
        imgEl.src = img;
        imgEl.alt = produto.nome || imgEl.alt;
      }
    });
  }

  sincronizarImagensGridCurado();

  gridTabs.forEach(tab => {
    tab.addEventListener('click', async () => {
      const filter = tab.dataset.gridFilter || 'todas';
      gridTabs.forEach(item => { const active = item === tab; item.classList.toggle('active', active); item.setAttribute('aria-selected', active ? 'true' : 'false'); });
      if (filter === 'todas') {
        curatedGrid && (curatedGrid.hidden = false);
        dynamicGrid && (dynamicGrid.hidden = true);
        gridCards.forEach(card => card.hidden = false);
        return;
      }
      try {
        await loadCatalogForGrid();
        if (curatedGrid) curatedGrid.hidden = true;
        renderDynamicGrid(filter);
      } catch (error) {
        if (dynamicGrid) { dynamicGrid.hidden = false; dynamicGrid.innerHTML = `<div class="catalog-filter-empty">${escapeHtmlCatalog(error.message || 'Não foi possível carregar os produtos.')}</div>`; }
      }
    });
  });

  let currentSlide = 0;
  let slideTimer = null;

  /* =========================================================
     CARROSSEL
     ========================================================= */
  function showSlide(index) {
    if (!slides.length) return;
    clearTimeout(slideTimer);

    slides.forEach((slide) => slide.classList.remove('active'));
    dots.forEach((dot) => dot.classList.remove('active'));

    currentSlide = (index + slides.length) % slides.length;
    slides[currentSlide].classList.add('active');
    dots[currentSlide]?.classList.add('active');

    /* Todos os banners administráveis avançam automaticamente a cada 5 s. */
    slideTimer = setTimeout(nextSlide, 5000);
  }

  function nextSlide() {
    showSlide(currentSlide + 1);
  }

  function prevSlide() {
    showSlide(currentSlide - 1);
  }

  /* Controles laterais. */
  nextBtn?.addEventListener('click', nextSlide);
  prevBtn?.addEventListener('click', prevSlide);

  /* Os indicadores recebem listeners após os banners ativos serem carregados. */

  /* Carrega os banners do Admin e inicializa o carrossel somente se houver slides. */
  carregarBannersPublicos().then(({ banners }) => {
    slides = [...document.querySelectorAll('.slide')];
    dots = [...document.querySelectorAll('.dot')];

    dots.forEach((dot) => {
      dot.addEventListener('click', () => {
        const slideIndex = Number(dot.getAttribute('data-slide'));
        showSlide(slideIndex);
      });
    });

    if (banners.length && slides.length) {
      showSlide(0);
    } else {
      clearTimeout(slideTimer);
    }
  });
});
