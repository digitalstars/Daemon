<?php


namespace DigitalStars\Daemon;


class Client {
    private $resource = null;
    private $error_func = null;

    public function __construct($id_resource) {
        $this->resource = msg_get_queue($id_resource);
    }

    public static function create($id_resource) {
        return new self($id_resource);
    }

    public function errorHandler($func) {
        $this->error_func = $func;
    }

    public function send($module, $msg) {
        if (!is_array($msg))
            $msg = [$msg];
        if (!msg_send($this->resource, 1, [$module, $msg], true, true, $error)) {
            if (is_callable($this->error_func))
                call_user_func($this->error_func, $error);
            else
                throw new \Exception($error);
        }
        return $this;
    }

    public function __call($name, $arguments) {
        return $this->send($name, $arguments);
    }
}