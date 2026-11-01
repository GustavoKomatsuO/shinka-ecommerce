# SHINKA 

### Loja do cliente
- Carrinho completo com quantidades, remoção, limpeza e total.
- Checkout com endereço, cupom, modalidade PAC/SEDEX e forma de pagamento.
- Conta do cliente com endereços e histórico.
- Detalhe do pedido.
- Rastreamento do pedido.
- Consulta de prazo dos Correios no checkout.
- Página de categoria genérica para novas categorias criadas no ADM.

### Categorias
- Botões centralizados.
- Tema exclusivo para Smartphones, Notebooks, Tablets e TVs.
- Textos e tema controláveis pelo ADM.
- Categorias ativas são refletidas na navegação da loja.

### Administração
- Novo módulo **Loja / Categorias**.
- Edição de nome, slug, descrição, tema, destaque, texto principal e status.
- Cadastro de código de rastreio e previsão de entrega nos pedidos.

### Banco
- Novos dados de endereços do cliente.
- Registro de pagamento/forma selecionada.
- Snapshot do endereço dentro do pedido.
- Campos de previsão/status da entrega.
- Migração automática/compatibilidade para banco existente.

### Correios
- Camada isolada em `services/CorreiosService.php`.
- API Rastro para eventos do objeto.
- API Prazo para PAC/SEDEX.
- Token somente no servidor.
- Fallback identificado quando o token não estiver configurado.
- Link oficial do rastreamento como alternativa.

## Limites conhecidos

1. O token real dos Correios não acompanha o ZIP. É uma credencial da instalação/contrato.
2. A API dos Correios pode exigir credenciais e permissões compatíveis com os serviços contratados.
3. O checkout registra PIX/cartão/boleto, mas não cobra o cliente. Gateway de pagamento é uma etapa separada.
4. O valor do frete da versão atual é de referência fixa por modalidade; a API integrada calcula prazo/data, não tarifa comercial. Para cálculo de preço de frete real, deve ser adicionada a API/serviço de preço contratado.

## Validação

- PHP: `php -l` em todos os arquivos PHP.
- JavaScript: `node --check` em todos os arquivos JS.
- Conferência dos caminhos dos arquivos criados/alterados.
