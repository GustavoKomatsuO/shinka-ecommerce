<?php
/**
 * Autenticação do cliente.
 *
 * O cadastro sempre cria papel=cliente. Funcionários entram pelo portal
 * administrativo separado; isso evita misturar permissões com o checkout.
 */
require_once __DIR__ . '/../public/config_db/database.php';

class AuthController
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    private function cpfValido(string $cpf): bool
    {
        $cpf = preg_replace('/\D/', '', $cpf);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        $soma = 0;
        for ($i = 0; $i < 9; $i++) {
            $soma += (int) $cpf[$i] * (10 - $i);
        }
        $resto = $soma % 11;
        $digito1 = $resto < 2 ? 0 : 11 - $resto;
        if ($digito1 !== (int) $cpf[9]) {
            return false;
        }

        $soma = 0;
        for ($i = 0; $i < 10; $i++) {
            $soma += (int) $cpf[$i] * (11 - $i);
        }
        $resto = $soma % 11;
        $digito2 = $resto < 2 ? 0 : 11 - $resto;
        return $digito2 === (int) $cpf[10];
    }

    public function cadastrar(): never
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respostaJson(false, 'Método inválido.');
        }

        $nome = trim((string) ($_POST['nome'] ?? ''));
        $cpf = preg_replace('/\D/', '', (string) ($_POST['codigo'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $senha = (string) ($_POST['senha'] ?? '');
        $confirmar = (string) ($_POST['confirmarSenha'] ?? '');

        if ($nome === '' || $cpf === '' || $email === '' || $senha === '' || $confirmar === '') {
            respostaJson(false, 'Preencha todos os campos obrigatórios.');
        }
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 150) {
            respostaJson(false, 'Informe um nome válido.');
        }
        if (!$this->cpfValido($cpf)) {
            respostaJson(false, 'CPF inválido.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            respostaJson(false, 'Informe um e-mail válido.');
        }
        if ($senha !== $confirmar) {
            respostaJson(false, 'As senhas não coincidem.');
        }
        if (strlen($senha) < 6) {
            respostaJson(false, 'A senha deve ter pelo menos 6 caracteres.');
        }

        $stmt = $this->pdo->prepare('SELECT id FROM usuarios WHERE cpf=:cpf OR email=:email LIMIT 1');
        $stmt->execute([':cpf' => $cpf, ':email' => $email]);
        if ($stmt->fetch()) {
            respostaJson(false, 'CPF ou e-mail já cadastrado.');
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO usuarios (nome, cpf, email, senha, papel, ativo)
                 VALUES (:nome, :cpf, :email, :senha, "cliente", 1)'
            );
            $stmt->execute([
                ':nome' => $nome,
                ':cpf' => $cpf,
                ':email' => $email,
                ':senha' => password_hash($senha, PASSWORD_DEFAULT),
            ]);
            respostaJson(true, 'Conta criada com sucesso!');
        } catch (PDOException $e) {
            respostaJson(false, $e->getCode() === '23000' ? 'CPF ou e-mail já cadastrado.' : 'Erro ao registrar usuário.');
        }
    }

    public function login(): never
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respostaJson(false, 'Método inválido.');
        }

        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $senha = (string) ($_POST['senha'] ?? '');

        if ($email === '' || $senha === '') {
            respostaJson(false, 'Informe e-mail e senha.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respostaJson(false, 'Informe um e-mail válido.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, nome, email, senha, papel, ativo
             FROM usuarios WHERE email=:email LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $usuario = $stmt->fetch();

        if (!$usuario || (int) $usuario['ativo'] !== 1 || !password_verify($senha, $usuario['senha'])) {
            respostaJson(false, 'E-mail ou senha incorretos.');
        }

        if ($usuario['papel'] !== 'cliente') {
            respostaJson(false, 'Esta conta possui acesso administrativo. Entre pelo Portal Admin SHINKA.');
        }

        session_regenerate_id(true);
        $_SESSION['usuario_id'] = (int) $usuario['id'];
        $_SESSION['usuario_nome'] = $usuario['nome'];
        $_SESSION['usuario_email'] = $usuario['email'];
        $_SESSION['usuario_papel'] = 'cliente';
        $_SESSION['admin_autenticado'] = false;
        $_SESSION['ultimo_acesso'] = time();

        respostaJson(true, 'Login efetuado com sucesso!', [
            'nome' => $usuario['nome'],
            'papel' => 'cliente',
            'ehAdmin' => false,
            'redirect' => 'telaPrincipla/index.html',
        ]);
    }

    public function logout(): never
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
        respostaJson(true, 'Sessão encerrada.');
    }
}
