# SHINKA — Guia de desenvolvimento

## Arquitetura

O projeto usa uma arquitetura modular em camadas. A entrada HTTP fica em `public/index.php`; controllers recebem a requisição; serviços concentram integrações; helpers concentram utilidades; o acesso a dados usa PDO.

### Regras de manutenção

1. **Uma responsabilidade por arquivo/função.**
2. **Nomes explícitos** em português quando o domínio estiver em português.
3. **Front-end sem SQL.** O navegador usa a API JSON.
4. **Integrações externas isoladas.** Correios fica em `services/CorreiosService.php`.
5. **Segredos fora do JavaScript.** Tokens e credenciais ficam no PHP/configuração do servidor.
6. **Validação server-side sempre.** Validação do navegador melhora UX, mas não substitui PHP.

## Git recomendado

Para trabalhos em equipe: `main` como versão estável e branches curtas (`feature/catalogo-filtros`, `fix/checkout-pagamento`). Use Conventional Commits, por exemplo `feat: adicionar pagamento simulado`.

## Testes

Antes de entregar:

- `php -l` em todos os PHP.
- Validação de JavaScript sem erros de sintaxe.
- Importação limpa do SQL em banco vazio.
- Login cliente e ADM.
- CRUD de produto/categoria.
- Carrinho → checkout → pedido.
- Fluxo de pagamento simulado.
- Consulta de prazo/rastreio quando a integração estiver configurada.
