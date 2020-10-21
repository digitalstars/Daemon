<?php


namespace DigitalStars\Daemon;


class Client {
    private $resource = null;
    private $error_func = null;
    private $is_init = false;
    private $id_resource = null;

    public function __construct($id_resource) {
        $this->id_resource = $id_resource;
    }

    public static function create($id_resource) {
        return new self($id_resource);
    }

    public function errorHandler($func) {
        $this->error_func = $func;
        return $this;
    }

    private function error($error) {
        if (is_callable($this->error_func))
            call_user_func($this->error_func, $error);
        else
            throw new \Exception($error);
    }

    public function send($module, $msg) {
        if (!$this->is_init) {
            try {
                $this->resource = msg_get_queue($this->id_resource);
            } catch (\Throwable $e) {
                $this->error($e);
                return $this;
            }
            $this->is_init = true;
        }
        if (!is_array($msg))
            $msg = [$msg];
        if (!msg_send($this->resource, 1, [$module, $msg], true, true, $error))
            $this->error($error);
        return $this;
    }

    public function __call($name, $arguments) {
        return $this->send($name, $arguments);
    }
}