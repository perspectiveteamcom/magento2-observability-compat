# Як підключити php.env до PHP-процесів

Цей крок потрібен, якщо у комплекті вибрано `otel`. Спочатку встановіть серверний Magento-модуль і запустіть агентів. Нижче `shop-stage` є назвою проєкту, а `/opt/observability/shop-stage` є його `agent_root`. Замініть ці два значення на свої в усіх командах.

`php.env` містить змінні на кшталт `OTEL_SERVICE_NAME` та адреси локального Collector. PHP має отримати їх у своє оточення під час запуску. Копіювання файла поряд із Magento цього не робить. Не додавайте його вміст до `php.ini`.

| Файл комплекту | Для чого він потрібен |
| --- | --- |
| `php.env` | Значення змінних для PHP. Його читає systemd, Docker Compose або native-обгортка |
| `php-fpm.conf` | Рядки `env[...]` для вибраного FPM-пулу; дозволяють передати змінні робочим процесам |
| `php-fpm.service.override.conf` | Лише native: systemd drop-in з `EnvironmentFile=/opt/observability/shop-stage/php.env` |
| `run-with-telemetry` | Native-обгортка: читає `php.env`, експортує змінні й запускає команду, передану після неї |
| `php-extensions.ini` | Окремий список PHP-розширень. Це не файл змінних оточення |

Виконайте лише розділ свого способу запуску. Вебзапити, cron і consumer можуть працювати в різних процесах; налаштування FPM не поширюється на CLI.

## Linux/systemd

### 1. Визначте службу й пул FPM

Команди виконуйте на сервері магазину. Подивіться назву служби:

```bash
systemctl list-unit-files --type=service 'php*fpm*'
```

Далі наведено приклад для Debian/Ubuntu з PHP 8.3:

| Параметр | Значення у прикладі | Де перевірити своє |
| --- | --- | --- |
| Служба FPM | `php8.3-fpm.service` | Результат попередньої команди |
| FPM-бінарник | `/usr/sbin/php-fpm8.3` | `systemctl cat php8.3-fpm.service`, рядок `ExecStart` |
| Каталог пулів | `/etc/php/8.3/fpm/pool.d` | Параметр `include` у головному FPM-конфігу з `ExecStart` |
| Пул цього магазину | `www` | Заголовок `[www]` у його файлі пулу |
| CLI PHP | `/usr/bin/php8.3` | Чинна команда cron/consumer; перевірте її через `-v` та `--ini` |
| Користувач Magento | `shop` | Власник чинного crontab або `User` служби consumer |

Якщо назва пулу інша, відкрийте `/opt/observability/shop-stage/php-fpm.conf` і замініть тільки заголовок `[www]` на неї. Наприклад, для пулу `[store]` потрібен заголовок `[store]`. Не створюйте новий пул замість наявного. Для кількох магазинів в одній службі FPM використовуйте окремі служби або окремі значення `env[...]` для кожного пулу: один `EnvironmentFile` служби задає одну проєктну ідентичність.

### 2. Підключіть оточення до FPM

У прикладі зі службою `php8.3-fpm` та пулом `www`:

```bash
cd /opt/observability/shop-stage
sudo install -d /etc/systemd/system/php8.3-fpm.service.d
sudo install -m 0644 php-fpm.service.override.conf \
  /etc/systemd/system/php8.3-fpm.service.d/observability.conf
sudo install -m 0644 php-fpm.conf \
  /etc/php/8.3/fpm/pool.d/zz-observability.conf
sudo /opt/observability/shop-stage/run-with-telemetry /usr/sbin/php-fpm8.3 -t
```

Після успішної перевірки конфігурації:

```bash
sudo systemctl daemon-reload
sudo systemctl restart php8.3-fpm.service
systemctl is-active php8.3-fpm.service
systemctl show php8.3-fpm.service --property=EnvironmentFiles
```

