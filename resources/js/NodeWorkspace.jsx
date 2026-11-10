import React, { useEffect, useState } from 'react';
import { Alert, Box, Breadcrumbs, Button, Chip, Divider, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { ancestors, blockers, completionInfo, isCompleted, nodeStatus, stateColors, stateLabels } from './workflow';

export default function NodeWorkspace({ state, id, onSelect, command, busy, permissions, onSource }) {
    const node = state.nodes[id];
    const [title, setTitle] = useState(node.title);
    const [description, setDescription] = useState(node.description);
    const [criteria, setCriteria] = useState(node.criteria.join('\n'));
    const [dependencies, setDependencies] = useState(node.dependencies);
    const [answer, setAnswer] = useState('');
    useEffect(() => { setTitle(node.title); setDescription(node.description); setCriteria(node.criteria.join('\n')); setDependencies(node.dependencies); setAnswer(''); }, [id, node.revision]);
    const lineage = ancestors(state.nodes, id);
    const unresolved = blockers(state, id);
    const unapprovedAncestor = lineage.slice(0, -1).find(parent => parent.status !== 'approved');
    const pendingAncestor = lineage.slice(0, -1).find(parent => parent.status !== 'approved' || parent.breakdown !== 'approved');
    const decision = Object.values(state.decisions).find(item => item.node_id === id);
    const children = Object.values(state.nodes).filter(child => child.parent_id === id);
    const status = nodeStatus(state, node);
    const completion = completionInfo(state, node);
    const changed = title !== node.title || description !== node.description || criteria !== node.criteria.join('\n') || JSON.stringify(dependencies) !== JSON.stringify(node.dependencies);
    const act = (action, extra = {}) => command(action, { node_id: id, ...extra });
    const canReview = !busy && !changed && permissions.review;
    const canEdit = !busy && !changed && permissions.edit;
    return <Box sx={{ maxWidth: 1160, mx: 'auto', p: { xs: 2, md: 4 } }}>
        {!permissions.review && <Alert severity="info" sx={{ mb: 3 }}>{permissions.edit ? 'Your Editor role can prepare and complete work. A Reviewer, Admin, or Owner must approve decisions and publication.' : 'Your Viewer role provides read-only access to this workspace.'}</Alert>}
        <Breadcrumbs sx={{ mb: 3 }}>{lineage.map(item => <Button key={item.id} size="small" onClick={() => onSelect(item.id)} color={item.id === id ? 'primary' : 'inherit'}>{item.title}</Button>)}</Breadcrumbs>
        <Stack direction="row" alignItems="center" spacing={1} sx={{ mb: 1 }}><Chip size="small" label={node.type === 'goal' ? '◇ Outcome goal' : '☑ Implementation task'} /><Typography variant="caption">Revision {node.revision}</Typography><Chip size="small" label={stateLabels[status]} sx={{ color: stateColors[status], bgcolor: `${stateColors[status]}12` }} /></Stack>
        <Typography variant="h4" sx={{ mb: 1 }}>{node.title}</Typography>
        <Typography color="text.secondary" sx={{ mb: 3 }}>{node.description}</Typography>{node.github_url && <Alert severity="info" sx={{ mb: 3 }}>Published to GitHub. <Button component="a" href={node.github_url} target="_blank" rel="noopener noreferrer">Open issue ↗</Button>Completion changes synchronize automatically. See GitHub publishing for synchronization status.</Alert>}
        {unresolved.length > 0 && <Alert severity="warning" sx={{ mb: 3 }}>This branch is paused for a human decision. {unresolved.map(item => item.node_id !== id ? <Button key={item.id} size="small" onClick={() => onSelect(item.node_id)}>Resolve at {state.nodes[item.node_id].title}</Button> : item.question)}</Alert>}
        {pendingAncestor && <Alert severity="info" sx={{ mb: 3 }}>{unapprovedAncestor ? `Approve “${unapprovedAncestor.title}” before approving this child. Approval flows from parent to child.` : `Approve the child breakdown on “${pendingAncestor.title}” before approving this node.`}<Button size="small" onClick={() => onSelect((unapprovedAncestor || pendingAncestor).id)}>Review required parent</Button></Alert>}
        {node.status === 'needs_review' && <Alert severity="info" sx={{ mb: 3 }}>{node.review_reason || 'This revision needs fresh approval. Earlier decisions remain in the history.'}</Alert>}
        <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', lg: '1.3fr 1fr' }, gap: 3 }}>
            <Stack spacing={3}>
                <Paper variant="outlined" sx={{ p: 3 }}>
                    <Typography variant="h6" sx={{ mb: 2 }}>The proposal</Typography>
                    <Stack spacing={2}>
                        <TextField label="Title" value={title} onChange={event => setTitle(event.target.value)} inputProps={{ maxLength: 180 }} fullWidth />
                        <TextField label="Intended outcome / description" value={description} onChange={event => setDescription(event.target.value)} multiline minRows={3} fullWidth />
                        {node.type === 'task' && <><TextField label="Acceptance criteria · one per line" value={criteria} onChange={event => setCriteria(event.target.value)} multiline minRows={3} fullWidth /><TextField select label="Depends on" value={dependencies} onChange={event => setDependencies(event.target.value)} slotProps={{ select: { multiple: true } }} fullWidth>{Object.values(state.nodes).filter(item => item.type === 'task' && item.id !== id).map(item => <MenuItem key={item.id} value={item.id}>{item.title}</MenuItem>)}</TextField></>}
                        <Button variant="outlined" disabled={busy || !permissions.edit || !changed || !title.trim() || !description.trim()} onClick={() => act('edit', { title, description, criteria: criteria.split('\n').map(line => line.trim()).filter(Boolean), dependencies })}>Save changes · requires renewed approval</Button>
                    </Stack>
                </Paper>
                {decision && <Paper variant="outlined" sx={{ p: 3, borderColor: '#e7d6b7' }}>
                    <Typography variant="overline" color="warning.main">Human decision</Typography>
                    <Typography variant="h6" sx={{ mb: 2 }}>{decision.question}</Typography>
                    <Typography variant="subtitle2">Suggested choice</Typography><Typography sx={{ my: 1 }}>{decision.proposal}</Typography>
                    <Typography variant="body2" color="text.secondary">{decision.rationale}</Typography>
                    <Typography variant="subtitle2" sx={{ mt: 2 }}>Alternatives</Typography>{decision.alternatives.map(item => <Typography key={item} variant="body2" sx={{ mt: 1 }}>• {item}</Typography>)}
                    {decision.answer && <Alert severity="success" sx={{ my: 2 }}>Approved answer: {decision.answer}<br />{decision.resolved_by} · {new Date(decision.resolved_at).toLocaleString()}</Alert>}
                    <TextField label="Your decision" placeholder={decision.proposal} value={answer} onChange={event => setAnswer(event.target.value)} multiline minRows={2} fullWidth sx={{ my: 2 }} />
                    <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap><Button variant="contained" disabled={!canReview} onClick={() => act('resolve', { answer: answer.trim() || decision.proposal })}>{answer.trim() ? 'Confirm my decision' : 'Accept suggested choice'}</Button>{!answer && <Button disabled={busy} onClick={() => setAnswer(decision.proposal)}>Edit suggestion</Button>}</Stack>
                </Paper>}
                {children.length > 0 && <Paper variant="outlined" sx={{ p: 3 }}><Typography variant="h6">Proposed breakdown</Typography><Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>Review these children as a set. Rejected proposals are excluded.</Typography>{children.map(child => <Button key={child.id} fullWidth onClick={() => onSelect(child.id)} sx={{ justifyContent: 'space-between', py: 1.5, textAlign: 'left' }}><span>{child.type === 'goal' ? '◇' : '☑'} {child.title}</span><Chip size="small" label={stateLabels[nodeStatus(state, child)]} /></Button>)}<Button variant="contained" disabled={!canReview || Boolean(pendingAncestor) || node.status !== 'approved' || node.breakdown === 'approved'} onClick={() => act('approve_breakdown')} sx={{ mt: 2 }}>Approve child breakdown</Button></Paper>}
            </Stack>
            <Stack spacing={3}>
                <Paper variant="outlined" sx={{ p: 3, borderColor: isCompleted(node) ? '#b9dfcc' : '#e5e8f0' }}>
                    <Typography variant="h6" sx={{ mb: 1 }}>Completion</Typography>
                    <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>Approval agrees the plan. Completion confirms the work is finished. Parents wait for all active children; rejected proposals are excluded.</Typography>
                    {completion.children.length > 0 && <Typography variant="subtitle2" sx={{ mb: 1 }}>{completion.completedChildren} / {completion.children.length} children completed</Typography>}
                    {completion.children.map(child => <Button key={child.id} fullWidth onClick={() => onSelect(child.id)} sx={{ justifyContent: 'flex-start', gap: 1, textAlign: 'left' }}><span>{isCompleted(child) ? '✓' : '○'}</span>{child.title}</Button>)}
                    {completion.prerequisites.length > 0 && <><Typography variant="subtitle2" sx={{ mt: 2 }}>Prerequisite tasks</Typography>{completion.prerequisites.map(prerequisite => <Button key={prerequisite.id} fullWidth onClick={() => onSelect(prerequisite.id)} sx={{ justifyContent: 'flex-start', gap: 1, textAlign: 'left' }}><span>{isCompleted(prerequisite) ? '✓' : '○'}</span>{prerequisite.title}</Button>)}</>}
                    {isCompleted(node) ? <><Alert severity="success" sx={{ my: 2 }}>Completed by {node.completed_by} · {new Date(node.completed_at).toLocaleString()}</Alert><Button variant="outlined" disabled={!canEdit} onClick={() => act('reopen')}>Reopen work</Button><Typography variant="caption" color="text.secondary" display="block" sx={{ mt: 1 }}>Reopening also reopens completed parents and tasks that depend on this work.</Typography></> : <><Box sx={{ my: 2 }}>{completion.reasons.map(reason => <Typography key={reason} variant="body2" color="text.secondary">• {reason}</Typography>)}</Box><Button variant="contained" color="success" fullWidth disabled={!canEdit || !completion.canComplete} onClick={() => act('complete')}>Mark {node.type === 'goal' ? 'goal' : 'task'} complete</Button></>}
                </Paper>
                <Paper variant="outlined" sx={{ p: 3 }}><Typography variant="h6" sx={{ mb: 1 }}>Available actions</Typography><Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>Approval applies to revision {node.revision}. Nothing proceeds automatically.</Typography><Stack spacing={1.5}>
                    {pendingAncestor && <Typography variant="caption" color="text.secondary">Child approval is locked until parent approval and breakdown review are complete.</Typography>}
                    <Button variant="contained" disabled={!canReview || Boolean(pendingAncestor) || unresolved.length > 0 || node.status === 'approved' || node.status === 'rejected'} onClick={() => act('approve')}>Approve {node.type === 'goal' ? 'goal' : 'task'}</Button>
                    {node.type === 'goal' && children.length === 0 && <Button variant="outlined" disabled={!canEdit || Boolean(pendingAncestor) || unresolved.length > 0 || node.status !== 'approved'} onClick={() => act('expand')}>Generate child proposals · simulation</Button>}
                    <Button color="error" disabled={!canReview || node.status === 'rejected'} onClick={() => act('reject')}>Reject proposal</Button>
                    {changed && <Typography variant="caption" color="warning.main">Save your changes before making a decision.</Typography>}
                </Stack></Paper>
                <Paper variant="outlined" sx={{ p: 3 }}><Typography variant="h6" sx={{ mb: 2 }}>Evidence & assumptions</Typography>{node.evidence.length ? node.evidence.map((source, index) => {
                    const document = state.documents.find(item => item.id === source.document_id);
                    const superseded = state.documents.some(item => item.previous_id === source.document_id);
                    return <Box key={index} sx={{ borderLeft: '3px solid #b0a1df', pl: 2, mb: 2 }}><Typography variant="body2">“{source.quote}”</Typography><Button size="small" onClick={() => onSource(document.id)} sx={{ px: 0 }}>{document.name} · v{document.version} · line {source.line}</Button>{superseded && <Typography variant="caption" color="warning.main" display="block">A newer source version exists. Compare it before reapproving.</Typography>}</Box>;
                }) : <Alert severity="info">No source evidence: this is a scripted example, not analysis of your uploaded text.</Alert>}
                    <Divider sx={{ my: 2 }} /><Typography variant="subtitle2" sx={{ mb: 1 }}>Explicit assumptions</Typography>{node.assumptions.map(item => <Typography key={item} variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>• {item}</Typography>)}
                </Paper>

                <Paper variant="outlined" sx={{ p: 3 }}><Typography variant="h6" sx={{ mb: 2 }}>Decision history</Typography>{state.history.filter(item => item.node_id === id).slice().reverse().map((item, index) => <Box key={index} sx={{ borderLeft: '2px solid #e3dfee', pl: 2, pb: 2 }}><Typography variant="body2" fontWeight={600}>{item.action.replaceAll('_', ' ')} · revision {item.revision}</Typography><Typography variant="caption" color="text.secondary">{item.actor} · {new Date(item.at).toLocaleString()}</Typography>{item.snapshot && <details style={{ marginTop: 8 }}><summary style={{ cursor: 'pointer', fontSize: 11, color: '#7460b6' }}>Inspect recorded revision</summary><Typography variant="body2" sx={{ mt: 1, fontWeight: 600 }}>{item.snapshot.title}</Typography><Typography variant="body2">{item.snapshot.description}</Typography>{item.snapshot.criteria.map(criterion => <Typography key={criterion} variant="body2">☐ {criterion}</Typography>)}{item.decision?.answer && <Typography variant="body2">Decision: {item.decision.answer}</Typography>}</details>}</Box>)}{!state.history.some(item => item.node_id === id) && <Typography variant="body2" color="text.secondary">No human decisions yet.</Typography>}</Paper>
            </Stack>
        </Box>
    </Box>;
}
