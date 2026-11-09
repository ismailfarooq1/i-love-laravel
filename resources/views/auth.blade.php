<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Tracework — Your team, your decisions</title>
        @viteReactRefresh
        @vite('resources/js/app.jsx')
    </head>
    <body>
        <div id="app" data-auth="{{ json_encode(['screen' => $screen, 'token' => $token, 'email' => $email, 'logMail' => app()->environment('local') && config('mail.default') === 'log', 'urls' => ['login' => route('login'), 'loginSubmit' => route('login.store'), 'register' => route('register'), 'registerSubmit' => route('register.store'), 'forgot' => route('password.request'), 'forgotSubmit' => route('password.email'), 'resetSubmit' => route('password.update'), 'resend' => route('verification.send'), 'logout' => route('logout'), 'home' => route('home'), 'invitation' => $screen === 'invitation' ? route('invitations.accept', ['token' => $token]) : null, 'invitationDetails' => $screen === 'invitation' ? route('invitations.details', ['token' => $token]) : null]]) }}"></div>
    </body>
</html>
