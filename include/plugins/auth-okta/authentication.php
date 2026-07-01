<?php
require_once 'config.php';

class OktaAuthPlugin extends Plugin {
    var $config_class = 'OktaAuthPluginConfig';

    function bootstrap() {
        $config = $this->getConfig();
        if (!$config || !$config->get('okta-issuer')
                || !$config->get('okta-client-id'))
            return;

        // Always register staff backend
        $staffBk = new OktaStaffAuthBackend($config);
        StaffAuthenticationBackend::register($staffBk);

        // Optionally register client backend
        if ($config->get('okta-enable-client')) {
            $clientBk = new OktaClientAuthBackend($config);
            UserAuthenticationBackend::register($clientBk);
        }
    }
}


/**
 * Shared OIDC helpers for Okta authentication.
 */
trait OktaOidcTrait {

    /**
     * Build the Okta /authorize redirect URL and send the browser there.
     * Stores CSRF state and nonce in $_SESSION.
     */
    protected function redirectToOkta($portalType) {
        $config = $this->config;
        $issuer = $config->get('okta-issuer');
        $state = bin2hex(Misc::randCode(16));
        $nonce = bin2hex(Misc::randCode(16));

        $_SESSION['okta:state']  = $state;
        $_SESSION['okta:nonce']  = $nonce;
        $_SESSION['okta:portal'] = $portalType; // 'staff' or 'client'

        $params = array(
            'client_id'     => $config->get('okta-client-id'),
            'redirect_uri'  => $config->get('okta-redirect-uri'),
            'response_type' => 'code',
            'scope'         => $config->get('okta-scopes') ?: 'openid profile email',
            'state'         => $state,
            'nonce'         => $nonce,
        );

        $url = $issuer . '/v1/authorize?' . http_build_query($params);
        Http::redirect($url);
    }

    /**
     * Fetch the /userinfo endpoint for additional profile data.
     */
    protected function fetchUserInfo($accessToken) {
        $config = $this->config;
        $url = $config->get('okta-issuer') . '/v1/userinfo';

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response)
            return array();

        return json_decode($response, true) ?: array();
    }

    /**
     * Full token exchange — returns both claims and raw token response.
     */
    protected function exchangeCodeFull($code) {
        $config = $this->config;
        $issuer = $config->get('okta-issuer');
        $tokenUrl = $issuer . '/v1/token';

        $params = array(
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $config->get('okta-redirect-uri'),
            'client_id'     => $config->get('okta-client-id'),
            'client_secret' => $config->get('okta-client-secret'),
        );

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response)
            return array(false, false);

        $data = json_decode($response, true);
        if (!$data || empty($data['id_token']))
            return array(false, false);

        $parts = explode('.', $data['id_token']);
        if (count($parts) !== 3)
            return array(false, false);

        $claims = json_decode(
            base64_decode(strtr($parts[1], '-_', '+/')),
            true
        );

        if (!$claims
                || !isset($claims['nonce'])
                || !hash_equals($_SESSION['okta:nonce'] ?? '', $claims['nonce']))
            return array(false, false);

        return array($claims, $data);
    }
}


/**
 * Staff authentication backend for Okta OIDC.
 */
class OktaStaffAuthBackend extends ExternalStaffAuthenticationBackend {
    use OktaOidcTrait;

    static $id = 'okta';
    static $name = 'Okta Authentication';
    static $service_name = 'Okta';
    static $fa_icon = 'openid';

    function __construct($config) {
        $this->config = $config;
    }

    function supportsInteractiveAuthentication() {
        return false;
    }

    function triggerAuth() {
        parent::triggerAuth();
        $this->redirectToOkta('staff');
    }

    function authenticate($username, $password) {
        return false;
    }

    /**
     * Called by the callback handler after Okta redirects back.
     * Returns a StaffSession on success or null on failure.
     */
    function callback($code) {
        list($claims, $tokenData) = $this->exchangeCodeFull($code);
        if (!$claims)
            return null;

        $email = $claims['email'] ?? null;

        // Try /userinfo for additional profile data
        if (!empty($tokenData['access_token'])) {
            $profile = $this->fetchUserInfo($tokenData['access_token']);
            if (!$email && !empty($profile['email']))
                $email = $profile['email'];
        }

        if (!$email)
            return null;

        // Look up existing staff by email
        $staff = StaffSession::lookup(array('email' => $email));
        if (!$staff || !$staff->getId()) {
            // Try by username portion of the email
            $username = strstr($email, '@', true);
            if ($username)
                $staff = StaffSession::lookup($username);
        }

        if (!$staff || !$staff->getId() || !$staff->isActive())
            return null;

        return $staff;
    }
}


/**
 * Client/user authentication backend for Okta OIDC.
 */
class OktaClientAuthBackend extends ExternalUserAuthenticationBackend {
    use OktaOidcTrait;

    static $id = 'okta.client';
    static $name = 'Okta Client Authentication';
    static $service_name = 'Okta';
    static $fa_icon = 'openid';

