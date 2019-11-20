<?php

namespace RMQDumper;

class CliController {

    function __construct($app) {
        $this->app = $app;
    }

    function handle($arguments) {
        $this->arguments = $arguments;
        $args = $arguments->get('_words_');
        if (isset($args[1])) {
            $command = $args[1];
        } else {
            $command = 'help';
        }
        $command_handler =  'command'.ucfirst(strtolower($command));
        if (method_exists($this, $command_handler)) {
            $this->app->setCredentials(
                $arguments->get('host'),
                $arguments->get('port'),
                $arguments->get('binary-port'),
                $arguments->get('user'),
                $arguments->get('pass')
            );
            $this->app->auto_declare = ($arguments->get('declare') == true);
            try {
                call_user_func_array(array($this, $command_handler), []);
            } catch (\PhpAmqpLib\Exception\AMQPProtocolChannelException $e) {
                fwrite(STDERR, $e->getMessage()."\n");
                exit(1);
            } catch (\Exception $e) {
                fwrite(STDERR, $e->getMessage()."\n");
                exit(1);
            }
        } else {
            $this->commandHelp();
        }
    }

    function commandHelp() {

        echo "RabbitMQ messages backup tool version ".$this->app->version."\n\n";

        echo "  Can dump messages to json file, load them back. Can modify destination vhost or queue name.\n";
        echo "  To backup exchanges, queues, bindings use RMQ webinterface.\n\n";

        echo "Usage:\n";
        echo "  rmq-dump command <arguments>\n\n";

        echo "Commands:\n";
        echo "  dump - dumps messages from RMQ to STDOUT\n";
        echo "  load - loads messages from STDIN to RMQ\n";
        echo "  dryload - Dry run load will show how -v -s -a optionas will affect messages\n";
        echo "  list - shows current state in RMQ with -v -s filters. Use as dry run for dump\n";
        echo "  help - prints help\n\n";

        echo "Arguments:\n";
        echo "  Full name    | Short | Default            | Note\n";
        echo "-------------------------------------------------------\n";

        foreach ($this->arguments->definitions as $key => $definition) {
            if ($key != '_words_') {
                if (!$definition->is_flag) {
                    echo sprintf("  --%-12s -%-6s %-20s %s\n",
                        $definition->name,
                        $definition->short,
                        $definition->default,
                        $definition->description
                    );
                } else {
                    echo sprintf("  --%-12s -%-6s %-20s %s\n",
                        $definition->name,
                        $definition->short,
                        'false',
                        $definition->description
                    );
                }
            }
        }
        echo "\n";
        echo "Examples:\n";

        echo "Make dump all messages of all vhosts:\n";
        echo "  rmq-dump dump -H host -u user -p password > dump.json\n\n";

        echo "Make dump all messages of vhost1:\n";
        echo "  rmq-dump dump -H host -u user -p password -v vhost1 > dump.json\n\n";

        echo "Load dump:\n";
        echo "  cat dump.json | rmq-dump load -H host -u user -p password \n\n";

        echo "Load to vhost1 and create queues if needed:\n";
        echo "  cat dump.json | rmq-dump load -H host -u user -p password -d -a vhost1 \n\n";

        echo "Load only messages from vhost1 to vhost2:\n";
        echo "  cat dump.json | rmq-dump load -H host -u user -p password -v host1 -a vhost2 \n\n";

        echo "Dry load to check you filters\n";
        echo "  cat dump.json | rmq-dump dryload -H host -u user -p password -v host1 -a vhost2 \n\n";

        echo "Copy all messages from queue1 to queue2 qithout storing them\n";
        echo "rmq-dump -u user -p pass -v /app/live:queue1 dump | ./rmq-dump -u user -p pass -a :queue2 load\n\n";

        echo "Copy all messages from vhost1 to vhost2 not storing them. Note: all queues have exists at vhost2\n";
        echo "rmq-dump -u user -p pass -v /vhost1 dump | ./rmq-dump -u user -p pass -a /vhost2 load\n\n";
    }

