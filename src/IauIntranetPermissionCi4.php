<?php

namespace Dpicon83\IauIntranetPermissionCi4;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use GuzzleHttp\Client;

class IauIntranetPermissionCi4 implements FilterInterface
{
    /**
     * @param RequestInterface $request
     * @param array|null       $arguments
     *
     * @return RequestInterface|ResponseInterface|string|void
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        if (!session()->has('oauth_user')) {
            return redirect()->route(env('API_LOGIN_ROUTE'));
        }

        // Captura os dados da sessão e do .env
        $numero_usp = session('oauth_user.loginUsuario');
        $nomeUsuario = session('oauth_user.nomeUsuario');
        $idSistema = env('API_SYSTEM_ID');
        $token = env('API_TOKEN');
        $tipoUsuario = isset($arguments[0]) ? $arguments[0] : null; // Tipo de usuário necessário é passado como argumento na chamada do filter na Rota
        $nivelUsuario = $this->convertePermissaoValorNumerico($tipoUsuario); // Cria um nivel numérico da permissão para facilitar verificações de segurança

        // Verifica se a permissão já existe na sessão para evitar nova consulta
        // Verifica o nível de acesso numericamente para verificações internas facilitadas
        if (session()->has("user_permission")) {
            if (session()->get("user_level") >= $nivelUsuario) {
                return;
            } else {
                if (env('API_LOGOUT_ON_DENIED')) {
                    $this->removeSessoes();
                    return redirect()->route(env('API_LOGIN_ROUTE'))->with('error', 'Você não possui nível de permissão necessário para acesso a essa área do sistema.');
                } else {
                    return redirect()->route(env('API_REDIRECT_ROUTE'))->with('error', 'Você não possui nível de permissão necessário para acesso a essa área do sistema.');
                }
            }
        }

        // Configura o cliente Guzzle para chamar a API
        $client = new Client(['base_uri' => env('API_BASE_URL')]);

        $response = $client->post('verify', [
            'headers' => [
                'Authorization' => "Bearer $token",
                'Content-Type'  => 'application/json'
            ],
            'json' => [
                'numero_usp' => $numero_usp,
                'nomeUsuario' => $nomeUsuario,
                'idSistema' => $idSistema,
                'tipoUsuario' => $tipoUsuario
            ],
            'http_errors' => false
        ]);

        // Verifica o status da resposta da API
        $statusCode = $response->getStatusCode();

        if ($statusCode === 200) {
            $data = json_decode($response->getBody(), true);

            // Verifica se a resposta da API indica autorização
            if (isset($data['authorized']) && $data['authorized'] === true) {
                session()->set("user_permission", $data['permissao']);
                session()->set("user_level", $data['nivelPermissao']);
                return redirect()->route('relatorios.usuarios.index');
            } else {
                $this->removeSessoes();
                return redirect()->route(env('API_LOGIN_ROUTE'))->with('error', 'Acesso negado.');
            }
        } elseif ($statusCode === 403) {
            // Acesso negado pela API
            $this->removeSessoes();
            return redirect()->route(env('API_LOGIN_ROUTE'))->with('error', 'Acesso negado.');
        } else {
            // Demais erros
            $this->removeSessoes();
            $data = json_decode($response->getBody(), true);
            if (isset($data['messages'])) {
                $erros = [];
                foreach ($data['messages'] as $key => $value) {
                    $erros[] = $value;
                }
                return redirect()->route(env('API_LOGIN_ROUTE'))->with('errors', $erros);
            } else {
                return redirect()->route(env('API_LOGIN_ROUTE'))->with('error', 'Erro desconhecido ao conectar à API.');
            }
        }
    }

    /**
     * Allows After filters to inspect and modify the response
     * object as needed. This method does not allow any way
     * to stop execution of other after filters, short of
     * throwing an Exception or Error.
     *
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @param array|null        $arguments
     *
     * @return ResponseInterface|void
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        //
    }

    private function removeSessoes()
    {
        session()->remove('temporary_credentials');
        session()->remove('oauth_user');
        session()->remove('token_credentials');
        session()->remove("user_permission");
        session()->remove("user_level");
    }

    private function convertePermissaoValorNumerico($permissao)
    {
        switch ($permissao) {
            case 'Administrador':
                return 5;
                break;
            case 'Gerente':
                return 4;
                break;
            case 'Avancado':
                return 3;
                break;
            case 'Intermediario':
                return 2;
                break;
            case 'Usuario':
                return 1;
                break;
            default:
                return 0;
        }
    }
}