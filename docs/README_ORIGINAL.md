# SHINKA — E-commerce

Sistema de e-commerce em PHP + MySQL com loja pública, conta do cliente, carrinho, checkout, categorias personalizáveis, catálogo com variações/imagens e console administrativo.

## Principais recursos

### Cliente

- cadastro e login;
- pesquisa e filtros por catálogo;
- páginas de categoria com identidade visual própria;
- página de produto com galeria/variações;
- carrinho persistente no navegador;
- checkout com endereço, cupom, pagamento e frete;
- histórico de pedidos;
- página individual do pedido;
- rastreamento com integração server-side dos Correios.

### ADM

- visão geral;
- catálogo de produtos;
- estoque;
- clientes;
- pedidos/OMS;
- imagens e variações;
- campanhas;
- auditoria;
- **Loja / Categorias**, para alterar textos, tema e ativação das categorias exibidas ao cliente.

## Categorias

As quatro categorias originais mantêm páginas exclusivas:

```text
public/telaPrincipla/categoria/
├── smartphones/
├── notebooks/
├── tablets/
└── tv/
```

Cada uma recebeu alinhamento central dos botões e uma linguagem visual própria, mantendo o padrão SHINKA.

Categorias novas criadas pelo ADM usam automaticamente:

`public/telaPrincipla/categoria.html?slug=...`

## Correios

`services/CorreiosService.php` centraliza:

- consulta de rastreio;
- consulta de prazo;
- normalização do retorno para o cliente;
- link oficial de rastreamento.

Configure `config/correios.php` ou as variáveis de ambiente. A credencial permanece no servidor.

Arquivo de exemplo:

`config/correios.php.example`

## Banco

- `database/shinka_db.sql` — instalação completa;
- `database/migracao_existente.sql` — migração de banco antigo;
- `public/config_db/database.php` — criação/garantia automática das estruturas necessárias.

## Guias

- `GUIA_SISTEMA.md` — manual simples do ADM e do usuário;
- `GUIA_INSTALACAO.md` — instalação, configuração e publicação;
- `RELATORIO_ADMIN.md` — visão detalhada do console administrativo;
- `IMPLEMENTACAO.md` — histórico técnico das melhorias.

## Estrutura resumida

```text
controllers/    regras de negócio
services/       integrações externas
models/         entidades/modelos
helpers/        segurança e utilidades
database/       SQL e migrações
public/admin/   interface administrativa
public/telaPrincipla/ loja do cliente
config/         configurações locais
```

## Validação realizada nesta entrega

Os arquivos PHP foram verificados com `php -l` e os arquivos JavaScript com `node --check`.

## Observação importante sobre pagamento

O checkout registra PIX, cartão ou boleto e o status da transação no banco. Esta entrega não inclui cobrança financeira real; para isso é necessária uma integração com um gateway de pagamento.


## Atualizações desta versão

- Checkout de uma página com experiência inspirada em padrões modernos de e-commerce.
- Pagamento simulado para PIX, cartão e boleto, sem cobrança real.
- Barra de pesquisa e filtros no catálogo administrativo.
- Editor de produto por etapas, com fechar/cancelar funcionando.
- Tema abstrato exclusivo para Smartphones sem desenho de aparelho.
- Estrutura de documentação para manutenção modular.


### Banco pronto para importação

Use `database/shinka_db_pronto_importar.sql` no phpMyAdmin.
Acessos: siga a criação local de contas descrita no README principal.
