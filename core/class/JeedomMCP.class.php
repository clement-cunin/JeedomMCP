<?php

class JeedomMCP extends eqLogic {

    /**
     * Return the path to the python3 executable.
     * Prefers pyenv shim, falls back to system python3.
     */
    private static function getPython3() {
        $pyenv = '/home/pi/.pyenv/shims/python3';
        return file_exists($pyenv) ? $pyenv : 'python3';
    }

    /**
     * Convert a Jeedom/Monolog numeric log level to a Python logging level name.
     * Jeedom uses Monolog constants: 100=DEBUG, 200=INFO, 300=WARNING, 400=ERROR.
     */
    private static function toPythonLogLevel($jeedomLevel) {
        if ($jeedomLevel <= 100) return 'debug';
        if ($jeedomLevel <= 200) return 'info';
        if ($jeedomLevel <= 300) return 'warning';
        return 'error';
    }

    /**
     * Generate a cryptographically secure API key and persist it.
     */
    public static function generateApiKey() {
        $key = bin2hex(random_bytes(24)); // 48 hex characters
        config::save('mcpApiKey', $key, __CLASS__);
        return $key;
    }

    /**
     * Called when the plugin is activated.
     * Generates an API key if none exists yet.
     */
    public static function activate() {
        if (config::byKey('mcpApiKey', __CLASS__) === '') {
            self::generateApiKey();
        }
    }

    public static function dependancy_info() {
        $return = [];
        $return['log'] = log::getPathToLog(__CLASS__ . '_update');
        $return['progress_file'] = jeedom::getTmpFolder(__CLASS__) . '/dependancy';
        $return['state'] = 'ok';
        $python3 = self::getPython3();
        exec($python3 . ' -c "import fastmcp, requests" 2>&1', $output, $rc);
        if ($rc !== 0) {
            $return['state'] = 'nok';
        }
        return $return;
    }

    public static function dependancy_install() {
        log::remove(__CLASS__ . '_update');
        return array(
            'script' => dirname(__FILE__) . '/../../resources/install.sh ' . jeedom::getTmpFolder(__CLASS__) . '/dependancy',
            'log' => log::getPathToLog(__CLASS__ . '_update')
        );
    }

    public static function deamon_info() {
        $return = [];
        $return['log'] = __CLASS__;
        $return['state'] = 'nok';
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            if (posix_getsid(trim(file_get_contents($pid_file)))) {
                $return['state'] = 'ok';
            } else {
                shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 &');
            }
        }
        $return['launchable'] = 'ok';
        return $return;
    }

    public static function deamon_start($_debug = false) {
        self::deamon_stop();
        $deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception(__('Daemon cannot start, reason: ', __FILE__) . $deamon_info['launchable_message']);
        }
        // Ensure an API key exists before starting
        if (config::byKey('mcpApiKey', __CLASS__) === '') {
            self::generateApiKey();
        }
        $path = realpath(dirname(__FILE__) . '/../../resources/mcp_server');
        $cmd = system::getCmdSudo() . self::getPython3() . ' ' . $path . '/server.py';
        $cmd .= ' --loglevel ' . ($_debug ? 'debug' : self::toPythonLogLevel(log::getLogLevel(__CLASS__)));
        $cmd .= ' --pid ' . jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        $cmd .= ' --port ' . config::byKey('port', __CLASS__, 8765);
        $cmd .= ' --apikey ' . config::byKey('mcpApiKey', __CLASS__);
        $cmd .= ' --base_url ' . network::getNetworkAccess('external', 'proto:ip:port:comp') . '/mcp';
        $cmd .= ' --jeedom_url ' . network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/core/api/jeeApi.php';
        $cmd .= ' --jeedom_apikey ' . jeedom::getApiKey();
        log::add(__CLASS__, 'info', 'Launching daemon: ' . $cmd);
        $result = exec($cmd . ' >> ' . log::getPathToLog(__CLASS__) . ' 2>&1 &');
        $i = 0;
        while ($i < 20) {
            sleep(1);
            $deamon_info = self::deamon_info();
            if ($deamon_info['state'] === 'ok') {
                break;
            }
            $i++;
        }
        if ($i >= 20) {
            log::add(__CLASS__, 'error', 'Unable to start daemon, check logs');
            return false;
        }
        return true;
    }

    public static function deamon_stop() {
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            system::kill($pid);
        }
        system::kill('mcp_server/server.py');
        sleep(1);
    }

    public static function cron() {
        // Scheduled tasks (if needed)
    }
}
