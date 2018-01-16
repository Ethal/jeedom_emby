const WebSocket = require('ws');
var request = require('request');
var express = require('express');
var app = express();
var urlJeedom = '';
var playerId = '';
var urlEmby = '';
var authToken = '';

process.env.NODE_TLS_REJECT_UNAUTHORIZED = "0";

process.argv.forEach(function(val, index, array) {
	switch ( index ) {
		case 2 : urlJeedom = val; break;
        case 3 : playerId = val; break;
        case 4 : urlEmby = val; break;
        case 5 : authToken = val; break;
	}
});

urlJeedom = urlJeedom + '&player=' + playerId;
urlEmby = 'ws://' + urlEmby + '?api_key=' + authToken + '&deviceId=' + playerId;

function connectJeedom(payload) {
    console.log((new Date()) + " - Data - " + payload);
	request(urlJeedom, function (error, response, body) {
		if (!error && response.statusCode == 200) {
			//if (log == 'debug') {console.log((new Date()) + " - Return OK from Jeedom");}
		}else{
			console.log((new Date()).toLocaleString(), error);
		}
	});
}

const ws = new WebSocket(urlEmby);

ws.on('open', function open() {
  ws.send('{"MessageType":"SessionsStart", "Data": "0,1500"}');
  ws.send('{"MessageType":"SessionsStop", "Data": ""}');
});

ws.on('message', connectJeedom(data));

app.get('/', function (req, res) {
  res.send('Hello World')
});

app.listen(3000);
