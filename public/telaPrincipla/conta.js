document.addEventListener('DOMContentLoaded', load);

async function load() {
  const root = document.getElementById('root');
  try {
    const data = await ShinkaStore.json(`${ShinkaStore.API}api/cliente/me`);
    const u = data.usuario;

    root.innerHTML = `
      <div class="hero-client">
        <div class="eyebrow">SHINKA / MINHA CONTA</div>
        <h1>Olá, ${esc(u.nome.split(' ')[0])}.</h1>
        <p>Consulte seus pedidos, endereços e acompanhe a entrega em um só lugar.</p>
      </div>
      <div style="height:18px"></div>
      <div class="grid-2">
        <section class="panel panel-pad">
          <div class="section-head"><div><h2>Meus dados</h2><p class="muted">Atualize seu nome, e-mail ou senha.</p></div></div>
          <form id="profileForm" class="stack" style="margin-top:15px">
            <div class="grid-2">
              <label class="field"><span>Nome</span><input name="nome" value="${esc(u.nome)}" required></label>
              <label class="field"><span>E-mail</span><input name="email" type="email" value="${esc(u.email)}" required></label>
            </div>
            <p class="muted" style="font-size:12px">Para trocar a senha, preencha os três campos abaixo. Deixe em branco para manter a senha atual.</p>
            <div class="grid-2">
              <label class="field"><span>Senha atual</span><input name="senha_atual" type="password" autocomplete="current-password"></label>
              <label class="field"><span>Nova senha</span><input name="senha_nova" type="password" autocomplete="new-password"></label>
            </div>
            <label class="field"><span>Confirmar nova senha</span><input name="senha_confirmar" type="password" autocomplete="new-password"></label>
            <div id="profileMsg" class="muted" style="font-size:13px"></div>
            <button class="btn btn-primary" type="submit">Salvar dados</button>
          </form>
        </section>

        <section class="panel panel-pad">
          <div class="section-head"><div><h2>Meus endereços</h2><p class="muted">Salve um endereço para agilizar o checkout.</p></div></div>
          <div class="stack" id="addresses">
            ${(data.enderecos || []).map(a => `
              <div class="address-card ${a.principal ? 'primary' : ''}">
                <strong>${esc(a.apelido || 'Endereço de entrega')}${a.principal ? ' · Principal' : ''}</strong>
                <p>${esc(a.destinatario)}</p>
                <p>${esc(a.logradouro)}, ${esc(a.numero)}${a.complemento ? ', ' + esc(a.complemento) : ''}</p>
                <p>${esc(a.bairro)} · ${esc(a.cidade)}/${esc(a.estado)} · ${formatCep(a.cep)}</p>
              </div>`).join('') || '<div class="empty">Nenhum endereço salvo.</div>'}
          </div>
          <form id="addressForm" class="stack" style="margin-top:15px">
            <div class="grid-2">
              <label class="field"><span>Apelido</span><input name="apelido" placeholder="Casa"></label>
              <label class="field"><span>Destinatário</span><input name="destinatario" value="${esc(u.nome)}" required></label>
            </div>
            <div class="grid-2">
              <label class="field"><span>CEP</span><input name="cep" id="enderecoCep" maxlength="9" required></label>
              <label class="field"><span>UF</span><input name="estado" id="enderecoEstado" maxlength="2" required></label>
            </div>
            <div id="enderecoCepHelp" class="muted" style="font-size:12px"></div>
            <div class="grid-2">
              <label class="field"><span>Logradouro</span><input name="logradouro" id="enderecoLogradouro" required></label>
              <label class="field"><span>Número</span><input name="numero" required></label>
            </div>
            <div class="grid-2">
              <label class="field"><span>Complemento</span><input name="complemento"></label>
              <label class="field"><span>Bairro</span><input name="bairro" id="enderecoBairro" required></label>
            </div>
            <label class="field"><span>Cidade</span><input name="cidade" id="enderecoCidade" required></label>
            <label style="font-size:12px;font-weight:700"><input type="checkbox" name="principal" value="1"> Tornar principal</label>
            <button class="btn btn-primary" type="submit">Salvar endereço</button>
          </form>
        </section>
      </div>
      <div style="height:18px"></div>
      <section class="panel panel-pad">
        <div class="section-head"><div><h2>Pedidos recentes</h2><p class="muted">Histórico das compras.</p></div></div>
        <div class="stack">
          ${(data.pedidos || []).map(order => orderCard(order)).join('') || '<div class="empty">Você ainda não fez pedidos.</div>'}
        </div>
      </section>

      <div style="height:18px"></div>
      <section class="panel panel-pad">
        <div class="section-head">
          <div>
            <h2>Sair da conta</h2>
            <p class="muted">Encerre apenas a sessão atual. Sua conta e seus dados continuarão salvos.</p>
          </div>
        </div>
        <button id="btnLogoutCliente" class="btn btn-danger" type="button">Sair da conta</button>
      </section>
    `;

    // Autopreenchimento de endereço via ViaCEP (consumido pelo backend PHP).
    const enderecoCep = document.getElementById('enderecoCep');
    const enderecoCepHelp = document.getElementById('enderecoCepHelp');
    enderecoCep?.addEventListener('input', () => {
      const digits = enderecoCep.value.replace(/\D/g, '').slice(0, 8);
      enderecoCep.value = digits.length > 5 ? `${digits.slice(0, 5)}-${digits.slice(5)}` : digits;
    });
    enderecoCep?.addEventListener('blur', async () => {
      const value = enderecoCep.value.replace(/\D/g, '');
      if (value.length !== 8) return;
      enderecoCepHelp.textContent = 'Consultando CEP…';
      try {
        const d = await ShinkaStore.json(`${ShinkaStore.API}api/cliente/cep&cep=${value}`);
        const endereco = d.endereco || {};
        document.getElementById('enderecoLogradouro').value = endereco.logradouro || '';
        document.getElementById('enderecoBairro').value = endereco.bairro || '';
        document.getElementById('enderecoCidade').value = endereco.cidade || '';
        document.getElementById('enderecoEstado').value = endereco.estado || '';
        enderecoCepHelp.textContent = 'Endereço preenchido automaticamente via ViaCEP.';
      } catch (err) {
        enderecoCepHelp.textContent = err.message || 'Não foi possível encontrar este CEP.';
      }
    });

    root.querySelector('#addressForm').addEventListener('submit', async e => {
      e.preventDefault();
      try {
        await ShinkaStore.post('api/cliente/endereco/salvar', new FormData(e.target));
        location.reload();
      } catch (err) {
        alert(err.message);
      }
    });

    root.querySelector('#profileForm').addEventListener('submit', async e => {
      e.preventDefault();
      const msg = document.getElementById('profileMsg');
      msg.textContent = 'Salvando...';
      try {
        const resposta = await ShinkaStore.post('api/cliente/atualizar', new FormData(e.target));
        msg.textContent = resposta.message || 'Dados atualizados com sucesso.';
        e.target.senha_atual.value = '';
        e.target.senha_nova.value = '';
        e.target.senha_confirmar.value = '';
      } catch (err) {
        msg.textContent = err.message || 'Não foi possível atualizar seus dados.';
      }
    });

    root.querySelector('#btnLogoutCliente').addEventListener('click', async () => {
      const confirmar = window.confirm('Deseja sair da sua conta? Seus dados e cadastro não serão excluídos.');
      if (!confirmar) return;

      const button = root.querySelector('#btnLogoutCliente');
      button.disabled = true;
      button.textContent = 'Saindo...';

      try {
        await ShinkaStore.post('api/logout', new FormData());
        window.location.href = '../login_cadastro.html';
      } catch (err) {
        button.disabled = false;
        button.textContent = 'Sair da conta';
        alert(err.message || 'Não foi possível encerrar a sessão.');
      }
    });
  } catch (e) {
    root.innerHTML = `<div class="panel panel-pad"><h2>Entre na sua conta</h2><p class="muted">${esc(e.message)}</p><a class="btn btn-primary" href="../login_cadastro.html">Entrar / criar conta</a></div>`;
  }
}

