RabbitMQ messages backup tool
=============================

Can dump messages to json file, load them back. Can modify destination vhost or queue name.

**It dumps and loads only messages**

To backup exchanges, queues, bindings use RMQ webinterface.

### How it works

rmq-dump does not consume messages (does not asknowleges).

rmq-dump gets (basic_get) all messages then closes connections so all mesages go back to their queues.
Tested up to 500k messages in a queue.

rmq-dump uses STDOUT and STDIN for dumping and loading messages.

rmq-dump stores all messages to json lines format.

It you need consistent state backup - stop all consumers and publisher before you make backup.

Each stored message contains:

- vhost
- queue
- body
- proerties
- headers

When you load them back it creates temporary exchange in order to put message to destination queue qith original routing key.
That means in properties on loaded message original exchenge will be replaced with temporary name.

All messages are sent to STDOUT so pipe it to file you want to store them.

During processing the programm uses STDERR to show status.

If you cake about message order do `tac dump.json > good_dump.json`. Because they stored in way for queue returned them.

Loading process expects messages from STDIN. Use examples below.

Since it stores messages in json lines format. Use `wc -l dump.json` to get how many messages in the file.

### Build and Install

`make && make install`


### Usage

`rmq-dump command <arguments>`

Commands:

- dump - dumps messages from RMQ to STDOUT
- load - loads messages from STDIN to RMQ
- dryload - Dry run load will show how -v -s -a optionas will affest messages
- list - shows current state in RMQ with -v -s filters. Use as dry run for load
- help - prints help


Make dump all messages of all vhosts:

`rmq-dump dump -H host -u user -p password > dump.json`

Make dump all messages of vhost /app/live:

`rmq-dump dump -H host -u user -p password -v /app/live > dump.json`

Load dump:

`cat dump.json | rmq-dump load -H host -u user -p password`

Load dump to vhost /app/test:

`cat dump.json | rmq-dump load -H host -u user -p password -a /app/test`

Load dump from big dump only specific vhost /app/prod to vhost /app/test:

`cat dump.json | rmq-dump load -H host -u user -p password -v /app/prod -a /app/test`

`-a or --alter` - a middleware that replaces vhost or/and queue value in a message. Can be used at dump or load process. 

List all what you have in RMQ:

`rmq-dump -H host -u user -p password list`

Print help:

`rmq-dump help`


**Options**

```
Arguments:
  Full name    | Short | Default            | Note
-------------------------------------------------------
  --host         -H      localhost            
  --port         -P      15672                
  --binary-port  -B      5672                 
  --user         -u                           
  --pass         -p                           
  --vhost        -v                           vhost[:queue] | :queue
  --skip         -s                           vhost[:queue] | :queue
  --alter        -a                           from_vhost[:queue]~to_vhost[:queue] | vhost[:queue]

```

`--vhost` `--skip` `--alter` can be used miltiple times


