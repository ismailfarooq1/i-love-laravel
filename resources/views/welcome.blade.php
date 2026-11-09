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
        <div id="app" data-endpoints="{{ json_encode(['show' => route('workspace.show', absolute: false), 'reset' => route('workspace.reset', absolute: false), 'commands' => route('workspace.commands', absolute: false), 'documents' => route('workspace.documents', absolute: false), 'teams' => route('teams.index', absolute: false), 'teamStore' => route('teams.store', absolute: false), 'selectTeam' => route('teams.select', ['team' => '__TEAM__'], false), 'member' => route('teams.members.update', ['team' => '__TEAM__', 'user' => '__USER__'], false), 'invite' => route('teams.invitations.store', ['team' => '__TEAM__'], false), 'revokeInvite' => route('teams.invitations.destroy', ['team' => '__TEAM__', 'invitation' => '__INVITATION__'], false), 'logout' => route('logout', absolute: false), 'login' => route('login', absolute: false), 'verification' => route('verification.notice', absolute: false)]) }}"></div>
    </body>
</html>
