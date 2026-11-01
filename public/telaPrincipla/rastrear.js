document.addEventListener('DOMContentLoaded',async()=>{
  const pedido=new URLSearchParams(location.search).get('pedido'),root=document.getElementById('root');
  if(!pedido){root.textContent='Pedido inválido.';return;}
  try{
    const d=await ShinkaStore.json(`${ShinkaStore.API}api/cliente/rastrear&pedido_id=${encodeURIComponent(pedido)}`),p=d.pedido,r=d.rastreio;
    const history=Array.isArray(p.historico_status)?p.historico_status:[];
    let body=`<div class="section-head"><div><div class="eyebrow">SHINKA / RASTREAMENTO</div><h2>Pedido #${p.id}</h2><p class="muted">${p.codigo_rastreio?'Acompanhe a entrega sem precisar copiar códigos.':'O trajeto será atualizado aqui conforme o pedido avançar.'}</p></div><span class="status status-blue">${esc(labelStatus(p.status))}</span></div>`;
    if(p.previsao_entrega)body+=`<div class="notice"><strong>Previsão de entrega:</strong> ${new Date(p.previsao_entrega+'T12:00:00').toLocaleDateString('pt-BR')}</div>`;

    if(history.length){
      body+=`<div class="tracking-route"><div class="route-head"><div><strong>Trajeto do pedido</strong><small>Atualizações registradas pela SHINKA</small></div><span>${history.length} ${history.length===1?'atualização':'atualizações'}</span></div><div class="tracking-timeline friendly-timeline">${history.slice().reverse().map((e,i)=>`<div class="track-item ${i===0?'is-current is-done':''} ${i>0?'is-done':''}"><div class="track-connector"></div><div class="track-dot">${iconForStatus(e.status)}</div><div class="track-copy"><strong>${esc(labelStatus(e.status))}</strong><small>${esc(e.detalhe||humanDetail(e.status))}${e.criado_em?' · '+esc(new Date(String(e.criado_em).replace(' ','T')).toLocaleString('pt-BR')):''}</small></div></div>`).join('')}</div></div>`;
    }else{
      const etapas=[['aguardando_pagamento','Pedido recebido','🧾'],['pago','Pagamento aprovado','✓'],['separacao','Em separação','▣'],['enviado','Em trânsito','🚚'],['entregue','Entregue','⌂']];
      const statusMap={aguardando_pagamento:0,pago:1,separacao:2,enviado:3,entregue:4,cancelado:-1,estornado:-1}; const atual=statusMap[p.status] ?? 0;
      body+=`<div class="tracking-route"><div class="route-head"><div><strong>Trajeto previsto</strong><small>Atualização do status do pedido</small></div><span>SHINKA</span></div><div class="tracking-timeline friendly-timeline">${etapas.map(([key,etapa,icon],i)=>`<div class="track-item ${i<=atual?'is-done':''} ${i===atual?'is-current':''}"><div class="track-connector"></div><div class="track-dot">${icon}</div><div class="track-copy"><strong>${etapa}</strong><small>${i===atual?'Etapa atual do pedido.':i<atual?'Etapa concluída.':'Aguardando atualização.'}</small></div></div>`).join('')}</div></div>`;
    }

    if(r?.eventos?.length){
      const eventos=[...r.eventos].reverse();
      body+=`<div class="tracking-route"><div class="route-head"><div><strong>Atualizações de transporte</strong><small>Eventos devolvidos pela transportadora</small></div><span>${eventos.length} eventos</span></div><div class="tracking-timeline">${eventos.map((e,i)=>`<div class="track-item ${i===0?'is-current is-done':''}"><div class="track-connector"></div><div class="track-dot">🚚</div><div class="track-copy"><strong>${esc(e.status||'Movimentação')}</strong><small>${esc(e.detalhe||'Movimentação registrada')}${e.data?' · '+esc(new Date(String(e.data).replace(' ','T')).toLocaleString('pt-BR')):''}</small></div></div>`).join('')}</div></div>`;
    }else if(p.codigo_rastreio){
      body+=`<div class="notice tracking-code-notice"><strong>Entrega em transporte</strong><br>O código foi associado ao pedido e os eventos detalhados aparecerão quando a consulta retornar dados.</div>`;
    }else{
      body+=`<div class="notice tracking-code-notice"><strong>Rastreamento ainda não disponível</strong><br>Assim que o pedido for enviado, o trajeto de transporte será incorporado nesta página.</div>`;
    }

    body+=`<div class="tracking-support"><div><strong>Precisa de ajuda?</strong><small>Use seu pedido como referência ao falar com o atendimento SHINKA.</small></div><a class="btn btn-secondary" href="conta.html">Ir para minha conta</a></div>`;
    if(r?.url_oficial)body+=`<a class="btn btn-secondary" style="margin-top:12px" href="${esc(r.url_oficial)}" target="_blank" rel="noopener">Abrir consulta oficial dos Correios</a>`;
    root.innerHTML=body;
  }catch(e){root.textContent=e.message||'Não foi possível consultar o rastreamento.'}
});
function humanDetail(status){return({aguardando_pagamento:'Pedido recebido e aguardando pagamento.',pago:'Pagamento confirmado e pedido liberado.',separacao:'Equipe SHINKA preparando os itens.',enviado:'Pedido entregue ao transporte.',entregue:'Entrega concluída.',cancelado:'Pedido cancelado.',estornado:'Pedido estornado.'})[status]||'Atualização registrada.'}
function iconForStatus(status){return({aguardando_pagamento:'🧾',pago:'✓',separacao:'▣',enviado:'🚚',entregue:'⌂',cancelado:'×',estornado:'↺'})[status]||'•'}
function labelStatus(s){return({aguardando_pagamento:'Aguardando pagamento',pago:'Pago',separacao:'Em separação',enviado:'Enviado',entregue:'Entregue',cancelado:'Cancelado',estornado:'Estornado'})[s]||s}
function esc(v){return String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]))}
