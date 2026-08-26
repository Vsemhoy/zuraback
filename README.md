# Zuratax Backend

Laravel API и серверная авторизация Zuratax.

## Локальная установка

```bash
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
```

Настройки базы данных задаются в `.env`.

## Браузерная авторизация

Реализована через серверные Laravel-сессии в HttpOnly cookie:

```text
POST /api/auth/login
GET  /api/auth/me
POST /api/auth/logout
```

Вход принимает `identity` (email или username) и `password`. Для запросов frontend обязательны JSON, разрешённый `Origin` и заголовок `X-App-Request`.

Локальный frontend обращается к относительному `/api`; Vite проксирует запросы на `https://zuraback`.

## Стартовый пользователь

Первый пользователь создаётся отдельным сидером и не создаётся общим `DatabaseSeeder`:

```bash
php artisan db:seed --class=StarterSeeder
```

Требуемые переменные:

```text
STARTER_USER_NAME
STARTER_USER_USERNAME
STARTER_USER_EMAIL
STARTER_USER_PASSWORD
```

Не размещайте реальный пароль в `.env.example` или Git.

## Проверка

```bash
php artisan test --compact
vendor/bin/pint --format agent
```

Продуктовая документация и архитектурные решения находятся в репозитории frontend в каталоге `docs`.
