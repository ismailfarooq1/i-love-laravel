import './bootstrap';
import React from 'react';
import { createRoot } from 'react-dom/client';
import { Button, Container, CssBaseline, Paper, Stack, ThemeProvider, Typography, createTheme } from '@mui/material';

const theme = createTheme({ palette: { primary: { main: '#6d4aff' } } });

function App() {
    return (
        <ThemeProvider theme={theme}>
            <CssBaseline />
            <Container maxWidth="sm" sx={{ py: 10 }}>
                <Paper elevation={3} sx={{ p: 5, borderRadius: 4 }}>
                    <Stack spacing={2}>
                        <Typography variant="overline" color="primary">Laravel · React · MUI</Typography>
                        <Typography variant="h3" component="h1">It’s running.</Typography>
                        <Typography color="text.secondary">Your local development starter is ready for its first feature.</Typography>
                        <Button variant="contained" sx={{ alignSelf: 'flex-start' }}>Get started</Button>
                    </Stack>
                </Paper>
            </Container>
        </ThemeProvider>
    );
}

createRoot(document.getElementById('app')).render(<App />);
