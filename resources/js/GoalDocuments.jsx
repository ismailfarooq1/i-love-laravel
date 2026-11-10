import React, { useState } from 'react';
import { Alert, Box, Button, Checkbox, Chip, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, Paper, Stack, TextField, Typography } from '@mui/material';

export default function GoalDocuments({ state, node, command, uploadDocument, busy, canEdit, onSource }) {
    const [choosing, setChoosing] = useState(false);
    const [selected, setSelected] = useState([]);
    const [pasting, setPasting] = useState(false);
    const [name, setName] = useState('Goal notes.txt');
    const [content, setContent] = useState('');
    const ids = node.document_ids || [];
    const attached = state.documents.filter(doc => ids.includes(doc.id));
    const parent = state.nodes[node.parent_id];
    const parentDocuments = state.documents.filter(doc => (parent?.document_ids || []).includes(doc.id));
    const copying = choosing === 'parent';
    const choices = copying ? parentDocuments : state.documents;
    const save = async () => { if (await command(copying ? 'copy_parent_documents' : 'attach_documents', { node_id: node.id, document_ids: selected })) setChoosing(false); };
    const upload = async event => {
        const file = event.target.files[0]; event.target.value = '';
        if (file) await uploadDocument(file, node.id);
    };
    return <Paper variant="outlined" sx={{ p: 3, borderColor: '#d8cee9' }}>
        <Stack direction="row" alignItems="center" gap={1} sx={{ mb: 1 }}><Typography variant="h6">Documents for this {node.type}</Typography><Chip label={attached.length} /></Stack>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>Keep the brief, notes, or requirements for “{node.title}” here. Attachments add context only when you explicitly select them for AI planning. Copying links an existing version without duplicating its text.</Typography>
        {!attached.length && <Alert severity="info" sx={{ mb: 2 }}>No documents attached yet. Add one here or choose a document already saved in this project.</Alert>}
        {attached.map(doc => {
            const newer = state.documents.some(next => next.previous_id === doc.id);
            return <Box key={doc.id} sx={{ border: '1px solid #e9ecf2', borderRadius: 2, p: 2, my: 1 }}><Stack direction="row" justifyContent="space-between" alignItems="center" gap={1} flexWrap="wrap"><Box><Typography variant="subtitle2">{doc.name}</Typography><Typography variant="caption" color="text.secondary">Version {doc.version} · attached to this {node.type}</Typography></Box><Button size="small" onClick={() => onSource(doc.id)}>Read document</Button></Stack>{newer && <Alert severity="warning" sx={{ mt: 1 }}>A newer version exists. Choose “Manage attachments” to replace this version when you are ready. Existing evidence keeps its original source.</Alert>}</Box>;
        })}
        <Stack direction="row" gap={1} flexWrap="wrap" sx={{ mt: 2 }}>
            <Button component="label" variant="outlined" disabled={busy || !canEdit}>Upload document<input hidden type="file" accept=".txt,.md,text/plain,text/markdown" onChange={upload} /></Button>
            <Button variant="outlined" disabled={busy || !canEdit} onClick={() => setPasting(!pasting)}>{pasting ? 'Cancel pasted document' : 'Paste text'}</Button>
            <Button disabled={busy || !canEdit} onClick={() => { setSelected([...ids]); setChoosing('library'); }}>Manage attachments</Button>{parent && <Button disabled={busy || !canEdit || !parentDocuments.some(doc => !ids.includes(doc.id))} onClick={() => { setSelected([]); setChoosing('parent'); }}>Copy from parent ({parentDocuments.length})</Button>}
        </Stack>
        <Typography variant="caption" color="text.secondary" display="block" sx={{ mt: 1 }}>Text and Markdown files (.txt, .md), up to 100 KB. Changing attachments requires fresh review of this item and any smaller steps. Copies keep their chosen version even if the parent’s attachments later change.</Typography>
        {pasting && <Stack spacing={2} sx={{ mt: 3 }}><TextField label="Document name" value={name} onChange={event => setName(event.target.value)} inputProps={{ maxLength: 180 }} /><TextField label="Paste the document text" value={content} onChange={event => setContent(event.target.value)} multiline minRows={5} inputProps={{ maxLength: 100000 }} /><Button variant="contained" disabled={busy || !canEdit || !name.trim() || !content.trim()} onClick={async () => { if (await command('document', { node_id: node.id, name: name.trim(), content })) { setContent(''); setPasting(false); } }}>Save and attach to this {node.type}</Button></Stack>}
        <Dialog open={Boolean(choosing)} onClose={() => !busy && setChoosing(false)} fullWidth maxWidth="sm"><DialogTitle>{copying ? `Copy documents from ${parent?.title}` : `Documents for ${node.title}`}</DialogTitle><DialogContent><Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{copying ? 'Choose versions attached to the immediate parent. They will be added to this item without replacing your existing attachments. Changes to the parent’s attachments will not change these copies.' : 'Select specific versions to attach. Unchecking a document removes its attachment here; it stays in the project library and any existing evidence.'}</Typography>{!choices.length && <Alert severity="info">No project documents yet. Upload or paste one in this goal first.</Alert>}{choices.map(doc => <Stack key={doc.id} direction="row" alignItems="center"><FormControlLabel sx={{ flex: 1 }} control={<Checkbox checked={selected.includes(doc.id)} disabled={busy || (copying && ids.includes(doc.id))} onChange={event => setSelected(current => event.target.checked ? [...current, doc.id] : current.filter(id => id !== doc.id))} />} label={`${doc.name} · version ${doc.version}`} /><Button size="small" onClick={() => onSource(doc.id)}>Read</Button></Stack>)}<Alert severity="warning" sx={{ mt: 2 }}>Changes will require this item and its smaller steps to be reviewed again. Previously quoted sources remain unchanged.</Alert></DialogContent><DialogActions><Button disabled={busy} onClick={() => setChoosing(false)}>Cancel</Button><Button variant="contained" disabled={busy || !canEdit || selected.length > 50 || (copying && !selected.length)} onClick={save}>{copying ? 'Copy selected attachments' : 'Save attachments'}</Button></DialogActions></Dialog>
    </Paper>;
}
