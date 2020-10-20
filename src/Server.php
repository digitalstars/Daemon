<?php


namespace DigitalStars\Daemon;

require_once __DIR__ . "/config_daemon.php";

class Server {
    private $is_multi_thread = IS_MULTI_THREAD;
    private $max_threads = MAX_THREADS;
    private $stop_server = false;
    private $currentJobs = [];
    private $modules = [];
    private $resource = null;
    private $error_func = null;
    private $max_size = MAX_SIZE;
    private $is_run = false;
    private $command = null;
    private $pid_file = '';
    private $pid = 0;
    private $init_func = null;
    private $id_resource = 0;
    private $is_set_error_handler = IS_SET_ERROR_HANDLER;
    private $is_log = IS_LOG;

    public function __construct($id_resource) {
        global $argv;
        $this->id_resource = $id_resource;
        $this->command = $argv[1] ?? '';
        $this->resource = msg_get_queue($id_resource);
        $this->pid_file = sys_get_temp_dir() . "/" . PREFIX . $id_resource . ".pid";
        $this->pid = getmypid();
        pcntl_async_signals(true); // Асинхронная обработка сигналов

        pcntl_signal(SIGTERM, array($this, "childSignalHandler"));
        pcntl_signal(SIGCHLD, array($this, "childSignalHandler"));
        pcntl_signal(SIGINT, array($this, "childSignalHandler"));
        pcntl_signal(SIGUSR1, array($this, "childSignalHandler"));

        if ($this->is_set_error_handler) {
            $error_handler = function ($errno, $errstr, $errfile, $errline) {
                // если ошибка попадает в отчет (при использовании оператора "@" error_reporting() вернет 0)
                if (error_reporting() & $errno) {
                    $errors = [
                        E_ERROR => 'E_ERROR',
                        E_WARNING => 'E_WARNING',
                        E_PARSE => 'E_PARSE',
                        E_NOTICE => 'E_NOTICE',
                        E_CORE_ERROR => 'E_CORE_ERROR',
                        E_CORE_WARNING => 'E_CORE_WARNING',
                        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
                        E_COMPILE_WARNING => 'E_COMPILE_WARNING',
                        E_USER_ERROR => 'E_USER_ERROR',
                        E_USER_WARNING => 'E_USER_WARNING',
                        E_USER_NOTICE => 'E_USER_NOTICE',
                        E_STRICT => 'E_STRICT',
                        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
                        E_DEPRECATED => 'E_DEPRECATED',
                        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
                    ];
                    if (is_callable($this->error_func))
                        $this->processingJob($this->error_func, [$errors[$errno], $errstr, $errfile, $errline, null]);
                    else
                        return false;
                }
                return TRUE; // не запускаем внутренний обработчик ошибок PHP
            };

            ini_set('error_reporting', E_ALL);
            ini_set('display_errors', 1);
            ini_set('display_startup_errors', 1);

            set_error_handler($error_handler);
        }
    }

    public function errorHandler($func) {
        $this->error_func = $func;
    }

    public function isLog($is = true) {
        $this->is_log = $is;
        return $this;
    }

    public function init($func) {
        $this->init_func = $func;
    }

    public function isMultiThread($bool = true) {
        if (!is_callable('pcntl_fork'))
            $this->error("Многопоточная обработка не поддерживается (модуль pcntl не найден)");
        $this->is_multi_thread = $bool == true;
        return $this;
    }

    private function maxSize($size) {
        $this->max_size = $size;
        return $this;
    }

    public function maxThreads($count = 0) {
        $this->max_threads = (int) $count;
        return $this;
    }

    public static function create($id_resource) {
        return new self($id_resource);
    }

    public function __destruct() {
        if ($this->command == 'start')
            $this->run(true);
        else if ($this->command == 'stop')
            $this->stop(true);
        else if ($this->command == 'kill')
            $this->kill(true);
        else if ($this->command == 'status') {
            if ($this->isActive())
                echo "Демон запущен".PHP_EOL;
            else
                echo "Демон НЕ запущен".PHP_EOL;
        } else if ($this->command == 'restart') {
            $this->stop(true)->run(true);
        } else if ($this->command == 'clear')
            $this->clear(true);
        else
            echo "Используйте: php ./script.php [start|stop|kill|status|restart|clear]" . PHP_EOL;
    }

    public function module($id, $anon) {
        $this->modules[$id] = $anon;
        return $this;
    }

    public function clear($echo = false) {
        if (msg_remove_queue($this->resource)) {
            if ($echo)
                echo "Очередь сообщений очищена!" . PHP_EOL;
            return true;
        } else {
            if ($echo)
                echo "Очередь сообщений очистить не вышло" . PHP_EOL;
            return false;
        }
    }

    public function stop($echo = false) {
        $pid = $this->isActive();
        if (!$pid) {
            if ($echo)
                echo "Демон и так остановлен" . PHP_EOL;
            return $this;
        }
        posix_kill($pid, SIGTERM);
        while ($this->isActive())
            sleep(1);
        if ($echo)
            echo "Остановлено!".PHP_EOL;
        return $this;
    }

    public function kill($echo = false) {
        $pid = $this->isActive();
        if (!$pid) {
            if ($echo)
                echo "Демон и так остановлен" . PHP_EOL;
            return $this;
        }
        posix_kill($pid, SIGUSR1);
        while ($this->isActive())
            sleep(1);
        if ($echo)
            echo "Остановлено!".PHP_EOL;
        return $this;
    }

