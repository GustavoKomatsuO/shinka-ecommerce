# SHINKA — mapa da autenticação, Usuario, Produto, Admin e banners

Este arquivo acompanha a versão do projeto analisada em 18/09/2026. Os números abaixo são as linhas da versão entregue neste ZIP.

## 1. Como funciona a autenticação

O projeto separa o login do cliente do login administrativo.

### Sessão

`public/index.php:6` chama `session_start()`. Isso inicia a sessão PHP usada para guardar o usuário autenticado.

### Cliente

`controllers/authController.php` é o fluxo de autenticação do cliente:

- linhas 45–97: `cadastrar()` valida nome, CPF, e-mail e senha e grava o usuário como `papel=cliente`.
- linha 91: a senha é transformada com `password_hash($senha, PASSWORD_DEFAULT)` antes de ir para o banco.
- linhas 99–144: `login()` procura o usuário pelo e-mail e usa `password_verify()` para comparar a senha digitada com o hash salvo.
- linhas 130–136: depois do login, a aplicação cria/regenera a sessão e grava `usuario_id`, `usuario_nome`, `usuario_email`, `usuario_papel` e `admin_autenticado=false`.
- linhas 146–162: `logout()` limpa e destrói a sessão.

A tela desse fluxo é `public/login_cadastro.html` e o JavaScript do formulário está em `public/loginCadastroScript.js`.

### Administrador

O administrador não usa o login do cliente. O login administrativo está em `controllers/AdminAuthController.php`:

- linhas 19–65: `login()`.
- linhas 32–39: busca o usuário em `usuarios` com `papel <> "cliente"`.
- linha 41: exige usuário ativo e valida a senha com `password_verify()`.
- linhas 45–51: cria a sessão administrativa.
- linhas 53–58: escolhe a seção inicial conforme o papel.

A tela é `public/admin/login.html` e o envio do formulário está em `public/admin/adminLoginScript.js`, linhas 46–97.

## 2. Onde o usuário é armazenado

Não existe uma tabela separada para administradores. Todos ficam em `usuarios`.

`database/shinka_db_pronto_importar.sql:22–35` define:

- `id`
- `nome`
- `cpf`
- `email`
- `senha`
- `papel`
- `ativo`

Os papéis existentes são:

`cliente`, `admin_master`, `catalogo`, `logistica`, `suporte`.

O `papel` é o que diferencia cliente de funcionário/admin.

## 3. Classe `Usuario`

Arquivo: `models/Usuario.php`.

- linhas 9–16: classe `Usuario` e conexão com o banco.
- linhas 18–30: `cadastrarCliente()`.
- linhas 32–37: `buscarPorEmail()`.
- linhas 39–44: `buscarPorCpf()`.

A classe `Usuario` é um **modelo**. Ela conversa com a tabela `usuarios`.

O fluxo de autenticação, entretanto, fica nos controllers. Por isso `AuthController` e `AdminAuthController` são responsáveis por validar a entrada e decidir se o login será aceito.

## 4. Classe `Produto`

Arquivo: `models/Produto.php`.

- linhas 9–16: classe `Produto` e conexão com o banco.
- linhas 18–30: `buscarAtivosPorCategoria()`.

Ela representa as operações de consulta do modelo de produto.

O controle administrativo de produtos está separado em `controllers/ProductController.php`:

- linhas 29–57: `exigirAdmin()` verifica sessão administrativa e aceita `admin_master` ou `catalogo`.
- linha 437: `listarPublico()` monta o catálogo público.
- linha 605: `painel()` fornece produtos para o Admin.
- linha 725: `salvar()` cria/edita produtos.
- linha 1444: `excluir()` remove produtos.

## 5. O que é a classe Admin

O projeto não possui `models/Admin.php`.

O conceito de administrador é dividido em duas classes:

### `AdminAuthController`

Arquivo: `controllers/AdminAuthController.php`, linha 10.

Responsável por **autenticar** funcionários/admins.

### `AdminController`

Arquivo: `controllers/AdminController.php`, linha 13.

Responsável pelas operações do painel.

