import React, { useState } from 'react';
import { Alert, Button, Checkbox, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { projectPath } from './ProjectHub';

export default function ChatGptConnections({ project, identity, endpoints, connections, action, busy }) {
    const [local, setLocal] = useState(false);
    const [catalogs, setCatalogs] = useState({});
    const [selected, setSelected] = useState({});
    const [welcome, setWelcome] = useState(new URLSearchParams(window.location.search).get('ai_connected') === 'chatgpt');
    const url = (name, connection) => projectPath(endpoints[name], project, { __CONNECTION__: connection?.id });
    const connect = async (connection) => {
        const result = await action('post', url('chatgptConnect'), { local_confirmation: local, connection_id: connection?.id });
        if (result?.url) window.location.assign(result.url);
    };
    return <Paper variant="outlined" sx={{ p: 3 }}><Stack spacing={2}>
        <Typography variant="h6">Your ChatGPT connection</Typography>
        <Typography variant="body2">Use your ChatGPT plan for document analysis. Your connection is private to you in this team. Relevant documents and planning context go directly to OpenAI when you start an analysis. Plan limits apply; extra credits are controlled in ChatGPT settings.</Typography>
        <Alert severity="info">For this local installation, complete sign-in on the computer running Tracework. You can open Tracework through ngrok on that computer. A phone or another computer cannot complete the local callback.</Alert>
        <FormControlLabel control={<Checkbox checked={local} onChange={event => setLocal(event.target.checked)} />} label="I am using the computer running Tracework." />
        <Button variant="contained" disabled={busy || !identity.permissions.edit || !local} onClick={() => connect()}>Continue with ChatGPT</Button>
        <Button component="a" href="https://chatgpt.com/settings/usage" target="_blank" rel="noopener noreferrer">Manage usage in ChatGPT ↗</Button>
        {connections.map(connection => <Stack key={connection.id} spacing={1} sx={{ borderTop: '1px solid', borderColor: 'divider', pt: 2 }}>
            <Typography variant="subtitle2">{connection.email} · connection #{connection.id}</Typography>
            <Typography variant="body2">{connection.connected ? (connection.model ? `Using ChatGPT plan · ${connection.model}` : 'Connected — select a model to begin') : 'Disconnected — reconnect to use this account'}</Typography>
            {connection.connected && <>
                <Button disabled={busy} onClick={async () => { const models = await action('get', url('chatgptModels', connection)); if (models) setCatalogs(current => ({ ...current, [connection.id]: models })); }}>Load available models</Button>
                {catalogs[connection.id] && <><TextField select label="Model available to this account" value={selected[connection.id] || ''} onChange={event => setSelected(current => ({ ...current, [connection.id]: event.target.value }))}><MenuItem value="">Choose a model</MenuItem>{catalogs[connection.id].map(model => <MenuItem key={model.id} value={model.id}>{model.name}</MenuItem>)}</TextField><Button disabled={busy || !selected[connection.id]} onClick={() => action('patch', url('chatgptUpdate', connection), { model: selected[connection.id], revision: connection.revision })}>Use this model</Button></>}
            </>}
            <Stack direction="row" spacing={1}>
                <Button disabled={busy || !local || !identity.permissions.edit} onClick={() => connect(connection)}>Reconnect this account</Button>
                {connection.connected && <Button color="error" disabled={busy} onClick={async () => { if (window.confirm('Disconnect ChatGPT? Queued analyses will stop; saved plans remain.')) { const result = await action('delete', url('aiDisconnect', connection)); if (result) window.alert(result.message); } }}>Disconnect</Button>}
            </Stack>
        </Stack>)}
    </Stack><Dialog open={welcome} onClose={() => setWelcome(false)}><DialogTitle>You’re using your ChatGPT plan</DialogTitle><DialogContent>Eligible analyses use your ChatGPT plan allowance. You can manage Tracework’s limits and any credit usage in ChatGPT settings. Select a model, then choose documents to analyze.</DialogContent><DialogActions><Button onClick={() => { setWelcome(false); const url = new URL(window.location.href); url.searchParams.delete('ai_connected'); window.history.replaceState({}, '', url); }}>Got it</Button></DialogActions></Dialog></Paper>;
}
