<?php
/**
 * Quick Shell extension.
 *
 * @category   Extension
 * @package    Pandora FMS
 * @subpackage QuickShell
 * @version    1.0.0
 * @license    See below
 *
 *    ______                 ___                    _______ _______ ________
 *   |   __ \.-----.--.--.--|  |.-----.----.-----. |    ___|   |   |     __|
 *  |    __/|  _  |     |  _  ||  _  |   _|  _  | |    ___|       |__     |
 * |___|   |___._|__|__|_____||_____|__| |___._| |___|   |__|_|__|_______|
 *
 * ============================================================================
 * Copyright (c) 2005-2019 Artica Soluciones Tecnologicas
 * Please see http://pandorafms.org for full contribution list
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation for version 2.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * ============================================================================
 */

// Begin.
global $config;

require_once $config['homedir'].'/include/functions_agents.php';
require_once $config['homedir'].'/godmode/wizards/Wizard.main.php';


/**
 * Show Quick Shell interface.
 *
 * @return void
 */
function quickShell()
{
    global $config;

    check_login();

    if (check_acl($config['id_user'], 0, 'PM') === false) {
        db_pandora_audit(
            'ACL Violation',
            'Trying to access Profile Management'
        );
        include 'general/noaccess.php';
        return;
    }

    $agent_id = get_parameter('id_agente', 0);
    $username = get_parameter('username', null);
    $method = get_parameter('method', null);
    $method_port = get_parameter('port', null);

    // Retrieve main IP Address.
    $address = agents_get_address($agent_id);

    ui_require_css_file('wizard');
    ui_require_css_file('discovery');

    // Settings.
    // WebSocket host, where to connect.
    if (isset($config['ws_host']) === false) {
        config_update_value('ws_host', $_SERVER['SERVER_ADDR']);
    }

    if (isset($config['ws_port']) === false) {
        config_update_value('ws_port', 8080);
    }

    if (isset($config['ws_proxy_url']) === false) {
        $ws_url = 'http://'.$config['ws_host'].':'.$config['ws_port'];
    } else {
        preg_match('/\/\/(.*)/', $config['ws_proxy_url'], $matches);
        $ws_url = 'ws://'.$matches[1];
    }

    // Gotty settings. Internal communication (WS).
    if (isset($config['gotty_host']) === false) {
        config_update_value('gotty_host', '127.0.0.1');
    }

    if (isset($config['gotty_telnet_port']) === false) {
        config_update_value('gotty_telnet_port', 8082);
    }

    if (isset($config['gotty_ssh_port']) === false) {
        config_update_value('gotty_ssh_port', 8081);
    }

    // Username. Retrieve from form.
    if (empty($username) === true) {
        // No username provided, ask for it.
        $wiz = new Wizard();

        $test = file_get_contents($ws_url);
        if ($test === false) {
            ui_print_error_message(__('WebService engine has not been started, please check documentation.'));
            $wiz->printForm(
                [
                    'form'   => [
                        'method' => 'POST',
                        'action' => '#',
                    ],
                    'inputs' => [
                        [
                            'class'     => 'w100p',
                            'arguments' => [
                                'name'       => 'submit',
                                'label'      => __('Retry'),
                                'type'       => 'submit',
                                'attributes' => 'class="sub next"',
                                'return'     => true,
                            ],
                        ],
                    ],
                ]
            );

            return;
        }

        $wiz->printForm(
            [
                'form'   => [
                    'id'     => 'pene',
                    'action' => '#',
                    'class'  => 'wizard',
                    'method' => 'post',
                ],
                'inputs' => [
                    [
                        'label'     => __('Username'),
                        'arguments' => [
                            'type' => 'text',
                            'name' => 'username',
                        ],
                    ],
                    [
                        'label'     => __('Port'),
                        'arguments' => [
                            'type'  => 'text',
                            'id'    => 'port',
                            'name'  => 'port',
                            'value' => 22,
                        ],
                    ],
                    [
                        'label'     => __('Method'),
                        'arguments' => [
                            'type'   => 'select',
                            'name'   => 'method',
                            'fields' => [
                                'ssh'    => __('SSH'),
                                'telnet' => __('Telnet'),
                            ],
                            'script' => "p=22; if(this.value == 'telnet') { p=23; } $('#text-port').val(p);",
                        ],
                    ],
                    [
                        'arguments' => [
                            'type'       => 'submit',
                            'label'      => __('Connect'),
                            'attributes' => 'class="sub next"',
                        ],
                    ],
                ],
            ],
            false,
            true
        );

        return;
    }

    // Initialize Gotty Client.
    $host = $config['gotty_host'];
    if ($method == 'ssh') {
        // SSH.
        $port = $config['gotty_ssh_port'];
        $command_arguments = "var args = '?arg=".$username.'@'.$address;
        $command_arguments .= '&arg=-p '.$method_port."';";
    } else if ($method == 'telnet') {
        // Telnet.
        $port = $config['gotty_telnet_port'];
        $command_arguments = "var args = '?arg=-l ".$username;
        $command_arguments .= '&arg='.$address;
        $command_arguments .= '&arg='.$method_port."';";
    } else {
        ui_print_error_message(__('Please use SSH or Telnet.'));
        return;
    }

    // If rediretion is enabled, we will try to connect to http:// or https:// endpoint.
    $test = get_headers($ws_url);
    if ($test === false) {
        if (empty($wiz) === true) {
            $wiz = new Wizard();
        }

        ui_print_error_message(__('WebService engine has not been started, please check documentation.'));
        echo $wiz->printGoBackButton('#');
        return;
    }

    $r = file_get_contents('http://'.$host.':'.$port.'/js/hterm.js');
    if (empty($r) === true) {
        if (empty($wiz) === true) {
            $wiz = new Wizard();
        }

        ui_print_error_message(__('WebService engine is not working properly, please check documentation.'));
        echo $wiz->printGoBackButton('#');
        return;
    }

    // Override gotty client settings.
    if (empty($config['gotty_user'])
        && empty($config['gotty_pass'])
    ) {
        $r .= "var gotty_auth_token = '';";
    } else {
        $r .= "var gotty_auth_token = '";
        $r .= $config['gotty_user'].':'.$gotty_pass."';";
    }

    // Set websocket target and method.
    $gotty = file_get_contents('http://'.$host.':'.$port.'/js/gotty.js');
    $url = "var url = (httpsEnabled ? 'wss://' : 'ws://') + window.location.host + window.location.pathname + 'ws';";
    if (empty($config['ws_proxy_url']) === true) {
        $new = "var url = (httpsEnabled ? 'wss://' : 'ws://')";
        $new .= " + window.location.host + ':";
        $new .= $config['ws_port'].'/'.$method."';";
    } else {
        $new = "var url = '";
        $new .= $config['ws_proxy_url'].'/'.$method."';";
    }

    // Update url.
    $gotty = str_replace($url, $new, $gotty);

    // Update websocket arguments.
    $args = 'var args = window.location.search;';
    $new = $command_arguments;

    // Update arguments.
    $gotty = str_replace($args, $new, $gotty);

    ?>
    <style>#terminal {
        height: 650px;
        width: 100%;
        margin: 0px;
        padding: 0;
      }
      #terminal > iframe {
        position: relative!important;
      }
    </style>
    <div id="terminal"></div>
    <script type="text/javascript">
    <?php echo $r; ?>
    </script>
    <script type="text/javascript">
    <?php echo $gotty; ?>
    </script>
    <?php

}


extensions_add_opemode_tab_agent(
    // TabId.
    'quick_shell',
    // TabName.
    __('QuickShell'),
    // TabIcon.
    'images/ehorus/terminal.png',
    // TabFunction.
    'quickShell',
    // Version.
    'N/A',
    // Acl.
    'PM'
);
