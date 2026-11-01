<?php
/**
 * Consumo da API ViaCEP (https://viacep.com.br/).
 *
 * É aqui que entra a parte "5.3 API" / "5.4 PHP consumindo API" do trabalho:
 * o PHP faz a REQUISIÇÃO HTTP para o ViaCEP, recebe a RESPOSTA em JSON e
 * devolve o endereço já tratado (logradouro, bairro, cidade, estado) para
 * quem chamou — sem o cliente precisar digitar tudo na mão.
 */
class ConsultaCep {

    private const URL_BASE = 'https://viacep.com.br/ws/';

    /**
     * Busca o endereço de um CEP.
     * Devolve sempre um array com 'sucesso' => bool, para quem chamar não
     * precisar tratar exceção: um CEP inválido é uma resposta normal, não
     * um erro de sistema.
     */
    public static function buscarPorCep(string $cep): array {
        $cep = preg_replace('/\D/', '', $cep);

        if (strlen($cep) !== 8) {
            return ['sucesso' => false, 'mensagem' => 'CEP deve ter 8 dígitos.'];
        }

        $corpo = self::requisitar(self::URL_BASE . $cep . '/json/');

        if ($corpo === null) {
            return ['sucesso' => false, 'mensagem' => 'Não foi possível consultar o CEP agora. Tente novamente.'];
        }

        $dados = json_decode($corpo, true);

        // O ViaCEP responde 200 OK com {"erro": true} quando o CEP não existe,
        // então o JSON precisa ser olhado, não só o código HTTP.
        if (!is_array($dados) || !empty($dados['erro'])) {
            return ['sucesso' => false, 'mensagem' => 'CEP não encontrado.'];
        }

        return [
            'sucesso'    => true,
            'cep'        => $dados['cep']        ?? '',
            'logradouro' => $dados['logradouro'] ?? '',
            'bairro'     => $dados['bairro']     ?? '',
            'cidade'     => $dados['localidade'] ?? '',
            'estado'     => $dados['uf']         ?? '',
        ];
    }

    /** Faz a requisição GET e devolve o corpo da resposta, ou null se falhar. */
    private static function requisitar(string $url): ?string {
        if (function_exists('curl_init')) {
            $resposta = self::requisitarComCurl($url, true);

            // Erro 60 = "SSL certificate problem". Comum em redes que
            // inspecionam tráfego HTTPS (antivírus corporativo/escolar,
            // proxy da instituição), onde não há acesso de administrador
            // para trocar o certificado raiz do PHP. Nesse caso específico,
            // tenta de novo sem validar o certificado: a consulta é só de
            // endereço público, sem dado sensível trafegando.
            if ($resposta === null && self::$ultimoErroCurl === 60) {
                $resposta = self::requisitarComCurl($url, false);
            }

            return $resposta;
        }

        // Sem cURL no PHP, tenta file_get_contents (exige allow_url_fopen = On).
        $contexto = stream_context_create([
            'http' => ['timeout' => 6, 'header' => "Accept: application/json\r\n"],
        ]);
        $resposta = @file_get_contents($url, false, $contexto);
        return $resposta !== false ? $resposta : null;
    }

    /** Código do último erro de cURL (0 = sem erro), para requisitar() decidir se tenta de novo. */
    private static int $ultimoErroCurl = 0;

    private static function requisitarComCurl(string $url, bool $verificarSsl): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => $verificarSsl,
            CURLOPT_SSL_VERIFYHOST => $verificarSsl ? 2 : 0,
        ]);
        $resposta = curl_exec($ch);
        self::$ultimoErroCurl = curl_errno($ch);
        $ok = ($resposta !== false) && (self::$ultimoErroCurl === 0);
        curl_close($ch);
        return $ok ? $resposta : null;
    }
}
