<?php

namespace RMQDumper;

class CallbackStream implements \Iterator {

    public
        $current = null,
        $key = 0;

    public function __construct($getter) {
        $this->getter = $getter;
    }

    public function current() {
        return $this->current;
    }

    public function key() {
        return $this->key;
    }

    public function next() {
        $this->current = call_user_func($this->getter);
        $this->key++;
    }

    public function rewind() {
        $this->next();
        $this->key = 0;
    }

    public function valid() {
        return !empty($this->current);
    }

}