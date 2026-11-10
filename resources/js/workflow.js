export const stateLabels = { completed: 'Completed', approved: 'Approved', proposed: 'Needs approval', needs_review: 'Changes need approval', rejected: 'Rejected', blocked: 'Waiting for a decision', published: 'Published' };
export const stateColors = { completed: '#18765a', approved: '#26876c', proposed: '#a47725', needs_review: '#c36b39', rejected: '#a14d57', blocked: '#c36b39', published: '#6458b8' };

export function ancestors(nodes, id) {
    const chain = [];
    let node = nodes[id];
    while (node) { chain.unshift(node); node = nodes[node.parent_id]; }
    return chain;
}

export function blockers(state, id) {
    const lineage = ancestors(state.nodes, id).map(node => node.id);
    return Object.values(state.decisions).filter(decision => lineage.includes(decision.node_id) && !decision.answer);
}

export function nodeStatus(state, node) {
    if (node.status === 'rejected') return 'rejected';
    if (blockers(state, node.id).length) return 'blocked';
    if (isCompleted(node)) return 'completed';
    if (node.status === 'approved' && node.published_revision === node.revision) return 'published';
    return node.status;
}

export function publishable(state, node) {
    return node.status === 'approved' && !blockers(state, node.id).length
        && ['none', 'approved'].includes(node.breakdown)
        && ancestors(state.nodes, node.id).slice(0, -1).every(parent => parent.status === 'approved' && parent.breakdown === 'approved');
}

export function isCompleted(node) {
    return node.status === 'approved' && node.completed_revision === node.revision;
}

export function completionInfo(state, node) {
    const children = Object.values(state.nodes).filter(child => child.parent_id === node.id && child.status !== 'rejected');
    const unfinishedChildren = children.filter(child => !isCompleted(child));
    const prerequisites = node.dependencies.map(id => state.nodes[id]).filter(Boolean);
    const unfinishedPrerequisites = prerequisites.filter(prerequisite => !isCompleted(prerequisite));
    const reasons = [];
    if (node.status !== 'approved') reasons.push('Approve this version of the plan first.');
    if (blockers(state, node.id).length) reasons.push('Answer the outstanding questions for this work first.');
    if (ancestors(state.nodes, node.id).slice(0, -1).some(parent => parent.status !== 'approved' || parent.breakdown !== 'approved')) reasons.push('Approve the larger goals and their smaller steps first.');
    if (node.type === 'goal' && (!children.length || node.breakdown !== 'approved')) reasons.push('Add and approve the smaller steps needed to achieve this goal first.');
    if (unfinishedChildren.length) reasons.push(`Complete the remaining ${unfinishedChildren.length} smaller ${unfinishedChildren.length === 1 ? 'step' : 'steps'} first.`);
    if (unfinishedPrerequisites.length) reasons.push(`Finish ${unfinishedPrerequisites.length} required ${unfinishedPrerequisites.length === 1 ? 'task' : 'tasks'} first.`);
    return { children, unfinishedChildren, prerequisites, unfinishedPrerequisites, completedChildren: children.length - unfinishedChildren.length, reasons, canComplete: reasons.length === 0 && !isCompleted(node) };
}

export function activityLabel(action) {
    return ({ add_goal: 'Goal added', propose_root: 'First goal suggested', expand: 'Smaller steps suggested', approve: 'Plan approved', approve_breakdown: 'Smaller steps approved', resolve: 'Question answered', edit: 'Plan updated', reject: 'Suggestion declined', complete: 'Work marked complete', reopen: 'Work reopened', document: 'Document saved', prepare_batch: 'Publishing preview prepared', publish: 'Demo publication completed', github_published: 'Work published to GitHub', github_completed: 'Work completed in GitHub', github_reopened: 'Work reopened from GitHub', github_invalid_close_reopened: 'Work reopened because required steps are unfinished' })[action] || action.replaceAll('_', ' ');
}
