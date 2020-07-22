#!/usr/bin/env node

/*сервер*/

var fs = require('fs');
var express = require('express');
var app = express();
var cors = require('cors')
var fileUpload = require('express-fileupload');
var cookieParser = require('cookie-parser');
var bodyParser = require('body-parser');
var http = require('http');
var server = http.createServer(app);
var io = require('socket.io')(server);
var port_default = 80;
var port = (process.argv.length > 2)? parseInt(process.argv[2]) : port_default;
var Helper = require('./modules/helper');
var chat = require('./modules/chat');
global.chat = chat;
global.io = io;
var controller = require('./modules/controller');
var Handler = require('./modules/handler');
var cons = require('consolidate');
const RTCMultiConnectionServer = require('rtcmulticonnection-server');


server.listen(port,function(){
    console.log('Server start at port '+port+ ' ' + Helper.getTime());
    /*сброс привилегий*/
    if (process.getuid && process.setuid) {
        console.log('Current uid: ' + process.getuid());
        if (process.geteuid)
            console.log('Current euid: ' + process.geteuid());
        try {
            process.setuid('www-data');
            console.log('New uid: ' + process.getuid());
            if (process.geteuid && process.seteuid){
                process.seteuid('www-data');
                console.log('New euid: ' + process.geteuid());
            }
        }
        catch (err) {
            console.log('Failed to set uid: ' + err);
        }
    }
});


/* CORS */
app.use(cors());

/* настройки для рендеринга шаблонов*/
app.engine('html', cons.swig);
app.set('view engine', 'html');
app.set('views',__dirname+'/views');

/* подключение каталога статических файлов, cookies, bodyParser */
app.use(express.static(__dirname+'/public'));
app.use(cookieParser());
app.use(bodyParser.urlencoded({ extended: false }));
app.use('/upload', fileUpload());

/*обработка запросов*/
app.get('/', controller.index );
app.get('/choosenicname', controller.choosenicname);
app.post('/choosenicname', controller.newUser);
app.get('/file/:secret', controller.download_file);
app.get('/file-del/:secret', controller.remove_file);
app.post('/upload', controller.upload_file);
app.get('/test', controller.test);
app.get('/conference', controller.conference);
app.get('/conference2', controller.conference2);
app.get('/test-design', controller.test_design);


io.on('connection', function(socket){
    Handler.user_connect(socket, chat);
    Handler.user_disconnect(socket, chat);
    Handler.user_message(socket, chat);
    Handler.message_history(socket, chat);
    Handler.request_files(socket, chat);
    Handler.wrtc_message(socket, chat);
    Handler.get_ice(socket, chat);
    Handler.login(socket, chat);
});

io.of('/rtcmulticonnection/').on('connection', function(socket) {
    RTCMultiConnectionServer.addSocket(socket);
});



