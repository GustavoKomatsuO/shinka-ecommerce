# Limitações e próximos passos

- Pagamentos são simulados; não há integração com gateway financeiro.
- Frete e prazo podem ser estimados localmente. Integrações oficiais dependem de credenciais e conectividade.
- O código mantém DDL automático durante a conexão; migrar para migrações explícitas é uma melhoria futura.
- Validar/adaptar o SQL antes de usar MySQL: o projeto contém sintaxe própria do MariaDB.
- O serviço `services/cep_api.php` recebido contém uma tentativa de consulta sem validação TLS após erro cURL 60. Esse comportamento não foi introduzido nesta publicação e deve ser substituído por configuração correta de certificados antes de exposição pública.
- Há referências antigas a caminhos locais e duplicação entre telas. A execução recomendada usa o nome local `projeto_ecommerce` e a entrada `public/index.php`.
- As imagens enviadas são gravadas em `img/` no diretório pai do projeto. Centralizar esse caminho em configuração é uma melhoria futura.
- Utilitários de manutenção são destinados a uso local. As contas padrão do pacote original foram removidas.
- Ainda faltam testes automatizados de integração, revisão de segurança para implantação e comprovação de compatibilidade entre versões de banco.

A documentação descreve o comportamento encontrado no código; não declara o sistema pronto para operação comercial.
