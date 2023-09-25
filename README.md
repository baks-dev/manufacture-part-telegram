# BaksDev Manufacture Part Telegram

![Version](https://img.shields.io/badge/version-6.3.4-blue) ![php 8.1+](https://img.shields.io/badge/php-min%208.1-red.svg)

Модуль производства партий продукции с помощью Telegram-бота

## Установка

``` bash
$ composer require baks-dev/manufacture-part-telegram
```

## Дополнительно

Изменения в схеме базы данных с помощью миграции

``` bash
$ php bin/console doctrine:migrations:diff

$ php bin/console doctrine:migrations:migrate
```

Установка файловых ресурсов в публичную директорию (javascript, css, image ...):

``` bash
$ php bin/console baks:assets:install
```

Тесты

``` bash
$ php bin/phpunit --group=manufacture-part-telegram
```

## Лицензия ![License](https://img.shields.io/badge/MIT-green)

The MIT License (MIT). Обратитесь к [Файлу лицензии](LICENSE.md) за дополнительной информацией.
