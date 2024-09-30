# BaksDev Avito Orders

[![Version](https://img.shields.io/badge/version-7.1.0-blue)](https://github.com/baks-dev/avito-orders/releases)
![php 8.3+](https://img.shields.io/badge/php-min%208.3-red.svg)

Модуль заказов Avito

## Установка

``` bash
$ composer require baks-dev/avito-orders
```

Для работы с заказами выполнить комманду для добавления типа профиля и доставку:

* #### FBS (доставка на склад Avito)

``` bash
php bin/console baks:users-profile-type:avito-fbs
php bin/console baks:payment:avito-fbs
php bin/console baks:delivery:avito-fbs
```

* #### DBS (доставка клиенту Avito)

``` bash
php bin/console baks:users-profile-type:avito-dbs
php bin/console baks:payment:avito-dbs
php bin/console baks:delivery:avito-dbs
```

Тесты

``` bash
$ php bin/phpunit --group=avito-orders
```

## Лицензия ![License](https://img.shields.io/badge/MIT-green)

The MIT License (MIT). Обратитесь к [Файлу лицензии](LICENSE.md) за дополнительной информацией.
