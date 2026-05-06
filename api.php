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

    $result[] = array(
        'method'   => 'GET',
        'endpoint' => 'diagnostics',
        'callback' => 'fpppluginmerossdirectDiagnostics'
    );

    return $result;
}

function fpppluginmerossdirectRunCommand($cmd, $timeoutSec = 45) {
    $descriptorspec = array(
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );

    $pipes = array();
    $process = proc_open($cmd, $descriptorspec, $pipes);
    if (!is_resource($process)) {
        return array(
            'ok' => false,
            'timeout' => false,
            'rc' => 127,
            'raw' => 'Unable to start command process',
        );
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = time();
    $timedOut = false;

    while (true) {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }

        if ((time() - $start) >= $timeoutSec) {
            $timedOut = true;
            proc_terminate($process);
            usleep(300000);
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            break;
        }

        usleep(100000);
    }

    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $rc = proc_close($process);
    if ($timedOut) {
        $rc = 124;
    }

    $raw = trim($stdout . (($stdout !== '' && $stderr !== '') ? "\n" : '') . $stderr);

    return array(
        'ok' => true,
        'timeout' => $timedOut,
        'rc' => $rc,
        'raw' => $raw,
    );
}

function fpppluginmerossdirectSocketCheck($host, $port = 443, $timeoutSec = 5) {
    $errno = 0;
    $errstr = '';
    $t0 = microtime(true);
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeoutSec);
    $elapsedMs = (int) round((microtime(true) - $t0) * 1000);

    if ($fp) {
        fclose($fp);
        return array('ok' => true, 'host' => $host, 'port' => $port, 'latencyMs' => $elapsedMs);
    }

    return array('ok' => false, 'host' => $host, 'port' => $port, 'errno' => $errno, 'error' => $errstr, 'latencyMs' => $elapsedMs);
}

function fpppluginmerossdirectDiagnostics() {
    global $settings;

    $plugin = 'fpp-plugin-meross-direct';
    $pluginDir = $settings['pluginDirectory'] . '/' . $plugin;
    $pythonLib = $pluginDir . '/python_libs';
    $controlScript = $pluginDir . '/commands/meross_control.py';

    $checks = array();

    $checks['pythonVersion'] = fpppluginmerossdirectRunCommand('python3 --version 2>&1', 8);

    $importCmd = 'PYTHONPATH=' . escapeshellarg($pythonLib) . ' python3 -c ' .
        escapeshellarg('from meross_iot.http_api import MerossHttpClient; print("import_ok")') . ' 2>&1';
    $checks['merossImport'] = fpppluginmerossdirectRunCommand($importCmd, 10);

    $checks['controlListProbe'] = fpppluginmerossdirectRunCommand('python3 ' . escapeshellarg($controlScript) . ' --list 2>&1', 25);

    $checks['socket_us'] = fpppluginmerossdirectSocketCheck('iotx-us.meross.com', 443, 5);
    $checks['socket_eu'] = fpppluginmerossdirectSocketCheck('iotx-eu.meross.com', 443, 5);
    $checks['socket_ap'] = fpppluginmerossdirectSocketCheck('iotx-ap.meross.com', 443, 5);

    return json(array('ok' => true, 'checks' => $checks));
}

function fpppluginmerossdirectEnsureDependencies() {
    global $settings;

    $plugin = 'fpp-plugin-meross-direct';
    $pluginDir = $settings['pluginDirectory'] . '/' . $plugin;
    $moduleDir = $pluginDir . '/python_libs/meross_iot';
    $pythonLib = $pluginDir . '/python_libs';

    $importCmd = 'PYTHONPATH=' . escapeshellarg($pythonLib) . ' python3 -c ' .
        escapeshellarg('from meross_iot.http_api import MerossHttpClient; print("import_ok")') . ' 2>&1';

    if (is_dir($moduleDir)) {
        $probe = fpppluginmerossdirectRunCommand($importCmd, 10);
        if (!$probe['timeout'] && $probe['rc'] === 0) {
            return array('ok' => true, 'installed' => true, 'message' => 'Dependencies already present');
        }
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
    $run = fpppluginmerossdirectRunCommand($cmd, 420);
    $rc = $run['rc'];
    $raw = $run['raw'];

    clearstatcache();
    if ($run['timeout']) {
        return array(
            'ok' => false,
            'error' => 'Dependency install timed out after 420 seconds',
            'rc' => $rc,
            'output' => $raw,
        );
    }

    $probe = fpppluginmerossdirectRunCommand($importCmd, 10);
    if ($rc !== 0 || !is_dir($moduleDir) || $probe['timeout'] || $probe['rc'] !== 0) {
        return array(
            'ok' => false,
            'error' => 'Unable to install meross-iot dependency',
            'rc' => $rc,
            'output' => trim($raw . "\n" . $probe['raw']),
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

    $run = fpppluginmerossdirectRunCommand($cmd, 60);
    $rc = $run['rc'];
    $raw = $run['raw'];

    if ($run['timeout']) {
        return json(array('ok' => false, 'error' => 'Device discovery timed out after 60 seconds', 'rc' => 124, 'output' => $raw));
    }

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

    $run = fpppluginmerossdirectRunCommand($cmd, 45);
    $rc = $run['rc'];
    $raw = $run['raw'];

    if ($run['timeout']) {
        return json(array('ok' => false, 'error' => 'Device action timed out after 45 seconds', 'rc' => 124, 'output' => $raw));
    }

    $decoded = json_decode($raw, true);
    if ($decoded !== null) {
        return json($decoded);
    }

    return json(array('ok' => $rc === 0, 'output' => $raw, 'rc' => $rc));
}
