document.addEventListener('DOMContentLoaded', async () => {
  const form = document.getElementById('checkoutForm');
  const notice = document.getElementById('notice');
  const cep = document.getElementById('cep');
  const cepHelp = document.getElementById('cepHelp');
  const shippingInfo = document.getElementById('shippingInfo');
  const cardFields = document.getElementById('cardFields');
  const paymentInfo = document.getElementById('paymentSimulationInfo');
  const pixPreview = document.getElementById('pixPreview');
  const cardPreview = document.getElementById('cardPreview');
  const pixCopyStatus = document.getElementById('pixCopyStatus');
  const copyPix = document.getElementById('copyPix');
  const submit = document.getElementById('submitCheckout');
  const cart = ShinkaStore.readCart();
  if (!cart.length) { location.href = 'carrinho.html'; return; }

  let shippingPrice = 0;
  let shippingService = '03298';
  let cepTimer = null;
  let cepConsultaSequencia = 0;

  const money = value => ShinkaStore.formatBRL(value);
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));

  function currentSubtotal() {
    return cart.reduce((sum, item) => sum + Number(item.preco || 0) * Number(item.quantidade || 0), 0);
  }

  function drawSummary() {
    const subtotal = currentSubtotal();
    document.getElementById('summaryItems').innerHTML = cart.map(item => `
      <div class="summary-item"><div><strong>${esc(item.nome)}</strong><small>${item.quantidade} × ${money(item.preco)}</small></div><b>${money(item.preco * item.quantidade)}</b></div>
    `).join('');
    document.getElementById('summaryCount').textContent = `${ShinkaStore.count()} ${ShinkaStore.count() === 1 ? 'item' : 'itens'}`;
    document.getElementById('summarySubtotal').textContent = money(subtotal);
    document.getElementById('summaryShipping').textContent = money(shippingPrice);
    document.getElementById('summaryDiscount').textContent = money(0);
    const total = subtotal + shippingPrice;
    document.getElementById('summaryTotal').textContent = money(total);
    document.getElementById('submitTotal').textContent = money(total);
  }

  function selectPayment(value) {
    document.querySelectorAll('.payment-method').forEach(el => el.classList.toggle('active', el.querySelector('input').value === value));
    const card = value === 'cartao';
    cardFields.hidden = !card;
    pixPreview.hidden = value !== 'pix';
    cardPreview.hidden = !card;
    ['cartao_numero','cartao_nome','cartao_validade','cartao_cvv'].forEach(name => { const input = form.elements[name]; if (input) input.required = card; });
    paymentInfo.textContent = value === 'pix' ? 'PIX será aprovado automaticamente no modo de demonstração. O QR mostrado é visual e não representa uma cobrança real.' : value === 'boleto' ? 'Boleto será emitido como aprovado no modo de demonstração.' : 'Cartão será aprovado automaticamente. Nenhum dado de cartão é armazenado.';
    if (pixCopyStatus) pixCopyStatus.hidden = true;
  }

  document.querySelectorAll('input[name="forma_pagamento"]').forEach(input => input.addEventListener('change', () => selectPayment(input.value)));
  document.querySelectorAll('input[name="servico_correiros"]').forEach(input => input.addEventListener('change', () => {
    shippingService = input.value;
    document.querySelectorAll('.shipping-option').forEach(option => option.classList.toggle('active', option.querySelector('input')?.checked));
    consultarPrazo();
  }));

  async function carregarCliente() {
    try {
      const me = await ShinkaStore.json(`${ShinkaStore.API}api/cliente/me`);
      form.destinatario.value = me.usuario?.nome || '';
      const addr = (me.enderecos || [])[0];
      if (addr) ['cep','logradouro','numero','complemento','bairro','cidade','estado'].forEach(k => {
        if (form.elements[k]) form.elements[k].value = addr[k] || '';
      });

      if ((form.cep.value || '').replace(/\D/g,'').length === 8) {
        await consultarCep();
        await consultarPrazo();
      }
    } catch (_) {}
  }

  function itensParaFrete() {
    return cart.map(item => ({
      id: Number(item.id),
      nome: item.nome,
      quantidade: Number(item.quantidade || 1),
      variantId: Number(item.variantId || 0)
    }));
  }

  function atualizarPrecosFrete(servicos) {
    document.querySelectorAll('[data-shipping-price]').forEach(node => {
      const item = servicos.find(row => String(row.codigo) === String(node.dataset.shippingPrice));
      node.textContent = item ? money(item.valor) : 'Indisponível';
    });

    const selected = form.querySelector('input[name="servico_correiros"]:checked');
    const current = selected
      ? servicos.find(row => String(row.codigo) === String(selected.value))
      : null;

    shippingPrice = current ? Number(current.valor || 0) : 0;

    const summaryShipping = document.getElementById('summaryShipping');
    if (summaryShipping) summaryShipping.textContent = money(shippingPrice);

    drawSummary();
  }

  async function consultarPrazo() {
    const cepValue = cep.value.replace(/\D/g, '');

    if (cepValue.length !== 8) {
      if (shippingInfo) {
        shippingInfo.textContent = 'Digite o CEP para calcular peso, dimensões, valor e prazo.';
      }
      return;
    }

    if (shippingInfo) {
      shippingInfo.textContent = 'Calculando peso, dimensões, valor e prazo…';
    }

    try {
      const params = new URLSearchParams({
        cep: cepValue,
        itens: JSON.stringify(itensParaFrete())
      });

      const data = await ShinkaStore.json(
        `${ShinkaStore.API}api/cliente/prazo&${params.toString()}`
      );

      const servicos = Array.isArray(data.servicos)
        ? data.servicos
        : [];

      atualizarPrecosFrete(servicos);

      const selected = form.querySelector('input[name="servico_correiros"]:checked');
      const current = selected
        ? servicos.find(row => String(row.codigo) === String(selected.value))
        : servicos[0];

      if (!current) {
        if (shippingInfo) {
          shippingInfo.textContent = 'Não foi possível calcular a entrega para este CEP.';
        }
        return;
      }

      const pacote = data.pacote || {};
      const fonte = current.configurada ? 'Correios' : 'Estimativa local';
      const prazo = current.dias
        ? `${current.dias} dia(s)`
        : 'prazo não informado';

      if (shippingInfo) {
        shippingInfo.textContent =
          `${fonte}: ${current.servico} por ${money(current.valor)} · ${prazo} · ` +
          `${Number(pacote.peso_kg || 0).toFixed(2)} kg · ` +
          `${Number(pacote.comprimento_cm || 0).toFixed(0)}×` +
          `${Number(pacote.largura_cm || 0).toFixed(0)}×` +
          `${Number(pacote.altura_cm || 0).toFixed(0)} cm`;
      }
    } catch (error) {
      if (shippingInfo) {
        shippingInfo.textContent =
          error.message ||
          'Não foi possível calcular a entrega.';
      }

      shippingPrice = 0;
      drawSummary();
    }
  }

  /**
   * Consulta o CEP no backend PHP, que por sua vez consome a API ViaCEP,
   * e preenche automaticamente os campos do endereço.
   *
   * A consulta utiliza uma sequência para impedir que uma resposta antiga
   * sobrescreva os dados de um CEP digitado posteriormente.
   */
  async function consultarCep() {
    const value = cep.value.replace(/\D/g, '');

    if (value.length !== 8) {
      if (cepHelp) cepHelp.textContent = 'Digite um CEP válido para preencher o endereço automaticamente.';
      return false;
    }

    const minhaConsulta = ++cepConsultaSequencia;

    if (cepHelp) {
      cepHelp.textContent = 'Consultando CEP…';
    }

    try {
      const d = await ShinkaStore.json(
        `${ShinkaStore.API}api/cliente/cep&cep=${encodeURIComponent(value)}`
      );

      if (minhaConsulta !== cepConsultaSequencia) {
        return false;
      }

      const endereco = d.endereco || {};

      const preencher = (campo, valor) => {
        const input = form.elements[campo];
        if (!input) return;
        if (valor !== undefined && valor !== null && String(valor).trim() !== '') {
          input.value = String(valor);
        }
      };

      preencher('logradouro', endereco.logradouro);
      preencher('complemento', endereco.complemento);
      preencher('bairro', endereco.bairro);
      preencher('cidade', endereco.cidade);
      preencher('estado', endereco.estado);

      if (cepHelp) {
        cepHelp.textContent = 'Endereço preenchido automaticamente via ViaCEP.';
      }

      const numero = form.elements.numero;
      if (numero && !numero.value) {
        numero.focus();
      }

      return true;
    } catch (error) {
      if (minhaConsulta !== cepConsultaSequencia) {
        return false;
      }

      if (cepHelp) {
        cepHelp.textContent = error.message || 'Não foi possível encontrar este CEP.';
      }

      return false;
    }
  }

  const cardNumber = form.elements.cartao_numero;
  const cardName = form.elements.cartao_nome;
  const cardExpiry = form.elements.cartao_validade;
  cardNumber?.addEventListener('input', () => { const digits=cardNumber.value.replace(/\D/g,'').slice(0,16); cardNumber.value=digits.replace(/(.{4})/g,'$1 ').trim(); const masked=(digits+'••••••••••••••••').slice(0,16).replace(/\d/g,'•'); document.getElementById('previewCardNumber').textContent=(masked.match(/.{1,4}/g)||['••••']).join(' '); });
  cardName?.addEventListener('input', () => { document.getElementById('previewCardName').textContent=(cardName.value||'NOME DO CARTÃO').toUpperCase().slice(0,22); });
  cardExpiry?.addEventListener('input', () => { const digits=cardExpiry.value.replace(/\D/g,'').slice(0,4); cardExpiry.value=digits.length>2?digits.slice(0,2)+'/'+digits.slice(2):digits; document.getElementById('previewCardExpiry').textContent=cardExpiry.value||'MM/AA'; });
  copyPix?.addEventListener('click', async () => {
    const code = `SHINKA-DEMO-PIX-${Date.now()}`;
    try { await navigator.clipboard.writeText(code); if(pixCopyStatus){pixCopyStatus.hidden=false;pixCopyStatus.textContent='Código PIX de demonstração copiado.';} } catch (_) { if(pixCopyStatus){pixCopyStatus.hidden=false;pixCopyStatus.textContent='Código visual de demonstração: '+code;} }
  });

  cep.addEventListener('input', () => {
    const digits = cep.value.replace(/\D/g, '').slice(0, 8);
    cep.value = digits.length > 5 ? `${digits.slice(0, 5)}-${digits.slice(5)}` : digits;

    clearTimeout(cepTimer);

    if (digits.length < 8) {
      cepConsultaSequencia++;
      if (cepHelp) cepHelp.textContent = 'Digite seu CEP para consultar a entrega.';
      return;
    }

    if (cepHelp) cepHelp.textContent = 'CEP completo. Buscando endereço…';

    cepTimer = setTimeout(async () => {
      const preenchido = await consultarCep();
      if (preenchido) {
        await consultarPrazo();
      }
    }, 250);
  });

  cep.addEventListener('blur', async () => {
    clearTimeout(cepTimer);
    const digits = cep.value.replace(/\D/g, '');
    if (digits.length !== 8) {
      if (cepHelp) cepHelp.textContent = 'Digite um CEP válido com 8 dígitos.';
      return;
    }

    const preenchido = await consultarCep();
    if (preenchido) {
      await consultarPrazo();
    }
  });

  form.addEventListener('submit', async event => {
    event.preventDefault();
    notice.className = 'checkout-notice';
    notice.textContent = 'Processando pagamento simulado e criando seu pedido…';
    submit.disabled = true;
    const fd = new FormData(form);
    fd.append('itens', JSON.stringify(cart.map(item => ({id: item.id, nome: item.nome, quantidade: item.quantidade, variantId: item.variantId || 0}))));
    fd.append('simular_pagamento', '1');
    try {
      const data = await ShinkaStore.post('api/cliente/checkout', fd);
      ShinkaStore.clear();
      location.href = `pedido.html?id=${data.pedido.id}`;
    } catch (error) {
      notice.textContent = error.message || 'Não foi possível finalizar a compra.';
      if (/estoque|esgotad|dispon[ií]vel|removido/i.test(error.message || '')) {
        const back = document.createElement('a');
        back.href = 'carrinho.html';
        back.textContent = ' Voltar ao carrinho';
        back.style.fontWeight = '700';
        notice.appendChild(back);
      }
      submit.disabled = false;
    }
  });

  drawSummary();
  selectPayment('pix');
  await carregarCliente();
});
