<?php
require_once INCLUDE_DIR . 'class.forms.php';

class OktaAuthPluginConfig extends PluginConfig {

    function getOptions() {
        return array(
            'okta-issuer' => new TextboxField(array(
                'label' => 'Okta Issuer URL',
                'hint'  => 'e.g. https://your-domain.okta.com/oauth2/default',
                'configuration' => array('size' => 60, 'length' => 255),
                'required' => true,
            )),
            'okta-client-id' => new TextboxField(array(
                'label' => 'Client ID',
                'hint'  => 'OAuth2 Client ID from your Okta application',
                'configuration' => array('size' => 60, 'length' => 255),
                'required' => true,
            )),
            'okta-client-secret' => new PasswordField(array(
                'label' => 'Client Secret',
                'hint'  => 'OAuth2 Client Secret (stored encrypted)',
                'configuration' => array('size' => 60, 'length' => 255),
                'required' => true,
            )),
            'okta-redirect-uri' => new TextboxField(array(
                'label' => 'Redirect / Callback URI',
                'hint'  => 'e.g. https://helpdesk.example.com/api/auth/okta — must match the redirect URI in Okta',
                'configuration' => array('size' => 60, 'length' => 255),
                'required' => true,
            )),
            'okta-scopes' => new TextboxField(array(
                'label' => 'Scopes',
                'hint'  => 'Space-separated OIDC scopes',
                'default' => 'openid profile email',
                'configuration' => array('size' => 60, 'length' => 255),
            )),
            'okta-enable-client' => new BooleanField(array(
                'label' => 'Enable Client Portal Login',
                'hint'  => 'Allow end-users to sign in with Okta on the client portal',
                'default' => false,
            )),
            'okta-auto-register' => new BooleanField(array(
                'label' => 'Auto-register Client Users',
                'hint'  => 'Automatically create client accounts for new Okta users (client portal only)',
                'default' => true,
            )),
        );
    }

    function pre_save(&$config, &$errors) {
        if ($config['okta-issuer'])
            $config['okta-issuer'] = rtrim($config['okta-issuer'], '/');
        return true;
    }
}
