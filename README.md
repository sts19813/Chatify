# 💬 Finance Chat AI

Sistema de chat tipo WhatsApp construido con **Laravel + Chatify +
Pusher** que se conecta a un backend de IA en **Python (Flask + LLM +
Whisper)** para registrar movimientos financieros automáticamente.

------------------------------------------------------------------------

## 🏗 Arquitectura

Browser → Laravel (Chatify) → Flask API → LLM + SQLite → Respuesta JSON
→ Pusher → UI

------------------------------------------------------------------------

## 🚀 Tecnologías

-   Laravel 10+
-   Chatify
-   Pusher
-   Flask
-   SQLite (microservicio IA)
-   MySQL (Laravel)
-   ngrok (desarrollo)
-   Whisper (voz opcional)
-   LLM local

------------------------------------------------------------------------

# ⚙️ Instalación Laravel

## 1️⃣ Clonar repositorio

``` bash
git clone https://github.com/tuusuario/finance-chat.git
cd finance-chat
```

## 2️⃣ Instalar dependencias

``` bash
composer install
npm install
```

## 3️⃣ Configurar entorno

``` bash
cp .env.example .env
php artisan key:generate
```

Configurar base de datos en `.env`.

## 4️⃣ Migraciones

``` bash
php artisan migrate
```

------------------------------------------------------------------------

# 💬 Instalar Chatify

``` bash
composer require munafio/chatify
php artisan chatify:install
php artisan migrate
```

------------------------------------------------------------------------

# 🔐 Instalar autenticación (Breeze)

``` bash
composer require laravel/breeze --dev
php artisan breeze:install
npm run dev
php artisan migrate
```

------------------------------------------------------------------------

# 🤖 Crear usuario Bot

``` bash
php artisan tinker
```

``` php
use App\Models\User;

User::create([
    'name' => 'Finance Bot',
    'email' => 'bot@local.com',
    'password' => bcrypt('password')
]);
```

------------------------------------------------------------------------

# 🔔 Configurar Pusher

Crear app en https://dashboard.pusher.com/

Agregar en `.env`:

    BROADCAST_DRIVER=pusher
    PUSHER_APP_ID=xxxx
    PUSHER_APP_KEY=xxxx
    PUSHER_APP_SECRET=xxxx
    PUSHER_APP_CLUSTER=us2

``` bash
php artisan config:clear
```

------------------------------------------------------------------------

# 🧠 Backend IA (Flask)

## Crear entorno

``` bash
python -m venv venv
venv\Scripts\activate
pip install flask requests faster-whisper
```

## Crear `api_server.py`

(Ver código en repositorio)

## Ejecutar servidor

``` bash
python api_server.py
```

------------------------------------------------------------------------

# 🌍 Exponer con ngrok

``` bash
ngrok http 5000
```

Copiar URL HTTPS generada.

En Laravel `.env`:

    FLASK_API_URL=https://tu-ngrok-url

``` bash
php artisan config:clear
```

------------------------------------------------------------------------

# 🎯 Flujo final

1.  Usuario escribe en Chatify
2.  Laravel envía mensaje a Flask
3.  Flask procesa con IA
4.  Respuesta vuelve a Laravel
5.  Pusher actualiza el chat en tiempo real

------------------------------------------------------------------------

# ✅ Estado

Proyecto funcional con:

-   Chat en tiempo real
-   Integración IA
-   Registro automático de movimientos
-   Arquitectura desacoplada Laravel + Flask
