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
        <div id="app" data-endpoints="{{ json_encode(['show' => route('workspace.show', absolute: false), 'reset' => route('workspace.reset', absolute: false), 'commands' => route('workspace.commands', absolute: false), 'documents' => route('workspace.documents', absolute: false), 'teams' => route('teams.index', absolute: false), 'teamStore' => route('teams.store', absolute: false), 'selectTeam' => route('teams.select', ['team' => '__TEAM__'], false), 'member' => route('teams.members.update', ['team' => '__TEAM__', 'user' => '__USER__'], false), 'invite' => route('teams.invitations.store', ['team' => '__TEAM__'], false), 'revokeInvite' => route('teams.invitations.destroy', ['team' => '__TEAM__', 'invitation' => '__INVITATION__'], false), 'logout' => route('logout', absolute: false), 'login' => route('login', absolute: false), 'verification' => route('verification.notice', absolute: false), 'projects' => route('projects.index', [], false), 'createProject' => route('projects.store', [], false), 'selectProject' => route('projects.select', ['project' => '__PROJECT__'], false), 'githubConnections' => route('github.connections', ['project' => '__PROJECT__'], false), 'githubConnect' => route('github.connect', ['project' => '__PROJECT__'], false), 'githubInstall' => route('github.install', ['project' => '__PROJECT__', 'connection' => '__CONNECTION__'], false), 'githubRepos' => route('github.repositories', ['project' => '__PROJECT__', 'connection' => '__CONNECTION__'], false), 'githubBind' => route('github.bind', ['project' => '__PROJECT__'], false), 'githubDisconnect' => route('github.disconnect', ['project' => '__PROJECT__'], false), 'githubPublications' => route('github.publications', ['project' => '__PROJECT__'], false), 'githubPreview' => route('github.preview', ['project' => '__PROJECT__'], false), 'githubApprove' => route('github.publish', ['project' => '__PROJECT__', 'publication' => '__PUBLICATION__'], false), 'githubSync' => route('github.synchronize', ['project' => '__PROJECT__'], false), 'githubRetry' => route('github.retryCreation', ['project' => '__PROJECT__', 'issue' => '__ISSUE__'], false)]) }}"></div>
    </body>
</html>
