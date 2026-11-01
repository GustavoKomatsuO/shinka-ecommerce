# SHINKA · E-commerce

Aplicação web de comércio eletrônico desenvolvida como **projeto acadêmico**, com PHP, banco relacional e JavaScript. Reúne a jornada do cliente e um painel administrativo para praticar desenvolvimento web, orientação a objetos, persistência de dados e consumo de APIs.

**Status:** projeto acadêmico em evolução · **Pagamentos:** simulados · **Ambiente recomendado:** XAMPP com PHP 8.2 e MariaDB.

[Executar localmente](#executar-localmente) · [Arquitetura](#arquitetura) · [Como testar](docs/COMO_TESTAR.md) · [Contribuir](CONTRIBUTING.md)

## Funcionalidades

| Área | Recursos implementados no código |
| --- | --- |
| Loja | Catálogo, pesquisa, categorias, página de produto, imagens e variantes. |
| Cliente | Cadastro, login, edição de perfil, endereços e encerramento de sessão. |
| Compra | Carrinho no navegador, checkout, cupons, registro de pedidos e pagamento simulado por PIX, cartão ou boleto. |
| Entrega | Consulta de CEP pelo ViaCEP, estimativas de frete/prazo e serviço de integração com os Correios. |
| Administração | Login administrativo, painel, produtos, categorias, estoque, clientes, pedidos, banners, cupons e registros de auditoria. |
| Imagens | Upload e processamento com GD; novos arquivos ficam no disco e o banco armazena seus caminhos. |

Sem credenciais dos Correios, preço e prazo usam estimativas locais. O rastreamento oficial depende do acesso à API. **O checkout não realiza cobranças reais** e deve ser usado com dados fictícios.

## Tecnologias

- **Backend:** PHP, sessões, PDO e respostas JSON.
- **Banco:** MariaDB, com tabelas relacionais e scripts SQL de instalação/migração.
- **Frontend:** HTML5, CSS3 e JavaScript, com Fetch API e armazenamento local do carrinho.
- **Integrações:** ViaCEP e serviço dos Correios.
- **Ambiente local:** Apache/XAMPP ou servidor embutido do PHP.

O projeto não usa framework PHP nem exige instalação via Composer ou npm. O código usa recursos do PHP 8.1+, como o tipo `never`; PHP 8.2 é a referência para a verificação de sintaxe.

## Arquitetura

O frontend consulta `public/index.php?route=...`. Esse arquivo seleciona o controller, que aplica as regras, acessa o banco via PDO/DAOs e chama os serviços de integração quando necessário. A resposta JSON atualiza a interface pelo JavaScript.

| Diretório | Responsabilidade |
| --- | --- |
| `public/` | Entrada HTTP, telas, scripts e estilos. |
| `controllers/` | Tratamento de requisições e regras dos fluxos de autenticação, catálogo, cliente e administração. |
| `models/` | Classes de usuário, produto, carrinho e item do carrinho. |
| `dao/` | Classes de acesso a dados de categorias e produtos. |
| `services/` | Consultas de CEP, frete, prazo e rastreamento. |
| `helpers/` | Respostas JSON, sessão, CSRF e processamento de imagens. |
| `public/config_db/` | Conexão PDO e inicialização do esquema. |
| `config/` | Exemplos de configuração e ajustes locais ignorados pelo Git. |
| `database/` | Instalação SQL e migrações para versões anteriores. |
| `tools/` | Utilitários de manutenção. |
| `docs/` | Validação, limitações e documentação complementar. |

A organização é inspirada em MVC. Parte dos controllers acessa PDO diretamente; a separação em camadas ainda pode evoluir.

## Executar localmente

### 1. Obter o projeto

No terminal, dentro de `C:\xampp\htdocs`:

```bash
git clone https://github.com/GustavoKomatsuO/shinka-ecommerce.git projeto_ecommerce
cd projeto_ecommerce
```

O nome local `projeto_ecommerce` preserva referências existentes em telas antigas.

### 2. Preparar o PHP e o banco

1. Inicie **Apache** e **MySQL/MariaDB** no XAMPP.
2. No `php.ini`, habilite `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `curl` e `openssl`; reinicie o Apache após mudanças.
3. Em uma instalação nova, importe **`database/shinka_db_pronto_importar.sql`** no phpMyAdmin. Ele cria `shinka_db`, tabelas e catálogo de demonstração.
4. Se precisar de credenciais próprias, copie `config/database.local.php.example` para `config/database.local.php` e ajuste os valores. O arquivo local não é versionado.

Também são aceitas `DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PASSWORD` como variáveis de ambiente, com precedência sobre o arquivo local. Não há carregamento automático de `.env`. Se mudar o nome do banco, adapte também o `CREATE DATABASE` e o `USE` do SQL antes de importar.

**Compatibilidade:** há comandos `ADD COLUMN IF NOT EXISTS` usados pelo MariaDB. A execução no MySQL precisa de adaptação e validação específicas; não foi comprovada nesta publicação. A inicialização atual exige permissão para criar banco e alterar tabelas.

### 3. Abrir as telas

| Tela | Endereço no XAMPP |
| --- | --- |
| Loja | `http://localhost/projeto_ecommerce/public/telaPrincipla/index.html` |
| Login/cadastro | `http://localhost/projeto_ecommerce/public/login_cadastro.html` |
| Administração | `http://localhost/projeto_ecommerce/public/admin/login.html` |

Alternativa, com o banco já ativo, a partir da raiz:

```bash
php -S localhost:8000 -t public
```

Abra `http://localhost:8000/telaPrincipla/index.html` ou `http://localhost:8000/admin/login.html`. O servidor embutido serve para desenvolvimento local.

### 4. Criar os acessos locais

O repositório não inclui contas, senhas ou hashes de usuários. Cadastre uma conta pela tela da loja com dados fictícios. Para transformá-la em administrador, execute na raiz, substituindo o argumento pelo e-mail que você cadastrou:

```bash
php tools/promover_admin.php seu-email
```

O utilitário funciona somente no terminal e promove uma conta existente. Saia da sessão e entre novamente no login administrativo. Para testar a área do cliente, cadastre uma segunda conta e mantenha seu papel original.

### 5. Imagens e integrações

- O upload exige GD e permissão de escrita. Na estrutura recebida, a pasta **`img/` é criada ao lado da pasta do projeto**: no XAMPP, `C:\xampp\htdocs\img`. Essa organização foi preservada; o banco mantém caminhos relativos `img/...` e o PHP entrega as imagens pelo endpoint próprio.
- Os produtos de demonstração podem precisar de imagens vinculadas pelo painel administrativo.
- Para configurar os Correios, copie `config/correios.php.example` para `config/correios.php` ou use as variáveis lidas por `services/CorreiosService.php`, como `CORREIOS_TOKEN` e `CORREIOS_ORIGEM_CEP`. Mantenha as credenciais no ambiente local.
- ViaCEP usa consulta pública e depende de acesso à internet. Para certificados do XAMPP, consulte o guia de instalação e configure um CA bundle confiável.

## Documentação e validação

- [Guia de instalação](GUIA_INSTALACAO.md)
- [Guia do sistema: cliente e administrador](GUIA_SISTEMA.md)
- [Visão do console administrativo](RELATORIO_ADMIN.md)
- [Como testar e limites da validação](docs/COMO_TESTAR.md)
- [Limitações e próximas melhorias](docs/LIMITACOES.md)
- [Orientações de contribuição](CONTRIBUTING.md)

A automação em `.github/workflows/validacao.yml` verifica sintaxe PHP e JavaScript. Ela não substitui os testes dos fluxos com banco de dados e serviços externos.

## Contexto do projeto

Projeto de portfólio acadêmico de [Gustavo Matsuo Komatsuzaki](https://github.com/GustavoKomatsuO), voltado à aplicação prática dos estudos de desenvolvimento web e banco de dados. A publicação preserva a implementação enviada, com ajustes de entrada/configuração e documentação para reprodução local.

Nenhuma licença de uso foi adicionada nesta publicação. Imagens, vídeo e outros materiais mantêm os direitos de seus respectivos titulares.
