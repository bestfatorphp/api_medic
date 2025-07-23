# Руководство по развертыванию Laravel 8 (PHP 8.2)

## 1. Подготовка сервера и клонирование проекта

### Установка Git и настройка SSH

```bash
# Обновление пакетов и установка Git
sudo apt update && sudo apt install git -y

# Генерация SSH-ключа (передать ключ мне)
ssh-keygen -t ed25519 -C "your_email@example.com"

# Просмотр и копирование открытого ключа
cat ~/.ssh/id_ed25519.pub

# После добавления ключа клонируйте репозиторий
git clone git@github.com:yourusername/yourproject.git
cd yourproject
```

## 2. Настройка Docker окружения согласно переданным файлам (описывать не буду)

## 3. Устновить cron или суперкроник (на выбор)... 
```bash
# Содержимое файйла cron:
* * * * * php /var/www/site/artisan schedule:run >> /dev/null 2>&1
```


## 4. Настройка Laravel внутри контейнера

```bash
# Перейти в контейнер сервиса:
docker compose exec app bash

# Копирование .env файла (после заполнить переменные окружения)
cp .env.example .env

# Установка зависимостей (если не были установлены автоматически)
composer install

# Генерация ключа приложения
php artisan key:generate

# Запуск миграций
php artisan migrate
```


## 5. В Битрикс Медтач, прописать в капчу ip хоста (сервера), чтобы пропускала запросы с сервиса по сбору статистики.


## 6. Поднять докер
```bash
docker compose up --build -d
```
