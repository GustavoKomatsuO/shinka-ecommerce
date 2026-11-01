/**
 * SHINKA — Catálogo público.
 *
 * Funciona para:
 * - Smartphones
 * - Notebooks
 * - Tablets
 * - TVs
 *
 * O catálogo recebe:
 * - produtos;
 * - famílias;
 * - cores;
 * - várias imagens por cor.
 */

document.addEventListener(
  'DOMContentLoaded',
  async () => {
    const API =
      '../../../index.php?route=';

    const categoria =
      document.body.dataset.category ||
      '';

    const grid =
      document.getElementById(
        'productsGrid'
      );

    const count =
      document.getElementById(
        'productCount'
      );

    const loading =
      document.getElementById(
        'catalogLoading'
      );

    const errorBox =
      document.getElementById(
        'catalogError'
      );

    const searchInput =
      document.getElementById(
        'productSearch'
      );

    const sortSelect =
      document.getElementById(
        'productSort'
      );

    const promoOnly =
      document.getElementById(
        'promoOnly'
      );

    const familyTabs =
      document.getElementById(
        'familyTabs'
      );

    let produtos = [];

    let filtroFamilia =
      '';

    function escapeHtml(
      value
    ) {
      return String(
        value ?? ''
      ).replace(
        /[&<>'"]/g,
        char =>
          ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#39;',
            '"': '&quot;'
          })[char]
      );
    }

    function money(
      value
    ) {
      return Number(
        value || 0
      ).toLocaleString(
        'pt-BR',
        {
          style: 'currency',
          currency: 'BRL'
        }
      );
    }

    function imageUrl(
      id,
      preset = 'medium'
    ) {
      if (!id) {
        return '';
      }

      return (
        `${API}api/imagem` +
        `&id=${encodeURIComponent(
          id
        )}` +
        `&preset=${encodeURIComponent(
          preset
        )}`
      );
    }

    function getVariants(
      product
    ) {
      if (
        Array.isArray(
          product.variantes
        ) &&
        product.variantes.length
      ) {
        return product.variantes;
      }

      if (
        product.imagem_id
      ) {
        return [
          {
            id: 0,
            nome: 'Padrão',
            sku:
              product.slug,
            cor_nome:
              'Padrão',
            cor_hex:
              '#D5D9E0',
            imagens: [
              {
                id:
                  Number(
                    product.imagem_id
                  ),
                principal: 1
              }
            ]
          }
        ];
      }

      return [];
    }

    function getVariantImages(
      variant
    ) {
      if (
        !Array.isArray(
          variant.imagens
        )
      ) {
        return [];
      }

      return variant.imagens
        .map(image => ({
          id:
            Number(
              image.id ||
              image.imagem_id ||
              0
            ),
          principal:
            Number(
              image.principal ||
              0
            )
        }))
        .filter(
          image =>
            image.id > 0
        );
    }

    function getPrimaryImage(
      variant
    ) {
      const images =
        getVariantImages(
          variant
        );

      return (
        images.find(
          image =>
            image.principal ===
            1
        ) ||
        images[0] ||
        null
      );
    }

    function getSearchText(
      product
    ) {
      return [
        product.nome,
        product.familia,
        product.descricao,
        product.especificacoes,
        product.categoria_nome
      ]
        .join(' ')
        .toLowerCase();
    }

    function filterProducts() {
      const search =
        searchInput.value
          .trim()
          .toLowerCase();

      const onlyPromo =
        promoOnly.checked;

      const filtered =
        produtos.filter(
          product => {
            const matchesSearch =
              !search ||
              getSearchText(
                product
              ).includes(
                search
              );

            const matchesFamily =
              !filtroFamilia ||
              String(
                product.familia ||
                ''
              ).toLowerCase() ===
              filtroFamilia
                .toLowerCase();

            const promo =
              Number(
                product.preco_promocional ||
                0
              ) > 0;

            const matchesPromo =
              !onlyPromo ||
              promo;

            return (
              matchesSearch &&
              matchesFamily &&
              matchesPromo
            );
          }
        );

      const sort =
        sortSelect.value;

      filtered.sort(
        (a, b) => {
          const precoA =
            Number(
              a.preco_promocional ||
              a.preco ||
              0
            );

          const precoB =
            Number(
              b.preco_promocional ||
              b.preco ||
              0
            );

          if (
            sort ===
            'preco_asc'
          ) {
            return (
              precoA -
              precoB
            );
          }

          if (
            sort ===
            'preco_desc'
          ) {
            return (
              precoB -
              precoA
            );
          }

          if (
            sort ===
            'nome_asc'
          ) {
            return String(
              a.nome ||
              ''
            ).localeCompare(
              String(
                b.nome ||
                ''
              ),
              'pt-BR'
            );
          }

          return (
            Number(
              b.destaque ||
              0
            ) -
            Number(
              a.destaque ||
              0
            )
          );
        }
      );

      return filtered;
    }

    function buildFamilyTabs() {
      const families =
        [
          ...new Set(
            produtos
              .map(
                product =>
                  product.familia
              )
              .filter(Boolean)
          )
        ];

      familyTabs.innerHTML = `
        <button
          class="family-tab active"
          type="button"
          data-family=""
        >
          Todos
        </button>
      `;

      families.forEach(
        family => {
          const button =
            document.createElement(
              'button'
            );

          button.type =
            'button';

          button.className =
            'family-tab';

          button.dataset.family =
            family;

          button.textContent =
            family;

          familyTabs.appendChild(
            button
          );
        }
      );

      familyTabs
        .querySelectorAll(
          '.family-tab'
        )
        .forEach(
          button => {
            button.addEventListener(
              'click',
              () => {
                filtroFamilia =
                  button.dataset
                    .family ||
                  '';

                familyTabs
                  .querySelectorAll(
                    '.family-tab'
                  )
                  .forEach(
                    item =>
                      item.classList.toggle(
                        'active',
                        item ===
                          button
                      )
                  );

                render();
              }
            );
          }
        );
    }

    function createCard(
      product
    ) {
      const variants =
        getVariants(
          product
        );

      const firstVariant =
        variants[0] ||
        null;

      const firstImage =
        firstVariant
          ? getPrimaryImage(
              firstVariant
            )
          : null;

      const imageId =
        firstImage?.id ||
        product.imagem_id ||
        0;

      const normalPrice =
        Number(
          product.preco ||
          0
        );

      const promoPrice =
        Number(
          product.preco_promocional ||
          0
        );

      const displayPrice =
        promoPrice > 0
          ? promoPrice
          : normalPrice;

      const specs =
        String(
          product.especificacoes ||
          ''
        )
          .split('•')
          .map(
            item =>
              item.trim()
          )
          .filter(Boolean);

      const card =
        document.createElement(
          'article'
        );

      card.className =
        'product-card';

      card.dataset.productId =
        product.id;

      card.innerHTML = `
        <div class="product-image-area">
          ${
            product.badge
              ? `
                <span class="product-badge">
                  ${escapeHtml(
                    product.badge
                  )}
                </span>
              `
              : ''
          }

          <img
            class="product-main-image"
            data-main-image
            src="${imageUrl(
              imageId
            )}"
            alt="${escapeHtml(
              product.nome
            )}"
            loading="lazy"
          >

          <div
            class="product-gallery"
            data-gallery
            aria-label="Galeria de ${escapeHtml(
              product.nome
            )}"
          ></div>
        </div>

        <div class="product-content">
          ${
            product.familia
              ? `
                <span class="product-family">
                  ${escapeHtml(
                    product.familia
                  )}
                </span>
              `
              : ''
          }

          <h3 class="product-name">
            ${escapeHtml(
              product.nome
            )}
          </h3>

          <p class="product-description">
            ${escapeHtml(
              product.descricao ||
              ''
            )}
          </p>

          ${
            specs.length
              ? `
                <div class="product-specs">
                  ${specs
                    .slice(
                      0,
                      4
                    )
                    .map(
                      spec =>
                        `<span class="product-spec">${escapeHtml(
                          spec
                        )}</span>`
                    )
                    .join('')}
                </div>
              `
              : ''
          }

          <div class="variant-heading">
            <span class="variant-title">
              Cor
            </span>

            <span
              class="variant-color-name"
              data-color-name
            >
              ${escapeHtml(
                firstVariant?.cor_nome ||
                'Padrão'
              )}
            </span>
          </div>

          <div
            class="color-options"
            data-color-options
          ></div>

          <div class="price-area">
            ${
              product.preco_anterior
                ? `
                  <span class="price-old">
                    ${money(
                      product.preco_anterior
                    )}
                  </span>
                `
                : ''
            }

            <span class="price-label">
              A partir de
            </span>

            <span class="price ${
              promoPrice > 0
                ? 'price-promo'
                : ''
            }">
              ${money(
                displayPrice
              )}
            </span>
          </div>

          <div class="product-actions">
            <a
              class="product-btn primary product-btn-full"
              href="../../produto.html?id=${product.id}"
            >
              Ver produto e comprar
            </a>
          </div>
        </div>
      `;

      setupVariants(
        card,
        variants
      );


      return card;
    }

    function setupVariants(
      card,
      variants
    ) {
      const mainImage =
        card.querySelector(
          '[data-main-image]'
        );

      const gallery =
        card.querySelector(
          '[data-gallery]'
        );

      const colors =
        card.querySelector(
          '[data-color-options]'
        );

      const colorName =
        card.querySelector(
          '[data-color-name]'
        );

      if (!variants.length) {
        return;
      }

      function selectVariant(
        variant,
        animate = true
      ) {
        const images =
          getVariantImages(
            variant
          );

        const primary =
          images.find(
            image =>
              image.principal ===
              1
          ) ||
          images[0];

        if (!primary) {
          return;
        }

        if (animate) {
          mainImage.classList.add(
            'is-changing'
          );
        }

        setTimeout(
          () => {
            mainImage.src =
              imageUrl(
                primary.id
              );

            mainImage.alt =
              `${card
                .querySelector(
                  '.product-name'
                )
                .textContent.trim()} — ${
                variant.cor_nome ||
                variant.nome ||
                'Variação'
              }`;

            mainImage.onload =
              () => {
                mainImage.classList.remove(
                  'is-changing'
                );
              };
          },
          animate
            ? 120
            : 0
        );

        card.dataset.variantId = String(variant.id || 0);
        card.dataset.imageId = String(primary.id || 0);

        colorName.textContent =
          variant.cor_nome ||
          variant.nome ||
          'Padrão';

        gallery.innerHTML =
          images.length >
          1
            ? images
                .map(
                  (
                    image,
                    index
                  ) => `
                    <button
                      class="gallery-thumb ${
                        index ===
                        0
                          ? 'active'
                          : ''
                      }"
                      type="button"
                      data-image-id="${image.id}"
                      aria-label="Imagem ${
                        index + 1
                      }"
                    >
                      <img
                        src="${imageUrl(
                          image.id,
                          'thumb'
                        )}"
                        alt=""
                        loading="lazy"
                      >
                    </button>
                  `
                )
                .join('')
            : '';

        gallery
          .querySelectorAll(
            '.gallery-thumb'
          )
          .forEach(
            button => {
              button.addEventListener(
                'click',
                () => {
                  const id =
                    Number(
                      button
                        .dataset
                        .imageId
                    );

                  mainImage.classList.add(
                    'is-changing'
                  );

                  setTimeout(
                    () => {
                      mainImage.src =
                        imageUrl(
                          id
                        );

                      gallery
                        .querySelectorAll(
                          '.gallery-thumb'
                        )
                        .forEach(
                          item =>
                            item.classList.toggle(
                              'active',
                              item ===
                                button
                            )
                        );
                    },
                    100
                  );
                }
              );
            }
          );

        colors
          .querySelectorAll(
            '.color-option'
          )
          .forEach(
            option => {
              option.classList.toggle(
                'active',
                option.dataset
                  .variantId ===
                  String(
                    variant.id
                  )
              );
            }
          );
      }

      colors.innerHTML =
        variants
          .map(
            (
              variant,
              index
            ) => `
              <button
                class="color-option ${
                  index === 0
                    ? 'active'
                    : ''
                }"
                type="button"
                data-variant-id="${
                  variant.id
                }"
                title="${escapeHtml(
                  variant.cor_nome ||
                  variant.nome ||
                  'Cor'
                )}"
                aria-label="Cor ${escapeHtml(
                  variant.cor_nome ||
                  variant.nome ||
                  'Padrão'
                )}"
                style="background-color:${
                  /^#[0-9A-Fa-f]{6}$/.test(
                    variant.cor_hex ||
                    ''
                  )
                    ? variant.cor_hex
                    : '#D5D9E0'
                }"
              ></button>
            `
          )
          .join('');

      colors
        .querySelectorAll(
          '.color-option'
        )
        .forEach(
          button => {
            button.addEventListener(
              'click',
              () => {
                const variant =
                  variants.find(
                    item =>
                      String(
                        item.id
                      ) ===
                      button
                        .dataset
                        .variantId
                  );

                if (
                  variant
                ) {
                  selectVariant(
                    variant,
                    true
                  );
                }
              }
            );
          }
        );

      selectVariant(
        variants[0],
        false
      );
    }

    function render() {
      const filtered =
        filterProducts();

      count.textContent =
        `${filtered.length} ${
          filtered.length ===
          1
            ? 'produto'
            : 'produtos'
        }`;

      grid.innerHTML =
        '';

      filtered.forEach(
        product =>
          grid.appendChild(
            createCard(
              product
            )
          )
      );

      if (
        !filtered.length
      ) {
        grid.innerHTML = `
          <div class="catalog-loading">
            Nenhum produto encontrado.
          </div>
        `;
      }
    }

    async function loadProducts() {
      loading.hidden =
        false;

      errorBox.hidden =
        true;

      try {
        const url =
          `${API}api/produtos` +
          `&categoria=${encodeURIComponent(
            categoria
          )}`;

        const response =
          await fetch(
            url,
            {
              method: 'GET',
              credentials:
                'same-origin',
              cache: 'no-store',
              headers: {
                Accept:
                  'application/json'
              }
            }
          );

        const text =
          await response.text();

        let data;

        try {
          data =
            JSON.parse(
              text
            );
        } catch (
          error
        ) {
          console.error(
            'Resposta da API:',
            text
          );

          throw new Error(
            'A API do catálogo retornou uma resposta inválida.'
          );
        }

        if (
          !response.ok ||
          data.success ===
            false
        ) {
          throw new Error(
            data.message ||
            'Não foi possível carregar os produtos.'
          );
        }

        produtos =
          Array.isArray(
            data.produtos
          )
            ? data.produtos
            : [];

        try {
          const categoryData = await fetch(`${API}api/categorias`, { credentials: 'same-origin', cache: 'no-store' }).then(response => response.json());
          const current = (categoryData.categorias || []).find(item => item.slug === categoria);
          if (current) {
            document.body.dataset.category = current.tema || categoria;
            document.querySelector('.catalog-intro p').textContent = current.hero_texto || document.querySelector('.catalog-intro p').textContent;
            document.querySelector('.eyebrow').textContent = `SHINKA / ${current.nome}`;
            document.querySelector('.breadcrumb span:last-child').textContent = current.nome;
          }
        } catch (_) {}

        buildFamilyTabs();
        render();
      } catch (
        error
      ) {
        console.error(
          'Erro no catálogo:',
          error
        );

        errorBox.textContent =
          error.message ||
          'Erro ao carregar o catálogo.';

        errorBox.hidden =
          false;
      } finally {
        loading.hidden =
          true;
      }
    }

    searchInput.addEventListener(
      'input',
      render
    );

    sortSelect.addEventListener(
      'change',
      render
    );

    promoOnly.addEventListener(
      'change',
      render
    );

    await loadProducts();
  }
);