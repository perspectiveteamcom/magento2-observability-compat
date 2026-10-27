# Perspective_ObservabilityCompat

Серверна інструментація через Magento plugins та подію `cron_job_run`. Працює на PHP 7.4-8.5 без нативного розширення OpenTelemetry й без патчів Magento. Збирає HTTP, SQL через Magento-адаптер, шаблони, cron і черги; метрики запитів та очищені копії Monolog. Прямі виклики PDO/cURL поза Magento-адаптерами не інструментує.

Базовий [Perspective_Observability](https://github.com/perspectiveteamcom/magento2-observability) постачає спільний CPU-профайлер і встановлюється автоматично. Для сучасного PHP з ext-opentelemetry є окремий [нативний адаптер](https://github.com/perspectiveteamcom/magento2-observability-otel). Два серверні адаптери разом заборонені Composer-обмеженням `conflict`.

## Встановлення

Звичайний шлях для проєкту: [автоматичний вибір через Otel ^1.0 || ^2.0](https://github.com/perspectiveteamcom/magento2-observability-otel#встановлення). На PHP 7.4-8.2 він установлює цей модуль. Наведені нижче команди потрібні, якщо ви свідомо обираєте Compat, зокрема на новому PHP без ext-opentelemetry.

Команди виконуйте в корені Magento під тим самим PHP, що й застосунок. У Warden запускайте їх через `warden env exec -T php-fpm`; у Docker/Kubernetes використовуйте PHP-контейнер збірки. Не змінюйте файли робочого `vendor` вручну. Для команди зі змінною оточення синтаксис Warden такий: `warden env exec -T php-fpm env OTEL_PHP_AUTOLOAD_ENABLED=false composer require ...`.

Composer читає VCS-репозиторії лише з кореневого `composer.json`. Додайте їх командами нижче; вони зберігають решту репозиторіїв магазину.

```bash
composer config repositories.perspective-observability vcs https://github.com/perspectiveteamcom/magento2-observability.git
composer config repositories.perspective-observability-otel vcs https://github.com/perspectiveteamcom/magento2-observability-otel.git
composer config repositories.perspective-observability-compat vcs https://github.com/perspectiveteamcom/magento2-observability-compat.git
```
Перевірте `ext-curl` у FPM та CLI. Для профілів додайте Excimer. SDK, Zipkin exporter і HTTP-клієнт установлює Composer; Guzzle 7 не є вимогою цього модуля.

```bash
OTEL_PHP_AUTOLOAD_ENABLED=false composer require 'perspective/module-observability-compat:^1.0' --with-dependencies
composer check-platform-reqs
php bin/magento module:enable Perspective_Observability Perspective_ObservabilityCompat
php bin/magento setup:upgrade
```

Збережіть `composer.json` і `composer.lock` у репозиторії магазину. Наступні збірки виконують `composer install` із lock-файла. `composer check-platform-reqs` перевіряє фактичний PHP та розширення; `config.platform.php` впливає на вибір залежностей, але не встановлює PHP. Не використовуйте `--ignore-platform-reqs` для розгортання.

Пакети встановлюються в `vendor/perspective/`. Перед переходом із `app/code` приберіть старі каталоги `Perspective/Observability*` у новій збірці, зберігши проєктні правки окремо. Дві копії з однаковою Magento-реєстрацією призведуть до помилки. Файли робочого релізу замінюйте штатним deployment.

Підключіть оточення за [кроками для FPM, cron і consumers](docs/php-environment.md). Виконайте звичайний deployment з DI та перезапуском PHP-процесів. Для Compat потрібні `OBSERVABILITY_COMPAT_ENABLED=true`, `OTEL_PHP_AUTOLOAD_ENABLED=false`, локальний Zipkin на 9411 і OTLP HTTP на 4318. На PHP 7.4-8.2 комплект агентів уже містить ці значення. Якщо обираєте Compat на 8.3+, адаптуйте конфігурацію агентів під ці протоколи; окремий сервер Zipkin не потрібен.

## Налаштування

| Змінна | Призначення |
| --- | --- |
| `OBSERVABILITY_COMPAT_ENABLED=true` | Вмикає цей адаптер |
| `OTEL_PHP_AUTOLOAD_ENABLED=false` | Залишає автоматичну інструментацію сторонніх пакетів вимкненою |
| `OTEL_SERVICE_NAME`, `OTEL_RESOURCE_ATTRIBUTES` | Назва сервісу, проєкт, середовище й унікальний вузол |
| `OTEL_EXPORTER_ZIPKIN_ENDPOINT` | Локальний Collector, шлях `/api/v2/spans` на порту 9411 |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | Локальний Collector на порту 4318 для метрик і журналів |
| `OTEL_TRACES_SAMPLER_ARG=0.1` | Частка нових трас; за наявності батьківської траси зберігається її рішення |
| `OTEL_TRACES_EXPORTER`, `OTEL_METRICS_EXPORTER`, `OTEL_LOGS_EXPORTER` | Значення `none` вимикає відповідний канал |
| `OBSERVABILITY_PROFILING_ENABLED`, `OBSERVABILITY_PROFILING_RATIO`, `OBSERVABILITY_PROFILING_ENDPOINT` | Увімкнення, частка вебзапитів та локальний шлюз профілів |
| `OTEL_SDK_DISABLED=true` | Вимикає новий збір; перезапустіть PHP-процеси після зміни |

Група cron `observability` виконує один `SELECT` агрегованих станів `cron_schedule` за добу та записує heartbeat. Якщо штатний планувальник запускає тільки перелічені групи, додайте цю групу до його розкладу. Не створюйте дубль запуску.

## Межі втручання

| Точка Magento | Що спостерігається |
| --- | --- |
| `Http::launch`, `FrontControllerInterface::dispatch`, `ResponseInterface::sendResponse` | Маршрут, тривалість і остаточний статус відповіді, зокрема REST |
| `DB\Adapter\Pdo\Mysql::query` | Тривалість SQL без тексту запиту та параметрів |
| `HTTP\Client\Curl::get/post`, `View\Element\Template::fetchView` | Тривалість HTTP та рендерингу без URL, аргументів або вмісту |
| `Amqp\Exchange::enqueue` | Публікація повідомлення |
| `MessageController::lock`, `QueueInterface::acknowledge/reject` | Обробка між успішним блокуванням і підтвердженням або відхиленням |
| `cron_job_run`, `Cron\Model\Schedule::save` | Запуск завдання й збереження його кінцевого стану |
| `ConsumerInterface::process`, `ProcessCronQueueObserver::execute` | Завершення залишених spans при виході з обробника |
| `Framework\Logger\Monolog::addRecord` | Копія тексту журналу; довільний context не експортується |

Plugins викликають бізнесовий метод один раз із початковими аргументами, повертають його результат і повторно кидають той самий виняток. Вони не змінюють відповідь, повідомлення, callbacks, SQL чи конфігурацію магазину. Heartbeat має звичайний запис у розкладі Magento; власних таблиць і запису бізнес-даних немає.

Контекст вхідного HTTP-запиту або повідомлення читається, якщо він уже є. Модуль не додає заголовків до вихідних запитів і повідомлень, тому producer та consumer можуть мати окремі траси. Нестандартний consumer, який обходить наведені точки, потребує проєктної інструментації. Для маршруту без `getFullActionName()` метрика використовує `unresolved`.

## Розширення й обмеження

Власну бізнес-операцію додавайте у модулі магазину через окремий Magento plugin. Для виклику без аргументів можна наслідувати `Plugin\AbstractPlugin` цього адаптера й використати захищений `invoke('erp.sync', Telemetry::INTERNAL, [], $proceed, [])`. Для методу з аргументами передайте їх без зміни. SDK provider належить цьому адаптеру й не реєструється глобально; для узгодженого parent context використовуйте його `Model\Telemetry`.

Назви spans і міток мають бути обмеженим набором. Не додавайте до них замовлення, SKU, email чи довільні URL. Проєкт може вимкнути окремий plugin за його іменем із `etc/di.xml`, використавши `disabled="true"` у власному DI-файлі.

PHP буферизує до 512 spans, 256 серій метрик і 64 записів журналу. Експорт виконується після відповіді, завершення завдання/повідомлення або процесу; publisher не виконує синхронного flush. Одна HTTP-спроба обмежена 250 мс, після помилки транспорт робить паузу на 5 секунд. Пакет має межу 512 KiB. При переповненні або недоступному Collector дані можуть втрачатися; повторну доставку вже прийнятих даних забезпечують черги Collector.

Маскування тексту журналу не охоплює довільні персональні дані. Файловий збір і Monolog можуть дублювати записи. Перевірте потрібні джерела та правила очищення у своєму проєкті.

Синтаксис, ізоляцію винятків та окремий SDK-потік перевірено на PHP 7.4 і 8.1-8.5 без запуску Magento. Це не підтверджує роботу кожного поєднання Magento, PHP і сторонніх модулів. Перед розгортанням виконайте перевірки магазину з недоступним Collector, REST, cron і чергами.

## Вимкнення

Задайте `OBSERVABILITY_COMPAT_ENABLED=false`, `OTEL_SDK_DISABLED=true`, `OBSERVABILITY_PROFILING_ENABLED=false` і перезапустіть PHP-процеси. Потім, якщо потрібне видалення, вимкніть `Perspective_ObservabilityCompat`, видаліть пакет через `composer remove` у наступній збірці й лише його окремий запуск cron. Composer-залежності видаляйте після перевірки інших модулів, які можуть їх використовувати.