function orderCard(o) {
  const statusClass = ['entregue', 'pago'].includes(o.status) ? 'status-green' : ['cancelado', 'estornado'].includes(o.status) ? 'status-red' : 'status-orange';
  return `<article class="order-card" style="border:1px solid var(--shinka-line);border-radius:16px">
    <div class="order-top">
      <div><strong>Pedido #${o.id}</strong><div class="muted">${new Date(String(o.criado_em).replace(' ', 'T')).toLocaleDateString('pt-BR')}</div></div>
      <div class="order-total">${ShinkaStore.formatBRL(o.total)}</div>
    </div>
    <div class="order-meta">
      <span class="status ${statusClass}">${labelStatus(o.status)}</span>
      ${o.codigo_rastreio ? `<span class="status status-blue">${esc(o.codigo_rastreio)}</span>` : ''}
      ${o.previsao_entrega ? `<span class="status status-blue">Previsto ${new Date(o.previsao_entrega + 'T12:00:00').toLocaleDateString('pt-BR')}</span>` : ''}
    </div>
    <div class="order-actions">
      <a class="btn btn-secondary" href="pedido.html?id=${o.id}">Ver pedido</a>
      ${o.codigo_rastreio ? `<a class="btn btn-primary" href="rastrear.html?pedido=${o.id}">Acompanhar entrega</a>` : ''}
    </div>
  </article>`;
}

function labelStatus(s) {
  return ({ aguardando_pagamento: 'Aguardando pagamento', pago: 'Pago', separacao: 'Em separação', enviado: 'Enviado', entregue: 'Entregue', cancelado: 'Cancelado', estornado: 'Estornado' })[s] || s;
}

function formatCep(v) {
  v = String(v || '').replace(/\D/g, '');
  return v.length === 8 ? v.slice(0, 5) + '-' + v.slice(5) : v;
}

function esc(v) {
  return String(v ?? '').replace(/[&<>'"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[c]));
}
