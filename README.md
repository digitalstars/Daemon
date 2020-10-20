# SimpleDaemon
# Подключение

### Используя composer
1\. Установить
```
composer require digitalstars/daemon
```
2\. Подключить `autoload.php`
```php
require_once "vendor/autoload.php";
```
### Вручную
1. Скачать последний релиз c [github](https://github.com/digitalstars/Daemon)
2. Подключить `autoload.php`.  
> Вот так будет происходить подключение, если ваш скрипт находится в той же папке, что и папка `daemon-master`
```php
require_once "daemon-master/autoload.php";
```
