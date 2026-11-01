<?php
/**
 * SHINKA — Front Controller.
 */

session_start();

require_once __DIR__ . '/../helpers/security.php';

$route = trim(
    (string)(
        $_GET['route'] ?? ''
    ),
    '/'
);

if ($route === '') {
    $route = 'home';
}

/**
 * Rede de segurança da API: qualquer erro que não tenha sido tratado dentro
 * de um controller volta como JSON legível (e não como página de erro em
 * HTML, que o painel mostrava como "O servidor retornou uma resposta
 * inválida"). O texto técnico (SQL, caminhos) fica só no error_log.
 */
if (str_starts_with($route, 'api/')) {
    set_exception_handler(static function (Throwable $e) use ($route): void {
        responderExcecao(
            $e,
            'Ocorreu um erro inesperado. Tente novamente; se continuar, avise o administrador do sistema.',
            'rota ' . $route
        );
    });
}

switch ($route) {
    case 'home':
        header(
            'Location: telaPrincipla/index.html'
        );
        exit;

    case 'login_cadastro':
        require __DIR__ . '/../view/login_cadastro.php';
        break;

    case 'api/csrf':
        respostaJson(
            true,
            '',
            [
                'token' =>
                    csrfToken()
            ]
        );
        break;

    case 'api/cadastrar':
        require_once
            __DIR__ .
            '/../controllers/authController.php';

        (new AuthController())
            ->cadastrar();

        break;

    case 'api/login':
        require_once
            __DIR__ .
            '/../controllers/authController.php';

        (new AuthController())
            ->login();

        break;

    case 'api/logout':
        $_SESSION = [];

        if (
            ini_get(
                'session.use_cookies'
            )
        ) {
            $params =
                session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        respostaJson(
            true,
            'Sessão encerrada.'
        );

        break;

    /* =====================================================
       CATÁLOGO PÚBLICO
       ===================================================== */

    case 'api/produtos':
        require_once
            __DIR__ .
            '/../controllers/ProductController.php';

        (new ProductController())
            ->listarPublico();

        break;

    case 'api/imagem':
        require_once
            __DIR__ .
            '/../controllers/ImageController.php';

        (new ImageController())
            ->servir();

        break;

    /* =====================================================
       ÁREA DO CLIENTE / CHECKOUT / ENTREGA
       ===================================================== */

    case 'api/cliente/me':
        require_once __DIR__ . '/../controllers/CustomerController.php';
        (new CustomerController())->me();
        break;

    case 'api/cliente/enderecos':
        require_once __DIR__ . '/../controllers/CustomerController.php';
        (new CustomerController())->enderecos();
        break;

    case 'api/cliente/endereco/salvar':
        exigirCsrf();
        require_once __DIR__ . '/../controllers/CustomerController.php';
        (new CustomerController())->salvarEndereco();
        break;

    case 'api/cliente/cep':
        require_once __DIR__ . '/../controllers/CustomerController.php';
        (new CustomerController())->consultarCep();
        break;

    case 'api/cliente/atualizar':
        exigirCsrf();
        require_once __DIR__ . '/../controllers/CustomerController.php';
        (new CustomerController())->atualizarPerfil();
        break;

    case 'api/cliente/prazo':
        require_once __DIR__ . '/../controllers/CustomerController.php';
        (new CustomerController())->prazo();
        break;

    case 'api/cliente/checkout':
        exigirCsrf();
        require_once __DIR__ . '/../controllers/CustomerController.php';
        (new CustomerController())->checkout();
        break;

    case 'api/cliente/pedidos':
        require_once __DIR__ . '/../controllers/CustomerController.php';
        (new CustomerController())->pedidos();
        break;

    case 'api/cliente/pedido':
        require_once __DIR__ . '/../controllers/CustomerController.php';
        (new CustomerController())->pedido();
        break;

    case 'api/cliente/rastrear':
        require_once __DIR__ . '/../controllers/CustomerController.php';
        (new CustomerController())->rastrearPedido();
        break;

    case 'api/categorias':
        require_once __DIR__ . '/../public/config_db/database.php';
        $pdo = Database::getConnection();
        $stmt = $pdo->query('SELECT id,slug,nome,descricao,tema,destaque_texto,hero_texto,ativo FROM categorias WHERE ativo=1 ORDER BY nome');
        respostaJson(true, '', ['categorias' => $stmt->fetchAll()]);
        break;

    /* =====================================================
       ADMIN
       ===================================================== */

    case 'api/admin/login':
        require_once __DIR__ . '/../controllers/AdminAuthController.php';
        (new AdminAuthController())->login();
        break;

    case 'api/admin/me':
        require_once
            __DIR__ .
            '/../controllers/AdminController.php';

        (new AdminController())
            ->me();

        break;

    case 'api/admin/dashboard':
        require_once
            __DIR__ .
            '/../controllers/AdminController.php';

        (new AdminController())
            ->dashboard();

        break;

    case 'api/admin/categorias':
        require_once __DIR__ . '/../controllers/AdminController.php';
        (new AdminController())->categorias();
        break;

    case 'api/admin/categoria/salvar':
        exigirCsrf();
        require_once __DIR__ . '/../controllers/AdminController.php';
        (new AdminController())->salvarCategoria();
        break;

    case 'api/admin/categoria/excluir':
        exigirCsrf();
        require_once __DIR__ . '/../controllers/AdminController.php';
        (new AdminController())->excluirCategoria();
        break;

    case 'api/admin/produtos':
        require_once
            __DIR__ .
            '/../controllers/ProductController.php';

        (new ProductController())
            ->painel();

        break;

    case 'api/admin/produto/salvar':
        exigirCsrf();

        require_once
            __DIR__ .
            '/../controllers/ProductController.php';

        (new ProductController())
            ->salvar();

        break;

    case 'api/admin/produto/excluir':
        exigirCsrf();

        require_once
            __DIR__ .
            '/../controllers/ProductController.php';

        (new ProductController())
            ->excluir();

        break;

    /* =====================================================
       ESTOQUE
       ===================================================== */

    case 'api/admin/estoque':
        require_once
            __DIR__ .
            '/../controllers/AdminController.php';

        (new AdminController())
            ->estoque();

        break;

    case 'api/admin/estoque-ajustar':
        exigirCsrf();

        require_once
            __DIR__ .
            '/../controllers/AdminController.php';

        (new AdminController())
            ->ajustarEstoque();

        break;

    /* =====================================================
       PEDIDOS
       ===================================================== */

    case 'api/admin/pedidos':
        require_once
            __DIR__ .
            '/../controllers/AdminController.php';

        (new AdminController())
            ->pedidos();

        break;

    case 'api/admin/entrega-atualizar':
        exigirCsrf();
        require_once __DIR__ . '/../controllers/AdminController.php';
        (new AdminController())->atualizarEntrega();
        break;

    case 'api/admin/pedido-status':
        exigirCsrf();

        require_once
            __DIR__ .
            '/../controllers/AdminController.php';

        (new AdminController())
            ->atualizarPedido();

        break;

    /* =====================================================
       CLIENTES
       ===================================================== */

    case 'api/admin/clientes':
        require_once
            __DIR__ .
            '/../controllers/AdminController.php';

        (new AdminController())
            ->clientes();

        break;

    case 'api/admin/cliente-historico':
        require_once
            __DIR__ .
            '/../controllers/AdminController.php';

        (new AdminController())
            ->historicoCliente();

        break;

    /* =====================================================
       IMAGENS
       ===================================================== */

    case 'api/admin/imagens':
        require_once
            __DIR__ .
            '/../controllers/ImageController.php';

        (new ImageController())
            ->listar();

        break;

    case 'api/admin/imagem/upload':
        exigirCsrf();

        require_once
            __DIR__ .
            '/../controllers/ImageController.php';

        (new ImageController())
            ->upload();

        break;

    case 'api/admin/imagem/excluir':
        exigirCsrf();

        require_once
            __DIR__ .
            '/../controllers/ImageController.php';

        (new ImageController())
            ->excluir();

        break;

    /* =====================================================
       BANNERS PÚBLICOS / CARROSSEL DA HOME
       ===================================================== */

    case 'api/banners':
        require_once
            __DIR__ .
            '/../controllers/MarketingController.php';

        (new MarketingController())
            ->bannersPublicos();

        break;

    /* =====================================================
       CAMPANHAS / ADMIN
       ===================================================== */

    case 'api/admin/banners':
        require_once
            __DIR__ .
            '/../controllers/MarketingController.php';

        (new MarketingController())
            ->banners();

        break;

    case 'api/admin/banner/salvar':
        exigirCsrf();

        require_once
            __DIR__ .
            '/../controllers/MarketingController.php';

        (new MarketingController())
            ->salvarBanner();

        break;

    case 'api/admin/banner/excluir':
        exigirCsrf();

        require_once
            __DIR__ .
            '/../controllers/MarketingController.php';

        (new MarketingController())
            ->excluirBanner();

        break;

    case 'api/admin/cupons':
        require_once
            __DIR__ .
            '/../controllers/MarketingController.php';

        (new MarketingController())
            ->cupons();

        break;

    case 'api/admin/cupom/salvar':
        exigirCsrf();

        require_once
            __DIR__ .
            '/../controllers/MarketingController.php';

        (new MarketingController())
            ->salvarCupom();

        break;

    /* =====================================================
       AUDITORIA
       ===================================================== */

    case 'api/admin/logs':
        require_once
            __DIR__ .
            '/../controllers/AdminController.php';

        (new AdminController())
            ->resumoLogs();

        break;

    default:
        if (
            str_starts_with(
                $route,
                'api/'
            )
        ) {
            http_response_code(404);

            respostaJson(
                false,
                'Rota da API não encontrada.'
            );
        }

        header(
            'Location: telaPrincipla/index.html'
        );
        exit;
}