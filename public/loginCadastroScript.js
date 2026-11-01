document.addEventListener('DOMContentLoaded', () => {
  /**
   * LOGIN/CADASTRO — apenas comportamento.
   * O CSS continua responsável pelo visual para evitar misturar regras de estilo e JS.
   */
  const authContainer = document.getElementById('authContainer');
  const tabCadastro = document.getElementById('tab-cadastro');
  const tabLogin = document.getElementById('tab-login');
  const tabLoginTop = document.getElementById('tab-login-top');
  const overlayCadastro = document.getElementById('overlayCadastro');
  const welcomeCopy = document.getElementById('welcomeCopy');
  const campoCpf = document.getElementById('cad-cpf');
  const formCadastro = document.getElementById('formCadastro');
  const formLogin = document.getElementById('formLogin');
  const msgCadastro = document.getElementById('msgCadastro');
  const msgLogin = document.getElementById('msgLogin');
  const btnCadastro = document.getElementById('btnCadastro');
  const btnLogin = document.getElementById('btnLogin');
  const togglePassword = document.getElementById('togglePassword');
  const campoSenhaLogin = document.getElementById('log-senha');
  const canvasAnimacao = document.getElementById('welcomeCanvas');
  const animatedShinka = document.getElementById('animatedShinka');

  let modoAtual = 'login';

  // ------------------------------------------------------------
  // Mensagens e utilitários
  // ------------------------------------------------------------
  function exibirMensagem(elemento, texto, tipo = null) {
    if (!elemento) return;
    elemento.textContent = texto;
    elemento.classList.remove('is-error', 'is-success');
    if (tipo) elemento.classList.add(tipo === 'erro' ? 'is-error' : 'is-success');
  }

  function somenteDigitos(valor) {
    return valor.replace(/\D/g, '');
  }

  function cpfValido(cpf) {
    const numero = somenteDigitos(cpf);
    if (numero.length !== 11 || /^(\d)\1{10}$/.test(numero)) return false;

    let soma = 0;
    for (let i = 0; i < 9; i++) soma += Number(numero[i]) * (10 - i);
    let resto = soma % 11;
    const digito1 = resto < 2 ? 0 : 11 - resto;
    if (digito1 !== Number(numero[9])) return false;

    soma = 0;
    for (let i = 0; i < 10; i++) soma += Number(numero[i]) * (11 - i);
    resto = soma % 11;
    const digito2 = resto < 2 ? 0 : 11 - resto;
    return digito2 === Number(numero[10]);
  }

  function formatarCPF(valor) {
    const digitos = somenteDigitos(valor).slice(0, 11);
    if (digitos.length <= 3) return digitos;
    if (digitos.length <= 6) return digitos.replace(/^(\d{3})(\d{1,3})$/, '$1.$2');
    if (digitos.length <= 9) return digitos.replace(/^(\d{3})(\d{3})(\d{1,3})$/, '$1.$2.$3');
    return digitos.replace(/^(\d{3})(\d{3})(\d{3})(\d{1,2})$/, '$1.$2.$3-$4');
  }

  // ------------------------------------------------------------
  // Animação do nome SHINKA no painel azul
  // ------------------------------------------------------------
  let shinkaLetters = [];
  let shinkaSweep = null;
  let shinkaAnimationFrame = null;

  function prepararShinkaAnimado() {
    if (!animatedShinka) return;
    const texto = animatedShinka.textContent.trim();
    animatedShinka.textContent = '';
    shinkaLetters = [...texto].map((letra, indice) => {
      const span = document.createElement('span');
      span.className = 'shinka-letter';
      span.textContent = letra;
      span.dataset.index = indice;
      animatedShinka.appendChild(span);
      return span;
    });

    shinkaSweep = document.createElement('span');
    shinkaSweep.className = 'shinka-sweep';
    animatedShinka.appendChild(shinkaSweep);
    executarAnimacaoShinka();
  }

  function executarAnimacaoShinka() {
    if (!animatedShinka || !shinkaLetters.length) return;
    if (shinkaAnimationFrame) cancelAnimationFrame(shinkaAnimationFrame);
    const inicio = performance.now();
    const duracaoTotal = 2500;

    const animar = (tempo) => {
      const decorrido = tempo - inicio;
      if (decorrido >= duracaoTotal) {
        shinkaLetters.forEach((letra) => {
          letra.style.opacity = '1';
          letra.style.transform = 'translateY(0) scale(1)';
          letra.style.textShadow = '0 0 5px rgba(255,255,255,.15)';
        });
        if (shinkaSweep) shinkaSweep.style.opacity = '0';
        shinkaAnimationFrame = null;
        return;
      }

      shinkaLetters.forEach((letra, indice) => {
        const atraso = indice * 55;
        const progresso = Math.max(0, Math.min(1, (decorrido - atraso) / 550));
        const suavizado = 1 - Math.pow(1 - progresso, 3);
        const brilho = suavizado;
        letra.style.opacity = brilho.toFixed(3);
        letra.style.transform = `translateY(${12 * (1 - suavizado)}px) scale(${.92 + .08 * suavizado})`;
        letra.style.textShadow = `0 0 ${3 + brilho * 8}px rgba(255,255,255,${brilho * .45})`;
      });

      if (shinkaSweep) {
        const sweepTempo = (decorrido - 650) / 1300;
        if (sweepTempo >= 0 && sweepTempo <= 1) {
          shinkaSweep.style.left = `${-20 + sweepTempo * 140}%`;
          shinkaSweep.style.opacity = Math.sin(sweepTempo * Math.PI) * .75;
        } else {
          shinkaSweep.style.opacity = '0';
        }
      }

      shinkaAnimationFrame = requestAnimationFrame(animar);
    };

    shinkaAnimationFrame = requestAnimationFrame(animar);
  }

  prepararShinkaAnimado();

  // ------------------------------------------------------------
  // Canvas do painel azul
  // ------------------------------------------------------------
  let ctxAnimacao = null;
  let larguraCanvas = 0;
  let alturaCanvas = 0;
  let animationId = null;
  let ondas = [];
  let particulas = [];

  function criarOndas() {
    ondas = [
      { amplitude: 28, comprimento: .008, velocidade: .0009, deslocamento: 0, espessura: 2, opacidade: .24 },
      { amplitude: 42, comprimento: .006, velocidade: -.00065, deslocamento: 1200, espessura: 1.5, opacidade: .14 },
      { amplitude: 22, comprimento: .011, velocidade: .0011, deslocamento: 2500, espessura: 1, opacidade: .13 },
      { amplitude: 55, comprimento: .0045, velocidade: -.00045, deslocamento: 3800, espessura: 1, opacidade: .08 }
    ];
  }

  function criarParticulas() {
    particulas = [];
    const quantidade = Math.max(20, Math.floor((larguraCanvas * alturaCanvas) / 20000));
    for (let i = 0; i < quantidade; i++) {
      particulas.push({
        x: Math.random() * larguraCanvas,
        y: Math.random() * alturaCanvas,
        raio: Math.random() * 1.8 + .4,
        velocidadeX: (Math.random() - .5) * .18,
        velocidadeY: (Math.random() - .5) * .10,
        fase: Math.random() * Math.PI * 2,
        velocidade: Math.random() * .0015 + .0005
      });
    }
  }

  function ajustarCanvasLogin() {
    if (!canvasAnimacao || !ctxAnimacao) return;
    const rect = canvasAnimacao.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    larguraCanvas = rect.width;
    alturaCanvas = rect.height;
    canvasAnimacao.width = Math.floor(larguraCanvas * dpr);
    canvasAnimacao.height = Math.floor(alturaCanvas * dpr);
    ctxAnimacao.setTransform(dpr, 0, 0, dpr, 0, 0);
    criarOndas();
    criarParticulas();
  }

  function desenharOndas(tempo) {
    ondas.forEach((onda) => {
      ctxAnimacao.beginPath();
      const linhaBase = alturaCanvas * .5;
      for (let x = -40; x <= larguraCanvas + 40; x += 5) {
        const y = linhaBase + Math.sin(x * onda.comprimento + tempo * onda.velocidade + onda.deslocamento) * onda.amplitude;
        if (x === -40) ctxAnimacao.moveTo(x, y);
        else ctxAnimacao.lineTo(x, y);
      }
      ctxAnimacao.strokeStyle = `rgba(255,255,255,${onda.opacidade})`;
      ctxAnimacao.lineWidth = onda.espessura;
      ctxAnimacao.stroke();
    });
  }

  function desenharBrilhoCentral(tempo) {
    const x = larguraCanvas * .5;
    const y = alturaCanvas * .5;
    const pulso = 1 + Math.sin(tempo * .0015) * .05;
    const raio = Math.min(larguraCanvas, alturaCanvas) * .3 * pulso;
    const gradiente = ctxAnimacao.createRadialGradient(x, y, 0, x, y, raio);
    gradiente.addColorStop(0, 'rgba(255,255,255,.055)');
    gradiente.addColorStop(.4, 'rgba(255,255,255,.018)');
    gradiente.addColorStop(1, 'rgba(255,255,255,0)');
    ctxAnimacao.fillStyle = gradiente;
    ctxAnimacao.fillRect(0, 0, larguraCanvas, alturaCanvas);
  }

  function desenharParticulas(tempo) {
    particulas.forEach((particula) => {
      particula.x += particula.velocidadeX;
      particula.y += particula.velocidadeY;
      if (particula.x < -10) particula.x = larguraCanvas + 10;
      if (particula.x > larguraCanvas + 10) particula.x = -10;
      if (particula.y < -10) particula.y = alturaCanvas + 10;
      if (particula.y > alturaCanvas + 10) particula.y = -10;

      const pulsacao = (Math.sin(tempo * particula.velocidade + particula.fase) + 1) / 2;
      const alpha = .05 + pulsacao * .25;
      ctxAnimacao.beginPath();
      ctxAnimacao.arc(particula.x, particula.y, particula.raio * (.7 + pulsacao * .5), 0, Math.PI * 2);
      ctxAnimacao.fillStyle = `rgba(255,255,255,${alpha})`;
      ctxAnimacao.fill();
    });
  }

  function renderizarAnimacao(tempo) {
    ctxAnimacao.clearRect(0, 0, larguraCanvas, alturaCanvas);
    desenharBrilhoCentral(tempo);
    desenharOndas(tempo);
    desenharParticulas(tempo);
    animationId = requestAnimationFrame(renderizarAnimacao);
  }

  if (canvasAnimacao) {
    ctxAnimacao = canvasAnimacao.getContext('2d');
    ajustarCanvasLogin();
    animationId = requestAnimationFrame(renderizarAnimacao);
    window.addEventListener('resize', ajustarCanvasLogin);
  }

  // ------------------------------------------------------------
  // Painel de cadastro sem vídeo
  // ------------------------------------------------------------
  function prepararPainelCadastro() {
    overlayCadastro?.classList.add('video-finished');
    welcomeCopy?.setAttribute('aria-hidden', 'false');
  }

  // ------------------------------------------------------------
  // Troca entre login e cadastro
  // ------------------------------------------------------------
  function mostrarPainel(nome) {
    const isLogin = nome === 'login';
    modoAtual = isLogin ? 'login' : 'cadastro';

    if (isLogin) {
      authContainer.classList.remove('right-panel-active');
      overlayCadastro?.classList.remove('video-finished');
      welcomeCopy?.setAttribute('aria-hidden', 'true');
      return;
    }

    authContainer.classList.add('right-panel-active');
    prepararPainelCadastro();
  }

  tabCadastro?.addEventListener('click', () => mostrarPainel('cadastro'));
  tabLogin?.addEventListener('click', () => mostrarPainel('login'));
  tabLoginTop?.addEventListener('click', () => mostrarPainel('login'));
  document.querySelectorAll('[data-switch-to]').forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      mostrarPainel(link.dataset.switchTo);
    });
  });

  // ------------------------------------------------------------
  // Mostrar/ocultar senha
  // ------------------------------------------------------------
  togglePassword?.addEventListener('click', () => {
    const visivel = campoSenhaLogin.type === 'text';
    campoSenhaLogin.type = visivel ? 'password' : 'text';
    togglePassword.textContent = visivel ? 'Mostrar' : 'Ocultar';
  });

  // ------------------------------------------------------------
  // CPF em tempo real
  // ------------------------------------------------------------
  function validarCpfEnquantoDigita() {
    if (!campoCpf) return false;
    const numero = somenteDigitos(campoCpf.value);
    campoCpf.classList.remove('cpf-valido', 'cpf-invalido');
    if (numero.length === 0 || numero.length < 11) {
      exibirMensagem(msgCadastro, '', null);
      return false;
    }
    if (!cpfValido(numero)) {
      campoCpf.classList.add('cpf-invalido');
      exibirMensagem(msgCadastro, 'CPF inválido. Verifique os números digitados.', 'erro');
      return false;
    }
    campoCpf.classList.add('cpf-valido');
    exibirMensagem(msgCadastro, '', null);
    return true;
  }

  campoCpf?.addEventListener('input', () => {
    campoCpf.value = formatarCPF(campoCpf.value);
    validarCpfEnquantoDigita();
  });

  campoCpf?.addEventListener('blur', () => {
    const numero = somenteDigitos(campoCpf.value);
    if (numero.length > 0 && numero.length < 11) {
      campoCpf.classList.add('cpf-invalido');
      exibirMensagem(msgCadastro, 'CPF incompleto.', 'erro');
    }
  });

  // ------------------------------------------------------------
  // Comunicação com as APIs PHP
  // ------------------------------------------------------------
  async function enviarFormulario(url, dados) {
    const resposta = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(dados)
    });

    const texto = await resposta.text();
    let resultado;
    try {
      resultado = JSON.parse(texto);
    } catch (erro) {
      console.error(texto);
      throw new Error('O servidor retornou uma resposta inválida.');
    }
    if (!resposta.ok) throw new Error(resultado.message || 'Erro na comunicação com o servidor.');
    return resultado;
  }

  // ------------------------------------------------------------
  // Cadastro
  // ------------------------------------------------------------
  formCadastro?.addEventListener('submit', async (event) => {
    event.preventDefault();
    exibirMensagem(msgCadastro, '', null);

    const dados = {
      nome: document.getElementById('cad-nome').value.trim(),
      codigo: document.getElementById('cad-cpf').value.trim(),
      email: document.getElementById('cad-email').value.trim(),
      senha: document.getElementById('cad-senha').value,
      confirmarSenha: document.getElementById('cad-confirmar').value
    };

    if (!cpfValido(dados.codigo)) {
      campoCpf?.classList.remove('cpf-valido');
      campoCpf?.classList.add('cpf-invalido');
      exibirMensagem(msgCadastro, 'CPF inválido. Verifique os números digitados.', 'erro');
      campoCpf?.focus();
      return;
    }

    if (dados.senha !== dados.confirmarSenha) {
      exibirMensagem(msgCadastro, 'As senhas não coincidem.', 'erro');
      return;
    }

    btnCadastro.disabled = true;
    btnCadastro.textContent = 'Criando conta...';

    try {
      const resultado = await enviarFormulario('index.php?route=api/cadastrar', dados);
      if (!resultado.success) {
        exibirMensagem(msgCadastro, resultado.message, 'erro');
        return;
      }

      exibirMensagem(msgCadastro, resultado.message, 'sucesso');
      formCadastro.reset();
      campoCpf?.classList.remove('cpf-valido', 'cpf-invalido');

      setTimeout(() => {
        mostrarPainel('login');
        const logEmail = document.getElementById('log-email');
        if (logEmail) logEmail.value = dados.email;
        exibirMensagem(msgLogin, 'Conta criada! Agora é só entrar.', 'sucesso');
      }, 900);
    } catch (erro) {
      console.error('Erro no cadastro:', erro);
      exibirMensagem(msgCadastro, erro.message || 'Erro de conexão. Verifique se o servidor está ativo.', 'erro');
    } finally {
      btnCadastro.disabled = false;
      btnCadastro.textContent = 'Criar minha conta';
    }
  });

  // ------------------------------------------------------------
  // Login — redirecionamento de admin é decidido pelo papel do banco.
  // ------------------------------------------------------------
  formLogin?.addEventListener('submit', async (event) => {
    event.preventDefault();
    exibirMensagem(msgLogin, '', null);

    const dados = {
      email: document.getElementById('log-email').value.trim(),
      senha: document.getElementById('log-senha').value
    };

    if (!dados.email || !dados.senha) {
      exibirMensagem(msgLogin, 'Informe e-mail e senha.', 'erro');
      return;
    }

    btnLogin.disabled = true;
    btnLogin.textContent = 'Entrando...';

    try {
      const resultado = await enviarFormulario('index.php?route=api/login', dados);
      if (!resultado.success) throw new Error(resultado.message);

      exibirMensagem(msgLogin, resultado.message, 'sucesso');
      setTimeout(() => {
        const requestedRedirect =
          new URLSearchParams(window.location.search).get('redirect') || '';

        const safeRedirect =
          requestedRedirect.startsWith('telaPrincipla/')
            ? requestedRedirect
            : (resultado.redirect || 'telaPrincipla/index.html');

        window.location.href = safeRedirect;
      }, 500);
    } catch (erro) {
      console.error('Erro no login:', erro);
      exibirMensagem(msgLogin, erro.message || 'Erro de conexão. Verifique se o servidor está ativo.', 'erro');
      btnLogin.disabled = false;
      btnLogin.textContent = 'Entrar na conta';
    }
  });

  mostrarPainel('login');
});
