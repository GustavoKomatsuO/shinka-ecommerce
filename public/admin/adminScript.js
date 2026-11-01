/**
 * SHINKA — Admin Console.
 *
 * Responsabilidades:
 * - autenticação da sessão administrativa;
 * - permissões por função;
 * - catálogo;
 * - produtos;
 * - variações;
 * - cores;
 * - múltiplas imagens por cor;
 * - pedidos;
 * - estoque;
 * - clientes;
 * - biblioteca de imagens;
 * - campanhas;
 * - auditoria.
 */

document.addEventListener('DOMContentLoaded', async () => {
  const API = new URL('../index.php?route=', window.location.href).href;

  const sidebar = document.getElementById('sidebar');
  const alertBox = document.getElementById('adminAlert');
  const sections = [...document.querySelectorAll('.admin-section')];
  const links = [...document.querySelectorAll('.side-link[data-section]')];
  const pageTitle = document.getElementById('pageTitle');
  const productDialog = document.getElementById('productDialog');
  const historyDialog = document.getElementById('historyDialog');
  const productForm = document.getElementById('productForm');
  const historyContent = document.getElementById('historyContent');
  const variantList = document.getElementById('variantList');

  let csrf = '';
  let papelAtual = '';
  let produtos = [];
  let categorias = [];
  let categoriasAdmin = [];
  let imagens = [];
  let centros = [];
  let stockProducts = [];
  let stockCenters = [];
  let stockSubmitting = false;
  let variantes = [];
  let catalogFilters = { search: '', category: '', status: '', lowStock: false };
  let activeSection = 'dashboard';
  let dashboardPollingTimer = null;
  let dashboardPollingInFlight = false;
  const DASHBOARD_POLLING_INTERVAL = 2000;

  const roleLabel = {
    admin_master: 'Admin Master',
    catalogo: 'Gestão de Catálogo',
    logistica: 'Gestão de Logística',
    suporte: 'Suporte ao Cliente'
  };

  const titles = {
    dashboard: 'Visão geral',
    catalogo: 'Catálogo',
    loja: 'Loja / Categorias',
    pedidos: 'Pedidos / OMS',
    estoque: 'Estoque / CDs',
    clientes: 'Clientes / Suporte',
    imagens: 'Banco de imagens',
    campanhas: 'Campanhas',
    auditoria: 'Auditoria'
  };

  const rolePermissions = {
    admin_master: [
      'dashboard',
      'catalogo',
      'loja',
      'pedidos',
      'estoque',
      'clientes',
      'imagens',
      'campanhas',
      'auditoria'
    ],
    catalogo: [
      'dashboard',
      'catalogo',
      'loja',
      'imagens',
      'campanhas'
    ],
    logistica: [
      'dashboard',
      'pedidos',
      'estoque'
    ],
    suporte: [
      'dashboard',
      'pedidos',
      'clientes'
    ]
  };

  function showAlert(message, type = 'error') {
    alertBox.textContent = message;
    alertBox.className = message
      ? `alert show ${type}`
      : 'alert';
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(
      /[&<>'"]/g,
      char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#39;',
        '"': '&quot;'
      })[char]
    );
  }

  function money(value) {
    return Number(value || 0).toLocaleString(
      'pt-BR',
      {
        style: 'currency',
        currency: 'BRL'
      }
    );
  }

  function dateTime(value) {
    if (!value) return '—';

    return new Date(
      String(value).replace(' ', 'T')
    ).toLocaleString('pt-BR');
  }

  function statusBadge(status) {
    const labels = {
      aguardando_pagamento: 'Aguardando pagamento',
      pago: 'Pago',
      separacao: 'Em separação',
      enviado: 'Enviado',
      entregue: 'Entregue',
      cancelado: 'Cancelado',
      estornado: 'Estornado'
    };

    const danger = [
      'cancelado',
      'estornado'
    ].includes(status);

    const warning = [
      'aguardando_pagamento',
      'separacao'
    ].includes(status);

    const css = danger
      ? 'danger'
      : warning
        ? 'warn'
        : 'ok';

    return `
      <span class="status ${css}">
        ${labels[status] || escapeHtml(status)}
      </span>
    `;
  }

  async function getJson(url) {
    const response = await fetch(
      url,
      {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          Accept: 'application/json'
        }
      }
    );

    const text = await response.text();

    let data;

    try {
      data = JSON.parse(text);
    } catch (error) {
      console.error('Resposta recebida:', text);

      throw new Error(
        'O servidor retornou uma resposta inválida.'
      );
    }

    if (!response.ok || data.success === false) {
      const error = new Error(
        data.message ||
        'Erro na comunicação com o servidor.'
      );

      error.status =
        response.status;

      throw error;
    }

    return data;
  }

  async function postForm(
    url,
    formData
  ) {
    if (csrf) {
      formData.append(
        'csrf_token',
        csrf
      );
    }

    const response = await fetch(
      url,
      {
        method: 'POST',
        credentials: 'same-origin',
        body: formData
      }
    );

    const text = await response.text();

    let data;

    try {
      data = JSON.parse(text);
    } catch (error) {
      console.error(
        'Resposta recebida:',
        text
      );

      throw new Error(
        'O servidor retornou uma resposta inválida.'
      );
    }

    if (!response.ok || data.success === false) {
      const error = new Error(
        data.message ||
        'Não foi possível concluir a operação.'
      );

      error.status =
        response.status;

      throw error;
    }

    return data;
  }

  async function initSession() {
    try {
      const me = await getJson(
        `${API}api/admin/me`
      );

      papelAtual =
        me.usuario.papel;

      document.getElementById(
        'adminName'
      ).textContent =
        me.usuario.nome;

      document.getElementById(
        'adminRole'
      ).textContent =
        roleLabel[papelAtual] ||
        papelAtual;

      const token =
        await getJson(
          `${API}api/csrf`
        );

      csrf =
        token.token;

      applyPermissions();
    } catch (error) {
      window.location.href =
        'login.html';

      throw error;
    }
  }

  function applyPermissions() {
    const allowed =
      rolePermissions[papelAtual] ||
      ['dashboard'];

    links.forEach(link => {
      link.hidden =
        !allowed.includes(
          link.dataset.section
        );
    });

    sections.forEach(section => {
      section.hidden =
        !allowed.includes(
          section.id.replace(
            'section-',
            ''
          )
        );
    });
  }

  function showSection(name) {
    const allowed =
      rolePermissions[papelAtual] ||
      [];

    const target =
      allowed.includes(name)
        ? name
        : 'dashboard';

    activeSection = target;

    sections.forEach(section => {
      section.classList.toggle(
        'active',
        section.id ===
          `section-${target}`
      );
    });

    links.forEach(link => {
      link.classList.toggle(
        'active',
        link.dataset.section ===
          target
      );
    });

    pageTitle.textContent =
      titles[target] ||
      target;

    sidebar.classList.remove(
      'open'
    );

    if (
      location.hash !==
      `#${target}`
    ) {
      history.replaceState(
        null,
        '',
        `#${target}`
      );
    }

    loadSection(target)
      .catch(error =>
        showAlert(
          error.message
        )
      );
  }

  async function loadDashboard(options = {}) {
    const data =
      await getJson(
        `${API}api/admin/dashboard`
      );

    document.getElementById(
      'kpiClientes'
    ).textContent =
      data.indicadores.clientes;

    document.getElementById(
      'kpiProdutos'
    ).textContent =
      data.indicadores.produtos;

    document.getElementById(
      'kpiPedidos'
    ).textContent =
      data.indicadores.pedidosAbertos;

    document.getElementById(
      'kpiFaturamento'
    ).textContent =
      money(
        data.indicadores.faturamento
      );

    document.getElementById(
      'recentOrders'
    ).innerHTML =
      data.pedidos.length
        ? data.pedidos
            .map(
              order => `
                <tr>
                  <td>#${order.id}</td>
                  <td>${escapeHtml(order.cliente)}</td>
                  <td>${statusBadge(order.status)}</td>
                  <td>${money(order.total)}</td>
                </tr>
              `
            )
            .join('')
        : `
          <tr>
            <td colspan="4" class="empty">
              Nenhum pedido.
            </td>
          </tr>
        `;

    document.getElementById(
      'lowStockList'
    ).innerHTML =
      data.estoque_baixo.length
        ? data.estoque_baixo
            .map(
              product => `
                <div class="stock-row">
                  <strong>
                    ${escapeHtml(product.nome)}
                  </strong>

                  <span>
                    ${product.estoque}
                    un. /
                    mín.
                    ${product.estoque_minimo}
                  </span>
                </div>
              `
            )
            .join('')
        : `
          <p class="empty">
            Nenhum estoque crítico.
          </p>
        `;

    if (options.polling) {
      updatePollingStatus('success');
    }
  }

  function updatePollingStatus(state = 'success') {
    const status = document.getElementById('adminPollingStatus');
    const dot = document.getElementById('adminPollingDot');
    if (!status || !dot) return;

    const now = new Date();
    const time = now.toLocaleTimeString('pt-BR');

    if (state === 'loading') {
      dot.className = 'status-dot polling-loading';
      status.textContent = 'Atualizando…';
      return;
    }

    if (state === 'error') {
      dot.className = 'status-dot polling-error';
      status.textContent = `Atualização pendente • ${time}`;
      return;
    }

    dot.className = 'status-dot polling-ok';
    status.textContent = `Atualizado às ${time}`;
  }

  async function pollDashboard() {
    if (dashboardPollingInFlight || activeSection !== 'dashboard') {
      return;
    }

    if (document.visibilityState !== 'visible') {
      return;
    }

    dashboardPollingInFlight = true;
    updatePollingStatus('loading');

    try {
      await loadDashboard({ polling: true });
    } catch (error) {
      console.warn('Polling do dashboard:', error.message);
      updatePollingStatus('error');
    } finally {
      dashboardPollingInFlight = false;
    }
  }

  function startDashboardPolling() {
    if (dashboardPollingTimer) {
      clearInterval(dashboardPollingTimer);
    }

    dashboardPollingTimer = setInterval(
      pollDashboard,
      DASHBOARD_POLLING_INTERVAL
    );
  }

  async function loadCatalog() {
    if (!['admin_master', 'catalogo'].includes(papelAtual)) return;

    const data = await getJson(`${API}api/admin/produtos`);
    produtos = Array.isArray(data.produtos) ? data.produtos : [];
    categorias = Array.isArray(data.categorias) ? data.categorias : [];

    fillProductFormOptions();
    fillCatalogFilters();
    renderCatalog();
  }

  function fillCatalogFilters() {
    const categoryFilter = document.getElementById('catalogCategoryFilter');
    if (!categoryFilter) return;
    const current = catalogFilters.category;
    categoryFilter.innerHTML = `
      <option value="">Todas as categorias</option>
      ${categorias.map(category => `<option value="${category.id}">${escapeHtml(category.nome)}</option>`).join('')}
    `;
    categoryFilter.value = current;

    const search = document.getElementById('catalogSearch');
    const status = document.getElementById('catalogStatusFilter');
    const low = document.getElementById('catalogLowStockFilter');
    if (search) search.value = catalogFilters.search;
    if (status) status.value = catalogFilters.status;
    if (low) low.checked = catalogFilters.lowStock;
  }

  function filteredCatalogProducts() {
    const query = catalogFilters.search.trim().toLocaleLowerCase('pt-BR');
    return produtos.filter(product => {
      const haystack = [
        product.nome,
        product.slug,
        product.familia,
        product.categoria_nome,
        product.descricao,
        product.especificacoes,
        product.badge,
        ...(Array.isArray(product.variantes) ? product.variantes.flatMap(v => [v.nome, v.sku, v.cor_nome]) : [])
      ].filter(Boolean).join(' ').toLocaleLowerCase('pt-BR');

      if (query && !haystack.includes(query)) return false;
      if (catalogFilters.category && String(product.categoria_id) !== String(catalogFilters.category)) return false;
      if (catalogFilters.status === 'ativo' && Number(product.ativo) !== 1) return false;
      if (catalogFilters.status === 'inativo' && Number(product.ativo) === 1) return false;
      if (catalogFilters.lowStock && Number(product.estoque) > Number(product.estoque_minimo)) return false;
      return true;
    });
  }

  function renderCatalog() {
    const body = document.getElementById('productTableBody');
    if (!body) return;

    const visible = filteredCatalogProducts();
    const count = document.getElementById('catalogResultCount');
    if (count) count.textContent = `${visible.length} ${visible.length === 1 ? 'produto' : 'produtos'}`;

    body.innerHTML = visible.length ? visible.map(product => {
      const totalVariantes = Array.isArray(product.variantes) ? product.variantes.length : 0;
      const isLow = Number(product.estoque) <= Number(product.estoque_minimo);
      return `
        <tr>
          <td>
            <div class="product-table-name"><strong>${escapeHtml(product.nome)}</strong><small>${escapeHtml(product.slug || '')}</small></div>
          </td>
          <td>${escapeHtml(product.categoria_nome || '—')}</td>
          <td>${totalVariantes} ${totalVariantes === 1 ? 'cor' : 'cores'}</td>
          <td>${money(product.preco_promocional || product.preco)}</td>
          <td><span class="stock-value ${isLow ? 'low' : ''}">${product.estoque}</span></td>
          <td>${Number(product.ativo) === 1 ? '<span class="status ok">Ativo</span>' : '<span class="status danger">Inativo</span>'}</td>
          <td class="table-actions">
            <button class="action-button" type="button" data-edit="${product.id}">Editar</button>
            ${papelAtual === 'admin_master' ? `<button class="action-button danger" type="button" data-delete="${product.id}">Excluir</button>` : ''}
          </td>
        </tr>
      `;
    }).join('') : '<tr><td colspan="7" class="empty">Nenhum produto corresponde aos filtros.</td></tr>';

    body.querySelectorAll('[data-edit]').forEach(button => button.addEventListener('click', () => openProduct(Number(button.dataset.edit))));
    body.querySelectorAll('[data-delete]').forEach(button => button.addEventListener('click', () => deleteProduct(Number(button.dataset.delete))));
  }

  function bindCatalogFilters() {
    const search = document.getElementById('catalogSearch');
    const category = document.getElementById('catalogCategoryFilter');
    const status = document.getElementById('catalogStatusFilter');
    const lowStock = document.getElementById('catalogLowStockFilter');
    const clear = document.getElementById('catalogClearFilters');
    search?.addEventListener('input', event => { catalogFilters.search = event.target.value; renderCatalog(); });
    category?.addEventListener('change', event => { catalogFilters.category = event.target.value; renderCatalog(); });
    status?.addEventListener('change', event => { catalogFilters.status = event.target.value; renderCatalog(); });
    lowStock?.addEventListener('change', event => { catalogFilters.lowStock = event.target.checked; renderCatalog(); });
    clear?.addEventListener('click', () => {
      catalogFilters = { search: '', category: '', status: '', lowStock: false };
      fillCatalogFilters();
      renderCatalog();
    });
  }

  function fillProductFormOptions() {
    const categorySelect =
      document.getElementById(
        'productCategory'
      );

    const imageSelect =
      document.getElementById(
        'productImageId'
      );

    categorySelect.innerHTML = `
      <option value="">
        Selecione uma categoria
      </option>

      ${categorias
        .map(
          category => `
            <option
              value="${category.id}"
            >
              ${escapeHtml(
                category.nome
              )}
            </option>
          `
        )
        .join('')}
    `;

    imageSelect.innerHTML = `
      <option value="">
        Sem imagem
      </option>

      ${imagens
        .map(
          image => `
            <option
              value="${image.id}"
            >
              #${image.id} —
              ${escapeHtml(
                image.nome_arquivo
              )}
            </option>
          `
        )
        .join('')}
    `;
  }

  function resetProductForm() {
    productForm.reset();

    document.getElementById(
      'productId'
    ).value = '';

    document.getElementById(
      'productActive'
    ).checked = true;

    document.getElementById(
      'dialogTitle'
    ).textContent =
      'Novo produto';

    variantes = [];

    renderVariants();

    fillProductFormOptions();
  }

  function openProduct(id = 0) {
    resetProductForm();

    if (!id) {
      productDialog.showModal();
      return;
    }

    const product =
      produtos.find(
        item =>
          Number(item.id) ===
          id
      );

    if (!product) {
      return;
    }

    document.getElementById(
      'dialogTitle'
    ).textContent =
      'Editar produto';

    document.getElementById(
      'productId'
    ).value =
      product.id;

    document.getElementById(
      'productCategory'
    ).value =
      product.categoria_id;

    document.getElementById(
      'productName'
    ).value =
      product.nome || '';

    document.getElementById(
      'productSlug'
    ).value =
      product.slug || '';

    document.getElementById(
      'productFamily'
    ).value =
      product.familia || '';

    document.getElementById(
      'productDescription'
    ).value =
      product.descricao || '';

    document.getElementById(
      'productSpecs'
    ).value =
      product.especificacoes || '';

    document.getElementById(
      'productPrice'
    ).value =
      product.preco || '';

    document.getElementById(
      'productPromoPrice'
    ).value =
      product.preco_promocional || '';

    document.getElementById(
      'productOldPrice'
    ).value =
      product.preco_anterior || '';

    document.getElementById(
      'productBadge'
    ).value =
      product.badge || '';
    document.getElementById('productWeight').value = product.peso_kg ?? '';
    document.getElementById('productHeight').value = product.altura_cm ?? '';
    document.getElementById('productWidth').value = product.largura_cm ?? '';
    document.getElementById('productLength').value = product.comprimento_cm ?? '';

    document.getElementById(
      'productImageId'
    ).value =
      product.imagem_id || '';

    document.getElementById(
      'productFeatured'
    ).checked =
      Number(
        product.destaque
      ) === 1;

    document.getElementById(
      'productActive'
    ).checked =
      Number(
        product.ativo
      ) === 1;

    variantes =
      Array.isArray(
        product.variantes
      )
        ? product.variantes.map(
            variant => ({
              id:
                Number(
                  variant.id || 0
                ),
              nome:
                variant.nome || '',
              sku:
                variant.sku || '',
              cor_nome:
                variant.cor_nome || '',
              cor_hex:
                variant.cor_hex || '',
              swatch_imagem_id:
                Number(variant.swatch_imagem_id || 0),
              imagens:
                Array.isArray(
                  variant.imagens
                )
                  ? variant.imagens.map(
                      image => ({
                        id: Number(image.id || image.imagem_id || 0),
                        principal: Number(image.principal || 0),
                        alt_text: image.alt_text || image.biblioteca_alt_text || '',
                        tipo_midia: image.tipo_midia || 'imagem',
                        midia_url: image.midia_url || ''
                      })
                    )
                  : []
            })
          )
        : [];

    renderVariants();

    productDialog.showModal();
  }

  function renderVariants() {
    if (!variantList) return;

    if (!variantes.length) {
      variantList.innerHTML = `
        <div class="variant-empty variant-empty-enhanced">
          <div class="variant-empty-icon">＋</div>
          <strong>Nenhuma cor adicionada</strong>
          <span>Adicione uma cor para definir nome, SKU, swatch e fotos específicas.</span>
        </div>
      `;
      return;
    }

    variantList.innerHTML = variantes.map((variant, variantIndex) => {
      const images = Array.isArray(variant.imagens)
        ? variant.imagens
        : [];

      const swatch = Number(
        variant.swatch_imagem_id || 0
      );

      const colorValue =
        /^#[0-9A-Fa-f]{6}$/.test(variant.cor_hex)
          ? variant.cor_hex
          : '#D5D9E0';

      const title =
        variant.cor_nome ||
        variant.nome ||
        'Nova cor';

      const swatchThumb = swatch
        ? `
            <img
              src="../index.php?route=api/imagem&id=${swatch}&preset=thumb"
              alt="Swatch de ${escapeHtml(title)}"
            >
          `
        : `
            <span class="variant-swatch-empty">•</span>
          `;

      return `
        <article
          class="variant-card variant-card-clean"
          data-variant-index="${variantIndex}"
        >
          <header class="variant-card-head variant-card-head-clean">
            <div class="variant-heading-clean">
              <span
                class="variant-color-preview"
                style="--variant-color:${colorValue}"
                data-color-preview="${variantIndex}"
              ></span>

              <div>
                <span class="panel-kicker">
                  COR ${String(variantIndex + 1).padStart(2, '0')}
                </span>

                <h4>
                  ${escapeHtml(title)}
                </h4>

                <small>
                  ${variant.sku ? `SKU ${escapeHtml(variant.sku)}` : 'SKU ainda não definido'}
                </small>
              </div>
            </div>

            <button
              type="button"
              class="action-button danger"
              data-remove-variant="${variantIndex}"
            >
              Remover
            </button>
          </header>

          <div class="variant-form-grid variant-form-grid-clean">
            <label>
              <span>Nome da cor</span>
              <input
                type="text"
                data-variant-color-name="${variantIndex}"
                value="${escapeHtml(variant.cor_nome || variant.nome)}"
                maxlength="100"
                placeholder="Ex.: Verde Titânio"
              >
            </label>

            <label>
              <span>SKU</span>
              <input
                type="text"
                data-variant-sku="${variantIndex}"
                value="${escapeHtml(variant.sku)}"
                maxlength="100"
                placeholder="Ex.: MIRAI-VD-256"
              >
            </label>

            <label class="variant-color-field">
              <span>Cor visual</span>

              <div class="variant-color-control">
                <input
                  type="color"
                  data-variant-color="${variantIndex}"
                  value="${colorValue}"
                  aria-label="Selecionar cor"
                >

                <code data-color-value="${variantIndex}">
                  ${colorValue}
                </code>
              </div>
            </label>
          </div>

          <div class="variant-swatch-panel">
            <div class="variant-swatch-preview">
              ${swatchThumb}
            </div>

            <div class="variant-swatch-copy">
              <strong>Swatch da cor</strong>
              <small>
                Use uma imagem quadrada para a amostra visual do produto.
              </small>
            </div>

            <div class="variant-swatch-actions">
              <button
                type="button"
                class="swatch-select"
                data-select-swatch="${variantIndex}"
              >
                ${swatch ? 'Trocar imagem' : 'Biblioteca'}
              </button>

              <label class="swatch-upload">
                Enviar
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/webp,image/avif"
                  data-swatch-upload="${variantIndex}"
                >
              </label>
            </div>
          </div>

          <div class="variant-images variant-images-clean">
            <div class="variant-images-head">
              <div>
                <span class="panel-kicker">FOTOS DA COR</span>
                <strong>
                  ${images.length}
                  ${images.length === 1 ? 'foto vinculada' : 'fotos vinculadas'}
                </strong>
                <small class="variant-help">
                  Estas fotos ficam vinculadas somente a esta cor.
                </small>
              </div>

              <button
                type="button"
                class="ghost-button auto"
                data-add-image="${variantIndex}"
              >
                Biblioteca
              </button>
            </div>

            <div
              class="sku-matrix-dropzone variant-dropzone-clean"
              data-variant-drop="${variantIndex}"
            >
              <strong>
                Arraste fotos desta cor para cá
              </strong>

              <span>
                JPG, PNG, WEBP ou AVIF
              </span>

              <input
                type="file"
                accept="image/jpeg,image/png,image/webp,image/avif"
                multiple
                data-variant-upload="${variantIndex}"
              >
            </div>

            <div
              class="selected-image-grid"
              data-variant-images="${variantIndex}"
            >
              ${images.length
                ? images.map((image, imageIndex) => `
                    <article
                      class="selected-image sku-media-card"
                      draggable="true"
                      data-drag-image="${variantIndex}:${imageIndex}"
                    >
                      <div
                        class="selected-image-grip"
                        title="Arraste para reordenar"
                      >☷</div>

                      <img
                        src="../index.php?route=api/imagem&id=${image.id}&preset=thumb"
                        alt="${escapeHtml(
                          image.alt_text ||
                          variant.cor_nome ||
                          variant.nome ||
                          'Produto SHINKA'
                        )}"
                      >

                      <div class="selected-image-info">
                        <span>#${image.id}</span>
                        <strong>
                          ${
                            Number(image.principal) === 1
                              ? 'Principal'
                              : `Posição ${imageIndex + 1}`
                          }
                        </strong>
                      </div>

                      <label class="media-alt-field">
                        <span>ALT</span>
                        <input
                          type="text"
                          value="${escapeHtml(
                            image.alt_text ||
                            `${variant.nome || variant.cor_nome || 'Produto SHINKA'} — visão ${imageIndex + 1}`
                          )}"
                          data-image-alt="${variantIndex}:${imageIndex}"
                          maxlength="255"
                        >
                      </label>

                      <div class="media-type-row">
                        <label>
                          Tipo
                          <select data-image-type="${variantIndex}:${imageIndex}">
                            <option value="imagem" ${image.tipo_midia === 'imagem' ? 'selected' : ''}>Foto</option>
                            <option value="modelo_3d" ${image.tipo_midia === 'modelo_3d' ? 'selected' : ''}>Modelo 3D</option>
                            <option value="video" ${image.tipo_midia === 'video' ? 'selected' : ''}>Vídeo</option>
                          </select>
                        </label>

                        <input
                          type="url"
                          value="${escapeHtml(image.midia_url || '')}"
                          placeholder="URL opcional"
                          data-image-url="${variantIndex}:${imageIndex}"
                        >
                      </div>

                      <div class="selected-image-actions">
                        ${
                          Number(image.principal) !== 1
                            ? `<button type="button" class="mini-button" data-set-primary-image="${variantIndex}:${imageIndex}">Principal</button>`
                            : '<span class="media-primary-badge">Thumbnail</span>'
                        }

                        <button
                          type="button"
                          class="mini-button danger"
                          data-remove-image="${variantIndex}:${imageIndex}"
                        >
                          Remover
                        </button>
                      </div>
                    </article>
                  `).join('')
                : `
                    <p class="variant-empty">
                      Nenhuma foto vinculada a esta cor.
                    </p>
                  `
              }
            </div>
          </div>
        </article>
      `;
    }).join('');

    bindVariantEvents();
  }

  async function uploadVariantImages(variantIndex, files) {
    const variant = variantes[variantIndex];
    if (!variant || !files.length) return;
    const base = variant.nome || variant.cor_nome || 'Produto SHINKA';
    const total = files.length;
    let uploaded = 0;
    for (const file of files) {
      const fd = new FormData();
      fd.append('imagem', file);
      fd.append('alt_text', `${base} — foto ${variant.imagens.length + 1}`);
      fd.append('uso', 'produto');
      try {
        const data = await postForm(`${API}api/admin/imagem/upload`, fd);
        variant.imagens.push({ id: Number(data.id), principal: variant.imagens.length === 0 ? 1 : 0, alt_text: `${base} — foto ${variant.imagens.length + 1}`, tipo_midia: 'imagem', midia_url: '' });
        uploaded += 1;
      } catch (error) {
        showAlert(`Não foi possível enviar ${file.name}: ${error.message}`);
      }
    }
    if (uploaded) {
      showAlert(`${uploaded}/${total} imagem(ns) adicionada(s) à cor ${base}.`, 'success');
      await loadImages();
      renderVariants();
    }
  }

  async function uploadVariantSwatch(variantIndex, file) {
    const variant = variantes[variantIndex];
    if (!variant || !file) return;
    const fd = new FormData();
    fd.append('imagem', file);
    fd.append('alt_text', `${variant.nome || variant.cor_nome || 'Produto SHINKA'} — swatch`);
    fd.append('uso', 'produto');
    try {
      const data = await postForm(`${API}api/admin/imagem/upload`, fd);
      variant.swatch_imagem_id = Number(data.id);
      showAlert('Swatch da variante atualizado.', 'success');
      await loadImages();
      renderVariants();
    } catch (error) {
      showAlert(`Não foi possível enviar o swatch: ${error.message}`);
    }
  }

  function bindVariantEvents() {
    document.querySelectorAll('[data-remove-variant]').forEach(button => button.addEventListener('click', () => {
      variantes.splice(Number(button.dataset.removeVariant), 1);
      renderVariants();
    }));

    document.querySelectorAll('[data-add-image]').forEach(button => button.addEventListener('click', () => openImageSelector(Number(button.dataset.addImage))));

    document.querySelectorAll('[data-variant-upload]').forEach(input => input.addEventListener('change', async () => {
      const index = Number(input.dataset.variantUpload);
      await uploadVariantImages(index, [...input.files]);
      input.value = '';
    }));

    document.querySelectorAll('[data-variant-drop]').forEach(zone => {
      const index = Number(zone.dataset.variantDrop);
      ['dragenter','dragover'].forEach(eventName => zone.addEventListener(eventName, event => { event.preventDefault(); zone.classList.add('is-dragging'); }));
      ['dragleave','drop'].forEach(eventName => zone.addEventListener(eventName, event => { event.preventDefault(); zone.classList.remove('is-dragging'); }));
      zone.addEventListener('drop', async event => { await uploadVariantImages(index, [...(event.dataTransfer?.files || [])]); });
    });

    document.querySelectorAll('[data-remove-image]').forEach(button => button.addEventListener('click', () => {
      const [vi, ii] = button.dataset.removeImage.split(':').map(Number);
      variantes[vi]?.imagens.splice(ii, 1);
      const imgs = variantes[vi]?.imagens || [];
      if (imgs.length && !imgs.some(x => Number(x.principal) === 1)) imgs[0].principal = 1;
      renderVariants();
    }));

    document.querySelectorAll('[data-set-primary-image]').forEach(button => button.addEventListener('click', () => {
      const [vi, ii] = button.dataset.setPrimaryImage.split(':').map(Number);
      const imgs = variantes[vi]?.imagens || [];
      imgs.forEach(x => x.principal = 0); if (imgs[ii]) imgs[ii].principal = 1;
      renderVariants();
    }));

    document.querySelectorAll('[data-image-alt]').forEach(input => input.addEventListener('input', () => {
      const [vi, ii] = input.dataset.imageAlt.split(':').map(Number); if (variantes[vi]?.imagens[ii]) variantes[vi].imagens[ii].alt_text = input.value;
    }));
    document.querySelectorAll('[data-image-type]').forEach(input => input.addEventListener('change', () => {
      const [vi, ii] = input.dataset.imageType.split(':').map(Number); if (variantes[vi]?.imagens[ii]) variantes[vi].imagens[ii].tipo_midia = input.value;
    }));
    document.querySelectorAll('[data-image-url]').forEach(input => input.addEventListener('input', () => {
      const [vi, ii] = input.dataset.imageUrl.split(':').map(Number); if (variantes[vi]?.imagens[ii]) variantes[vi].imagens[ii].midia_url = input.value;
    }));

    document.querySelectorAll('[data-variant-name]').forEach(input => input.addEventListener('input', () => { const i=Number(input.dataset.variantName); if(variantes[i]) variantes[i].nome=input.value; }));
    document.querySelectorAll('[data-variant-sku]').forEach(input => input.addEventListener('input', () => { const i=Number(input.dataset.variantSku); if(variantes[i]) variantes[i].sku=input.value; }));
    document.querySelectorAll('[data-variant-color-name]').forEach(input => input.addEventListener('input', () => {
      const i = Number(input.dataset.variantColorName);
      if (!variantes[i]) return;
      variantes[i].cor_nome = input.value;
      variantes[i].nome = input.value;
      const title = document.querySelector(`[data-variant-index=\"${i}\"] h4`);
      if (title) title.textContent = input.value.trim() || 'Nova cor';
    }));
    document.querySelectorAll('[data-variant-color]').forEach(input => input.addEventListener('input', () => {
      const i = Number(input.dataset.variantColor);
      if (!variantes[i]) return;
      variantes[i].cor_hex = input.value;
      const out = document.querySelector(`[data-color-value=\"${i}\"]`);
      if (out) out.textContent = input.value;
      const preview = document.querySelector(`[data-color-preview=\"${i}\"]`);
      if (preview) preview.style.setProperty('--variant-color', input.value);
    }));
    document.querySelectorAll('[data-select-swatch]').forEach(button => button.addEventListener('click', () => openImageSelector(Number(button.dataset.selectSwatch), true)));
    document.querySelectorAll('[data-swatch-upload]').forEach(input => input.addEventListener('change', async () => { const i=Number(input.dataset.swatchUpload); await uploadVariantSwatch(i, input.files?.[0]); input.value=''; }));

    document.querySelectorAll('[data-drag-image]').forEach(card => {
      card.addEventListener('dragstart', event => { event.dataTransfer.setData('text/plain', card.dataset.dragImage); card.classList.add('is-dragging'); });
      card.addEventListener('dragend', () => card.classList.remove('is-dragging'));
      card.addEventListener('dragover', event => event.preventDefault());
      card.addEventListener('drop', event => {
        event.preventDefault();
        const data = event.dataTransfer.getData('text/plain'); if(!data) return;
        const [fromV, fromI] = data.split(':').map(Number); const toI=Number(card.dataset.dragImage.split(':')[1]);
        if(fromV===Number(card.dataset.dragImage.split(':')[0]) && fromI!==toI){
          const list=variantes[fromV]?.imagens; if(list){ const [moved]=list.splice(fromI,1); list.splice(toI,0,moved); list.forEach((x,i)=>x.principal=i===0?1:Number(x.principal&&x.principal)); if(!list.some(x=>Number(x.principal)===1)) list[0].principal=1; renderVariants(); }
        }
      });
    });
  }

  function addVariant() {
    variantes.push({
      id: 0,
      nome: '',
      sku: '',
      cor_nome: '',
      cor_hex: '#D5D9E0',
      swatch_imagem_id: 0,
      imagens: []
    });

    renderVariants();
  }

  function openImageSelector(
    variantIndex,
    swatchOnly = false
  ) {
    if (!imagens.length) {
      showAlert(
        'Cadastre imagens primeiro no Banco de imagens.'
      );

      return;
    }

    const selectedIds =
      variantes[
        variantIndex
      ]?.imagens.map(
        image => Number(image.id)
      ) || [];

    const html =
      imagens
        .map(
          image => `
            <button
              type="button"
              class="image-selector-item ${
                selectedIds.includes(
                  Number(image.id)
                )
                  ? 'selected'
                  : ''
              }"
              data-select-library-image="${image.id}"
            >
              <img
                src="../index.php?route=api/imagem&id=${image.id}"
                alt="${escapeHtml(
                  image.alt_text ||
                  image.nome_arquivo
                )}"
              >

              <span>
                #${image.id}
              </span>
            </button>
          `
        )
        .join('');

    const dialog =
      document.createElement(
        'dialog'
      );

    dialog.className =
      'image-selector-dialog';

    dialog.innerHTML = `
      <section>
        <header class="dialog-head">
          <div>
            <span class="panel-kicker">
              BIBLIOTECA SHINKA
            </span>

            <h2>
              Selecionar imagem
            </h2>
          </div>

          <button
            type="button"
            class="close-button"
            data-close-selector
          >
            ×
          </button>
        </header>

        <div class="image-selector-grid">
          ${html}
        </div>
      </section>
    `;

    document.body.appendChild(
      dialog
    );

    dialog
      .querySelectorAll(
        '[data-select-library-image]'
      )
      .forEach(button => {
        button.addEventListener(
          'click',
          () => {
            const imageId =
              Number(
                button.dataset
                  .selectLibraryImage
              );

            if (
              !variantes[
                variantIndex
              ]
            ) {
              return;
            }

            const exists =
              variantes[
                variantIndex
              ].imagens.some(
                image =>
                  Number(
                    image.id
                  ) === imageId
              );

            if (swatchOnly) {
              variantes[variantIndex].swatch_imagem_id = imageId;
              dialog.close();
              dialog.remove();
              renderVariants();
              return;
            }

            if (!exists) {
              variantes[variantIndex].imagens.push({
                id: imageId,
                principal: variantes[variantIndex].imagens.length === 0 ? 1 : 0,
                alt_text: `${variantes[variantIndex].nome || variantes[variantIndex].cor_nome || 'Produto SHINKA'} — visão ${variantes[variantIndex].imagens.length + 1}`,
                tipo_midia: 'imagem',
                midia_url: ''
              });
            }

            dialog.close();
            dialog.remove();

            renderVariants();
          }
        );
      });

    dialog
      .querySelector(
        '[data-close-selector]'
      )
      .addEventListener(
        'click',
        () => {
          dialog.close();
          dialog.remove();
        }
      );

    dialog.addEventListener(
      'close',
      () => {
        if (
          document.body.contains(
            dialog
          )
        ) {
          dialog.remove();
        }
      }
    );

    dialog.showModal();
  }

  async function saveProduct(event) {
    event.preventDefault();


    const formData =
      new FormData(
        productForm
      );

    const validVariants =
      variantes.map(
        variant => ({
          id:
            Number(
              variant.id || 0
            ),
          nome:
            String(
              variant.nome || ''
            ).trim(),
          sku:
            String(
              variant.sku || ''
            ).trim(),
          cor_nome:
            String(
              variant.cor_nome || ''
            ).trim(),
          cor_hex:
            String(variant.cor_hex || '').trim(),
          swatch_imagem_id: Number(variant.swatch_imagem_id || 0),
          imagens:
            Array.isArray(
              variant.imagens
            )
              ? variant.imagens.map(image => ({
                  id: Number(image.id),
                  principal: Number(image.principal || 0),
                  alt_text: String(image.alt_text || '').trim(),
                  tipo_midia: ['imagem','modelo_3d','video'].includes(image.tipo_midia) ? image.tipo_midia : 'imagem',
                  midia_url: String(image.midia_url || '').trim()
                }))
              : []
        })
      )
      .filter(
        variant =>
          variant.nome ||
          variant.cor_nome ||
          variant.imagens.length
      );

    formData.set(
      'variantes',
      JSON.stringify(
        validVariants
      )
    );

    try {
      await postForm(
        `${API}api/admin/produto/salvar`,
        formData
      );

      productDialog.close();

      showAlert(
        'Produto salvo com sucesso.',
        'success'
      );

      await loadImages();
      await loadCatalog();
      await loadDashboard();
    } catch (error) {
      showAlert(
        error.message
      );
    }
  }

  async function deleteProduct(id) {
    if (
      !confirm(
        'Excluir este produto?'
      )
    ) {
      return;
    }

    const formData =
      new FormData();

    formData.append(
      'id',
      id
    );

    try {
      await postForm(
        `${API}api/admin/produto/excluir`,
        formData
      );

      showAlert(
        'Produto excluído.',
        'success'
      );

      await loadCatalog();
      await loadDashboard();
    } catch (error) {
      showAlert(
        error.message
      );
    }
  }

  async function loadOrders() {
    if (
      ![
        'admin_master',
        'logistica',
        'suporte'
      ].includes(papelAtual)
    ) {
      return;
    }

    const data =
      await getJson(
        `${API}api/admin/pedidos`
      );

    const canEdit =
      [
        'admin_master',
        'logistica'
      ].includes(
        papelAtual
      );

    document.getElementById(
      'ordersTableBody'
    ).innerHTML =
      data.pedidos.length
        ? data.pedidos
            .map(order => {
              const statuses = [
                'aguardando_pagamento',
                'pago',
                'separacao',
                'enviado',
                'entregue',
                'cancelado',
                'estornado'
              ];

              const selector =
                canEdit
                  ? `
                    <select
                      class="order-status"
                      data-status-id="${order.id}"
                    >
                      ${statuses
                        .map(
                          status => `
                            <option
                              value="${status}"
                              ${
                                status ===
                                order.status
                                  ? 'selected'
                                  : ''
                              }
                            >
                              ${
                                status.replaceAll(
                                  '_',
                                  ' '
                                )
                              }
                            </option>
                          `
                        )
                        .join('')}
                    </select>
                  `
                  : statusBadge(
                      order.status
                    );

              return `
                <tr>
                  <td>
                    #${order.id}
                    <br>
                    <small>
                      ${dateTime(
                        order.criado_em
                      )}
                    </small>
                  </td>

                  <td>
                    ${escapeHtml(
                      order.cliente
                    )}
                    <br>
                    <small>
                      ${escapeHtml(
                        order.email
                      )}
                    </small>
                  </td>

                  <td>
                    ${selector}
                  </td>

                  <td>
                    ${money(
                      order.total
                    )}
                  </td>

                  <td>
                    ${canEdit ? `
                      <div class="delivery-editor">
                        <input class="delivery-code" data-delivery-code="${order.id}" value="${escapeHtml(order.codigo_rastreio || '')}" placeholder="AA123456789BR" maxlength="13">
                        <input class="delivery-date" data-delivery-date="${order.id}" type="date" value="${escapeHtml(order.previsao_entrega || '')}">
                        <button class="action-button" type="button" data-save-delivery="${order.id}">Rastreio</button>
                      </div>
                    ` : `${escapeHtml(order.transportadora || '—')}<br><small>${escapeHtml(order.codigo_rastreio || '')}</small>`}
                  </td>

                  <td>
                    ${
                      canEdit
                        ? `
                          <button
                            class="action-button"
                            type="button"
                            data-save-order="${order.id}"
                          >
                            Salvar
                          </button>
                        `
                        : '—'
                    }
                  </td>
                </tr>
              `;
            })
            .join('')
        : `
          <tr>
            <td colspan="6" class="empty">
              Nenhum pedido.
            </td>
          </tr>
        `;

    document
      .querySelectorAll(
        '[data-save-order]'
      )
      .forEach(button => {
        button.addEventListener(
          'click',
          async () => {
            const id =
              button.dataset
                .saveOrder;

            const select =
              document.querySelector(
                `[data-status-id="${id}"]`
              );

            const formData =
              new FormData();

            formData.append(
              'id',
              id
            );

            formData.append(
              'status',
              select.value
            );

            try {
              await postForm(
                `${API}api/admin/pedido-status`,
                formData
              );

              showAlert(
                'Pedido atualizado.',
                'success'
              );

              await loadOrders();
              await loadDashboard();
            } catch (error) {
              showAlert(
                error.message
              );
            }
          }
        );
      });
  }

    document.querySelectorAll('[data-save-delivery]').forEach(button => {
      button.addEventListener('click', async () => {
        const id = button.dataset.saveDelivery;
        const formData = new FormData();
        formData.append('pedido_id', id);
        formData.append('codigo_rastreio', document.querySelector(`[data-delivery-code="${id}"]`)?.value || '');
        formData.append('previsao_entrega', document.querySelector(`[data-delivery-date="${id}"]`)?.value || '');
        formData.append('transportadora', 'Correios');
        try { await postForm(`${API}api/admin/entrega-atualizar`, formData); showAlert('Rastreio e previsão atualizados.', 'success'); await loadOrders(); } catch (error) { showAlert(error.message); }
      });
    });

  const STOCK_LIMIT = 10000000;

  function currentStockOperation() {
    const checked = document.querySelector(
      '#stockForm input[name="operacao"]:checked'
    );

    return checked ? checked.value : 'adicionar';
  }

  function stockSelection() {
    const productId = Number(
      document.getElementById('stockProduct').value || 0
    );
    const centerId = Number(
      document.getElementById('stockCenter').value || 0
    );

    const product =
      stockProducts.find(item => Number(item.id) === productId) || null;
    const center =
      stockCenters.find(item => Number(item.id) === centerId) || null;
    const balanceRow = product
      ? (product.centros || []).find(item => Number(item.id) === centerId)
      : null;

    return {
      product,
      center,
      centerId,
      centerBalance: balanceRow ? Number(balanceRow.quantidade) : 0
    };
  }

  // Valida a movimentação no navegador (o servidor valida de novo).
  function validateStockMove(operation, raw, product, centerBalance) {
    if (!/^\d+$/.test(raw)) {
      return {
        error: 'Use apenas números inteiros (sem vírgulas, sinais ou letras).'
      };
    }

    const quantity = Number(raw);

    if (quantity > STOCK_LIMIT) {
      return {
        error: `Quantidade acima do limite permitido (${STOCK_LIMIT.toLocaleString('pt-BR')} un.).`
      };
    }

    if (operation !== 'definir' && quantity < 1) {
      return { error: 'A quantidade deve ser maior que zero.' };
    }

    if (operation === 'remover' && quantity > centerBalance) {
      return {
        error: `Este CD tem apenas ${centerBalance} un. — não é possível remover ${quantity}.`
      };
    }

    const balanceAfter =
      operation === 'adicionar'
        ? centerBalance + quantity
        : operation === 'remover'
          ? centerBalance - quantity
          : quantity;

    const totalAfter =
      Number(product.estoque) + (balanceAfter - centerBalance);

    if (totalAfter < 0) {
      return {
        error: `O estoque total do produto (${product.estoque} un.) é menor que a quantidade informada.`
      };
    }

    if (balanceAfter > STOCK_LIMIT || totalAfter > STOCK_LIMIT) {
      return {
        error: `O saldo resultante passaria do limite permitido (${STOCK_LIMIT.toLocaleString('pt-BR')} un.).`
      };
    }

    return { quantity, balanceAfter, totalAfter };
  }

  // Atualiza rótulos, botão e a prévia "saldo atual → novo saldo".
  function updateStockForm() {
    const operation = currentStockOperation();
    const quantityInput = document.getElementById('stockQuantity');
    const label = document.getElementById('stockQuantityLabel');
    const submit = document.getElementById('stockSubmit');
    const preview = document.getElementById('stockPreview');

    const texts = {
      adicionar: {
        label: 'Quantidade a adicionar',
        button: 'Adicionar ao estoque',
        min: 1
      },
      remover: {
        label: 'Quantidade a remover',
        button: 'Remover do estoque',
        min: 1
      },
      definir: {
        label: 'Novo saldo do CD (contagem física)',
        button: 'Definir saldo',
        min: 0
      }
    }[operation];

    label.textContent = texts.label;
    quantityInput.min = String(texts.min);
    submit.classList.toggle('is-danger', operation === 'remover');

    if (!stockSubmitting) {
      submit.textContent = texts.button;
    }

    preview.className = 'stock-preview';

    const { product, centerId, centerBalance } = stockSelection();

    if (!product || !centerId) {
      preview.textContent =
        'Selecione um produto e um centro de distribuição.';
      return;
    }

    const current =
      `Saldo atual no CD: ${centerBalance} un. · Total do produto: ${product.estoque} un.`;
    const raw = quantityInput.value.trim();

    if (raw === '') {
      preview.textContent = current;
      return;
    }

    const check = validateStockMove(operation, raw, product, centerBalance);

    if (check.error) {
      preview.classList.add('is-error');
      preview.textContent = check.error;
      return;
    }

    preview.innerHTML =
      `${current} → depois: CD <strong>${check.balanceAfter}</strong> un. · Total <strong>${check.totalAfter}</strong> un.`;
  }

  function renderStockTable() {
    document.getElementById('stockTableWrap').innerHTML = `
      <table>
        <thead>
          <tr>
            <th>Produto</th>
            <th>Total</th>
            <th>Distribuição</th>
            <th>Indicador</th>
            <th>Ação</th>
          </tr>
        </thead>

        <tbody>
          ${
            stockProducts.length
              ? stockProducts
                  .map(product => {
                    const unallocated = Number(product.nao_alocado || 0);
                    const unallocatedNote =
                      unallocated > 0
                        ? `<span class="stock-unallocated">Sem CD definido: ${unallocated} un.</span>`
                        : unallocated < 0
                          ? `<span class="stock-unallocated">Soma dos CDs excede o total em ${Math.abs(unallocated)} un.</span>`
                          : '';
                    const isLow =
                      Number(product.estoque) <= Number(product.estoque_minimo);

                    return `
                      <tr>
                        <td>${escapeHtml(product.nome)}</td>
                        <td><strong>${product.estoque}</strong></td>
                        <td>
                          ${
                            product.centros?.length
                              ? product.centros
                                  .map(
                                    center =>
                                      `${escapeHtml(center.nome)}: ${center.quantidade}`
                                  )
                                  .join('<br>')
                              : 'Sem saldo por CD'
                          }
                          ${unallocatedNote}
                        </td>
                        <td>
                          ${
                            isLow
                              ? '<span class="status warn">Repor</span>'
                              : '<span class="status ok">Normal</span>'
                          }
                        </td>
                        <td>
                          <button
                            class="action-button"
                            type="button"
                            data-stock-pick="${product.id}"
                          >
                            Movimentar
                          </button>
                        </td>
                      </tr>
                    `;
                  })
                  .join('')
              : `
                <tr>
                  <td colspan="5" class="empty">Nenhum produto.</td>
                </tr>
              `
          }
        </tbody>
      </table>
    `;
  }

  async function loadStock() {
    if (
      ![
        'admin_master',
        'logistica'
      ].includes(papelAtual)
    ) {
      return;
    }

    const data = await getJson(`${API}api/admin/estoque`);

    // Variáveis próprias: não podem sobrescrever a lista de produtos do catálogo.
    stockCenters = Array.isArray(data.centros) ? data.centros : [];
    stockProducts = Array.isArray(data.produtos) ? data.produtos : [];

    const productSelect = document.getElementById('stockProduct');
    const centerSelect = document.getElementById('stockCenter');

    // Guarda a seleção atual para não "pular" para o primeiro item após salvar.
    const previousProduct = productSelect.value;
    const previousCenter = centerSelect.value;

    productSelect.innerHTML = stockProducts.length
      ? stockProducts
          .map(
            product => `
              <option value="${product.id}">
                ${escapeHtml(product.nome)} — ${product.estoque} un.
              </option>
            `
          )
          .join('')
      : '<option value="">Nenhum produto ativo</option>';

    centerSelect.innerHTML = stockCenters.length
      ? stockCenters
          .map(
            center => `
              <option value="${center.id}">
                ${escapeHtml(center.nome)} — ${escapeHtml(center.cidade)} / ${escapeHtml(center.estado)}
              </option>
            `
          )
          .join('')
      : '<option value="">Nenhum centro cadastrado</option>';

    if (stockProducts.some(item => String(item.id) === previousProduct)) {
      productSelect.value = previousProduct;
    }

    if (stockCenters.some(item => String(item.id) === previousCenter)) {
      centerSelect.value = previousCenter;
    }

    renderStockTable();
    updateStockForm();
  }

  async function loadCustomers() {
    if (
      ![
        'admin_master',
        'suporte'
      ].includes(papelAtual)
    ) {
      return;
    }

    const data =
      await getJson(
        `${API}api/admin/clientes`
      );

    document.getElementById(
      'clientsTableBody'
    ).innerHTML =
      data.clientes.length
        ? data.clientes
            .map(
              client => `
                <tr>
                  <td>
                    <strong>
                      ${escapeHtml(
                        client.nome
                      )}
                    </strong>
                  </td>

                  <td>
                    ${escapeHtml(
                      client.email
                    )}
                  </td>

                  <td>
                    ${client.pedidos}
                  </td>

                  <td>
                    ${money(
                      client.total_compras
                    )}
                  </td>

                  <td>
                    ${dateTime(
                      client.criado_em
                    )}
                  </td>

                  <td>
                    <button
                      class="action-button"
                      type="button"
                      data-history="${client.id}"
                    >
                      Ver pedidos
                    </button>
                  </td>
                </tr>
              `
            )
            .join('')
        : `
          <tr>
            <td
              colspan="6"
              class="empty"
            >
              Nenhum cliente.
            </td>
          </tr>
        `;

    document
      .querySelectorAll(
        '[data-history]'
      )
      .forEach(button => {
        button.addEventListener(
          'click',
          () =>
            openHistory(
              Number(
                button.dataset
                  .history
              )
            )
        );
      });
  }

  async function openHistory(
    clientId
  ) {
    historyContent.innerHTML =
      '<p class="empty">Carregando histórico…</p>';

    historyDialog.showModal();

    try {
      const data =
        await getJson(
          `${API}api/admin/cliente-historico&cliente_id=${clientId}`
        );

      historyContent.innerHTML =
        data.pedidos.length
          ? data.pedidos
              .map(
                order => `
                  <div class="history-row">
                    <strong>
                      #${order.id}
                    </strong>

                    <span>
                      ${statusBadge(
                        order.status
                      )}
                    </span>

                    <span>
                      ${money(
                        order.total
                      )}
                    </span>

                    <small>
                      ${dateTime(
                        order.criado_em
                      )}
                    </small>
                  </div>
                `
              )
              .join('')
          : `
            <p class="empty">
              Nenhum pedido deste cliente.
            </p>
          `;
    } catch (error) {
      historyContent.innerHTML = `
        <p class="empty">
          ${escapeHtml(
            error.message
          )}
        </p>
      `;
    }
  }

  async function loadImages() {
    if (
      ![
        'admin_master',
        'catalogo'
      ].includes(papelAtual)
    ) {
      return;
    }

    const data =
      await getJson(
        `${API}api/admin/imagens`
      );

    imagens =
      Array.isArray(
        data.imagens
      )
        ? data.imagens
        : [];

    document.getElementById(
      'imageGrid'
    ).innerHTML =
      imagens.length
        ? imagens
            .map(
              image => `
                <article class="image-item">
                  <img
                    src="../index.php?route=api/imagem&id=${image.id}"
                    alt="${escapeHtml(
                      image.alt_text ||
                      image.nome_arquivo
                    )}"
                    loading="lazy"
                  >

                  <div class="image-meta">
                    <strong class="image-identification">
                      ${escapeHtml(
                        image.alt_text ||
                        image.nome_arquivo ||
                        `Imagem #${image.id}`
                      )}
                    </strong>

                    <small class="image-file-name">
                      Arquivo: ${escapeHtml(
                        image.nome_arquivo ||
                        '—'
                      )}
                    </small>

                    <small>
                      #${image.id}
                      •
                      ${
                        (
                          Number(
                            image.tamanho_bytes
                          ) /
                          1024
                        ).toFixed(0)
                      }
                      KB
                    </small>

                    <small class="image-path">
                      ${escapeHtml(image.caminho_arquivo || 'img/—')}
                    </small>

                    <div>
                      <a
                        class="action-button"
                        href="../index.php?route=api/imagem&id=${image.id}&download=1"
                      >
                        Baixar
                      </a>

                      <button
                        class="action-button danger"
                        type="button"
                        data-delete-image="${image.id}"
                      >
                        Remover
                      </button>
                    </div>
                  </div>
                </article>
              `
            )
            .join('')
        : `
          <p class="empty">
            Nenhuma imagem cadastrada.
          </p>
        `;

    fillProductFormOptions();

    document
      .querySelectorAll(
        '[data-delete-image]'
      )
      .forEach(button => {
        button.addEventListener(
          'click',
          async () => {
            if (
              !confirm(
                'Remover esta imagem da biblioteca?'
              )
            ) {
              return;
            }

            const formData =
              new FormData();

            formData.append(
              'id',
              button.dataset
                .deleteImage
            );

            try {
              await postForm(
                `${API}api/admin/imagem/excluir`,
                formData
              );

              showAlert(
                'Imagem removida.',
                'success'
              );

              await loadImages();
              await loadCatalog();
            } catch (error) {
              showAlert(
                error.message
              );
            }
          }
        );
      });
  }

  async function loadCampaigns() {
    if (
      ![
        'admin_master',
        'catalogo'
      ].includes(papelAtual)
    ) {
      return;
    }

    const [
      bannerData,
      couponData
    ] = await Promise.all([
      getJson(
        `${API}api/admin/banners`
      ),
      getJson(
        `${API}api/admin/cupons`
      )
    ]);

    document.getElementById(
      'bannerImageId'
    ).innerHTML = `
      <option value="">
        Sem imagem
      </option>

      ${imagens
        .map(
          image => `
            <option
              value="${image.id}"
            >
              #${image.id} —
              ${escapeHtml(
                image.nome_arquivo
              )}
            </option>
          `
        )
        .join('')}
    `;

    document.getElementById(
      'bannersTableBody'
    ).innerHTML =
      bannerData.banners.length
        ? bannerData.banners
            .map(
              banner => `
                <tr>
                  <td>
                    <strong>
                      ${escapeHtml(
                        banner.titulo
                      )}
                    </strong>

                    <br>

                    <small>
                      ${escapeHtml(
                        banner.subtitulo ||
                        ''
                      )}
                    </small>
                  </td>

                  <td>
                    ${banner.ordem}
                  </td>

                  <td>
                    ${
                      Number(
                        banner.ativo
                      ) === 1
                        ? `
                          <span class="status ok">
                            Ativo
                          </span>
                        `
                        : `
                          <span class="status danger">
                            Inativo
                          </span>
                        `
                    }
                  </td>

                  <td>
                    <button
                      class="action-button danger"
                      type="button"
                      data-delete-banner="${banner.id}"
                    >
                      Excluir
                    </button>
                  </td>
                </tr>
              `
            )
            .join('')
        : `
          <tr>
            <td
              colspan="4"
              class="empty"
            >
              Nenhum banner.
            </td>
          </tr>
        `;

    document.getElementById(
      'couponsTableBody'
    ).innerHTML =
      couponData.cupons.length
        ? couponData.cupons
            .map(
              coupon => `
                <tr>
                  <td>
                    <strong>
                      ${escapeHtml(
                        coupon.codigo
                      )}
                    </strong>
                  </td>

                  <td>
                    ${
                      coupon.tipo ===
                      'percentual'
                        ? '%'
                        : 'R$'
                    }
                  </td>

                  <td>
                    ${
                      coupon.tipo ===
                      'percentual'
                        ? coupon.valor
                        : money(
                            coupon.valor
                          )
                    }
                  </td>

                  <td>
                    ${
                      Number(
                        coupon.ativo
                      ) === 1
                        ? `
                          <span class="status ok">
                            Ativo
                          </span>
                        `
                        : `
                          <span class="status danger">
                            Inativo
                          </span>
                        `
                    }
                  </td>
                </tr>
              `
            )
            .join('')
        : `
          <tr>
            <td
              colspan="4"
              class="empty"
            >
              Nenhuma regra.
            </td>
          </tr>
        `;

    document
      .querySelectorAll(
        '[data-delete-banner]'
      )
      .forEach(button => {
        button.addEventListener(
          'click',
          async () => {
            if (
              !confirm(
                'Excluir este banner?'
              )
            ) {
              return;
            }

            const formData =
              new FormData();

            formData.append(
              'id',
              button.dataset
                .deleteBanner
            );

            try {
              await postForm(
                `${API}api/admin/banner/excluir`,
                formData
              );

              showAlert(
                'Banner excluído.',
                'success'
              );

              await loadCampaigns();
            } catch (error) {
              showAlert(
                error.message
              );
            }
          }
        );
      });
  }

  /* =========================================================
     AUDITORIA: busca + filtros + limite de 5 com "Ver mais"
     ========================================================= */

  const AUDIT_PAGE_SIZE = 5;
  let auditLogs = [];
  let auditFilters = { search: '', entity: '', action: '' };
  let auditVisible = AUDIT_PAGE_SIZE;

  const AUDIT_ACTION_LABELS = {
    'estoque.adicionar': 'Estoque · adição',
    'estoque.remover': 'Estoque · remoção',
    'estoque.definir': 'Estoque · saldo definido',
    'estoque.ajustado': 'Estoque · ajuste',
    'produto.criado': 'Produto criado',
    'produto.salvo': 'Produto atualizado',
    'produto.excluido': 'Produto excluído',
    'categoria.salva': 'Categoria salva',
    'categoria.excluida': 'Categoria excluída',
    'pedido.status_alterado': 'Status do pedido alterado',
    'entrega.atualizada': 'Entrega atualizada',
    'imagem.criada': 'Imagem enviada',
    'imagem.removida': 'Imagem removida',
    'banner.criado': 'Banner criado',
    'banner.atualizado': 'Banner atualizado',
    'banner.excluido': 'Banner excluído',
    'banner.imagem_processada': 'Imagem de banner processada',
    'cupom.criado': 'Cupom criado',
    'cupom.atualizado': 'Cupom atualizado'
  };

  const AUDIT_ENTITY_LABELS = {
    produto: 'Produto',
    categoria: 'Categoria',
    pedido: 'Pedido',
    imagem: 'Imagem',
    banner: 'Banner',
    cupom: 'Cupom'
  };

  function auditActionLabel(code) {
    return AUDIT_ACTION_LABELS[code] || String(code || '—');
  }

  function auditEntityLabel(code) {
    return AUDIT_ENTITY_LABELS[code] || String(code || '—');
  }

  function parseAuditDetails(raw) {
    if (!raw) return null;
    if (typeof raw === 'object') return raw;

    try {
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : null;
    } catch (error) {
      return null;
    }
  }

  // Resumo curto e legível dos detalhes gravados no log.
  function auditDetailsText(log) {
    const details = parseAuditDetails(log.detalhes);

    if (!details) return '';

    if (String(log.acao || '').startsWith('estoque.') && 'saldo_centro_depois' in details) {
      const parts = [
        `CD #${details.centro_id}: ${details.saldo_centro_antes} → ${details.saldo_centro_depois} un.`
      ];

      if ('total_antes' in details) {
        parts.push(`total ${details.total_antes} → ${details.total_depois} un.`);
      }

      return parts.join(' · ');
    }

    const text = Object.entries(details)
      .map(([key, value]) => {
        const shown =
          value !== null && typeof value === 'object'
            ? JSON.stringify(value)
            : String(value);

        return `${key}: ${shown}`;
      })
      .join(' · ');

    return text.length > 160 ? `${text.slice(0, 157)}…` : text;
  }

  function filteredAuditLogs() {
    const query = auditFilters.search.trim().toLocaleLowerCase('pt-BR');

    return auditLogs.filter(log => {
      if (auditFilters.entity && log.entidade !== auditFilters.entity) return false;
      if (auditFilters.action && log.acao !== auditFilters.action) return false;

      if (!query) return true;

      const haystack = [
        log.usuario,
        log.acao,
        auditActionLabel(log.acao),
        log.entidade,
        auditEntityLabel(log.entidade),
        log.entidade_id,
        dateTime(log.criado_em),
        auditDetailsText(log)
      ]
        .filter(value => value !== null && value !== undefined && value !== '')
        .join(' ')
        .toLocaleLowerCase('pt-BR');

      return haystack.includes(query);
    });
  }

  // Monta as opções dos filtros a partir dos eventos carregados, mantendo a seleção.
  function fillAuditFilters() {
    const entitySelect = document.getElementById('auditEntityFilter');
    const actionSelect = document.getElementById('auditActionFilter');

    if (!entitySelect || !actionSelect) return;

    const entities = [...new Set(auditLogs.map(log => log.entidade).filter(Boolean))]
      .sort((a, b) => auditEntityLabel(a).localeCompare(auditEntityLabel(b), 'pt-BR'));
    const actions = [...new Set(auditLogs.map(log => log.acao).filter(Boolean))]
      .sort((a, b) => auditActionLabel(a).localeCompare(auditActionLabel(b), 'pt-BR'));

    // Se a opção escolhida não existe mais nos dados, o filtro volta para "Todas".
    if (auditFilters.entity && !entities.includes(auditFilters.entity)) auditFilters.entity = '';
    if (auditFilters.action && !actions.includes(auditFilters.action)) auditFilters.action = '';

    entitySelect.innerHTML =
      '<option value="">Todas</option>' +
      entities
        .map(code => `<option value="${escapeHtml(code)}">${escapeHtml(auditEntityLabel(code))}</option>`)
        .join('');

    actionSelect.innerHTML =
      '<option value="">Todas</option>' +
      actions
        .map(code => `<option value="${escapeHtml(code)}">${escapeHtml(auditActionLabel(code))}</option>`)
        .join('');

    entitySelect.value = auditFilters.entity;
    actionSelect.value = auditFilters.action;

    const search = document.getElementById('auditSearch');
    if (search) search.value = auditFilters.search;
  }

  function renderAudit() {
    const body = document.getElementById('logsTableBody');

    if (!body) return;

    const filtered = filteredAuditLogs();
    const shown = filtered.slice(0, auditVisible);
    const remaining = filtered.length - shown.length;

    const count = document.getElementById('auditResultCount');

    if (count) {
      count.textContent = filtered.length
        ? `Mostrando ${shown.length} de ${filtered.length} ${filtered.length === 1 ? 'evento' : 'eventos'}` +
          (filtered.length !== auditLogs.length ? ` (filtrado de ${auditLogs.length})` : '')
        : '0 eventos';
    }

    body.innerHTML = shown.length
      ? shown
          .map(log => {
            const details = auditDetailsText(log);

            return `
              <tr>
                <td>${dateTime(log.criado_em)}</td>
                <td>${escapeHtml(log.usuario || '—')}</td>
                <td>${escapeHtml(auditActionLabel(log.acao))}</td>
                <td>${escapeHtml(auditEntityLabel(log.entidade))}</td>
                <td>${escapeHtml(log.entidade_id || '—')}</td>
                <td>${details ? `<span class="audit-details">${escapeHtml(details)}</span>` : '—'}</td>
              </tr>
            `;
          })
          .join('')
      : `
        <tr>
          <td colspan="6" class="empty">
            ${auditLogs.length ? 'Nenhum evento corresponde à busca ou aos filtros.' : 'Nenhum evento.'}
          </td>
        </tr>
      `;

    const wrap = document.getElementById('auditMoreWrap');
    const moreButton = document.getElementById('auditShowMore');
    const lessButton = document.getElementById('auditShowLess');

    if (!wrap || !moreButton || !lessButton) return;

    wrap.hidden = filtered.length <= AUDIT_PAGE_SIZE;
    moreButton.hidden = remaining <= 0;
    lessButton.hidden = auditVisible <= AUDIT_PAGE_SIZE;

    moreButton.textContent =
      `Ver mais (${Math.min(AUDIT_PAGE_SIZE, remaining)} de ${remaining} restantes)`;
  }

  // Qualquer mudança de busca/filtro volta para os primeiros 5 resultados.
  function resetAuditView() {
    auditVisible = AUDIT_PAGE_SIZE;
    renderAudit();
  }

  function bindAuditFilters() {
    document.getElementById('auditSearch')?.addEventListener('input', event => {
      auditFilters.search = event.target.value;
      resetAuditView();
    });

    document.getElementById('auditEntityFilter')?.addEventListener('change', event => {
      auditFilters.entity = event.target.value;
      resetAuditView();
    });

    document.getElementById('auditActionFilter')?.addEventListener('change', event => {
      auditFilters.action = event.target.value;
      resetAuditView();
    });

    document.getElementById('auditClearFilters')?.addEventListener('click', () => {
      auditFilters = { search: '', entity: '', action: '' };
      fillAuditFilters();
      resetAuditView();
    });

    document.getElementById('auditShowMore')?.addEventListener('click', () => {
      auditVisible += AUDIT_PAGE_SIZE;
      renderAudit();
    });

    document.getElementById('auditShowLess')?.addEventListener('click', () => {
      auditVisible = AUDIT_PAGE_SIZE;
      renderAudit();
    });
  }

  async function loadLogs() {
    if (papelAtual !== 'admin_master') {
      return;
    }

    const data = await getJson(`${API}api/admin/logs`);

    auditLogs = Array.isArray(data.logs) ? data.logs : [];
    auditVisible = AUDIT_PAGE_SIZE;

    fillAuditFilters();
    renderAudit();
  }

  async function loadCategories() {
    if (!['admin_master','catalogo'].includes(papelAtual)) return;
    const data = await getJson(`${API}api/admin/categorias`);
    categoriasAdmin = data.categorias || [];
    const selector = document.getElementById('categorySelector');
    const body = document.getElementById('categoriesTableBody');
    if (!selector || !body) return;
    selector.innerHTML = `<option value="">— Nova categoria —</option>` + categoriasAdmin.map(c => `<option value="${c.id}">${escapeHtml(c.nome)}</option>`).join('');
    body.innerHTML = categoriasAdmin.length ? categoriasAdmin.map(c => `<tr><td><strong>${escapeHtml(c.nome)}</strong><br><small>${escapeHtml(c.slug)}</small></td><td>${escapeHtml(c.tema || 'default')}</td><td>${Number(c.ativo)===1 ? '<span class="status ok">Ativa</span>' : '<span class="status danger">Inativa</span>'}<br><small>${Number(c.total_produtos||0)} produto(s)</small></td><td><div class="table-actions"><button type="button" class="mini-button" data-edit-category="${c.id}">Editar</button>${papelAtual === 'admin_master' ? `<button type="button" class="mini-button danger" data-delete-category="${c.id}" ${Number(c.total_produtos||0) > 0 ? `title="Esta categoria tem ${Number(c.total_produtos)} produto(s) vinculado(s); mova ou exclua-os antes de excluir a categoria."` : ''}>Excluir</button>` : ''}</div></td></tr>`).join('') : '<tr><td colspan="4" class="empty">Nenhuma categoria.</td></tr>';
    selector.onchange = () => fillCategoryForm(Number(selector.value));
    body.querySelectorAll('[data-edit-category]').forEach(button => button.addEventListener('click', () => { selector.value = button.dataset.editCategory; fillCategoryForm(Number(button.dataset.editCategory)); }));
    body.querySelectorAll('[data-delete-category]').forEach(button => button.addEventListener('click', () => deleteCategory(Number(button.dataset.deleteCategory))));
    if (categoriasAdmin[0]) fillCategoryForm(categoriasAdmin[0].id);
  }

  async function deleteCategory(id) {
    const categoria = categoriasAdmin.find(item => Number(item.id) === Number(id));
    if (!categoria) return;
    const totalProdutos = Number(categoria.total_produtos || 0);
    if (totalProdutos > 0) {
      showAlert(`Esta categoria tem ${totalProdutos} produto(s) vinculado(s). Mova ou exclua esses produtos (ou altere a categoria deles) antes de excluir "${categoria.nome}".`);
      return;
    }
    if (!confirm(`Excluir a categoria "${categoria.nome}"? Esta ação não pode ser desfeita.`)) return;
    const fd = new FormData(); fd.append('id', String(id));
    try { await postForm(`${API}api/admin/categoria/excluir`, fd); showAlert('Categoria excluída com sucesso.', 'success'); await loadCategories(); await loadCatalog(); }
    catch (error) { showAlert(error.message); }
  }

  function fillCategoryForm(id) {
    const c = categoriasAdmin.find(item => Number(item.id) === Number(id));
    if (!c) return;
    document.getElementById('categoryId').value = c.id;
    document.getElementById('categoryName').value = c.nome || '';
    document.getElementById('categorySlug').value = c.slug || '';
    document.getElementById('categoryDescription').value = c.descricao || '';
    document.getElementById('categoryTheme').value = c.tema || 'default';
    document.getElementById('categoryHighlight').value = c.destaque_texto || '';
    document.getElementById('categoryHero').value = c.hero_texto || '';
    document.getElementById('categoryActive').checked = Number(c.ativo) === 1;
  }

  async function loadSection(
    section
  ) {
    showAlert('');

    if (
      section ===
      'dashboard'
    ) {
      await loadDashboard();
    }

    if (
      section ===
      'catalogo'
    ) {
      await loadImages();
      await loadCatalog();
    }

    if (section === 'loja') {
      await loadCategories();
    }

    if (
      section ===
      'pedidos'
    ) {
      await loadOrders();
    }

    if (
      section ===
      'estoque'
    ) {
      await loadStock();
    }

    if (
      section ===
      'clientes'
    ) {
      await loadCustomers();
    }

    if (
      section ===
      'imagens'
    ) {
      await loadImages();
    }

    if (
      section ===
      'campanhas'
    ) {
      await loadImages();
      await loadCampaigns();
    }

    if (
      section ===
      'auditoria'
    ) {
      await loadLogs();
    }
  }

  links.forEach(link => {
    link.addEventListener(
      'click',
      () =>
        showSection(
          link.dataset.section
        )
    );
  });

  document
    .querySelectorAll(
      '[data-go]'
    )
    .forEach(button => {
      button.addEventListener(
        'click',
        () =>
          showSection(
            button.dataset.go
          )
      );
    });

  document
    .getElementById(
      'menuButton'
    )
    .addEventListener(
      'click',
      () =>
        sidebar.classList.toggle(
          'open'
        )
    );

  document
    .getElementById(
      'newProductButton'
    )
    .addEventListener(
      'click',
      () =>
        openProduct()
    );

  document.getElementById('closeProductDialog')?.addEventListener('click', () => productDialog.close());
  document.getElementById('cancelProductButton')?.addEventListener('click', () => productDialog.close());
  productDialog?.addEventListener('cancel', event => { event.preventDefault(); productDialog.close(); });
  bindCatalogFilters();
  bindAuditFilters();

  document
    .getElementById(
      'addVariantButton'
    )
    .addEventListener(
      'click',
      addVariant
    );

  productForm.addEventListener(
    'submit',
    saveProduct
  );

  document
    .getElementById(
      'closeHistory'
    )
    .addEventListener(
      'click',
      () =>
        historyDialog.close()
    );

  (function setupStockForm() {
    const stockForm = document.getElementById('stockForm');
    const quantityInput = document.getElementById('stockQuantity');
    const submitButton = document.getElementById('stockSubmit');

    ['stockProduct', 'stockCenter'].forEach(id =>
      document.getElementById(id).addEventListener('change', updateStockForm)
    );

    stockForm
      .querySelectorAll('input[name="operacao"]')
      .forEach(radio => radio.addEventListener('change', updateStockForm));

    quantityInput.addEventListener('input', updateStockForm);

    // "Movimentar" na tabela já seleciona o produto no formulário.
    document
      .getElementById('stockTableWrap')
      .addEventListener('click', event => {
        const button = event.target.closest('[data-stock-pick]');

        if (!button) return;

        document.getElementById('stockProduct').value =
          button.dataset.stockPick;

        updateStockForm();
        stockForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
        quantityInput.focus({ preventScroll: true });
      });

    stockForm.addEventListener('submit', async event => {
      event.preventDefault();

      // Evita duplo clique / Enter repetido (a soma é cumulativa).
      if (stockSubmitting) return;

      const operation = currentStockOperation();
      const { product, center, centerId, centerBalance } = stockSelection();

      if (!product || !center || !centerId) {
        showAlert('Selecione o produto e o centro de distribuição.');
        return;
      }

      const check = validateStockMove(
        operation,
        quantityInput.value.trim(),
        product,
        centerBalance
      );

      if (check.error) {
        showAlert(check.error);
        quantityInput.focus();
        return;
      }

      const reduces = check.balanceAfter < centerBalance;

      if (
        (operation === 'remover' || (operation === 'definir' && reduces)) &&
        !confirm(
          `Confirmar a redução do estoque de "${product.nome}" em ${center.nome}?\n\n` +
          `Saldo do CD: ${centerBalance} → ${check.balanceAfter} un.\n` +
          `Total do produto: ${product.estoque} → ${check.totalAfter} un.`
        )
      ) {
        return;
      }

      stockSubmitting = true;
      submitButton.disabled = true;
      submitButton.textContent = 'Salvando...';
      showAlert('');

      try {
        const result = await postForm(
          `${API}api/admin/estoque-ajustar`,
          new FormData(stockForm)
        );

        quantityInput.value = '';
        showAlert(result.message || 'Estoque atualizado.', 'success');
      } catch (error) {
        showAlert(error.message);
      } finally {
        stockSubmitting = false;
        submitButton.disabled = false;
      }

      // Recarrega sempre (sucesso ou erro) para mostrar os saldos reais do servidor.
      try {
        await loadStock();
        await loadDashboard();
      } catch (refreshError) {
        console.error('Falha ao atualizar a tela de estoque:', refreshError);
      }

      updateStockForm();
    });
  })();

  document
    .getElementById(
      'imageUploadForm'
    )
    .addEventListener(
      'submit',
      async event => {
        event.preventDefault();

        const form = event.currentTarget;
        const submitButton = form.querySelector('button[type=\"submit\"]');

        try {
          if (submitButton) {
            submitButton.disabled = true;
            submitButton.dataset.originalText = submitButton.textContent;
            submitButton.textContent = 'Enviando...';
          }

          await postForm(
            `${API}api/admin/imagem/upload`,
            new FormData(form)
          );

          form.reset();

          showAlert(
            'Imagem salva em img e vinculada ao banco pelo caminho.',
            'success'
          );

          await loadImages();
          await loadCatalog();
        } catch (error) {
          showAlert(
            error.message || 'Não foi possível enviar a imagem.'
          );
        } finally {
          if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = submitButton.dataset.originalText || 'Salvar em img';
          }
        }
      }
    );

  document
    .getElementById(
      'bannerForm'
    )
    .addEventListener(
      'submit',
      async event => {
        event.preventDefault();

        const form = event.currentTarget;

        try {
          await postForm(
            `${API}api/admin/banner/salvar`,
            new FormData(form)
          );

          form.reset();

          showAlert(
            'Banner criado.',
            'success'
          );

          await loadCampaigns();
        } catch (error) {
          showAlert(
            error.message
          );
        }
      }
    );

  document
    .getElementById(
      'couponForm'
    )
    .addEventListener(
      'submit',
      async event => {
        event.preventDefault();

        const form = event.currentTarget;

        try {
          await postForm(
            `${API}api/admin/cupom/salvar`,
            new FormData(form)
          );

          form.reset();

          showAlert(
            'Regra de desconto criada.',
            'success'
          );

          await loadCampaigns();
        } catch (error) {
          showAlert(
            error.message
          );
        }
      }
    );

  document.getElementById('categoryForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    try {
      await postForm(`${API}api/admin/categoria/salvar`, new FormData(form));
      showAlert('Categoria atualizada para a loja do cliente.', 'success');
      await loadCategories();
    } catch (error) {
      showAlert(error.message);
    }
  });

  document.getElementById('newCategoryButton')?.addEventListener('click', () => {
    document.getElementById('categoryId').value = '';
    document.getElementById('categorySelector').value = '';
    document.getElementById('categoryName').value = '';
    document.getElementById('categorySlug').value = '';
    document.getElementById('categoryDescription').value = '';
    document.getElementById('categoryTheme').value = 'default';
    document.getElementById('categoryHighlight').value = '';
    document.getElementById('categoryHero').value = '';
    document.getElementById('categoryActive').checked = true;
  });

  document
    .getElementById(
      'btnLogout'
    )
    .addEventListener(
      'click',
      async () => {
        try {
          const formData =
            new FormData();

          await postForm(
            `${API}api/logout`,
            formData
          );
        } catch (_) {
        } finally {
          window.location.href =
            'login.html';
        }
      }
    );

  try {
    await initSession();

    updatePollingStatus('success');
    startDashboardPolling();

    await loadImages()
      .catch(
        error =>
          console.warn(
            'Biblioteca de imagens:',
            error.message
          )
      );

    const hash =
      location.hash.slice(1);

    const firstSection =
      rolePermissions[
        papelAtual
      ]?.includes(hash)
        ? hash
        : 'dashboard';

    showSection(
      firstSection
    );
  } catch (error) {
    console.error(
      'Inicialização do Admin:',
      error
    );
  }
});