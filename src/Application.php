<?php

namespace RMQDumper;


use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PhpAmqpLib\Exception\AMQPRuntimeException;

class Application {

    public
        $exchange = '_dumper_temp',
        $vhost = null,
        $current_queue = null,
        $version = '0.0.4',
        $queue_list;

    function __construct() {
        $this->queue_list = array();
    }

    function setCredentials($host, $port, $bport, $user, $pass) {
        $this->host = $host;
        $this->port = $port;
        $this->bport = $bport;
        $this->user = $user;
        $this->pass = $pass;
    }

    function close($delete_exchange = false) {
        if ($delete_exchange) {
            try {
                if (!empty($this->channel)) {
                    $this->channel->exchange_delete($this->exchange);
                }
            } catch (AMQPRuntimeException $e) {}
        }
        if (!empty($this->connection)) {
            $this->connection->close();
        }
        if (!empty($this->channel)) {
            $this->channel->close();
        }
    }

    function connectVhost($vhost, $temp_exchange = false) {
        if ($this->vhost === $vhost) {
            return $this->getQueueList($vhost);
        }
        $this->close($temp_exchange);
        $this->vhost = $vhost;
        $this->connection = new AMQPStreamConnection(
            $this->host,
            $this->bport,
            $this->user,
            $this->pass,
            $vhost
        );
        $this->channel = $this->connection->channel();
        $this->channel->basic_qos(0, 10, false);
        if ($temp_exchange) {
            try {
                $this->channel->exchange_delete($this->exchange);
            } catch (AMQPRuntimeException $e) {}
            $this->channel->exchange_declare($this->exchange, 'fanout', false, false, false);
        }
        return $this->getQueueList($vhost);
    }

    function rmqAPICall($command) {
        $command = array_map('rawurlencode', (array)$command);
        $command = implode('/', $command);
        $json = file_get_contents(
            'http://'.
            rawurlencode($this->user).':'.
            rawurlencode($this->pass).
            '@'.$this->host.':'.$this->port.'/api/'.
            $command
        );
        $list = json_decode($json, true);
        if (empty($list) || isset($list['error'])) {
            throw new \Exception($json, 1);
        }
        return $list;
    }


    function getVhostList() {
        $list = $this->rmqAPICall("vhosts");
        $data = array();
        foreach ($list as $key => $value) {
            $data[] = $value['name'];
        }
        return $data;
    }

    function getQueueList($vhost) {
        if (isset($this->queue_list[$vhost])) {
            return $this->queue_list[$vhost];
        }
        $list = $this->rmqAPICall("queues");
        $data = array();
        foreach ($list as $key => $value) {
            if ($value['vhost'] === $vhost) {
                $data[$value['name']] = $value['messages'];
            }
        }
        $this->queue_list[$vhost] = $data;
        return $data;
    }

    function dumpQueue($queue_name, $ack) {
        return new CallbackStream(function () use ($queue_name, $ack) {
            $msg = $this->channel->basic_get($queue_name, $ack);
            return $this->messageHandler($msg, $queue_name);
        });
    }

    function messageHandler($msg, $queue) {
        if (empty($msg)) {
            return null;
        }
        $headers = array();
        $props = $msg->get_properties();
        if (isset($props['application_headers'])) {
            $headers = $props['application_headers']->getNativeData();
        }
        unset($props['application_headers']);
        $message_data = array(
            'vhost' => $this->vhost,
            'queue' => $queue,
            'exchange' => $msg->delivery_info['exchange'],
            'routing_key' => $msg->delivery_info['routing_key'],
            'headers' => $headers,
            'properties' => $props,
            'body' => $msg->getBody(),
        );
        return $message_data;
    }

    function configureExhangeWith($queue) {
        if ($this->current_queue === $queue) {
            return;
        }
        if ($this->current_queue !== null) {
            $this->channel->queue_unbind($this->current_queue, $this->exchange);
        }
        if ($this->auto_declare) {
            $this->channel->queue_declare(
                $queue,
                false, //passive
                true, //durable
                false, //exclusive
                false //autodelete
            );
        }
        $this->channel->queue_bind($queue, $this->exchange);
        $this->current_queue = $queue;
    }

    function loadMessage($message_data, $dry_run = false) {
        $queues = $this->connectVhost($message_data['vhost'], true);
        if (!isset($queues[$message_data['queue']]) && !$this->auto_declare) {
            throw new \Exception("Missing queue: {$message_data['vhost']}:{$message_data['queue']}. Try add --declare", 1);
        }
        if (!$dry_run) {
            $this->configureExhangeWith($message_data['queue'], $dry_run);
            $msg = new AMQPMessage($message_data['body'], $message_data['properties']);
            $headers = new AMQPTable($message_data['headers']);
            $msg->set('application_headers', $headers);
            $this->channel->basic_publish($msg, $this->exchange, $message_data['routing_key']);
        }
    }

}
