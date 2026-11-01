# Instalação do SHINKA

O passo a passo atualizado, incluindo requisitos, importação, contas de demonstração e URLs, está em [Executar localmente no README](README.md#executar-localmente).

## Configurações locais

- Banco: copie `config/database.local.php.example` para `config/database.local.php`.
- Correios: copie `config/correios.php.example` para `config/correios.php`.
- Os arquivos locais são ignorados pelo Git. Não inclua credenciais em commits.
- Não há leitura automática de `.env`; defina variáveis no processo/servidor ou use os arquivos PHP locais.

## Extensões e HTTPS

Ative PDO MySQL, mbstring, fileinfo, GD, cURL e OpenSSL no PHP. Configure `curl.cainfo` e `openssl.cafile` no `php.ini` com um CA bundle confiável quando o XAMPP não reconhecer os certificados HTTPS. Reinicie o Apache.

## Banco existente

Faça backup antes de aplicar os scripts de `database/`. `migracao_existente.sql` e os scripts de imagens atendem estruturas antigas; revise a necessidade de cada um antes de executar. Para instalação nova, use somente `shinka_db_pronto_importar.sql`.

## Armazenamento

O código recebido usa `img/` no diretório pai do projeto. Garanta permissão de escrita para o PHP nesse local. Veja os detalhes no README.
