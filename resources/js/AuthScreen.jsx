import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { Alert, Box, Button, CircularProgress, Link, Paper, Stack, TextField, Typography } from '@mui/material';

export default function AuthScreen({ config }) {
    const { screen, urls } = config;
    const registering = screen === 'register';
    const verifying = screen === 'verification.notice';
    const resetting = screen === 'password.reset';
    const forgot = screen === 'password.request';
    const invitation = screen === 'invitation';
    const [form, setForm] = useState({ email: config.email || '', name: '', password: '', password_confirmation: '', token: config.token });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [details, setDetails] = useState(null);
    useEffect(() => {
        if (invitation) axios.get(urls.invitationDetails).then(result => setDetails(result.data)).catch(() => setError('This invitation is unavailable. It may be expired, already used, or intended for another email address.'));
    }, []);
    const submit = async event => {
        event?.preventDefault(); setBusy(true); setError(''); setMessage('');
        const url = invitation ? urls.invitation : verifying ? urls.resend : registering ? urls.registerSubmit : resetting ? urls.resetSubmit : forgot ? urls.forgotSubmit : urls.loginSubmit;
        const payload = invitation || verifying ? {} : forgot ? { email: form.email } : registering
            ? { name: form.name, email: form.email, password: form.password, password_confirmation: form.password_confirmation }
            : resetting ? { email: form.email, password: form.password, password_confirmation: form.password_confirmation, token: form.token }
                : { email: form.email, password: form.password };
        try {
            const result = await axios.post(url, payload, { headers: { Accept: 'application/json' } });
            if (result.data.redirect) window.location.assign(result.data.redirect);
            else setMessage(result.data.message);
        } catch (error) { setError(Object.values(error.response?.data?.errors || {}).flat().join(' ') || error.response?.data?.message || 'The request could not be completed. Please try again.'); }
        finally { setBusy(false); }
    };
    const field = (name, label, type = 'text') => <TextField key={name} label={label} type={type} value={form[name]} required fullWidth disabled={busy} onChange={event => setForm(previous => ({ ...previous, [name]: event.target.value }))} autoComplete={name === 'email' ? 'email' : name === 'name' ? 'name' : type === 'password' ? registering || resetting ? 'new-password' : 'current-password' : undefined} inputProps={{ maxLength: type === 'password' ? 128 : name === 'name' ? 100 : 254 }} />;
    const title = invitation ? 'Join your team' : verifying ? 'Verify your email' : registering ? 'Start with your team' : resetting ? 'Choose a new password' : forgot ? 'Reset your password' : 'Welcome back';
    return <Box sx={{ minHeight: '100vh', display: 'grid', placeItems: 'center', bgcolor: '#1e293b', p: 3 }}>
        <Box sx={{ width: '100%', maxWidth: 460 }}>
            <Typography sx={{ textAlign: 'center', color: 'white', fontSize: 32, fontWeight: 750, mb: 1 }}>⌘ Trace<span style={{ color: '#b8a3ed' }}>work</span></Typography>
            <Typography sx={{ textAlign: 'center', color: '#acb5c5', mb: 4, fontSize: 13 }}>Your judgment. AI’s legwork.</Typography>
            <Paper sx={{ p: { xs: 3, sm: 4 } }}>
                <Typography variant="h5" sx={{ mb: 1 }}>{title}</Typography>
                <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>{invitation ? 'Accept an invitation with the account matching the invited email.' : verifying ? 'Check your inbox and follow the verification link before accessing team workspaces.' : registering ? 'Create your account and a private team workspace. Passwords must contain at least 12 characters.' : forgot ? 'We’ll send a link if your email is registered.' : resetting ? 'Use at least 12 characters. Existing sessions will be signed out.' : 'Sign in to your team’s goals, tasks, and decisions.'}</Typography>
                {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
                {message && <Alert severity="success" sx={{ mb: 2 }}>{message}</Alert>}
                <Stack component="form" onSubmit={submit} spacing={2}>
                    {registering && field('name', 'Your name')}
                    {!verifying && !invitation && field('email', 'Email address', 'email')}
                    {!verifying && !forgot && !invitation && field('password', 'Password', 'password')}
                    {(registering || resetting) && field('password_confirmation', 'Confirm password', 'password')}
                    {invitation && details && <Alert severity="info">Team: {details.team}<br />Role: {details.role}</Alert>}
                    {invitation && !details && !error && <CircularProgress size={24} />}
                    <Button type="submit" variant="contained" disabled={busy || (invitation && !details)}>{busy ? 'Please wait…' : invitation ? 'Accept invitation' : verifying ? 'Resend verification email' : registering ? 'Create account' : resetting ? 'Save password' : forgot ? 'Send reset link' : 'Sign in'}</Button>
                </Stack>
                {config.logMail && (verifying || forgot) && <Typography variant="caption" color="text.secondary" display="block" sx={{ mt: 2 }}>Local development uses the mail log. Find the link in storage/logs/laravel.log.</Typography>}
                <Stack direction="row" flexWrap="wrap" useFlexGap gap={2} sx={{ mt: 3 }}>
                    {!verifying && !invitation && <Link href={registering || forgot || resetting ? urls.login : urls.register} fontSize={12}>{registering || forgot || resetting ? 'Back to sign in' : 'Create an account'}</Link>}
                    {screen === 'login' && <Link href={urls.forgot} fontSize={12}>Forgot password?</Link>}
                    {(verifying || invitation) && <Button size="small" onClick={async () => { const result = await axios.post(urls.logout); window.location.assign(result.data.redirect); }}>Sign out / use another account</Button>}
                    {invitation && <Link href={urls.home} fontSize={12}>Return to workspace</Link>}
                    {verifying && <Link href={urls.home} fontSize={12}>I’ve verified my email</Link>}
                </Stack>
            </Paper>
        </Box>
    </Box>;
}
