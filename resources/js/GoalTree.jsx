import React, { useEffect, useRef, useState } from 'react';
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { CSS2DObject, CSS2DRenderer } from 'three/addons/renderers/CSS2DRenderer.js';
import { Alert, Box } from '@mui/material';
import { completionInfo, isCompleted, nodeStatus, stateColors, stateLabels } from './workflow';

export default function GoalTree({ state, expanded, onSelect, onToggle, cameraState, viewCommand, dependencies }) {
    const host = useRef(null);
    const controller = useRef(null);
    const handlers = useRef({ onSelect, onToggle });
    const [failed, setFailed] = useState(false);
    handlers.current = { onSelect, onToggle };

    useEffect(() => {
        const container = host.current;
        if (!container) return;
        let renderer;
        try { renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true }); }
        catch { setFailed(true); return; }
        const scene = new THREE.Scene();
        const camera = new THREE.PerspectiveCamera(38, 1, 0.1, 500);
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        renderer.setClearColor(0xf6f7f9, 0);
        container.appendChild(renderer.domElement);
        const labels = new CSS2DRenderer();
        Object.assign(labels.domElement.style, { position: 'absolute', inset: '0', pointerEvents: 'none' });
        container.appendChild(labels.domElement);
        const controls = new OrbitControls(camera, renderer.domElement);
        controls.enableDamping = true;
        controls.enableRotate = false;
        controls.minDistance = 10;
        controls.maxDistance = 160;
        controls.mouseButtons.LEFT = THREE.MOUSE.PAN;
        controls.mouseButtons.RIGHT = THREE.MOUSE.PAN;
        const nodes = Object.values(state.nodes);
        const byParent = new Map();
        for (const node of nodes) {
            const key = node.parent_id ?? '_root';
            byParent.set(key, [...(byParent.get(key) || []), node]);
        }
        const positions = new Map();
        const cards = [];
        let leaf = 0;
        function layout(node, depth) {
            const children = expanded.includes(node.id) ? byParent.get(node.id) || [] : [];
            const x = children.length ? children.map(child => layout(child, depth + 1)).reduce((a, b) => a + b, 0) / children.length : leaf++ * 7;
            positions.set(node.id, new THREE.Vector3(x, -depth * 5, depth * 0.15));
            return x;
        }
        for (const node of byParent.get('_root') || []) layout(node, 0);
        const centerX = (Math.max(1, leaf) - 1) * 3.5;
        for (const position of positions.values()) position.x -= centerX;
        function connect(start, end, color, dashed = false) {
            const curve = dashed
                ? new THREE.CubicBezierCurve3(start.clone().add(new THREE.Vector3(0, 1.6, 0.3)), new THREE.Vector3(start.x, start.y + 5, 0.6), new THREE.Vector3(end.x, end.y + 5, 0.6), end.clone().add(new THREE.Vector3(0, 1.6, 0.3)))
                : new THREE.CubicBezierCurve3(start, new THREE.Vector3(start.x, (start.y + end.y) / 2, start.z), new THREE.Vector3(end.x, (start.y + end.y) / 2, end.z), end);
            if (dashed) {
                const geometry = new THREE.BufferGeometry().setFromPoints(curve.getPoints(50));
                const line = new THREE.Line(geometry, new THREE.LineDashedMaterial({ color, dashSize: 0.3, gapSize: 0.2 }));
                line.computeLineDistances();
                scene.add(line);
                const arrow = new THREE.ArrowHelper(curve.getTangent(0.6).normalize(), curve.getPoint(0.6), 0.65, color, 0.35, 0.28);
                scene.add(arrow);
            } else {
                scene.add(new THREE.Mesh(new THREE.TubeGeometry(curve, 40, 0.035, 6, false), new THREE.MeshBasicMaterial({ color })));
            }
        }
        for (const node of nodes) {
            const position = positions.get(node.id);
            if (!position) continue;
            const parent = positions.get(node.parent_id);
            if (parent) connect(parent, position, isCompleted(node) ? 0x26876c : 0x8d9bb3);
            if (dependencies) for (const id of node.dependencies) {
                if (positions.has(id)) connect(positions.get(id), position, 0x8272cb, true);
            }
            const status = nodeStatus(state, node);
            const color = stateColors[status];
            const geometry = node.type === 'goal' ? new THREE.OctahedronGeometry(0.2) : new THREE.BoxGeometry(0.28, 0.28, 0.28);
            const mesh = new THREE.Mesh(geometry, new THREE.MeshBasicMaterial({ color }));
            mesh.position.copy(position);
            scene.add(mesh);
            const card = document.createElement('div');
            card.className = `goal-card ${node.type === 'goal' ? 'is-goal' : ''}`;
            card.style.setProperty('--node-color', color);
            const select = document.createElement('button');
            select.className = 'goal-card-select';
            const meta = document.createElement('span');
            meta.className = 'goal-card-meta';
            meta.textContent = `${node.type === 'goal' ? '◇ GOAL' : '☑ TASK'} · ${node.parent_id === null ? 'TOP-LEVEL GOAL' : `REV ${node.revision}`}`;
            const title = document.createElement('span');
            title.className = 'goal-card-title';
            title.textContent = node.title;
            const badge = document.createElement('span');
            badge.className = 'goal-card-status';
            badge.textContent = `${['completed', 'approved', 'published'].includes(status) ? '✓' : status === 'blocked' ? '!' : '○'} ${stateLabels[status]}`;
            select.append(meta, title, badge);
            const completion = completionInfo(state, node);
            if (completion.children.length || completion.prerequisites.length) {
                const progress = document.createElement('span');
                progress.className = 'goal-card-progress';
                progress.textContent = completion.children.length
                    ? `${completion.completedChildren}/${completion.children.length} children complete`
                    : `${completion.prerequisites.length - completion.unfinishedPrerequisites.length}/${completion.prerequisites.length} prerequisites complete`;
                select.append(progress);
            }
            select.setAttribute('aria-label', `${node.title}, ${stateLabels[status]}. Open overview`);
            select.addEventListener('click', () => handlers.current.onSelect(node.id));
            card.append(select);
            const children = byParent.get(node.id) || [];
            if (children.length) {
                const toggle = document.createElement('button');
                toggle.className = 'goal-card-toggle';
                toggle.textContent = expanded.includes(node.id) ? '−' : `+ ${children.length}`;
                toggle.setAttribute('aria-label', `${expanded.includes(node.id) ? 'Collapse' : 'Expand'} ${node.title}`);
                toggle.setAttribute('aria-expanded', expanded.includes(node.id));
                toggle.addEventListener('click', () => handlers.current.onToggle(node.id));
                card.append(toggle);
            }
            const anchor = document.createElement('div');
            anchor.appendChild(card);
            cards.push(card);
            const label = new CSS2DObject(anchor);
            label.position.copy(position);
            scene.add(label);
        }
        const box = new THREE.Box3();
        for (const position of positions.values()) box.expandByPoint(position);
        function fit(id) {
            const target = id && positions.get(id) ? positions.get(id).clone() : box.isEmpty() ? new THREE.Vector3() : box.getCenter(new THREE.Vector3());
            const size = box.isEmpty() ? new THREE.Vector3(10, 10, 0) : box.getSize(new THREE.Vector3());
            const distance = id ? 23 : Math.max((size.y + 9) / 2 / Math.tan(THREE.MathUtils.degToRad(19)), (size.x + 10) / 2 / Math.tan(THREE.MathUtils.degToRad(19)) / camera.aspect, 25);
            controls.target.copy(target);
            camera.position.copy(target).add(new THREE.Vector3(0, 0.6, distance));
            controls.update();
        }
        const resize = () => {
            const { width, height } = container.getBoundingClientRect();
            camera.aspect = width / Math.max(height, 1);
            camera.updateProjectionMatrix();
            renderer.setSize(width, height);
            labels.setSize(width, height);
        };
        resize();
        if (cameraState.current) {
            camera.position.fromArray(cameraState.current.position);
            controls.target.fromArray(cameraState.current.target);
            controls.update();
        } else fit();
        const observer = new ResizeObserver(resize);
        observer.observe(container);
        controller.current = { fit };
        let frame;
        const render = () => {
            frame = requestAnimationFrame(render);
            controls.update();
            const first = controls.target.clone().project(camera);
            const second = controls.target.clone().add(new THREE.Vector3(7, 0, 0)).project(camera);
            const spacing = Math.abs(second.x - first.x) * container.clientWidth / 2;
            const scale = Math.min(1, Math.max(0.25, spacing / 200));
            for (const card of cards) card.style.transform = `scale(${scale})`;
            renderer.render(scene, camera);
            labels.render(scene, camera);
        };
        render();
        const lost = event => { event.preventDefault(); setFailed(true); };
        renderer.domElement.addEventListener('webglcontextlost', lost);
        return () => {
            cameraState.current = { position: camera.position.toArray(), target: controls.target.toArray(), commandNonce: cameraState.current?.commandNonce };
            cancelAnimationFrame(frame);
            observer.disconnect();
            controls.dispose();
            renderer.domElement.removeEventListener('webglcontextlost', lost);
            scene.traverse(object => { object.geometry?.dispose(); if (Array.isArray(object.material)) object.material.forEach(material => material.dispose()); else object.material?.dispose(); });
            renderer.dispose();
            renderer.domElement.remove();
            labels.domElement.remove();
            controller.current = null;
        };
    }, [state, expanded, dependencies, cameraState]);

    useEffect(() => {
        if (viewCommand && cameraState.current?.commandNonce !== viewCommand.nonce && controller.current) {
            controller.current.fit(viewCommand.id);
            cameraState.current = { ...cameraState.current, commandNonce: viewCommand.nonce };
        }
    }, [viewCommand, cameraState]);

    return <Box sx={{ position: 'relative', height: '100%', minHeight: 520 }}>
        <Box ref={host} className="goal-scene" sx={{ position: 'absolute', inset: 0 }} />
        {failed && <Alert severity="warning" sx={{ position: 'absolute', top: 20, left: 20, right: 20 }}>The 3D view is unavailable in this browser. Use the goal outline to navigate and review every node.</Alert>}
    </Box>;
}
