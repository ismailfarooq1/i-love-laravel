<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Tracework — Transparent AI Backlog Builder</title>
        @viteReactRefresh
        @vite('resources/js/app.jsx')
    </head>
    <body>
        <div id="app" data-endpoints="{{ json_encode(['show' => route('workspace.show', absolute: false), 'reset' => route('workspace.reset', absolute: false), 'commands' => route('workspace.commands', absolute: false), 'documents' => route('workspace.documents', absolute: false)]) }}"></div>
    </body>
</html>