    function __construct($config) {
        $this->config = $config;
    }

    function supportsInteractiveAuthentication() {
        return false;
    }

    function triggerAuth() {
        parent::triggerAuth();
        $this->redirectToOkta('client');
    }

    function authenticate($username, $password) {
        return false;
    }

    /**
     * Called by the callback handler after Okta redirects back.
     * Returns a ClientSession, ClientCreateRequest, or null.
     */
    function callback($code) {
        list($claims, $tokenData) = $this->exchangeCodeFull($code);
        if (!$claims)
            return null;

        $email = $claims['email'] ?? null;
        $name  = $claims['name']  ?? null;

        // Supplement from /userinfo
        if (!empty($tokenData['access_token'])) {
            $profile = $this->fetchUserInfo($tokenData['access_token']);
            if (!$email && !empty($profile['email']))
                $email = $profile['email'];
            if (!$name && !empty($profile['name']))
                $name = $profile['name'];
        }

        if (!$email)
            return null;

        // Look up existing user by email
        $user = User::lookup(array('emails__address' => $email));
        if ($user && ($acct = $user->getAccount())) {
            $client = new ClientSession(new EndUser($user));
            return $client;
        }

        // Auto-register support
        if ($this->config->get('okta-auto-register')) {
            $nameParts = $name ? explode(' ', $name, 2) : array($email);
            $info = array(
                'email' => $email,
                'name'  => $name ?: $email,
            );
            if (count($nameParts) > 1) {
                $info['first'] = $nameParts[0];
                $info['last']  = $nameParts[1];
            }
            return new ClientCreateRequest($this, $email, $info);
        }

        return null;
    }
}


// Register the OIDC callback route via the API dispatcher.
// URL: /api/auth/okta?code=...&state=...
Signal::connect('api', function($dispatcher) {
    $dispatcher->append(
        url('^/auth/okta$', function() {
            // Validate state parameter
            $state = $_GET['state'] ?? '';
            if (!$state || !hash_equals($_SESSION['okta:state'] ?? '', $state)) {
                Http::response(403, 'Invalid state parameter');
                return;
            }

            $code = $_GET['code'] ?? '';
            if (!$code) {
                Http::response(400, 'Missing authorization code');
                return;
            }

            // Clean up CSRF tokens
            $portal = $_SESSION['okta:portal'] ?? 'staff';
            unset($_SESSION['okta:state']);

            if ($portal === 'client') {
                $bk = UserAuthenticationBackend::getBackend('okta.client');
                if (!$bk) {
                    // Try with plugin-instance-suffixed id
                    foreach (UserAuthenticationBackend::allRegistered() as $b) {
                        if ($b instanceof OktaClientAuthBackend) {
                            $bk = $b;
                            break;
                        }
                    }
                }
                if (!$bk) {
                    Http::response(500, 'Okta client backend not found');
                    return;
                }
                $result = $bk->callback($code);

                // Clean up nonce
                unset($_SESSION['okta:nonce'], $_SESSION['okta:portal']);

                if ($result instanceof ClientSession) {
                    try {
                        if ($bk->login($result, $bk))
                            Http::redirect(ROOT_PATH . 'tickets.php');
                        else
                            Http::redirect(ROOT_PATH . 'login.php?e=login');
                    } catch (AccessDenied $e) {
                        $_SESSION['_client']['auth']['msg'] = $e->getMessage();
                        Http::redirect(ROOT_PATH . 'login.php');
                    }
                } elseif ($result instanceof ClientCreateRequest) {
                    if ($result->attemptAutoRegister())
                        Http::redirect(ROOT_PATH . 'tickets.php');
                    // Registration failed or disabled — redirect to login
                    Http::redirect(ROOT_PATH . 'login.php');
                } else {
                    $_SESSION['_client']['auth']['msg']
                        = 'Okta login failed — no matching account found';
                    Http::redirect(ROOT_PATH . 'login.php');
                }
            } else {
                // Staff portal
                $bk = StaffAuthenticationBackend::getBackend('okta');
                if (!$bk) {
                    foreach (StaffAuthenticationBackend::allRegistered() as $b) {
                        if ($b instanceof OktaStaffAuthBackend) {
                            $bk = $b;
                            break;
                        }
                    }
                }
                if (!$bk) {
                    Http::response(500, 'Okta staff backend not found');
                    return;
                }
                $result = $bk->callback($code);

                // Clean up nonce
                unset($_SESSION['okta:nonce'], $_SESSION['okta:portal']);

                if ($result instanceof StaffSession) {
                    if ($bk->login($result, $bk))
                        Http::redirect(ROOT_PATH . 'scp/index.php');
                    else
                        Http::redirect(ROOT_PATH . 'scp/login.php?e=login');
                } else {
                    $_SESSION['_staff']['auth']['msg']
                        = 'Okta login failed — no matching staff account found';
                    Http::redirect(ROOT_PATH . 'scp/login.php');
                }
            }
        })
    );
});
