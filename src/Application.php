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
        $version = '0.0.1';

    function __construct() {
        copy(__DIR__."/rabbitmqadmin", "/tmp/rabbitmqadmin");
        chmod("/tmp/rabbitmqadmin", 0766);
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
            return;
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
        if ($temp_exchange) {
            try {
                $this->channel->exchange_delete($this->exchange);
            } catch (AMQPRuntimeException $e) {}
            $this->channel->exchange_declare($this->exchange, 'fanout', false, false, false);
        }
        return $this->getQueueList($vhost);
    }

    function rmqAdminCall($command) {
        $json = shell_exec("/tmp/rabbitmqadmin ".
            "-u '{$this->user}' ".
            "--password='{$this->pass}' ".
            "-H {$this->host} ".
            "-P '{$this->port}' ".
            "-f raw_json $command"
        );
        $list = json_decode($json, true);
        if ($list === null) {
            throw new \Exception($json, 1);
        }
        return $list;
    }


    function getVhostList() {
        $list = $this->rmqAdminCall("list vhosts");
        $data = array();
        foreach ($list as $key => $value) {
            $data[] = $value['name'];
        }
        return $data;
    }

    function getExchangeList() {
        $list = $this->rmqAdminCall("list exchanges name");
        $data = array();
        foreach ($list as $key => $value) {
            $data[$value['name']] = $value['name'];
        }
        return $data;
    }

    function getQueueList($vhost) {
        $list = $this->rmqAdminCall("list queues");
        $data = array();
        foreach ($list as $key => $value) {
            if ($value['vhost'] === $vhost) {
                $data[$value['name']] = isset($value['messages'])?$value['messages']:0;
            }
        }
        return $data;
    }

    function dumpVhost($vhost_name) {
        $this->connectVhost($vhost_name);
        $queues = $this->getQueueList($vhost_name);
        foreach ($queues as $queue => $count) {
            $this->dumpQueue($queue);
        }
    }

    function dumpQueue($queue_name) {
        return new CallbackStream(function () use ($queue_name) {
            $msg = $this->channel->basic_get($queue_name, false);
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
        $this->channel->queue_bind($queue, $this->exchange);
        $this->current_queue = $queue;
    }

    function loadMessage($message_data, $dry_run = false) {
        $queues = $this->connectVhost($message_data['vhost'], true);
        if (!isset($queues[$message_data['queue']])) {
            throw new \Exception("Queue does not exist {$message_data['vhost']}:{$message_data['queue']}", 1);
        }
        $this->configureExhangeWith($message_data['queue']);
        $msg = new AMQPMessage($message_data['body'], $message_data['properties']);
        $headers = new AMQPTable($message_data['headers']);
        $msg->set('application_headers', $headers);
        if (!$dry_run) {
            $this->channel->basic_publish($msg, $this->exchange, $message_data['routing_key']);
        }
    }

}