- linhas 22–28: `exigir()` verifica se existe uma sessão administrativa e se o papel tem permissão para a operação.
- linhas 30–40: `me()` retorna os dados do administrador autenticado.
- linhas 43–68: dashboard.
- linhas 71–134: pedidos e atualização de status.
- linhas 136–166: clientes/histórico.
- linhas 167–256: estoque.
- linhas 257–308: auditoria e categorias.
- linhas 309–340: entrega/rastreio.

Portanto, no código, **Admin é um papel + sessão + controllers**, e não uma classe de modelo isolada.

## 6. Como uma rota administrativa fica protegida

O arquivo `public/index.php` é o roteador principal.

Exemplos:

- linhas 175–178: login administrativo → `AdminAuthController::login()`.
- linhas 180–188: sessão do admin → `AdminController::me()`.
- linhas 211–243: catálogo/produtos.
- linhas 341–375: biblioteca de imagens.
- linhas 395–425: banners administrativos.

Para gravações administrativas, o front controller chama `exigirCsrf()` antes de executar a alteração. A implementação do CSRF está em `helpers/security.php:6–38`.

## 7. Proteção da área do cliente

`controllers/CustomerController.php:18–26` contém `clienteId()`.

Ela exige:

`$_SESSION['usuario_id']` válido + `$_SESSION['usuario_papel'] === 'cliente'`.

Assim, um administrador não entra nas rotas de conta do cliente somente por estar autenticado.

## 8. Onde ficam os banners do carrossel no Admin

No painel administrativo:

**Menu lateral → Campanhas → Banner da Home**.

O HTML dessa área está em `public/admin/index.html:460–550`.

Na versão entregue, o painel foi deixado explícito com:

- `BANNERS DO CARROSSEL`
- `Banner da Home`
- texto explicando que os banners ativos aparecem no carrossel da página inicial.

O JavaScript que carrega essa área está em `public/admin/adminScript.js:1915–2144` e o envio do formulário ocorre em `public/admin/adminScript.js:2486–2517`.

## 9. Imagens dos banners

O banco usa a tabela `imagens` e a tabela `banners`.

`database/shinka_db_pronto_importar.sql:55–80` define a biblioteca de imagens.

`database/shinka_db_pronto_importar.sql:289–300` define `banners`.

Quando uma imagem de banner é processada, `helpers/ImageService.php:82–108` cria as versões:

- `banner_desktop` — 1920×720
- `banner_mobile` — 1080×1350

`controllers/ImageController.php:36–108` entrega essas imagens ao navegador.

## 10. O carrossel agora está ligado ao Admin

Antes da alteração, os slides da home estavam fixos em `public/telaPrincipla/index.html:142–240` e o avanço era controlado por `public/telaPrincipla/script.js:123–180`.

Na versão entregue:

- `public/index.php:381–389` criou a rota pública `api/banners`.
- `controllers/MarketingController.php:30–55` retorna apenas banners ativos, em ordem.
- `public/telaPrincipla/script.js:69–154` consulta essa API e monta o carrossel usando os banners ativos.
- `public/telaPrincipla/script.js:261–272` inicializa o carrossel depois da carga.

Se nenhum banner estiver ativo ou a API estiver indisponível, a home mantém os slides estáticos de fallback.

## 11. Fluxo resumido

`Login cliente`

`public/login_cadastro.html`
→ `public/loginCadastroScript.js`
→ `public/index.php?route=api/login`
→ `AuthController::login()`
→ `usuarios`
→ `$_SESSION`

`Login Admin`

`public/admin/login.html`
→ `adminLoginScript.js`
→ `public/index.php?route=api/admin/login`
→ `AdminAuthController::login()`
→ `usuarios.papel`
→ `$_SESSION['admin_autenticado']`

`Banner do carrossel`

`Admin > Campanhas > Banner da Home`
→ `api/admin/banner/salvar`
→ `MarketingController::salvarBanner()`
→ `banners` + `imagens`
→ `api/banners`
→ `public/telaPrincipla/script.js`
→ `carrossel da home`
