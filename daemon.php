<?php
define ('LOCAL_IP', 'bioinformatic.us');
define ('LOCAL_PORT', '15004');
define ('USE_SSL', false);
define ('SSL_CERT', '/var/www/.cert/certificate.crt');
define ('SSL_KEY', '/var/www/.cert/private.key');
define ('SAVE_FILE', 'save.txt');
define ('DEBUG', true);
define ('STOP_FLAG_FILE', 'stop_command');

$file = @fopen(SAVE_FILE, 'a');
if ($file == false) {
    echo "WARNING! Cant open save file for writing. " .
        "Looks like no enought rights. Configuration save will be disabled\n";
}
else fclose($file);

require_once 'lib/Workerman/Autoloader.php';
use Workerman\Worker;
set_include_path('lib/PHPSeclib');
include('Net/SSH2.php');

Worker::$logFile = __DIR__ . "/workerman.log";
if (USE_SSL) {
    $context = array(
        'ssl' => array(
            'peer_name' => 'myvds.tk',
            'local_cert'  => SSL_CERT,
            'local_pk'    => SSL_KEY,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );
}
else $context = [];
$Websocket = new Worker("websocket://".LOCAL_IP.":".LOCAL_PORT, $context);
$Websocket->user = 'www-data';
$Websocket->count = 1;
if (USE_SSL) $Websocket->transport = 'ssl';

$SSH = null;
$SESSION = array();
$OUTPUT = array();
$STOP_FLAG = false;
$PROMPT = '';
$AUTH = ['username' => '', 'password' => ''];

/**
 * @param $connection \Workerman\Connection\TcpConnection
 */
$Websocket->onConnect = function($connection) {
    if (DEBUG) echo str_repeat('_', 180)."\n";
    if (DEBUG) echo "Open connection\n";
    /**
     * @var $SESSION array
     * @var $SSH Net_SSH2
     */
    global $SESSION, $SSH;
    if (!isset($SESSION['files'])) $SESSION['files'] = [];
    if (!isset($SESSION['interpreters'])) $SESSION['interpreters'] = [];
    if (!isset($SESSION['commands'])) $SESSION['commands'] = [];
    $connection->send(json_encode(['action' => 'load', 'SESSION' => $SESSION]));
    if (is_object($SSH) && $SSH->isConnected()) {
        sendLog($connection, 'Previous connection found');
        $connection->send(json_encode(['action' => 'connected']));
        sendDir($connection);
    }
    $file = @fopen(SAVE_FILE, 'a');
    if ($file == false) {
        sendLog($connection, "WARNING! Cant open save file for writing. " .
            "Looks like no enough rights. Configuration saving will be disabled\n", 'error');
    }
    else fclose($file);
};

/**
 * @param $connection \Workerman\Connection\TcpConnection
 * @param $data string
 */
$Websocket->onMessage = function($connection, $data) {
    /**
     * @var $SESSION array
     * @var $OUTPUT array
     * @var $AUTH array
     * @var $SSH Net_SSH2
     * @var $PROMPT string
     */
    global $SESSION, $SSH, $OUTPUT, $PROMPT, $AUTH;
    $data = json_decode($data, true);
    if (DEBUG) var_dump($data);
    try {
        switch ($data['action']) {
            case 'connect':
                if (is_object($SSH) && $SSH->isConnected()) $SSH->disconnect();
                if (!defined('NET_SSH2_LOGGING')) define('NET_SSH2_LOGGING', NET_SSH2_LOG_COMPLEX);
                foreach ($data as $name => $value) $SESSION[$name] = $value;
                $data['host'] = explode(':', $data['host']);
                if (!isset($data['host'][1])) $data['host'][1] = 22;
                $SSH = new Net_SSH2($data['host'][0], (int)$data['host'][1]);
                //$SSH->setTimeout(0);
                $AUTH['username'] = $data['username'];
                $AUTH['password'] = $data['password'];
                $SSH->login($data['username'], $data['password']);
                if ($SSH->isConnected() && !$SSH->isAuthenticated()) {
                    $SSH->login($data['username'], ''); //only after this we can get response from Duo
                    $answer = $SSH->message_log[count($SSH->message_log) - 1];
                    if (DEBUG) var_dump($answer);
                    preg_match_all('/([\d])\. ([\w]+ [\w]+)/', $answer, $result);
                    $option = false;
                    for ($i = 0; $i < count($result[0]); $i++) {
                        if ($result[2][$i] == $data['secondFactor']) {
                            $option = $result[1][$i];
                            break;
                        }
                    }
                    if ($option === false) {
                        sendLog($connection, "No option '{$data['secondFactor']}'. Output:\n$answer", 'error');
                        $SSH->disconnect();
                    }
                    else {
                        $connection->send(json_encode(['action' => "DuoAuth"]));
                        if (DEBUG) echo "option = $option \n";
                        $SSH->login($data['username'], "$option");
                        if (DEBUG) echo $SSH->getLog();
                        if ($data['secondFactor'] != 'SMS passcodes') {
                            //in Russia we call it "Kostyl" - crutch. Dunno why lib doesnt check it's own logs
                            //correctly, soo I am going to do it myself
                            $lastLog = $SSH->message_number_log[count($SSH->message_number_log) - 1];
                            if (strpos($lastLog, 'NET_SSH2_MSG_USERAUTH_SUCCESS') !== false) { //auth ok
                                $SSH->bitmap |= NET_SSH2_MASK_LOGIN;
                                $connection->send(json_encode(['action' => 'connected']));
                                if (isset($SESSION['dir'])) sendDir($connection);
                                $SSH->setTimeout(0);
                                setPrompt();
                            }
                            else {
                                sendLog($connection, "Connection failed", 'error');
                                $connection->send(json_encode(['action' => 'disconnected']));
                                $SSH->disconnect();
                            }
                        }
                    }
                }
                else {
                    if ($SSH->isAuthenticated()) {
                        if (DEBUG) var_dump($SSH->message_log[count($SSH->message_log) - 1]);
                        sendLog($connection, "Looks like Duo failed back, and we logged in");
                        setPrompt();
                        $connection->send(json_encode(['action' => 'connected']));
                        $SSH->setTimeout(0);
                        if (isset($SESSION['dir'])) sendDir($connection);
                    } else { //$SSH->isConnected() == false
                        sendLog($connection, "No SSH connection. Is server address correct?", 'error');
                    }
                }
                break;
            case 'DuoAuth':
                $SSH->login($AUTH['username'], $data['DuoAnswer']);
                //in Russia we call it "Kostyl" - crutch. Dunno why lib doesnt check it's own logs
                //correctly, soo I am going to do it myself
                $lastLog = $SSH->message_number_log[count($SSH->message_number_log) - 1];
                if (strpos($lastLog, 'NET_SSH2_MSG_USERAUTH_SUCCESS') !== false) { //auth ok
                    $SSH->bitmap |= NET_SSH2_MASK_LOGIN;
                    $connection->send(json_encode(['action' => 'connected']));
                    if (isset($SESSION['dir'])) sendDir($connection);
                    $SSH->setTimeout(0);
                    setPrompt();
                } else {
                    sendLog($connection, "Connection failed", 'error');
                    $connection->send(json_encode(['action' => 'disconnected']));
                    $SSH->disconnect();
                }
                break;
            case 'interpreter':
                switch ($data['subaction']) {
                    case 'add':
                        $SESSION['interpreters'][] = $data['interpreter'];
                        break;
                    case 'change':
                        $id = array_search($data['prev'], $SESSION['interpreters']);
                        $SESSION['interpreters'][$id] = $data['interpreter'];
                        foreach ($SESSION['commands'] as &$command) {
                            if ($command['interpreter'] == $data['prev']) $command['interpreter'] = $data['interpreter'];
                        }
                        unset($command);
                        break;
                    case 'delete':
                        $id = array_search($data['interpreter'], $SESSION['interpreters']);
                        unset($SESSION['interpreters'][$id]);
                        break;
                }
                break;
            case 'option':
                $SESSION[$data['name']] = $data['value'];
                break;
            case 'file':
                switch ($data['subaction']) {
                    case 'upload':
                        $content = urldecode(base64_decode($data['content']));
                        $path = $SESSION['dir'] . '/' . $data['name'];
                        $content = str_replace(['"', '`'], ['\"', '\`'], $content);
                        $ls = trim($SSH->exec("ls $path"));
                        if ($ls != $path) {
                            if (preg_match('/\.tar$/', $data['name']) == 1) {
                                $commands = [
                                    "echo -e \"$content\" >> $path",
                                    "tar xvzf tgz --strip=1 {$SESSION['dir']}",
                                    "rm {$data['name']}"
                                ];
                            } else $commands = ["echo -e \"$content\" >> $path"];
                            $result = '';
                            foreach ($commands as $command) {
                                $result = $SSH->exec($command);
                                if (DEBUG) var_dump($result);
                                if ($result != '') {
                                    sendLog($connection, "Error when trying command \"$command\":\n$result", 'error');
                                    if (DEBUG) echo("Error when trying command $command:\n$result");
                                    break;
                                }
                            }
                            if ($result == '') sendLog($connection, "File {$data['name']} uploaded");
                        } else {
                            sendLog($connection, "File {$data['name']} already exists", 'error');
                        }
                        break;
                    case 'delete':
                        $path = $SESSION['dir'] . '/' . $data['name'];
                        $result = $SSH->exec("rm $path");
                        if (strpos($result, 'rm:') !== false) sendLog($connection, "Got this:\n$result", 'error');
                        break;
                    case 'view':
                        $path = $SESSION['dir'] . '/' . $data['name'];
                        $content = $SSH->exec("cat $path");
                        $connection->send(json_encode([
                            'action' => 'view',
                            'title' => $path,
                            'text' => base64_encode($content)
                        ]));
                        break;
                    case 'change':
                        $prev = $SESSION['dir'] . '/' . $data['prev'];
                        $path = $SESSION['dir'] . '/' . $data['name'];
                        $result = $SSH->exec("mv $prev $path");
                        if (strpos($result, 'mv:') !== false) sendLog($connection, "Got this:\n$result", 'error');
                        else {
                            foreach ($SESSION['commands'] as &$command) {
                                if ($command['file'] == $data['prev']) $command['file'] = $data['name'];
                            }
                            unset($command);
                        }
                        break;
                    case 'dir':
                        if (isset($SESSION['dir'])) sendDir($connection);
                        else sendLog($connection, "Please specify dir first", 'error');
                        break;
                }
                break;
            case 'command':
                switch ($data['subaction']) {
                    case 'add':
                        $SESSION['commands'][$data['id']] = [
                            'interpreter' => '',
                            'arguments' => '',
                            'file' => ''
                        ];
                        $SESSION['commands'][$data['id']][$data['name']] = $data['value'];
                        break;
                    case 'change':
                        $SESSION['commands'][$data['id']][$data['name']] = $data['value'];
                        break;
                    case 'delete':
                        unset($SESSION['commands'][$data['id']]);
                        break;
                    case 'run':
                        $arr = $SESSION['commands'][$data['id']];
                        $command = $arr['interpreter'] . ' ' .
                            $arr['arguments'] . ' ' .
                            $SESSION['dir'] . '/' . $arr['file'];
                        if (DEBUG) var_dump($command);
                        $connection->send(json_encode([
                            'action' => 'command',
                            'status' => 'running',
                            'id' => $data['id'],
                        ]));
                        $OUTPUT[$data['id']] = '';
                        $SSH->enablePTY();
                        $SSH->setTimeout(1);
                        $SSH->read();
                        $SSH->write($command . "\n");
                        while (true) {
                            $result = $SSH->read($PROMPT);
                            if (DEBUG) var_dump($result);
                            if ($SSH->isTimeout()) {
                                $OUTPUT[$data['id']] .= $result;
                            } else {
                                $OUTPUT[$data['id']] .= str_replace($PROMPT, '', $result);
                                sendLog($connection, "Command ended");
                                break;
                            }
                            clearstatcache();
                            if (file_exists(STOP_FLAG_FILE)) {
                                unlink(STOP_FLAG_FILE);
                                sendLog($connection, "Command ended by request");
                                break;
                            }
                        }
                        $regex = $command;
                        $regex = preg_quote($regex);
                        $regex = str_replace('/', '\/', $regex);
                        $regex = '/^'.$regex.'\r?\n/';
                        $OUTPUT[$data['id']] = preg_replace($regex, '', $OUTPUT[$data['id']]);
                        $SSH->setTimeout(0);
                        $SSH->disablePTY();
                        $connection->send(json_encode([
                            'action' => 'command',
                            'status' => 'done',
                            'id' => $data['id']
                        ]));
                        break;
                    case 'output':
                        $arr = $SESSION['commands'][$data['id']];
                        $command = $arr['interpreter'] . ' ' .
                            $arr['arguments'] . ' ' .
                            $SESSION['dir'] . '/' . $arr['file'];
                        $connection->send(json_encode([
                            'action' => 'view',
                            'title' => $command,
                            'text' => base64_encode($OUTPUT[$data['id']])
                        ]));
                        break;
                }
                break;
            case 'ping':
                $connection->send(json_encode([
                    'action' => 'pong',
                    'isConnected' => is_object($SSH) && $SSH->isConnected()]));
                break;
            case 'debug':
                if (DEBUG) {
                    ob_start();
                    try {
                        eval($data['code']);
                    }
                    catch (Exception $e) {
                        echo $e."\n";
                    }
                    $res = ob_get_contents();
                    ob_end_clean();
                    echo "DEBUG: " . $res . "\n";
                    $connection->send(json_encode(['action' => 'debug', 'result' => $res]));
                }
                break;
        }
    } catch (Exception $e) {
        sendLog($connection, "Got exception:\n".$e);
    }
};

/**
 * @param $connection \Workerman\Connection\TcpConnection
 */
$Websocket->onClose = function($connection) {
    if (DEBUG) echo "Close connection\n";
};

$Websocket->onWorkerStart = function() {
    /**
     * @var $SESSION array
     */
    global $SESSION;
    if (is_file(SAVE_FILE)) $SESSION = json_decode(file_get_contents(SAVE_FILE), true);
};

$Websocket->onWorkerStop = function() {
    /**
     * @var $SESSION array
     */
    global $SESSION;
    $file = fopen(SAVE_FILE, 'w');
    if ($file) {
        fwrite($file, json_encode($SESSION));
        fclose($file);
    }
};

Worker::runAll();

/**
 * @param $connection \Workerman\Connection\TcpConnection
 * @param $text string
 * @param $type string
 */
function sendLog($connection, $text, $type = 'normal') {
    $connection->send(json_encode([
        'action' => 'log',
        'type' => $type,
        'text' => $text
    ]));
}

/**
 * @param $connection \Workerman\Connection\TcpConnection
 */
function sendDir($connection) {
    /**
     * @var $SSH Net_SSH2
     * @var $SESSION array
     */
    global $SESSION, $SSH;
    if (!is_object($SSH) || $SSH->isConnected() == false) {
        sendLog($connection, "No SSH connection, cant list dir", 'error');
    }
    else {
        $path = $SESSION['dir'];
        $result = $SSH->exec("dir $path");
        $result = trim($result);
        if (DEBUG) var_dump($result);
        if (strpos($result, 'dir: cannot access') !== false)
            sendLog($connection, "Got error:\n$result", 'error');
        else {
            if (strlen($result) > 0) $files = preg_split('/\s+/', $result);
            else {
                $files = [];
                sendLog($connection, "{$SESSION['dir']} is empty");
            }
            asort($files);
            $connection->send(json_encode(['action' => 'dir', 'files' => $files]));
        }
    }
}

function setPrompt() {
    /**
     * @var $SSH Net_SSH2
     * @var $PROMPT string
     */
    global $SSH, $PROMPT;
    $SSH->setTimeout(1);
    $SSH->read();
    $SSH->write("\n");
    $PROMPT = trim($SSH->read());
    $SSH->setTimeout(0);
}