Очікується `active` і шлях до `/opt/observability/shop-stage/php.env`. Drop-in передає змінні головному процесу FPM, а файл пулу передає їх workers. [FPM за замовчуванням очищає оточення workers](https://www.php.net/manual/en/install.fpm.configuration.php), тому потрібні обидва файли.

### 3. Перевірте обгортку CLI

Виконайте від користувача Magento:

```bash
sudo -u shop /opt/observability/shop-stage/run-with-telemetry /usr/bin/php8.3 -r \
  'echo getenv("OTEL_SERVICE_NAME"), PHP_EOL, getenv("OTEL_EXPORTER_OTLP_ENDPOINT"), PHP_EOL;'
```

Очікується `shop-stage-magento` та `http://127.0.0.1:4318`. Ця команда перевіряє CLI-оточення; роботу FPM далі перевіряють вебзапитом і його даними у Grafana.

### 4. Змініть чинну команду cron

Відкрийте crontab того користувача, від якого вже працює Magento:

```bash
sudo crontab -u shop -e
```

Збережіть розклад, аргументи, перенаправлення журналу та блокування вашого завдання. Додайте обгортку безпосередньо перед PHP. Наприклад, чинний рядок:

```cron
* * * * * /usr/bin/php8.3 /srv/shop/current/bin/magento cron:run >> /srv/shop/current/var/log/cron.log 2>&1
```

має стати:

```cron
* * * * * /opt/observability/shop-stage/run-with-telemetry /usr/bin/php8.3 /srv/shop/current/bin/magento cron:run >> /srv/shop/current/var/log/cron.log 2>&1
```

Замініть наявний рядок, не додавайте його копію. Якщо crontab генерує ваш deployment, внесіть цю саму зміну до його шаблона. Нове оточення отримає наступний запуск; перезапуск служби cron не потрібен. Те саме правило застосуйте до інших PHP-команд Magento у цьому crontab.

### 5. Підключіть consumer

Якщо consumer працює через systemd, візьміть його чинну назву з `systemctl list-units --type=service`. Для прикладу `shop-consumer.service`:

```bash
sudo install -d /etc/systemd/system/shop-consumer.service.d
sudo install -m 0644 /opt/observability/shop-stage/php-fpm.service.override.conf \
  /etc/systemd/system/shop-consumer.service.d/observability.conf
sudo systemctl daemon-reload
sudo systemctl restart shop-consumer.service
systemctl is-active shop-consumer.service
```

Цей drop-in містить тільки `EnvironmentFile`, тому підходить і для CLI-служби. Він зберігає її користувача, команду, аргументи й правила перезапуску.

Якщо consumer запускає Supervisor, змініть `command` у його чинному `[program:...]`. Приклад:

```ini
[program:shop-consumer]
command=/opt/observability/shop-stage/run-with-telemetry /usr/bin/php8.3 /srv/shop/current/bin/magento queue:consumers:start async.operations.all
```

Залиште свої назву consumer, аргументи та решту параметрів програми. Застосуйте зміну тільки цієї програми:

```bash
sudo supervisorctl reread
sudo supervisorctl update shop-consumer
```

Якщо надалі зміниться лише вміст `php.env`, виконайте `sudo supervisorctl restart 'shop-consumer:*'`. Для systemd після такої зміни потрібен `restart` FPM і кожної CLI-служби, яка читає цей файл.

## Docker Compose

Команди цього розділу виконуйте на сервері магазину. У прикладі застосунок має `/srv/shop/current/compose.yaml` зі службами `php-fpm`, `cron` і `queue`; агенти встановлені окремо в `/opt/observability/shop-stage`.

### 1. Підготуйте fragment застосунку

З каталогу застосунку подивіться назви служб:

```bash
cd /srv/shop/current
docker compose config --services
```

Відкрийте `/opt/observability/shop-stage/docker.fragment.yml`. У ньому вже є `env_file` для `php-fpm` і монтування конфігурації пулу. Якщо FPM-служба має іншу назву, замініть ключ `services.php-fpm` на неї. У `php-fpm.conf` замініть `[www]`, якщо ваш пул має іншу назву.

У наявному `.env` застосунку задайте каталог пулів усередині PHP-контейнера. Для офіційного образу `php:*-fpm` це:

```dotenv
PHP_FPM_CONFIG_DIR=/usr/local/etc/php-fpm.d
```

Для образу з іншим шляхом візьміть каталог із `include` його `php-fpm.conf`, наприклад `/etc/php-fpm.d`. Значення стосується контейнера, а не хоста.

Якщо cron і consumer працюють в окремих службах, додайте до того самого `services` у fragment:

```yaml
  cron:
    env_file:
      - /opt/observability/shop-stage/php.env
  queue:
    env_file:
      - /opt/observability/shop-stage/php.env
```

Назви `cron` і `queue` мають збігатися з результатом `config --services`; відсутні служби не додавайте. Якщо ці команди запускаються через `docker compose exec` у `php-fpm`, вони вже успадкують оточення цього контейнера. У служб не повинно бути старих `OTEL_*` або `OBSERVABILITY_*` у секції `environment`, які перекривають потрібні значення з `env_file`.

### 2. Перевірте й застосуйте конфігурацію

З каталогу застосунку, для прикладу з трьома службами:

```bash
docker compose -f compose.yaml -f /opt/observability/shop-stage/docker.fragment.yml config --quiet
docker compose -f compose.yaml -f /opt/observability/shop-stage/docker.fragment.yml \
  up -d --no-deps --force-recreate php-fpm cron queue
```

Якщо ваш застосунок уже використовує кілька Compose-файлів, залиште всі його чинні `-f` і додайте fragment останнім. Використовуйте цей самий список у наступних командах розгортання. Звичайний `docker compose restart` [не підхоплює змінні нового оточення](https://docs.docker.com/reference/cli/docker/compose/restart/).

Перевірте значення у кожній PHP-службі, замінюючи `php-fpm` її назвою:

```bash
docker compose -f compose.yaml -f /opt/observability/shop-stage/docker.fragment.yml \
  exec -T php-fpm php -r \
  'echo getenv("OTEL_SERVICE_NAME"), PHP_EOL, getenv("OTEL_EXPORTER_OTLP_ENDPOINT"), PHP_EOL;'
```

Очікується `shop-stage-magento` та `http://observability-collector:4318`. Ця перевірка читає оточення контейнера; файл пулу потрібен окремо для FPM workers.

## Warden

Виконуйте команди з каталогу магазину, де лежать `.env` і `.warden/`. Агенти вже мають працювати через Compose з `/opt/observability/shop-stage`.

1. Відкрийте `/opt/observability/shop-stage/warden.fragment.yml` і файл магазину `.warden/warden-env.yml`.
2. Якщо `.warden/warden-env.yml` ще немає, створіть його копіюванням fragment. Якщо він є, перенесіть записи `environment` та `volumes` для відповідних служб у його чинний `services`. Не замінюйте весь файл: він може містити налаштування магазину. Warden [автоматично підключає цей файл](https://docs.warden.dev/environments/customizing.html).
3. Fragment уже містить значення з `php.env`. Якщо надалі змінюєте їх, оновлюйте відповідні записи `environment` у `.warden/warden-env.yml`. Саме редагування окремого `php.env` тут не змінить контейнер.
4. Перевірте, що монтування `php-fpm.conf` веде до `/etc/php-fpm.d/zz-observability.conf`, а його заголовок відповідає пулу магазину. Для окремих cron/consumer-служб скопіюйте той самий блок `environment` зі служби `php-fpm`; конфігурація пулу їм не потрібна. `php-debug` у fragment має вимкнену інструментацію.
5. Перевірте й перестворіть PHP-контейнер:

```bash
warden env config --quiet
warden env up -d --no-deps --force-recreate php-fpm
warden env exec -T php-fpm php -r \
  'echo getenv("OTEL_SERVICE_NAME"), PHP_EOL, getenv("OTEL_EXPORTER_OTLP_ENDPOINT"), PHP_EOL;'
```

Очікується `shop-stage-magento` та `http://observability-collector:4318`. До команди `up` додайте назви окремих cron/consumer-служб, якщо ви змінили і їхнє оточення. Команди Magento всередині Warden запускайте через `warden env exec -T php-fpm`, а не хостовий PHP.

## Kubernetes

Виконуйте цей розділ на машині з `kubectl`, доступом до namespace магазину та отриманим каталогом `agent/`. Агенти й ConfigMap `observability-config` уже мають бути встановлені. Приклад використовує namespace `shop-stage`, Deployment `shop-web` із контейнером `php-fpm`, Deployment `shop-consumer` і CronJob `shop-cron` із контейнером `php`.

### 1. Підключіть вебконтейнер

Подивіться фактичні назви:

```bash
kubectl -n shop-stage get deployments,cronjobs
kubectl -n shop-stage get deployment shop-web -o jsonpath='{.spec.template.spec.containers[*].name}'
```

Відкрийте згенерований `application.patch.json`. У `spec.template.spec.containers[0]` задайте фактичне `name` FPM-контейнера та `volumeMounts[0].mountPath` до його каталогу пулів. Типове значення: `/usr/local/etc/php-fpm.d/zz-observability.conf`. Значення `env` вже згенеровано; `service.instance.id` бере ім'я pod через Downward API. `metadata.labels` дозволяє pod звертатися до агентів через NetworkPolicy.

```bash
kubectl -n shop-stage patch deployment shop-web --type=strategic \
  --patch-file application.patch.json --dry-run=server -o name
kubectl -n shop-stage patch deployment shop-web --type=strategic \
  --patch-file application.patch.json
kubectl -n shop-stage rollout status deployment/shop-web
```

### 2. Підключіть CLI-контейнери

У каталозі `agent/` створіть два patch-файли з того самого оточення. У прикладі CLI-контейнер називається `php`; якщо назва інша, змініть її в рядку `container['name']` нижче. Якщо consumer і CronJob мають різні назви контейнерів, задайте їх окремо у відповідних отриманих файлах.

```bash
python3 - <<'PY'
import copy
import json
from pathlib import Path
patch = json.loads(Path('application.patch.json').read_text())
pod = copy.deepcopy(patch['spec']['template'])
container = pod['spec']['containers'][0]
container['name'] = 'php'
container.pop('volumeMounts', None)
pod['spec'].pop('volumes', None)
Path('consumer.patch.json').write_text(json.dumps({'spec': {'template': pod}}, indent=2))
Path('cronjob.patch.json').write_text(json.dumps({'spec': {'jobTemplate': {'spec': {'template': pod}}}}, indent=2))
PY
kubectl -n shop-stage patch deployment shop-consumer --type=strategic \
  --patch-file consumer.patch.json --dry-run=server -o name
kubectl -n shop-stage patch cronjob shop-cron --type=strategic \
  --patch-file cronjob.patch.json --dry-run=server -o name
```

Після успішних перевірок повторіть обидві команди `patch` без `--dry-run=server -o name`, потім виконайте `kubectl -n shop-stage rollout status deployment/shop-consumer`. Зміна CronJob діє для наступних Jobs; уже запущені Jobs зберігають своє оточення.

### 3. Перевірте значення

```bash
kubectl -n shop-stage exec deployment/shop-web -c php-fpm -- php -r \
  'echo getenv("OTEL_SERVICE_NAME"), PHP_EOL, getenv("OTEL_EXPORTER_OTLP_ENDPOINT"), PHP_EOL;'
```

Очікується `shop-stage-magento` та `http://observability.shop-stage.svc.cluster.local:4318`. Збережіть зміни у Helm/Kustomize або іншому джерелі ваших manifests, інакше наступне розгортання може їх прибрати. Після зміни ConfigMap з конфігурацією FPM виконайте `kubectl -n shop-stage rollout restart deployment/shop-web`: [монтування через `subPath` не оновлює файл у вже запущеному контейнері](https://kubernetes.io/docs/tasks/configure-pod-container/configure-pod-configmap/#mounted-configmaps-are-updated-automatically).
