import React, { useState } from 'react';
import axios from 'axios';
import { Alert, Box, Button, Chip, LinearProgress, Paper, Stack, Typography } from '@mui/material';
import { ancestors, blockers, isCompleted, nodeStatus, stateLabels } from './workflow';

export function ProjectDirectory({ identity, context, endpoints, onCreate, onContinue }) {
    const [opening, setOpening] = useState(null);
    const [error, setError] = useState('');
    const open = async project => {
        if (project.id === context.project?.id) { onContinue(); return; }
        setOpening(project.id); setError('');
        try {
            await axios.post(endpoints.selectProject.replace('__PROJECT__', project.id));
            window.location.assign(window.location.pathname + '?view=overview');
        } catch { setError('Could not open this project. Please try again.'); setOpening(null); }
    };
    return <Stack spacing={3}>
        <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ sm: 'center' }} gap={2}>
            <Box><Typography variant="overline" color="text.secondary">{identity.team?.name}</Typography><Typography variant="h4">Your projects</Typography><Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>Choose what you’re building. Each project has its own goals, documents, and tasks. You can connect GitHub later if your team uses it.</Typography></Box>
            {identity.permissions.manage && <Button variant="contained" onClick={onCreate}>+ New project</Button>}
        </Stack>
        {error && <Alert severity="error">{error}</Alert>}
        <Paper variant="outlined" sx={{ p: 2.5, bgcolor: '#f2effa' }}><Typography variant="subtitle2">A project is the home for your work</Typography><Typography variant="body2" color="text.secondary" sx={{ mt: .5 }}>A project is something you’re working on, such as improving employee onboarding. Goals describe success; tasks are the individual actions that get you there.</Typography></Paper>
        {!context.projects.length && <Paper variant="outlined" sx={{ p: 5, textAlign: 'center' }}><Typography variant="h5">What would you like to build?</Typography><Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>Create a project first, then add the goals you want to achieve.</Typography>{identity.permissions.manage ? <Button sx={{ mt: 2 }} variant="contained" onClick={onCreate}>Create your first project</Button> : <Typography sx={{ mt: 2 }}>Ask an Owner or Admin to create a project.</Typography>}</Paper>}
        <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', lg: 'repeat(2, minmax(0, 1fr))' }, gap: 2 }}>
            {context.projects.map(project => <Paper key={project.id} variant="outlined" sx={{ p: 3, display: 'flex', flexDirection: 'column', alignItems: 'flex-start' }}>
                <Chip label="PROJECT" sx={{ mb: 2, color: '#7460b6', bgcolor: '#f2effa', letterSpacing: 1 }} />
                <Typography variant="h5" sx={{ overflowWrap: 'anywhere' }}>{project.name}</Typography>
                <Typography variant="body2" color="text.secondary" sx={{ mt: 1, mb: 2 }}>{project.description || 'A dedicated space for your goals and tasks.'}</Typography>
                <Typography variant="caption" color="text.secondary" sx={{ mt: 'auto', mb: 2 }}>{project.repository_full_name ? `GitHub · ${project.repository_full_name}` : 'Planning locally · GitHub can be connected later'}</Typography>
                <Button variant="outlined" disabled={opening !== null} onClick={() => open(project)}>{opening === project.id ? 'Opening…' : 'Open project →'}</Button>
            </Paper>)}
        </Box>
    </Stack>;
}

