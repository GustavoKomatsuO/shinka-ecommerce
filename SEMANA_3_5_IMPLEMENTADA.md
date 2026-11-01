# SHINKA — Semana 3, 4 e 5

## Semana 3
- Produtos: CRUD mantido e edição passa a preservar estoque e dados logísticos quando esses campos não são enviados pelo formulário.
- Categorias: cadastrar, listar, editar e excluir; a exclusão é recusada quando existem produtos vinculados.
- Imagens: fluxo existente preservado.
- Área administrativa: estoque, produtos, categorias e dashboard preservados.
- DAO: `dao/ProdutoDAO.php` e `dao/CategoriaDAO.php` centralizam o CRUD das entidades principais.

## Semana 5 — frete
- Produtos agora armazenam peso e dimensões: `peso_kg`, `altura_cm`, `largura_cm`, `comprimento_cm`.
- O administrador informa esses dados no cadastro/edição do produto.
- O backend monta o peso e as dimensões do pacote a partir dos itens do carrinho.
- O checkout deixa de usar preços fixos por modalidade.
- Com token dos Correios configurado, o sistema consulta preço e prazo das APIs dos Correios usando CEP de origem/destino, peso e dimensões.
- Sem token, o sistema usa uma estimativa local dinâmica baseada nos mesmos dados, para o projeto continuar testável sem credenciais externas.
- ViaCEP continua sendo usado pelo PHP para preencher o endereço a partir do CEP.

## Configuração dos Correios
Edite `config/correios.php`:

- `token`: token de acesso da API dos Correios.
- `origem_cep`: CEP de origem da postagem.
- `nu_dr` e `nu_contrato`: quando exigidos pela credencial/contrato.
- `pac_codigo` e `sedex_codigo`: códigos 03298 e 03220.

Também podem ser usadas as variáveis de ambiente equivalentes implementadas em `CorreiosService.php`.