    public function isActive() {
        return is_file($this->pid_file) ? (@file_get_contents($this->pid_file) ?? false) : false;
    }

    public function run($echo = false) {
        if ($this->is_run || ($this->command != 'start' && $this->command != 'restart'))
            return false;
        if ($this->isActive()) {
            if ($echo)
                echo "Демон уже запущен!".PHP_EOL;
            return false;
        }
        if ($echo)
            echo "Демон запускается...".PHP_EOL;
        $this->is_run = true;
        $this->closeConsole();
        file_put_contents($this->pid_file, $this->pid);
        if (is_callable($this->init_func)) {
            try {
                call_user_func($this->init_func);
            } catch (\Throwable $e) {
                if ($echo)
                    echo "Не удалось инициализировать демона!".PHP_EOL;
                unlink($this->pid_file);
                throw new \Exception($e);
            }
        }
        while (!$this->stop_server) {
            if (!msg_receive($this->resource, 0, $type, $this->max_size, $message, true, 0, $error)) {
                if ($error == 4)
                    continue;
                else if ($error == 43 || $error == 22) {
                    $this->resource = msg_get_queue($this->id_resource);
                    continue;
                }
                $this->error($error);
            }
            $this->wait();
            if (isset($this->modules[$message[0]]))
                $this->processingJob($this->modules[$message[0]], $message[1]);
        }
        $this->wait(true);
        unlink($this->pid_file);
        return true;
    }

    private function error($msg) {
        if (is_callable($this->error_func)) {
            $this->wait();
            if (!is_array($msg))
                $msg = ["ERROR_DAEMON", $msg, null, null, null];
            $this->processingJob($this->error_func, $msg, true);
        } else {
            if ($this->pid == getmypid())
                unlink($this->pid_file);
            throw new \Exception($msg);
        }
        return 1;
    }

    private function wait($wait_all = false) {
        $wait_count = $wait_all ? 1 : $this->max_threads;
        while(count($this->currentJobs) >= $wait_count) {
            sleep(1);
        }
    }

    private function processingJob($anon, $args, $is_error = false) {
        if (!$this->is_multi_thread) {
            try {
                return call_user_func_array($anon, $args);
            } catch (\Throwable $e) {
                if ($is_error)
                    throw new \Exception($e);
                else
                    return $this->error(["ERROR_MODULE", $e->getMessage(), $e->getFile(), $e->getLine(), $e]);
            }
        }
        $pid = pcntl_fork();
        if ($pid == -1) {
            $this->error("Не удалось создать дочерний процесс!");
        } else if ($pid) {
            $this->currentJobs[$pid] = TRUE;
        } else {
            try {
                call_user_func_array($anon, $args);
            } catch (\Throwable $e) {
                if ($is_error)
                    throw new \Exception($e);
                else
                    $this->error(["ERROR_MODULE", $e->getMessage(), $e->getFile(), $e->getLine(), $e]);
            }
            exit();
        }
        return false;
    }

    private function closeConsole() {
        global $STDIN, $STDOUT, $STDERR;
        if (!$this->is_multi_thread)
            return;
        if (!is_callable('pcntl_fork'))
            $this->error("Многопоточная обработка не поддерживается (модуль pcntl не найден)");
        $child_pid = pcntl_fork();
        if ($child_pid) {
            exit();
        }
        posix_setsid();

        $path_log = dirname(current(get_included_files())) . "/daemon_logs";
        if (!is_dir($path_log))
            if (!mkdir($path_log))
                $this->error("Не удалось создать папку логов");

        ini_set('error_log',$path_log.'/error.log');
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        $STDIN = fopen('/dev/null', 'r');
        if ($this->is_log) {
            $STDOUT = fopen($path_log . '/application.log', 'ab');
            $STDERR = fopen($path_log . '/daemon.log', 'ab');
        } else {
            $STDOUT = fopen('/dev/null', 'ab');
            $STDERR = fopen('/dev/null', 'ab');
        }
        $this->pid = getmypid();
    }

    public function childSignalHandler($signo, $pid = null, $status = null) {
        switch($signo) {
            case SIGINT:
            case SIGTERM:
                // При получении сигнала завершения работы устанавливаем флаг
                if (!$this->is_run)
                    exit(0);
                $this->stop_server = true;
                break;
            case SIGCHLD:
                // При получении сигнала от дочернего процесса
                if (!$pid) {
                    $pid = pcntl_waitpid(-1, $status, WNOHANG);
                }
                if (isset($pid['pid']))
                    $pid = $pid['pid'];
                // Пока есть завершенные дочерние процессы
                while ($pid > 0) {
                    if ($pid && isset($this->currentJobs[$pid])) {
                        // Удаляем дочерние процессы из списка
                        unset($this->currentJobs[$pid]);
                    }
                    $pid = pcntl_waitpid(-1, $status, WNOHANG);
                }
                break;
            case SIGUSR1:
                foreach ($this->currentJobs as $job => $status) {
                    posix_kill($job, SIGKILL);
                }
                unlink($this->pid_file);
                exit();
            default:
                // все остальные сигналы
        }
    }
}