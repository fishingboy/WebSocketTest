<?php
/*  >php -q server.php  */

$debug = true;

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();

$sock    = WebSocket("localhost", 12345);
$sockets = array(
    $sock
);
$users   = array();

while (true) {
    $read = $sockets;

    $write  = NULL;
    $except = NULL;
    if (socket_select($read, $write, $except, NULL) < 1)
        continue;

    if (in_array($sock, $read)) {
        $newsock = socket_accept($sock);
        connect($newsock);

        $key = array_search($sock, $read);
        unset($read[$key]);
    }

    foreach ($read as $socket) {
        $bytes = @socket_recv($socket, $buffer, 2048, 0);

        if ($bytes == 0) {
            disconnect($socket);
        } else {
            $user = getuserbysocket($socket);
            if (!$user->handshake) {
                dohandshake($user, $buffer);
            } else {
                console("\nprocess -> id: " . $user->id);
                process($socket, $buffer);
            }
        }
    }

}

//---------------------------------------------------------------

function doTest($socket)
{
    while (true) {
        console("[doTest] " . $socket);
        $sendText = date('Y-m-d H:i:s');

        // 如果送失敗就停止
        if (!send($socket, $sendText)) {
            echo "[doTest] Stop \n";
            return;
        }
        sleep(1);
    }
}

function process($socket, $msg)
{
    // 訊息需要解碼
    $action = unmask($msg);
    console("< " . $action);
}

/**
 * Unmask a received recvMsg
 * @param $payload
 */
function unmask($recvMsg)
{
    // ord 回傳 ascii code
    // 127 → 0x01111111
    $length = ord($recvMsg[1]) & 127;

    if ($length == 126) {
        $masks = substr($recvMsg, 4, 4);
        $data  = substr($recvMsg, 8);
    } elseif ($length == 127) {
        $masks = substr($recvMsg, 10, 4);
        $data  = substr($recvMsg, 14);
    } else {
        $masks = substr($recvMsg, 2, 4);
        $data  = substr($recvMsg, 6);
    }

    $text = '';
    for ($i = 0; $i < strlen($data); ++$i) {
        $text .= $data[$i] ^ $masks[$i % 4];
    }
    return $text;
}

function send($client, $msg)
{
    console("> " . $msg);
    $sendMsg = encode($msg);
    $result  = socket_write($client, $sendMsg, strlen($sendMsg));

    if (!$result) {
        disconnect($client);
        $client = false;
        return false;
    }
    return true;
}


/**
 * Encode a text for sending to clients via ws://
 * @param $text
 */
function encode($text)
{
    $header        = " ";
    $header[0]     = chr(0x81);
    $header_length = 1;

    //Payload length:  7 bits, 7+16 bits, or 7+64 bits
    $dataLength = strlen($text);

    //The length of the payload data, in bytes: if 0-125, that is the payload length.
    if ($dataLength <= 125) {
        $header[1]     = chr($dataLength);
        $header_length = 2;
    } elseif ($dataLength <= 65535) {
        // If 126, the following 2 bytes interpreted as a 16
        // bit unsigned integer are the payload length.

        $header[1]     = chr(126);
        $header[2]     = chr($dataLength >> 8);
        $header[3]     = chr($dataLength & 0xFF);
        $header_length = 4;
    } else {
        // If 127, the following 8 bytes interpreted as a 64-bit unsigned integer (the
        // most significant bit MUST be 0) are the payload length.
        $header[1]     = chr(127);
        $header[2]     = chr(($dataLength & 0xFF00000000000000) >> 56);
        $header[3]     = chr(($dataLength & 0xFF000000000000) >> 48);
        $header[4]     = chr(($dataLength & 0xFF0000000000) >> 40);
        $header[5]     = chr(($dataLength & 0xFF00000000) >> 32);
        $header[6]     = chr(($dataLength & 0xFF000000) >> 24);
        $header[7]     = chr(($dataLength & 0xFF0000) >> 16);
        $header[8]     = chr(($dataLength & 0xFF00) >> 8);
        $header[9]     = chr($dataLength & 0xFF);
        $header_length = 10;
    }
    return $header . $text;
}


function WebSocket($address, $port)
{
    $master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die("socket_create() failed");
    socket_set_option($master, SOL_SOCKET, SO_REUSEADDR, 1) or die("socket_option() failed");
    socket_bind($master, $address, $port) or die("socket_bind() failed");
    socket_listen($master, 20) or die("socket_listen() failed");
    echo "Server Started : " . date('Y-m-d H:i:s') . "\n";
    echo "Master socket  : " . $master . "\n";
    echo "Listening on   : " . $address . " port " . $port . "\n\n";
    return $master;
}

function connect($socket)
{
    global $sockets, $users;
    $newUser         = new User();
    $newUser->id     = uniqid();
    $newUser->socket = $socket;
    array_push($users, $newUser);
    array_push($sockets, $socket);
    console("id:" . $newUser->id . ", " . $socket . " CONNECTED!");
}

function disconnect($socket)
{
    global $sockets, $users;
    $found = null;
    $n     = count($users);
    for ($i = 0; $i < $n; $i++) {
        if ($users[$i]->socket == $socket) {
            $found = $i;
            break;
        }
    }
    if (!is_null($found)) {
        array_splice($users, $found, 1);
    }
    $index = array_search($socket, $sockets);
    socket_close($socket);
    console($socket . " DISCONNECTED!");
    if ($index >= 0) {
        array_splice($sockets, $index, 1);
    }
}

function dohandshake($user, $buffer)
{
    console("\nRequesting handshake...");
    console($buffer);

    list($resource, $host, $origin, $strkey, $data) = getheaders($buffer);
    if (strlen($strkey) == 0) {
        socket_close($user->socket);
        console('failed');
        return false;
    }

    $hash_data = base64_encode(sha1($strkey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

    $upgrade = "HTTP/1.1 101 WebSocket Protocol Handshake\r\n" . "Upgrade: webSocket\r\n" . "Connection: Upgrade\r\n" . "WebSocket-Origin: " . $origin . "\r\n" . "WebSocket-Location: ws://" . $host . $resource . "\r\n" . "Sec-WebSocket-Accept:" . $hash_data . "\r\n\r\n";

    socket_write($user->socket, $upgrade, strlen($upgrade));
    $user->handshake = true;
    console($upgrade);
    console("Done handshaking...\n\n");

    doTest($user->socket);
    return true;
}


function getheaders($req)
{
    $r = $h = $o = $key = $data = null;
    if (preg_match("/GET (.*) HTTP/", $req, $match)) {
        $r = $match[1];
    }
    if (preg_match("/Host: (.*)\r\n/", $req, $match)) {
        $h = $match[1];
    }
    if (preg_match("/Origin: (.*)\r\n/", $req, $match)) {
        $o = $match[1];
    }
    if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $req, $match)) {
        $key = $match[1];
    }
    if (preg_match("/\r\n(.*?)\$/", $req, $match)) {
        $data = $match[1];
    }

    return array(
        $r,
        $h,
        $o,
        $key,
        $data
    );
}

function getuserbysocket($socket)
{
    global $users;
    $found = null;
    foreach ($users as $user) {
        if ($user->socket == $socket) {
            $found = $user;
            break;
        }
    }
    return $found;
}

function wrap($msg = "")
{
    return chr(0) . $msg . chr(255);
}
function unwrap($msg = "")
{
    return substr($msg, 1, strlen($msg) - 2);
}
function console($msg = "")
{
    global $debug;
    if ($debug) {
        echo $msg . "\n";
    }
}

class User
{
    var $id;
    var $socket;
    var $handshake;
}