export default function ProjectOverview({ project, permissions, onNavigate, onAddGoal, onSelect, onMap }) {
    const nodes = Object.values(project.state.nodes);
    const roots = nodes.filter(node => node.parent_id === null && node.status !== 'rejected');
    const tasks = nodes.filter(node => node.type === 'task' && node.status !== 'rejected');
    const completed = tasks.filter(isCompleted).length;
    const reviews = nodes.filter(node => (['proposed', 'needs_review'].includes(node.status) || node.breakdown === 'proposed')).length;
    const unanswered = Object.values(project.state.decisions).filter(item => !item.answer);
    const questions = unanswered.length;
    const review = nodes.find(node => (['proposed', 'needs_review'].includes(node.status) || node.breakdown === 'proposed') && !blockers(project.state, node.id).length && ancestors(project.state.nodes, node.id).slice(0, -1).every(parent => parent.status === 'approved' && parent.breakdown === 'approved'));
    const readyToPlan = nodes.find(node => node.type === 'goal' && node.status === 'approved' && node.breakdown === 'none' && !nodes.some(child => child.parent_id === node.id));
    const step = !roots.length
        ? { title: 'Add your first goal', description: 'Describe one result you want this project to achieve. You can add more goals later.', label: 'Add a goal', action: onAddGoal, disabled: !permissions.edit }
        : questions ? { title: 'Answer an open question', description: 'Part of the plan is waiting for a decision. You can read the question and suggested options before deciding.', label: 'Read the question', action: () => onSelect(unanswered[0].node_id) }
        : review ? { title: 'Check a suggested plan', description: 'Read the suggestion, change anything that needs adjusting, and approve it when you agree. Approval means the plan is agreed, not that the work is finished.', label: 'Review the suggestion', action: () => onSelect(review.id) }
        : readyToPlan ? { title: 'Break a goal into smaller steps', description: 'Open an agreed goal and ask for suggested steps. Review those steps before approving them.', label: 'Open the goal', action: () => onSelect(readyToPlan.id) }
        : { title: 'Review progress with your team', description: 'Open a goal to check its tasks. Mark work complete when it is done; larger goals wait for their required steps to finish.', label: 'View goals and tasks', action: () => onNavigate('map') };
    return <Stack spacing={3}>
        <Paper variant="outlined" sx={{ p: { xs: 2.5, md: 3.5 }, background: 'linear-gradient(120deg, #f0ebfb, #fff 80%)' }}>
            <Typography variant="overline" color="primary">Project overview</Typography><Typography variant="h4" sx={{ mt: .5 }}>{project.name}</Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mt: 1, maxWidth: 700 }}>{project.description || 'Define what success looks like, break it into goals, and review the work before publishing.'}</Typography>
            <Stack direction="row" gap={1} flexWrap="wrap" sx={{ mt: 2.5 }}><Button variant="contained" onClick={() => onNavigate('map')}>See goals and tasks →</Button>{permissions.edit && <Button variant="outlined" onClick={onAddGoal}>+ Write a goal</Button>}<Button variant="outlined" onClick={() => onNavigate('ai')}>Suggest goals from documents</Button></Stack>
        </Paper>
        <Paper variant="outlined" sx={{ p: 3, borderColor: '#d7cee9' }}><Typography variant="overline" color="primary">What to do next</Typography><Typography variant="h6" sx={{ mt: .5 }}>{step.title}</Typography><Typography variant="body2" color="text.secondary" sx={{ my: 1, maxWidth: 760 }}>{step.description}</Typography><Button variant="contained" disabled={step.disabled} onClick={step.action}>{step.label} →</Button></Paper>
        <Paper component="details" variant="outlined" sx={{ p: 2.5 }}><Typography component="summary" variant="subtitle2" sx={{ cursor: 'pointer' }}>New here? See how a project becomes a practical plan</Typography><Box sx={{ mt: 2 }}><Typography variant="body2" color="text.secondary">For example, an HR team might plan a better experience for new employees:</Typography><Box component="ol" sx={{ pl: 3, '& li': { mb: 1.5 }, fontSize: 14 }}><li><strong>Project: Employee onboarding.</strong> The place for the team’s documents, goals, and tasks.</li><li><strong>Goal: Every new starter feels ready for day one.</strong> The result you want to achieve.</li><li><strong>Smaller goal: Send useful information before they join.</strong> One part of reaching that result.</li><li><strong>Task: Prepare and send a welcome pack.</strong> A specific action, with a checklist of what “done” means.</li></Box><Typography variant="body2" color="text.secondary">Add background documents → agree the goals → review the smaller steps → do the work → mark it complete. You can share approved work with GitHub, a work-tracking tool used by many software teams, if your team needs it.</Typography><Alert severity="info" sx={{ mt: 2 }}>You choose what to approve. Agreeing a plan and finishing the work are two separate actions. AI suggestions link to your selected documents. Review their meaning and assumptions; matching a quote does not guarantee the suggestion is correct.</Alert></Box></Paper>
        <Box sx={{ display: 'grid', gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)' }, gap: 2 }}>
            {[['Goals', roots.length, 'map'], ['Tasks completed', `${completed} / ${tasks.length}`, 'map'], ['Plans to review', reviews, 'reviews'], ['Questions to answer', questions, 'reviews']].map(([label, value, target]) => <Paper key={label} variant="outlined" sx={{ p: 2 }}><Typography variant="body2" color="text.secondary">{label}</Typography><Typography sx={{ fontSize: 26, fontWeight: 650, my: .5 }}>{value}</Typography><Button size="small" onClick={() => onNavigate(target)}>View →</Button></Paper>)}
        </Box>
        <Box><Typography variant="h6">Goals in this project</Typography><Typography variant="body2" color="text.secondary">Each goal describes an outcome. Open it to read the plan and available actions, or explore its branches on the map.</Typography></Box>
        {!roots.length && <Paper variant="outlined" sx={{ p: 4, textAlign: 'center' }}><Typography variant="h6">Start with an outcome</Typography><Typography variant="body2" color="text.secondary" sx={{ my: 1 }}>What should this project achieve? Add your first goal, then break it into smaller pieces.</Typography><Button variant="contained" disabled={!permissions.edit} onClick={onAddGoal}>Add first goal</Button></Paper>}
        {roots.map(root => {
            const branch = nodes.filter(node => { let current = node; while (current) { if (current.id === root.id) return true; current = project.state.nodes[current.parent_id]; } return false; });
            const branchTasks = branch.filter(node => node.type === 'task' && node.status !== 'rejected');
            const done = branchTasks.filter(isCompleted).length;
            return <Paper key={root.id} variant="outlined" sx={{ p: 2.5 }}><Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" gap={2}><Box sx={{ flex: 1, minWidth: 0 }}><Stack direction="row" gap={1} alignItems="center" flexWrap="wrap"><Typography variant="h6">{root.title}</Typography><Chip label={stateLabels[nodeStatus(project.state, root)]} /></Stack><Typography variant="body2" color="text.secondary" sx={{ mt: .5 }}>{done} of {branchTasks.length} tasks complete · {branch.filter(node => node.type === 'goal').length - 1} smaller goals · {(root.document_ids || []).length} documents</Typography><LinearProgress variant="determinate" value={branchTasks.length ? done / branchTasks.length * 100 : 0} aria-label={`${root.title} task completion`} sx={{ mt: 1.5, maxWidth: 360, height: 5, borderRadius: 1 }} /></Box><Stack direction="row" alignItems="center" gap={1}><Button onClick={() => onSelect(root.id)}>Review goal</Button><Button variant="outlined" onClick={() => onMap(root.id)}>View on map →</Button></Stack></Stack></Paper>;
        })}
        <Paper variant="outlined" sx={{ p: 2.5 }}><Typography variant="h6">Sharing with your delivery team</Typography><Typography variant="body2" color="text.secondary" sx={{ mt: .5 }}>{project.repository_full_name ? `${project.repository_full_name} · ${project.repository_private ? 'Private' : 'Public'}` : 'You can plan and review work here without GitHub. If your team uses GitHub to track delivery, an administrator can connect a repository (a shared project space) later.'}</Typography>{(project.repository_id || permissions.manage) && <Button sx={{ mt: 1 }} onClick={() => onNavigate(project.repository_id ? 'publish' : 'settings')}>{project.repository_id ? 'Review publishing →' : 'Connect GitHub · optional →'}</Button>}</Paper>
    </Stack>;
}
