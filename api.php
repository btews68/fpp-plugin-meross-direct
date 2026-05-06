<?php

function getEndpointsfpppluginmerossdirect() {
    $result = array();

    $result[] = array(
        'method'   => 'GET',
        'endpoint' => 'devices',
        'callback' => 'fpppluginmerossdirectDevices'
    );

    $result[] = array(
        'method'   => 'POST',
        'endpoint' => 'run',
        'callback' => 'fpppluginmerossdirectRun'
    );

    return $result;
}

function fpppluginmerossdirectEnsureDependencies() {
    global $settings;

    $plugin = 'fpp-plugin-meross-direct';
    $pluginDir = $settings['pluginDirectory'] . '/' . $plugin;
    $moduleDir = $pluginDir . '/python_libs/meross_iot';

    if (is_dir($moduleDir)) {
        return array('ok' => true, 'installed' => true, 'message' => 'Dependencies already present');
    }

    $installScript = $pluginDir . '/scripts/fpp_install.sh';
    if (!file_exists($installScript)) {
        return array(
            'ok' => false,
            'error' => 'Install script not found',
            'path' => $installScript,
        );
    }

    $cmd = 'bash ' . escapeshellarg($installScript) . ' 2>&1';
    $output = array();
    $rc = 0;
    exec($cmd, $output, $rc);
    $raw = implode("\n", $output);

    clearstatcache();
    if ($rc !== 0 || !is_dir($moduleDir)) {
        return array(
            'ok' => false,
            'error' => 'Unable to install meross-iot dependency',
            'rc' => $rc,
            'output' => $raw,
        );
    }

    return array('ok' => true, 'installed' => true, 'message' => 'Dependencies installed');
}

function fpppluginmerossdirectDevices() {
    global $settings;

    $deps = fpppluginmerossdirectEnsureDependencies();
    if (!$deps['ok']) {
        return json($deps);
    }

    $plugin = 'fpp-plugin-meross-direct';
    $script = $settings['pluginDirectory'] . '/' . $plugin . '/commands/meross_control.py';
    $cmd    = 'python3 ' . escapeshellarg($script) . ' --list 2>&1';

    $output = array();
    $rc     = 0;
    exec($cmd, $output, $rc);
    $raw = implode("\n", $output);

    if ($rc != 0) {
        return json(array('ok' => false, 'error' => $raw, 'rc' => $rc));
    }

    $decoded = json_decode($raw, true);
    if ($decoded === null) {
        return json(array('ok' => false, 'error' => 'Unable to decode script output', 'raw' => $raw));
    }

    return json($decoded);
}

function fpppluginmerossdirectRun() {
    global $settings;

    $deps = fpppluginmerossdirectEnsureDependencies();
    if (!$deps['ok']) {
        return json($deps);
    }

    $plugin = 'fpp-plugin-meross-direct';
    $script = $settings['pluginDirectory'] . '/' . $plugin . '/commands/meross_action.sh';

    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return json(array('ok' => false, 'error' => 'JSON body required'));
    }

    $action   = isset($data['action'])   ? trim($data['action'])   : '';
    $deviceId = isset($data['deviceId']) ? trim($data['deviceId']) : '';
    $value    = isset($data['value'])    ? trim($data['value'])    : '';

    if (empty($action)) {
        return json(array('ok' => false, 'error' => 'action is required'));
    }

    $allowed_actions = array('on', 'off', 'toggle', 'level', 'status');
    if (!in_array($action, $allowed_actions, true)) {
        return json(array('ok' => false, 'error' => 'Invalid action. Use: on, off, toggle, level, status'));
    }

    $args = array();
    if (!empty($deviceId)) {
        $args[] = escapeshellarg($deviceId);
    }
    $args[] = escapeshellarg($action);
    if (!empty($value)) {
        $args[] = escapeshellarg($value);
    }

    $cmd = 'bash ' . escapeshellarg($script) . ' ' . implode(' ', $args) . ' 2>&1';

    $output = array();
    $rc     = 0;
    exec($cmd, $output, $rc);
    $raw = implode("\n", $output);

    $decoded = json_decode($raw, true);
    if ($decoded !== null) {
        return json($decoded);
    }

    return json(array('ok' => $rc === 0, 'output' => $raw, 'rc' => $rc));
}
