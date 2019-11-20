<?php

namespace RMQDumper;

include __DIR__.'/../vendor/autoload.php';

use Reactor\CliArguments\ArgumentsParser;
use Reactor\CliArguments\ArgumentDefinition;



$argumets = new ArgumentsParser($GLOBALS['argv']);
$argumets->addDefinition(new ArgumentDefinition('_words_', '', null, false, true, ''));
$argumets->addDefinition(new ArgumentDefinition('host', 'H', 'localhost'));
$argumets->addDefinition(new ArgumentDefinition('port', 'P', '15672'));
$argumets->addDefinition(new ArgumentDefinition('binary-port', 'B', '5672'));
$argumets->addDefinition(new ArgumentDefinition('user', 'u'));
$argumets->addDefinition(new ArgumentDefinition('pass', 'p'));
$argumets->addDefinition(new ArgumentDefinition('vhost', 'v', null, false, true, 'vhost[:queue] | :queue'));
$argumets->addDefinition(new ArgumentDefinition('skip', 's', null, false, true, 'vhost[:queue] | :queue'));
$argumets->addDefinition(new ArgumentDefinition('alter', 'a', null, false, true, 'vhost[:queue]~vhost[:queue] | vhost[:queue]'));
$argumets->addDefinition(new ArgumentDefinition('ack', 'k', true, true, false, 'acknowlege (delete) messages when dump'));
$argumets->addDefinition(new ArgumentDefinition('declare', 'd', true, true, false, 'declare persistent queues when load'));
$argumets->parse();

$app = new Application();

$cli_controller = new CliController($app);
$cli_controller->handle($argumets);
