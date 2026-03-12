<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Chatify') }} | Selecciona un bot</title>
    <style>
        :root {
            --bg-start: #f8fafc;
            --bg-end: #e0f2fe;
            --text-main: #0f172a;
            --text-soft: #334155;
            --card-bg: #ffffff;
            --card-border: #cbd5e1;
            --card-border-hover: #0ea5e9;
            --badge-bg: #e0f2fe;
            --badge-text: #075985;
            --error-bg: #fee2e2;
            --error-text: #b91c1c;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Instrument Sans", "Segoe UI", sans-serif;
            color: var(--text-main);
            background: linear-gradient(160deg, var(--bg-start), var(--bg-end));
            min-height: 100vh;
        }

        .container {
            width: min(1080px, 100%);
            margin: 0 auto;
            padding: 2rem 1.25rem 3rem;
        }

        h1 {
            margin: 0;
            font-size: clamp(1.8rem, 3vw, 2.6rem);
            line-height: 1.15;
        }

        .subtitle {
            margin-top: 0.75rem;
            color: var(--text-soft);
            max-width: 64ch;
            line-height: 1.5;
        }

        .guest-entry {
            margin-top: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            border: 1px solid #0284c7;
            color: #075985;
            background: #e0f2fe;
            padding: 0.55rem 0.9rem;
            border-radius: 0.65rem;
            font-weight: 700;
            transition: filter 160ms ease;
        }

        .guest-entry:hover {
            filter: brightness(0.96);
        }

        .error {
            margin-top: 1rem;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            background: var(--error-bg);
            color: var(--error-text);
            font-weight: 600;
        }

        .agents {
            margin-top: 1.5rem;
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        }

        .agent-card {
            display: block;
            text-decoration: none;
            border: 1px solid var(--card-border);
            border-radius: 1rem;
            padding: 1rem;
            background: var(--card-bg);
            color: inherit;
            transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
        }

        .agent-card:hover {
            transform: translateY(-2px);
            border-color: var(--card-border-hover);
            box-shadow: 0 8px 20px rgba(2, 132, 199, 0.18);
        }

        .agent-top {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 0.75rem;
        }

        .agent-type {
            margin: 0;
            color: var(--text-soft);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 700;
        }

        .agent-name {
            margin: 0.25rem 0 0;
            font-size: 1.2rem;
            line-height: 1.25;
            font-weight: 700;
        }

        .agent-description {
            margin: 0.85rem 0 0;
            color: var(--text-soft);
            line-height: 1.5;
            font-size: 0.95rem;
        }

        .agent-action {
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 0.35rem 0.7rem;
            background: var(--badge-bg);
            color: var(--badge-text);
            font-size: 0.78rem;
            font-weight: 700;
        }

        .empty-state {
            margin-top: 1.5rem;
            border: 1px dashed var(--card-border);
            border-radius: 1rem;
            padding: 1rem;
            background: var(--card-bg);
            color: var(--text-soft);
        }

        .hint {
            margin-top: 1.25rem;
            color: var(--text-soft);
            font-size: 0.92rem;
        }
    </style>
</head>
<body>
    <main class="container">
        <h1>Elige con que bot o IA quieres chatear</h1>
        <p class="subtitle">
            Selecciona un asistente y te mando directo al chat para que empieces a escribirle.
            No necesitas iniciar sesion manualmente.
        </p>
        <a class="guest-entry" href="{{ route('chat.guest') }}">Chatear como invitado</a>

        @if (session('error'))
            <div class="error">{{ session('error') }}</div>
        @endif

        @if ($agents->isEmpty())
            <div class="empty-state">
                No hay bots o agentes IA disponibles en este momento.
            </div>
        @else
            <section class="agents" aria-label="Lista de agentes de chat">
                @foreach ($agents as $agent)
                    <a class="agent-card" href="{{ route('chat.start', ['agentId' => $agent['id']]) }}">
                        <div class="agent-top">
                            <div>
                                <p class="agent-type">{{ $agent['type_label'] }}</p>
                                <h2 class="agent-name">{{ $agent['name'] }}</h2>
                            </div>
                            <span class="agent-action">Chatear</span>
                        </div>
                        <p class="agent-description">{{ $agent['description'] }}</p>
                    </a>
                @endforeach
            </section>
        @endif

        <p class="hint">Al seleccionar un agente, abrire su conversacion en Chatify.</p>
    </main>
</body>
</html>
