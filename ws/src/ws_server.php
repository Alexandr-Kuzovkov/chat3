<?php

namespace App;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use App\Repository\UsersRepository;
use App\Repository\StoreRepository;
use App\Repository\EventRepository;

/**
 * Class WebsocketServer
 */
class WebsocketServer
{
    const PING_DELAY_MS = 25000;

    /**
     * @var Server
     */
    private $ws;

    private $usersRepository;
    private $storeRepository;
    private $eventRepository;

    /**
     * WebsocketServer constructor.
     */
    public function __construct() {

        $this->usersRepository = new UsersRepository();
        $this->storeRepository = new StoreRepository();
        $this->eventRepository = new EventRepository();

        $this->ws = new Server('0.0.0.0', 9502);

        $this->ws->on('start', function (Server $ws){
            echo "OpenSwoole WebSocket Server is started at http://127.0.0.1:9502\n";
        });
        $this->ws->on('open', function ($ws, Request $request): void {
            $this->onConnection($request);
        });
        $this->ws->on('message', function ($ws, Frame $frame): void  {
            $this->onMessage($frame);
        });
        $this->ws->on('close', function ($ws, $id): void  {
            $this->onClose($id);
        });
        $this->ws->on('workerStart', function (Server $ws) {
            $this->onWorkerStart($ws);
        });

        $this->ws->start();
    }

    /**
     * @param Server $ws
     */
    private function onWorkerStart(Server $ws): void {

        $ws->tick(self::PING_DELAY_MS, function () use ($ws) {
            foreach ($ws->connections as $id) {
                $ws->push($id, 'ping', WEBSOCKET_OPCODE_PING);
            }
        });
    }


    /**
     * Client connected
     * @param Request $request
     */
    private function onConnection(Request $request): void {
        echo "client-{$request->fd} is connected\n";
        //var_dump($request);
        $nicname = null;
        if (isset($request->get)){
            $get = $request->get;
            if (isset($get['nicname'])){
                $nicname = $get['nicname'];
            }
            if ($nicname){
                $this->ws->push($request->fd, json_encode(['type' => 'connect']));
                $user = [
                    'nicname' => $nicname
                ];
                $this->usersRepository->save($request->fd, $user);
                $usersResponse = $this->usersRepository->getUsers();
                foreach ($this->ws->connections as $id){
                    $this->ws->push($id, json_encode(['type' => 'users_online', 'users_online' => $usersResponse]));
                }
            }
        }
    }

    /**
     * @param $frame
     */
    private function onMessage($frame): void {
        echo 'We recieve: ';
        print_r($frame);
        $decodedData = json_decode($frame->data);
        print('data:'.PHP_EOL);
        var_dump($decodedData);
        if (!isset($decodedData->type))
            return;
        switch ($decodedData->type){
            case 'user_connect':
                if ($decodedData->nicname){
                    $user = ['nicname' => $decodedData->nicname];
                    $this->usersRepository->save($frame->fd, $user);
                    foreach ($this->ws->connections as $id){
                        $this->ws->push($id, json_encode(['type' => 'new_user', 'user' => $decodedData->nicname]));
                    }
                }
                $ice = \getIce();
                $usersResponse = $this->usersRepository->getUsers();
                foreach ($this->ws->connections as $id){
                    if ($frame->fd === $id){
                        $this->ws->push($frame->fd, json_encode(['type' => 'users_online', 'users_online' => $usersResponse, 'ice' => $ice]));
                    } else {
                        $this->ws->push($id, json_encode(['type' => 'users_online', 'users_online' => $usersResponse]));
                    }
                }
                break;

            case 'get_event':
                if ($decodedData->event_id){
                    $currEvent = $this->eventRepository->get($decodedData->event_id);
                    if (!$currEvent){
                        $currEvent = ['event_id' => $decodedData->event_id, 'publisher_id' => '', 'is_live' => 0];
                    }
                    $this->ws->push($frame->fd, json_encode(['type' => 'curr_event', 'event' => $currEvent, 'event_id' => $decodedData->event_id]));
                }
                break;

            case 'stream_updated':
                if ($decodedData->event_id){
                foreach ($this->ws->connections as $id){
                        print('sending update_stream:'.PHP_EOL);
                        $this->ws->push($id, json_encode(['type' => 'update_stream', 'event_id' => $decodedData->event_id]));
                    }
                }
                break;

            default: echo 'unknown message type'.PHP_EOL;
        }

    }


    /**
     * @param $id
     */
    private function onClose(int $id): void {
        echo "client-{$id} is closed\n";
        $user = $this->usersRepository->get($id);
        if (!$user)
            return;
        $nicname = $user['data']['nicname'];
        $this->usersRepository->delete($id);
        $this->ws->push($id, json_encode(['type' => 'disconnect']));
        $usersResponse = $this->usersRepository->getUsers();
        foreach ($this->ws->connections as $id){
            $this->ws->push($id, json_encode(['type' => 'users_online', 'users_online' => $usersResponse]));
            $this->ws->push($id, json_encode(['type' => 'user_disconnected', 'user' => $nicname]));
        }
    }


}