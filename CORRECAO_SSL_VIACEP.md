# Correção do erro SSL do ViaCEP no XAMPP

Se aparecer:

`SSL certificate problem: self signed certificate in certificate chain`

isso significa que o PHP/cURL não conseguiu validar a cadeia de certificados HTTPS usada para acessar o ViaCEP.

## O que foi corrigido no projeto

O `services/ViaCepService.php` agora:

- mantém `CURLOPT_SSL_VERIFYPEER = true`;
- mantém `CURLOPT_SSL_VERIFYHOST = 2`;
- procura `curl.cainfo` no `php.ini`;
- procura `openssl.cafile`;
- aceita `CURL_CA_BUNDLE`;
- procura automaticamente um `cacert.pem` em `config/`;
- procura os caminhos comuns do PHP/XAMPP no Windows;
- usa o repositório nativo de certificados do Windows quando o build do cURL oferece `CURLSSLOPT_NATIVE_CA`;
- retorna uma mensagem mais clara quando a cadeia não pode ser validada.

## Configuração recomendada no XAMPP

1. Abra `C:\xampp\php\php.ini`.
2. Procure por:

```ini
curl.cainfo=
openssl.cafile=
```

3. Configure os dois apontando para um bundle CA válido, por exemplo:

```ini
curl.cainfo="C:\xampp\php\extras\ssl\cacert.pem"
openssl.cafile="C:\xampp\php\extras\ssl\cacert.pem"
```

4. Reinicie o Apache no XAMPP.
5. Atualize a página do checkout e teste novamente o CEP.

Não desative `CURLOPT_SSL_VERIFYPEER` para esconder o erro. Isso desliga a verificação TLS e deixa a conexão vulnerável a interceptação. O cURL recomenda usar um CA store/bundle válido em vez de `-k`/modo insecure.

## Caso a máquina use antivírus ou proxy com inspeção HTTPS

Se o navegador abre o ViaCEP normalmente, mas o PHP continua acusando `self signed certificate in certificate chain`, pode existir uma autoridade certificadora local do antivírus/proxy que precisa ser instalada no CA store utilizado pelo PHP/cURL. Nesse caso, não é adequado simplesmente desligar a validação SSL.
