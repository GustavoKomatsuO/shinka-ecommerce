<?php
/**
 * SHINKA - integração com a API ViaCEP (https://viacep.com.br/).
 *
 * Responsável por consultar um CEP, tratar a resposta HTTP/JSON e devolver
 * os dados de endereço já normalizados para o restante da aplicação.
 * Não depende de token: é uma API pública, gratuita, sem autenticação.
 */
class ViaCepService
{
    private const BASE_URL = 'https://viacep.com.br/ws';

    /**
     * Consulta o endereço de um CEP na API ViaCEP.
     *
     * @throws InvalidArgumentException CEP com formato inválido.
     * @throws RuntimeException Falha de rede ou resposta inesperada da API.
     */
    public function consultar(string $cep): array
    {
        $cep = preg_replace('/\D/', '', $cep);
        if (strlen($cep) !== 8) {
            throw new InvalidArgumentException('Informe um CEP válido, com 8 dígitos.');
        }

        $url = self::BASE_URL . '/' . $cep . '/json/';
        $resposta = $this->requisitar($url);

        if (!$resposta['ok']) {
            throw new RuntimeException($resposta['message']);
        }

        $dados = $resposta['data'];
        if (!is_array($dados) || !empty($dados['erro'])) {
            throw new RuntimeException('CEP não encontrado.');
        }

        return [
            'cep' => $cep,
            'logradouro' => (string) ($dados['logradouro'] ?? ''),
            'complemento' => (string) ($dados['complemento'] ?? ''),
            'bairro' => (string) ($dados['bairro'] ?? ''),
            'cidade' => (string) ($dados['localidade'] ?? ''),
            'estado' => (string) ($dados['uf'] ?? ''),
        ];
    }

    /**
     * Procura um arquivo CA confiável antes de fazer a requisição.
     *
     * Isso resolve o cenário comum do XAMPP/Windows em que o PHP-cURL não
     * está apontando para um bundle de certificados, sem desativar a
     * validação HTTPS.
     */
    private function localizarCaBundle(): ?string
    {
        $candidatos = [];

        $curlCainfo = trim((string) ini_get('curl.cainfo'));
        if ($curlCainfo !== '') {
            $candidatos[] = $curlCainfo;
        }

        $opensslCafile = trim((string) ini_get('openssl.cafile'));
        if ($opensslCafile !== '') {
            $candidatos[] = $opensslCafile;
        }

        $envBundle = trim((string) getenv('CURL_CA_BUNDLE'));
        if ($envBundle !== '') {
            $candidatos[] = $envBundle;
        }

        // Bundle opcional dentro do próprio projeto.
        $candidatos[] = __DIR__ . '/../config/cacert.pem';

        // Caminhos comuns de PHP/XAMPP no Windows.
        $phpDir = dirname(PHP_BINARY);
        $candidatos[] = $phpDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem';
        $candidatos[] = $phpDir . DIRECTORY_SEPARATOR . 'curl-ca-bundle.crt';
        $candidatos[] = dirname($phpDir) . DIRECTORY_SEPARATOR . 'apache' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'curl-ca-bundle.crt';

        foreach ($candidatos as $arquivo) {
            if ($arquivo !== '' && is_file($arquivo) && is_readable($arquivo)) {
                return $arquivo;
            }
        }

        return null;
    }

    private function requisitar(string $url): array
    {
        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'message' => 'A extensão cURL do PHP está desabilitada. Ative a extensão curl no php.ini do XAMPP e reinicie o Apache.'
            ];
        }

        $caBundle = $this->localizarCaBundle();

        $opcoes = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($caBundle !== null) {
            $opcoes[CURLOPT_CAINFO] = $caBundle;
        } elseif (defined('CURLSSLOPT_NATIVE_CA') && defined('CURLOPT_SSL_OPTIONS')) {
            // Em builds do libcurl que suportam isso, usa o repositório nativo
            // de certificados do Windows sem desligar a validação TLS.
            $opcoes[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $opcoes);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $raw === false) {
            if ($errno === CURLE_SSL_CACERT || $errno === CURLE_PEER_FAILED_VERIFICATION) {
                $extra = $caBundle === null
                    ? ' Configure curl.cainfo/openssl.cafile no php.ini do XAMPP para apontar para um CA bundle válido.'
                    : ' O CA bundle configurado não confia na cadeia apresentada pelo servidor/proxy.';

                return [
                    'ok' => false,
                    'message' => 'Não foi possível validar o certificado HTTPS do ViaCEP (cURL erro ' . $errno . ').' . $extra
                ];
            }

            return [
                'ok' => false,
                'message' => 'Não foi possível consultar o CEP: ' . ($error ?: 'erro de rede')
            ];
        }

        if ($status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'message' => 'ViaCEP retornou o status HTTP ' . $status . '.'
            ];
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return [
                'ok' => false,
                'message' => 'O ViaCEP retornou uma resposta em formato inválido.'
            ];
        }

        return [
            'ok' => true,
            'data' => $data
        ];
    }
}