    function commandList() {
        $to_dump = $this->parseVhostsArg($this->arguments->get('vhost'));
        $to_skip = $this->parseVhostsArg($this->arguments->get('skip'));
        $vhosts = $this->app->getVhostList();
        $cnt_total = 0;
        foreach ($vhosts as $vhost) {
            if ($this->matchVhostQueue($vhost, null, $to_skip) || !$this->matchVhostQueue($vhost, null, $to_dump, true)) {
                continue;
            }

            echo "$vhost\n";
            $queues = $this->app->getQueueList($vhost);
            foreach ($queues as $queue => $messages) {
                if ($this->matchVhostQueue($vhost, $queue, $to_dump, true)) {
                    if (!$this->matchVhostQueue($vhost, $queue, $to_skip)) {
                        echo "    $queue $messages\n";
                        $cnt_total += $messages;
                    } else {
                        echo "    $queue skipped\n";
                    }
                }
            }
        }
        echo "Total: $cnt_total\n";
    }

    function commandDump() {
        $to_dump = $this->parseVhostsArg($this->arguments->get('vhost'));
        $to_skip = $this->parseVhostsArg($this->arguments->get('skip'));
        $to_alter = $this->parseAlternation($this->arguments->get('alter'));
        $ack = ($this->arguments->get('ack') == true);
        fwrite(STDERR, "Dumping with ack ".($ack?'true':'false')." \n");
        $cnt_total = 0;
        $this->printAlter($to_alter);
        foreach ($this->app->getVhostList() as $vhost) {
            $cnt_total += $this->dumpVhost($vhost, $to_dump, $to_skip, $to_alter, $ack);
        }
        fwrite(STDERR, "Total: $cnt_total\n");
        $this->app->close();
    }

    function dumpVhost($vhost, $to_dump, $to_skip, $to_alter, $ack) {
        if ($this->matchVhostQueue($vhost, null, $to_skip) || !$this->matchVhostQueue($vhost, null, $to_dump, true)) {
            return 0;
        }
        $queues = $this->app->connectVhost($vhost);
        fwrite(STDERR, "$vhost \n");

        $cnt_total = 0; 
        foreach ($queues as $queue => $size) {
            if ($this->matchVhostQueue($vhost, $queue, $to_dump, true)) {
                if ($this->matchVhostQueue($vhost, $queue, $to_skip)) {
                    fwrite(STDERR, "    $queue skipped\n");
                } elseif (!($size > 0)) {
                    fwrite(STDERR, "    $queue empty\n");
                } else {
                    $cnt_total += $this->dumpQueue($queue, $size, $to_alter, $ack);
                }
            }
        }
        return $cnt_total;
    }

    function printAlter($alters) {
        foreach($alters as $alternation) {
            $src = $alternation['source'];
            $dst = $alternation['destination'];
            fwrite(STDERR, "Alternation:  {$src['vhost']}:{$src['queue']} to {$dst['vhost']}:{$dst['queue']}\n");
        }
    }

    function dumpQueue($queue, $expected, $to_alter, $ack) {
        $cnt_total = 0;
        foreach ($this->app->dumpQueue($queue, $ack) as $key => $message) {
            echo json_encode($this->alterMessage($message, $to_alter))."\n";
            $cnt_total++;
            fwrite(STDERR, "    $queue $cnt_total of $expected\r");
        }
        fwrite(STDERR, "    $queue $cnt_total of $expected\n");
        return $cnt_total;
    }

    function commandDryload() {
        $this->commandLoad(true);
    }

