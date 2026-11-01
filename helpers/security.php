<?php
/**
 * SHINKA — funções de segurança.
 */

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] =
            bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function exigirCsrf(): void {
    $token =
        $_SERVER['HTTP_X_CSRF_TOKEN']
        ??
        $_POST['csrf_token']
        ??
        '';

    if (
        !$token ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $token
        )
    ) {
        http_response_code(419);

        respostaJson(
            false,
            'Token de segurança inválido ou expirado. Recarregue a página.'
        );
    }
}

function respostaJson(
    bool $success,
    string $message = '',
    array $data = []
): never {
    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message
            ],
            $data
        ),
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/**
 * Traduz erros do banco (PDOException) para mensagens que o usuário
 * entende. Nunca devolve o texto cru do MySQL (SQLSTATE, nomes de
 * tabela/constraint), que não ajuda o usuário e expõe a estrutura do banco.
 *
 * Códigos MySQL tratados:
 *  1451 = tentou apagar/alterar um registro que ainda é referenciado;
 *  1452 = tentou gravar uma referência a um registro que não existe;
 *  1062 = valor duplicado em coluna única;
 *  1406 = texto maior que o campo comporta;
 *  1205/1213 = bloqueio/deadlock (sistema ocupado).
 */
function mensagemErroBanco(Throwable $e, string $padrao): string {
    $driver = 0;
    if ($e instanceof PDOException && isset($e->errorInfo[1])) {
        $driver = (int)$e->errorInfo[1];
    }

    return match ($driver) {
        1451 => 'Este registro ainda está em uso por outros dados do sistema e não pode ser excluído.',
        1452 => 'Algum item relacionado (categoria, imagem ou produto) não existe mais. Recarregue a página e tente novamente.',
        1062 => 'Já existe um registro com esse valor. Verifique os campos únicos (como código, slug ou SKU).',
        1406 => 'Algum campo excede o tamanho máximo permitido.',
        1205, 1213 => 'O sistema está ocupado no momento. Tente novamente em instantes.',
        default => $padrao,
    };
}

/**
 * Resolve uma exceção capturada em uma rota da API e responde em JSON:
 *  - PDOException  -> mensagem amigável (nunca o SQL), status 409/500;
 *  - erros de regra de negócio (DomainException, InvalidArgumentException,
 *    RuntimeException) -> a mensagem escrita pelo próprio sistema, status 409;
 *  - qualquer outro erro -> mensagem genérica, com o detalhe só no error_log.
 */
function responderExcecao(Throwable $e, string $padrao, string $contexto = ''): never {
    if ($e instanceof PDOException) {
        error_log('SHINKA ' . $contexto . ' [PDO]: ' . $e->getMessage());
        $driver = (int)($e->errorInfo[1] ?? 0);
        http_response_code(in_array($driver, [1451, 1452, 1062], true) ? 409 : 500);
        respostaJson(false, mensagemErroBanco($e, $padrao));
    }

    if (
        $e instanceof DomainException ||
        $e instanceof InvalidArgumentException ||
        $e instanceof RuntimeException
    ) {
        $mensagem = trim($e->getMessage());
        http_response_code(409);
        respostaJson(false, $mensagem !== '' ? $mensagem : $padrao);
    }

    error_log('SHINKA ' . $contexto . ': ' . $e->getMessage());
    http_response_code(500);
    respostaJson(false, $padrao);
}

function registrarAdminLog(
    PDO $pdo,
    string $acao,
    ?string $entidade = null,
    ?int $entidadeId = null,
    array $detalhes = []
): void {
    if (
        empty(
            $_SESSION['usuario_id']
        )
    ) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO admin_logs (
            usuario_id,
            acao,
            entidade,
            entidade_id,
            detalhes
        ) VALUES (
            :usuario,
            :acao,
            :entidade,
            :entidade_id,
            :detalhes
        )'
    );

    $stmt->execute([
        ':usuario' =>
            (int)$_SESSION['usuario_id'],

        ':acao' =>
            $acao,

        ':entidade' =>
            $entidade,

        ':entidade_id' =>
            $entidadeId,

        ':detalhes' =>
            $detalhes
                ? json_encode(
                    $detalhes,
                    JSON_UNESCAPED_UNICODE
                )
                : null
    ]);
}