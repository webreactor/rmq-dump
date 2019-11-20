RabbitMQ messages backup tool
=============================

Can dump messages to json file, load them back. Can modify destination vhost or queue name.

**It dumps and loads only messages**

To backup exchanges, queues, bindings use RMQ webinterface.

### Features

* Dumps messages
* Does not consume messages, server state is not changed after dumping
* Loads messages to RabbitMQ
* Can filter load and dump lists of vhosts or queus
* Can alter message changing result vhost or queue name
* Uses json lines format
* Works with STDOUT and STDIN streams
* Has dry run option

### How it works

**Dumping**

rmq-dump runs throught all specified vhosts and queues (all, if not specified), using built-in rabbitmqadmin retrieves list of vhosts and queues.
Using basic_get rmq-dump gets all messages qithout acknowledging then closes connection so all messages go back to their queues.
Tested up to 500k messages in a queue. rmq-dump sends all recieved messages in STDOUT with json lines format. Pipe it to a file if you want to store them.

It you need consistent state backup - stop all consumers and publishers before you make backup.

Each stored message contains:

- vhost
- queue
- body
- properties
- headers

During processing the programm uses STDERR to show status.

If you care about message order do `tac dump.json > good_dump.json`. Because they stored in way for queue returned them.

Since it stores messages in json lines format. Use `wc -l dump.json` to get how many messages in a dump file.

**Loading**

Loading process expects messages from STDIN. Use examples below.

When you load them back it creates temporary exchange in order to put message to destination queue qith original routing key.
That means in properties of loaded message original exchange will be replaced with temporary name.

Loading is a good place to apply `--alter` filters that can modify destination vhost or queue name.

Only specified source vhost, queue name can be loaded using `-vhost` from a big dump.

rmq-dump can be piped to another rmq-dump that allows copy messages qithout storing them.

### Build and Install

`make && make install`

or use binary

```bash 
curl -L https://github.com/webreactor/rmq-dump/releases/download/0.0.4/rmq-dump > /usr/local/bin/rmq-dump
chmod a+x /usr/local/bin/rmq-dump
```

### Usage

`rmq-dump command <arguments>`

Commands:

- `dump`: dumps messages from RMQ to STDOUT
- `load`: loads messages from STDIN to RMQ
- `dryload`: dry run load will show how `-v`, `-s` and `-a` options will affect messages
- `list`: shows current state in RabbitMQ with -v -s filters. Use as dry run for dump
- `help`: prints help


Make dump all messages of all vhosts:

`rmq-dump dump -H host -u user -p password > dump.json`

Make dump all messages of vhost /app/live:

`rmq-dump dump -H host -u user -p password -v /app/live > dump.json`

Load dump:

`cat dump.json | rmq-dump load -H host -u user -p password`

Load dump to vhost /app/test and create queues if needed:

`cat dump.json | rmq-dump load -H host -u user -p password -d -a /app/test`

Load dump from big dump only specific vhost /app/prod to vhost /app/test:

`cat dump.json | rmq-dump load -H host -u user -p password -v /app/prod -a /app/test`

`-a or --alter` - is a middleware that replaces vhost or/and queue value in a message. Can be used at dump or load process.

Copy all messages from one queue1 to queue2 qithout storing them:

`rmq-dump -u user -p pass -v /app/live:queue1 dump | ./rmq-dump -u user -p pass -a :queue2 load`

Copy all messages from vhost1 to vhost2 not storing them. Note: all queues have exists at vhost2:

`rmq-dump -u user -p pass -v /vhost1 dump | ./rmq-dump -u user -p pass -a /vhost2 load`

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