    function commandLoad($_dry_run = false) {
        $cnt_total = $cnt = 0;
        $filters = $this->arguments->getAll();
        $queue = $vhost = null;
        $to_dump = $this->parseVhostsArg($this->arguments->get('vhost'));
        $to_skip = $this->parseVhostsArg($this->arguments->get('skip'));
        $to_alter = $this->parseAlternation($this->arguments->get('alter'));
        $this->printAlter($to_alter);
        while ($t = fgets(STDIN)) {
            $message = json_decode($t, true);
            if ($this->matchVhostQueue($message['vhost'], $message['queue'], $to_dump, true) && !$this->matchVhostQueue($message['vhost'], $message['queue'], $to_skip)) {
                $message = $this->alterMessage($message, $to_alter);
                $this->app->loadMessage($message, $_dry_run);
                if ($queue != $message['queue'] || $vhost != $message['vhost']) {
                    if ($queue !== null) {
                        echo "    $queue $cnt\n";
                    }
                    if ($vhost != $message['vhost']) {
                        $vhost = $message['vhost'];
                        echo "$vhost\n";
                    }
                    $queue = $message['queue'];
                    $cnt = 0;
                }
                echo "    $queue $cnt\r";
                $cnt++;
                $cnt_total++;
            }
        }
        if ($cnt_total > 0) {
            echo "    $queue $cnt\n";
        }
        echo "Total: $cnt_total\n";
        $this->app->close(true);
    }

    function alterMessage($message, $alters) {
        foreach($alters as $alternation) {
            if ($this->matchesAlternation($message, $alternation['source'])) {
                $destination = $alternation['destination'];
                if (!empty($destination['vhost'])) {
                    $message['vhost'] = $destination['vhost'];
                }
                if (!empty($destination['queue'])) {
                    $message['queue'] = $destination['queue'];
                }
            }
        }
        return $message;
    }

    function matchesAlternation($message, $vhost_queue) {
        if (!empty($vhost_queue['vhost']) && $message['vhost'] != $vhost_queue['vhost']) {
            return false;
        }
        if (!empty($vhost_queue['queue']) && $message['queue'] != $vhost_queue['queue']) {
            return false;
        }
        return true;
    }

    function parseVhostsArg($raw) {
        $data = array(
            'vhosts' => array(),
            'queues' => array(),
        );
        foreach ($raw as $value) {
            $value = $this->parseVhostQueueName($value);
            if (empty($value['vhost'])) {
                $data['queues'][] = $value['queue'];
            } else {
                if (empty($value['queue'])) {
                    $data['vhosts'][$value['vhost']] = array();
                } else {
                    $data['vhosts'][$value['vhost']][] = $value['queue'];
                }
            }
        }
        return $data;
    }

    function parseVhostQueueName($name) {
        $name = explode(':', $name);
        if (!isset($name[1])) {
            $name[1] = null;
        }
        $rez = array(
            'vhost' => $name[0],
            'queue' => $name[1],
        );
        return $rez;
    }

    function parseAlternation($raw) {
        $data = array();
        foreach ($raw as $value) {
            $value = explode('~', $value);
            if (!isset($value[1])) {
                $source = null;
                $destination = $this->parseVhostQueueName($value[0]);
            } else {
                $source = $this->parseVhostQueueName($value[0]);
                $destination = $this->parseVhostQueueName($value[1]);
            }
            $data[] = array(
                'source' => $source,
                'destination' => $destination,
            );
        }
        return $data;
    }

    function matchVhostQueue($vhost, $queue, $stack, $empty_is_match = false) {
        if (empty($stack['vhosts']) && empty($stack['queues']) && $empty_is_match) {
            return true;
        }
        if (empty($stack['vhosts']) && $queue == null && $empty_is_match) {
            return true;
        }
        if (in_array($queue, $stack['queues'])) {
            return true;
        }

        if (isset($stack['vhosts'][$vhost])) {
            if (in_array($queue,$stack['vhosts'][$vhost])) {
                return true;
            }
            if ($queue == null) {
                return true;
            }
            if (empty($stack['vhosts'][$vhost])) {
                return true;
            }
        }
        return false;
    }

}
