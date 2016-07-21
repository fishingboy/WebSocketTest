<html>
<head>
<meta http-equiv="Content-Type" content="text/html" charset="utf-8">
<title>WebSocket</title>
<script src="https://code.jquery.com/jquery-1.12.4.js"></script>
<script type="text/javascript">
var socket;  // WebSocket

$(document).ready(function() {
    SetupWebSocket();
});

// 連線至提供資料的WebSocket
function SetupWebSocket()
{
    var host = 'ws://localhost:12345/server.php';
    socket = new WebSocket(host);

    socket.onopen = function(e) {
        $('#ShowTime').html('WebSocket Connected!');
    };

    socket.onmessage = function(e) {
        $('#ShowTime').html(e.data);
    };

    socket.onclose = function(e) {
        alert('Disconnected - status ' + this.readyState);
    };
}
</script>
</head>
<body>
    <div id="ShowTime" Style="font-size:24px"></div>
</body>
</html>