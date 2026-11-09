export const stateLabels = { completed: 'Completed', approved: 'Approved', proposed: 'Needs approval', needs_review: 'Review changes', rejected: 'Rejected', blocked: 'Decision needed', published: 'Published · demo' };
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
    if (node.status !== 'approved') reasons.push('Approve the current revision first.');
    if (blockers(state, node.id).length) reasons.push('Resolve the open human decisions for this branch.');
    if (ancestors(state.nodes, node.id).slice(0, -1).some(parent => parent.status !== 'approved' || parent.breakdown !== 'approved')) reasons.push('Approve the ancestor goals and their breakdowns first.');
    if (node.type === 'goal' && (!children.length || node.breakdown !== 'approved')) reasons.push('Generate and approve a child breakdown first.');
    if (unfinishedChildren.length) reasons.push(`Complete the remaining ${unfinishedChildren.length} child ${unfinishedChildren.length === 1 ? 'node' : 'nodes'} first.`);
    if (unfinishedPrerequisites.length) reasons.push(`Finish ${unfinishedPrerequisites.length} prerequisite ${unfinishedPrerequisites.length === 1 ? 'task' : 'tasks'} first.`);
    return { children, unfinishedChildren, prerequisites, unfinishedPrerequisites, completedChildren: children.length - unfinishedChildren.length, reasons, canComplete: reasons.length === 0 && !isCompleted(node) };
}
