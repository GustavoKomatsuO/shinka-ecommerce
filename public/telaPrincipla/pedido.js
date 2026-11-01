document.addEventListener('DOMContentLoaded', async () => {
  const id = new URLSearchParams(location.search).get('id');
  const root = document.getElementById('root');

  if (!id) { root.textContent = 'Pedido inválido.'; return; }

  try {
    const data = await ShinkaStore.json(`${ShinkaStore.API}api/cliente/pedido&id=${encodeURIComponent(id)}`);
    const p = data.pedido;
    const addr = p.endereco_entrega || {};
    const pagamento = p.pagamento_status === 'aprovado' ? 'Aprovado' : p.pagamento_status === 'pendente' ? 'Pendente' : p.pagamento_status;
    const history = Array.isArray(p.historico_status) ? p.historico_status : [];
    const steps = buildTimeline(p.status, history);

    root.innerHTML = `
      <div class="section-head">
        <div>
          <div class="eyebrow">SHINKA / PEDIDO</div>
          <h1 class="page-title">Pedido #${p.id}</h1>
          <p class="muted">Realizado em ${new Date(String(p.criado_em).replace(' ','T')).toLocaleString('pt-BR')}</p>
        </div>
        <span class="status status-blue">${esc(labelStatus(p.status))}</span>
      </div>

      <section class="tracking-route order-progress-card">
        <div class="route-head"><div><strong>Acompanhe seu pedido</strong><small>Você vê cada mudança de etapa em um único lugar.</small></div><a class="btn btn-secondary" href="rastrear.html?pedido=${p.id}">Ver rastreamento</a></div>
        <div class="tracking-timeline friendly-timeline">${steps.map((step, i) => `
          <div class="track-item ${step.done ? 'is-done' : ''} ${step.current ? 'is-current' : ''}">
            <div class="track-connector"></div>
            <div class="track-dot" aria-hidden="true">${step.icon}</div>
            <div class="track-copy"><strong>${esc(step.title)}</strong><small>${esc(step.detail)}${step.date ? ' · ' + esc(step.date) : ''}</small></div>
          </div>`).join('')}</div>
      </section>

      <div class="grid-2" style="margin-top:18px">
        <section>
          <div class="panel panel-pad">
            <h2>Itens</h2>
            <div class="stack" style="margin-top:12px">
              ${p.itens.map(i => `<div class="summary-line"><span>${esc(i.nome_produto)} × ${i.quantidade}</span><strong>${ShinkaStore.formatBRL(i.subtotal)}</strong></div>`).join('')}
            </div>
            <div class="summary-line"><span>Subtotal</span><strong>${ShinkaStore.formatBRL(p.subtotal)}</strong></div>
            <div class="summary-line"><span>Frete</span><strong>${ShinkaStore.formatBRL(p.frete)}</strong></div>
            <div class="summary-line"><span>Desconto</span><strong>${ShinkaStore.formatBRL(p.desconto)}</strong></div>
            <div class="summary-line total"><span>Total</span><strong>${ShinkaStore.formatBRL(p.total)}</strong></div>
          </div>

          <div class="panel panel-pad" style="margin-top:16px">
            <h2>Pagamento</h2>
            <p class="muted" style="margin-top:8px">Forma: <strong>${labelPayment(p.forma_pagamento)}</strong></p>
            <p class="muted">Status: <strong>${esc(pagamento)}</strong></p>
            ${p.transacao ? `<p class="muted">Transação: <strong>${esc(p.transacao)}</strong></p>` : ''}
            <div class="notice" style="margin-top:12px">Pagamento processado em modo de demonstração. Nenhuma cobrança real foi feita.</div>
          </div>
        </section>

        <section>
          <div class="panel panel-pad">
            <h2>Entrega</h2>
            <p class="muted">${esc(addr.logradouro || '')} ${esc(addr.numero || '')} · ${esc(addr.cidade || '')}/${esc(addr.estado || '')}</p>
            <p class="muted">CEP ${formatCep(addr.cep)}</p>
            ${p.codigo_rastreio ? `<div class="notice" style="margin-top:12px"><strong>${esc(labelStatus(p.status))}</strong><br>${p.previsao_entrega ? `Previsão: ${new Date(p.previsao_entrega+'T12:00:00').toLocaleDateString('pt-BR')}` : 'Previsão ainda não informada.'}</div><a class="btn btn-primary btn-block" style="margin-top:10px" href="rastrear.html?pedido=${p.id}">Acompanhar entrega</a>` : '<div class="notice">Seu pedido ainda está sendo preparado. O código de rastreio aparecerá quando a expedição for concluída.</div>'}
          </div>
        </section>
      </div>`;
  } catch (e) {
    root.textContent = e.message || 'Pedido não encontrado.';
  }
});

function buildTimeline(status, history) {
  const order = ['aguardando_pagamento','pago','separacao','enviado','entregue'];
  const fallback = [
    ['aguardando_pagamento','Pedido recebido','Pedido registrado na SHINKA.','🧾'],
    ['pago','Pagamento aprovado','Pagamento confirmado para processamento.','✓'],
    ['separacao','Em separação','Seu pedido está sendo preparado.','▣'],
    ['enviado','Enviado','O pedido saiu para transporte.','🚚'],
    ['entregue','Entregue','Entrega concluída.','⌂']
  ];
  const current = order.indexOf(status);
  if (history.length) {
    return history.slice().reverse().map((item, index) => ({
      title: labelStatus(item.status),
      detail: item.detalhe || humanDetail(item.status),
      date: item.criado_em ? new Date(String(item.criado_em).replace(' ','T')).toLocaleString('pt-BR') : '',
      icon: iconForStatus(item.status),
      done: index > 0 || status === item.status,
      current: index === 0
    }));
  }
  return fallback.map(([key,title,detail,icon], i) => ({title, detail: i <= current ? detail : 'Aguardando atualização.', icon, done: i <= current, current: i === current}));
}

function humanDetail(status){ return ({aguardando_pagamento:'Pedido recebido e aguardando pagamento.',pago:'Pagamento confirmado e pedido liberado.',separacao:'Equipe SHINKA preparando os itens.',enviado:'Pedido entregue ao transporte.',entregue:'Pedido entregue ao destinatário.',cancelado:'Pedido cancelado.',estornado:'Pedido estornado.'})[status] || 'Atualização do pedido registrada.'; }
function iconForStatus(status){ return ({aguardando_pagamento:'🧾',pago:'✓',separacao:'▣',enviado:'🚚',entregue:'⌂',cancelado:'×',estornado:'↺'})[status] || '•'; }
function labelStatus(s){ return ({aguardando_pagamento:'Aguardando pagamento',pago:'Pago',separacao:'Em separação',enviado:'Enviado',entregue:'Entregue',cancelado:'Cancelado',estornado:'Estornado'})[s] || s; }
function labelPayment(s){ return ({pix:'PIX',cartao:'Cartão',boleto:'Boleto'})[s] || s || '—'; }
function formatCep(v){ v=String(v||'').replace(/\D/g,''); return v.length===8?v.slice(0,5)+'-'+v.slice(5):v; }
function esc(v){ return String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c])); }
