'use strict';

(() => {
    const body = document.body;
    let csrf = body.dataset.csrf || '';
    const state = {
        ideas: [],
        currentIdeaId: null,
        bundle: null,
        candidates: [],
        activeTab: 'idea',
    };

    const qs = (selector, root = document) => root.querySelector(selector);
    const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    const element = (tag, options = {}, children = []) => {
        const node = document.createElement(tag);
        Object.entries(options).forEach(([key, value]) => {
            if (key === 'className') node.className = value;
            else if (key === 'text') node.textContent = String(value ?? '');
            else if (key === 'dataset') Object.assign(node.dataset, value);
            else if (key === 'attrs') Object.entries(value).forEach(([name, val]) => node.setAttribute(name, String(val)));
            else if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2).toLowerCase(), value);
            else node[key] = value;
        });
        const list = Array.isArray(children) ? children : [children];
        list.filter((child) => child !== null && child !== undefined).forEach((child) => {
            node.append(child instanceof Node ? child : document.createTextNode(String(child)));
        });
        return node;
    };

    const formatDate = (value) => {
        if (!value) return 'Unknown date';
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
    };

    const api = async (route, options = {}) => {
        const method = options.method || 'GET';
        const headers = new Headers(options.headers || {});
        if (method !== 'GET') headers.set('X-CSRF-Token', csrf);
        let bodyData = options.body;
        if (bodyData && !(bodyData instanceof FormData)) {
            headers.set('Content-Type', 'application/json');
            bodyData = JSON.stringify(bodyData);
        }
        const response = await fetch(`?api=${encodeURIComponent(route)}${options.query || ''}`, {
            method,
            headers,
            body: bodyData,
            credentials: 'same-origin',
        });
        let data;
        try { data = await response.json(); }
        catch { data = { ok: false, error: `Server returned HTTP ${response.status}.` }; }
        if (!response.ok || !data.ok) throw new Error(data.error || `Request failed with HTTP ${response.status}.`);
        return data;
    };

    const setLoading = (active, message = 'Working…') => {
        const overlay = qs('#loading-overlay');
        if (!overlay) return;
        qs('#loading-message').textContent = message;
        overlay.hidden = !active;
    };

    const toast = (message, type = 'success') => {
        const region = qs('#toast-region');
        if (!region) return;
        const node = element('div', { className: `toast ${type === 'error' ? 'error' : ''}`, text: message });
        region.append(node);
        window.setTimeout(() => node.remove(), 4200);
    };

    const listBlock = (title, values) => {
        const block = element('div', { className: 'section-block' });
        block.append(element('h4', { text: title }));
        if (!Array.isArray(values) || values.length === 0) {
            block.append(element('p', { text: 'Not yet defined.' }));
            return block;
        }
        const list = element('ul', { className: 'compact-list' });
        values.forEach((value) => list.append(element('li', { text: value })));
        block.append(list);
        return block;
    };

    const textBlock = (title, text) => {
        const block = element('div', { className: 'section-block' });
        block.append(element('h4', { text: title }), element('p', { text: text || 'Not yet defined.' }));
        return block;
    };

    const openNewIdea = () => {
        const dialog = qs('#new-idea-dialog');
        if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
    };

    const closeNewIdea = () => {
        const dialog = qs('#new-idea-dialog');
        if (dialog?.open) dialog.close();
    };

    const loadIdeas = async (preferredId = null) => {
        const data = await api('ideas');
        state.ideas = data.ideas || [];
        renderIdeaList();
        const id = preferredId || state.currentIdeaId || state.ideas[0]?.id || null;
        if (id) await selectIdea(id);
        else renderNoIdea();
    };

    const renderIdeaList = () => {
        const list = qs('#idea-list');
        if (!list) return;
        list.replaceChildren();
        if (state.ideas.length === 0) {
            list.append(element('div', { className: 'empty-list', text: 'No case files yet.' }));
            return;
        }
        state.ideas.forEach((idea) => {
            const button = element('button', {
                className: `idea-item ${idea.id === state.currentIdeaId ? 'active' : ''}`,
                type: 'button',
                onclick: () => selectIdea(idea.id).catch(handleError),
            }, [
                element('strong', { text: idea.title || 'Untitled idea' }),
                element('span', { text: `${String(idea.status || 'captured').replaceAll('_', ' ')} · ${formatDate(idea.updated_at)}` }),
            ]);
            list.append(button);
        });
    };

    const renderNoIdea = () => {
        state.currentIdeaId = null;
        state.bundle = null;
        qs('#empty-state').hidden = false;
        qs('#case-workspace').hidden = true;
    };

    const selectIdea = async (id) => {
        state.currentIdeaId = id;
        const data = await api('idea', { query: `&id=${encodeURIComponent(id)}` });
        state.bundle = data.bundle;
        renderIdeaList();
        renderBundle();
    };

    const renderBundle = () => {
        const bundle = state.bundle;
        if (!bundle) return renderNoIdea();
        qs('#empty-state').hidden = true;
        qs('#case-workspace').hidden = false;
        qs('#case-title').textContent = bundle.idea.title || 'Untitled idea';
        qs('#case-status').textContent = String(bundle.idea.status || 'captured').replaceAll('_', ' ');
        qs('#original-text').textContent = bundle.idea.original_text || '';
        qs('#original-hash').textContent = `sha256 ${String(bundle.idea.hash || '').slice(0, 12)}…`;
        renderCounts(bundle.counts || {});
        renderInterpretation();
        renderClaims();
        renderCandidates();
        renderSources();
        renderChallenges();
        renderActivity();
        switchTab(state.activeTab);
    };

    const renderCounts = (counts) => {
        const row = qs('#case-counts');
        row.replaceChildren();
        [
            `${counts.sources || 0} sources`,
            `${counts.trusted_sources || 0} trusted`,
            `${counts.open_challenges || 0} open challenges`,
        ].forEach((label) => row.append(element('span', { className: 'count-chip', text: label })));
    };

    const renderInterpretation = () => {
        const holder = qs('#interpretation-content');
        holder.replaceChildren();
        const version = state.bundle?.current_version;
        if (!version || version.kind === 'original') {
            holder.append(element('div', { className: 'empty-list', text: 'No separate interpretation exists yet. Generate one, compare it with the original, and accept it only when it matches your meaning.' }));
            return;
        }
        holder.append(
            textBlock('Precise restatement', version.summary),
            textBlock('Problem', version.problem),
            textBlock('Proposed mechanism', version.mechanism),
            textBlock('Intended result', version.intended_result),
            listBlock('Assumptions', version.assumptions),
            listBlock('Ambiguities', version.ambiguities),
            listBlock('Success criteria', version.success_criteria),
            listBlock('Failure criteria', version.failure_criteria),
        );
        const actions = element('div', { className: 'action-row' });
        const accepted = Boolean(version.accepted);
        actions.append(element('span', { className: 'trust-chip', text: accepted ? 'Accepted interpretation' : 'Awaiting user acceptance' }));
        if (!accepted) {
            actions.append(element('button', {
                className: 'button primary',
                type: 'button',
                text: 'Accept this interpretation',
                onclick: () => acceptVersion(version.id),
            }));
        }
        actions.append(element('span', { className: 'type-chip', text: version.model ? `Model: ${version.model}` : 'Deterministic fallback' }));
        holder.append(actions);
    };

    const renderClaims = () => {
        const holder = qs('#claims-list');
        holder.replaceChildren();
        const claims = state.bundle?.current_claims || [];
        if (claims.length === 0) {
            holder.append(element('div', { className: 'empty-list', text: 'Claims will appear after the idea is interpreted.' }));
            return;
        }
        claims.slice().reverse().forEach((claim) => {
            const card = element('article', { className: 'item-card' });
            card.append(element('h4', { text: claim.text }));
            const meta = element('div', { className: 'meta-row' }, [
                element('span', { className: 'type-chip', text: claim.type || 'claim' }),
                element('span', { className: 'trust-chip', text: claim.status || 'unverified' }),
            ]);
            card.append(meta);
            holder.append(card);
        });
    };

    const renderCandidates = () => {
        const holder = qs('#candidate-list');
        if (!holder) return;
        holder.replaceChildren();
        if (state.candidates.length === 0) {
            holder.append(element('div', { className: 'empty-list', text: 'No current search results. Search using the mechanism, problem, academic terminology, or a competing method.' }));
            return;
        }
        const savedIds = new Set((state.bundle?.sources || []).map((source) => source.identifier).filter(Boolean));
        state.candidates.forEach((candidate) => {
            const card = element('article', { className: 'item-card' });
            card.append(element('h4', { text: candidate.title || 'Untitled source' }));
            if (candidate.authors?.length) card.append(element('p', { text: candidate.authors.join(', ') }));
            card.append(element('p', { text: candidate.summary || 'No summary available.' }));
            const meta = element('div', { className: 'meta-row' }, [
                element('span', { className: 'type-chip', text: candidate.source_type || 'source' }),
                element('span', { className: 'type-chip', text: candidate.provider || 'unknown provider' }),
                candidate.year ? element('span', { className: 'type-chip', text: candidate.year }) : null,
                candidate.metrics?.citations !== undefined ? element('span', { className: 'type-chip', text: `${candidate.metrics.citations} citations` }) : null,
                candidate.metrics?.stars !== undefined ? element('span', { className: 'type-chip', text: `${candidate.metrics.stars} stars` }) : null,
            ]);
            card.append(meta);
            const actions = element('div', { className: 'action-row' });
            if (candidate.url) actions.append(element('a', { className: 'source-link', href: candidate.url, target: '_blank', rel: 'noopener noreferrer', text: 'Open source ↗' }));
            const alreadySaved = candidate.identifier && savedIds.has(candidate.identifier);
            actions.append(element('button', {
                className: alreadySaved ? 'button ghost' : 'button secondary',
                type: 'button',
                text: alreadySaved ? 'Already saved' : 'Save as candidate',
                disabled: alreadySaved,
                onclick: () => saveCandidate(candidate),
            }));
            card.append(actions);
            holder.append(card);
        });
    };

    const renderSources = () => {
        const holder = qs('#source-list');
        holder.replaceChildren();
        const sources = state.bundle?.sources || [];
        if (sources.length === 0) {
            holder.append(element('div', { className: 'empty-list', text: 'No sources saved. Search prior work or upload an exact PDF.' }));
            return;
        }
        sources.forEach((source) => {
            const card = element('article', { className: 'item-card' });
            card.append(element('h4', { text: source.title || 'Untitled source' }));
            card.append(element('p', { text: source.summary || 'No source note available.' }));
            const meta = element('div', { className: 'meta-row' }, [
                element('span', { className: 'type-chip', text: source.source_type || 'source' }),
                element('span', { className: 'trust-chip', text: source.trust_status || 'candidate' }),
                element('span', { className: 'type-chip', text: source.provider || 'manual' }),
                source.file_sha256 ? element('span', { className: 'hash-chip', text: `sha256 ${source.file_sha256.slice(0, 12)}…` }) : null,
            ]);
            card.append(meta);
            const actions = element('div', { className: 'action-row' });
            if (source.source_type === 'uploaded_pdf') {
                actions.append(element('a', { className: 'source-link', href: `?download_source=${encodeURIComponent(source.id)}`, target: '_blank', rel: 'noopener', text: 'Open preserved PDF ↗' }));
            } else if (source.url) {
                actions.append(element('a', { className: 'source-link', href: source.url, target: '_blank', rel: 'noopener noreferrer', text: 'Open source ↗' }));
            }
            const select = element('select', { attrs: { 'aria-label': `Trust status for ${source.title}` } });
            ['candidate', 'trusted', 'contested', 'superseded', 'retracted', 'excluded'].forEach((value) => {
                select.append(element('option', { value, text: value.replaceAll('_', ' '), selected: source.trust_status === value }));
            });
            select.addEventListener('change', () => updateSourceTrust(source.id, select.value));
            actions.append(select);
            card.append(actions);
            holder.append(card);
        });
    };

    const severityRank = { potentially_fatal: 4, major: 3, material: 2, minor: 1 };
    const renderChallenges = () => {
        const strongest = qs('#strongest-challenge');
        const holder = qs('#challenge-list');
        strongest.replaceChildren();
        holder.replaceChildren();
        const challenges = state.bundle?.challenges || [];
        if (challenges.length === 0) {
            holder.append(element('div', { className: 'empty-list', text: 'No structured challenges yet. Generate pushback only after the system has a clear interpretation of the idea.' }));
            return;
        }
        const sorted = challenges.slice().sort((a, b) => (severityRank[b.severity] || 0) - (severityRank[a.severity] || 0));
        const lead = sorted[0];
        const leadCard = element('article', { className: 'strongest-card' });
        leadCard.append(
            element('p', { className: 'eyebrow', text: 'Strongest unresolved challenge' }),
            element('h3', { text: lead.challenge }),
            element('p', { text: lead.why_it_matters || '' }),
            element('div', { className: 'meta-row' }, [
                element('span', { className: `severity-chip ${lead.severity}`, text: String(lead.severity || '').replaceAll('_', ' ') }),
                element('span', { className: 'type-chip', text: String(lead.evidence_status || '').replaceAll('_', ' ') }),
            ]),
            element('div', { className: 'challenge-details' }, [
                element('div', {}, [element('strong', { text: 'Cheapest resolution test' }), element('span', { text: lead.resolution_test || 'Not specified.' })]),
            ]),
        );
        strongest.append(leadCard);
        sorted.forEach((challenge) => {
            const card = element('article', { className: 'item-card' });
            card.append(element('h4', { text: challenge.challenge }));
            const details = element('div', { className: 'challenge-details' });
            details.append(
                element('div', {}, [element('strong', { text: 'Claim under challenge' }), element('span', { text: challenge.claim || 'Not specified.' })]),
                element('div', {}, [element('strong', { text: 'Why it matters' }), element('span', { text: challenge.why_it_matters || 'Not specified.' })]),
                element('div', {}, [element('strong', { text: 'Resolution test' }), element('span', { text: challenge.resolution_test || 'Not specified.' })]),
            );
            card.append(details, element('div', { className: 'meta-row' }, [
                element('span', { className: `severity-chip ${challenge.severity}`, text: String(challenge.severity || '').replaceAll('_', ' ') }),
                element('span', { className: 'type-chip', text: challenge.category || 'challenge' }),
                element('span', { className: 'type-chip', text: String(challenge.evidence_status || '').replaceAll('_', ' ') }),
            ]));
            holder.append(card);
        });
    };

    const renderActivity = () => {
        const holder = qs('#activity-list');
        holder.replaceChildren();
        const activities = state.bundle?.activities || [];
        if (activities.length === 0) {
            holder.append(element('div', { className: 'empty-list', text: 'No activity recorded.' }));
            return;
        }
        activities.forEach((activity) => {
            const item = element('div', { className: 'timeline-item' });
            item.append(
                element('time', { text: formatDate(activity.created_at) }),
                element('strong', { text: String(activity.type || 'activity').replaceAll('.', ' › ') }),
                element('p', { text: activity.message || '' }),
            );
            holder.append(item);
        });
    };

    const switchTab = (tab) => {
        state.activeTab = tab;
        qsa('.tab-button').forEach((button) => button.classList.toggle('active', button.dataset.tab === tab));
        qsa('.tab-panel').forEach((panel) => panel.classList.toggle('active', panel.dataset.panel === tab));
    };

    const refreshBundle = async () => {
        if (!state.currentIdeaId) return;
        const data = await api('idea', { query: `&id=${encodeURIComponent(state.currentIdeaId)}` });
        state.bundle = data.bundle;
        renderBundle();
    };

    const openEditInterpretation = () => {
        if (!state.bundle) return;
        const version = state.bundle.current_version || {};
        const set = (selector, value) => { const field = qs(selector); if (field) field.value = value || ''; };
        set('#edit-summary', version.kind === 'original' ? state.bundle.idea.original_text : version.summary);
        set('#edit-problem', version.problem);
        set('#edit-mechanism', version.mechanism);
        set('#edit-result', version.intended_result);
        set('#edit-assumptions', (version.assumptions || []).join('\n'));
        set('#edit-ambiguities', (version.ambiguities || []).join('\n'));
        set('#edit-success', (version.success_criteria || []).join('\n'));
        set('#edit-failure', (version.failure_criteria || []).join('\n'));
        set('#edit-claims', (state.bundle.current_claims || []).map((claim) => claim.text).join('\n'));
        const dialog = qs('#edit-interpretation-dialog');
        if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
    };

    const closeEditInterpretation = () => {
        const dialog = qs('#edit-interpretation-dialog');
        if (dialog?.open) dialog.close();
    };

    const saveManualInterpretation = async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const fields = Object.fromEntries(new FormData(form).entries());
        fields.idea_id = state.currentIdeaId;
        setLoading(true, 'Saving the corrected interpretation as a new version…');
        try {
            const data = await api('manual-interpretation', { method: 'POST', body: fields });
            state.bundle = data.bundle;
            closeEditInterpretation();
            renderBundle();
            toast('Corrected interpretation saved as a new version. Review and accept it when ready.');
        } finally { setLoading(false); }
    };

    const createIdea = async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const formData = new FormData(form);
        setLoading(true, 'Creating the immutable case file…');
        try {
            const data = await api('ideas', {
                method: 'POST',
                body: { title: formData.get('title'), original_text: formData.get('original_text') },
            });
            form.reset();
            closeNewIdea();
            await loadIdeas(data.idea.id);
            toast('Original idea preserved.');
        } finally { setLoading(false); }
    };

    const generateInterpretation = async () => {
        if (!state.currentIdeaId) return;
        setLoading(true, 'Separating the problem, mechanism, claims, and ambiguities…');
        try {
            const data = await api('interpret', { method: 'POST', body: { idea_id: state.currentIdeaId } });
            state.bundle = data.bundle;
            renderBundle();
            toast(data.mode === 'openai' ? 'Interpretation generated for your review.' : 'Fallback interpretation generated. Add an OpenAI key for deeper analysis.');
        } finally { setLoading(false); }
    };

    const acceptVersion = async (versionId) => {
        setLoading(true, 'Recording your accepted interpretation…');
        try {
            const data = await api('accept-version', { method: 'POST', body: { idea_id: state.currentIdeaId, version_id: versionId, accepted: true } });
            state.bundle = data.bundle;
            renderBundle();
            toast('Interpretation accepted. The original remains unchanged.');
        } finally { setLoading(false); }
    };

    const runResearch = async (event) => {
        event.preventDefault();
        const query = qs('#research-query').value.trim();
        if (!query) return;
        setLoading(true, 'Searching papers and public repositories…');
        try {
            const data = await api('research', { method: 'POST', body: { idea_id: state.currentIdeaId, query, per_provider: 6 } });
            state.candidates = data.result.candidates || [];
            const summary = qs('#research-summary');
            const errors = data.result.errors || [];
            summary.hidden = false;
            summary.classList.toggle('warning', errors.length > 0);
            summary.textContent = `${state.candidates.length} unique candidates found across available providers.${errors.length ? ` ${errors.length} provider(s) reported an error; inspect the activity record before treating coverage as complete.` : ''}`;
            renderCandidates();
            await refreshBundle();
            switchTab('prior-work');
            toast('Prior-work search completed. Results are candidates, not verified evidence.');
        } finally { setLoading(false); }
    };

    const saveCandidate = async (candidate) => {
        setLoading(true, 'Saving the source as a candidate…');
        try {
            const data = await api('save-source', { method: 'POST', body: { idea_id: state.currentIdeaId, candidate } });
            state.bundle = data.bundle;
            renderBundle();
            toast('Source saved as a candidate. Review it before promotion.');
        } finally { setLoading(false); }
    };

    const updateSourceTrust = async (sourceId, trustStatus) => {
        setLoading(true, 'Updating the source status…');
        try {
            const data = await api('source-trust', { method: 'POST', body: { idea_id: state.currentIdeaId, source_id: sourceId, trust_status: trustStatus } });
            state.bundle = data.bundle;
            renderBundle();
            switchTab('sources');
            toast(`Source marked ${trustStatus.replaceAll('_', ' ')}.`);
        } finally { setLoading(false); }
    };

    const uploadSource = async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const formData = new FormData(form);
        formData.set('idea_id', state.currentIdeaId);
        setLoading(true, 'Hashing and preserving the exact PDF…');
        try {
            const data = await api('upload-source', { method: 'POST', body: formData });
            state.bundle = data.bundle;
            form.reset();
            renderBundle();
            switchTab('sources');
            toast('Exact PDF preserved with a SHA-256 hash.');
        } finally { setLoading(false); }
    };

    const generateChallenges = async () => {
        if (!state.currentIdeaId) return;
        setLoading(true, 'Attacking the strongest reasonable version of the idea…');
        try {
            const data = await api('challenge', { method: 'POST', body: { idea_id: state.currentIdeaId } });
            state.bundle = data.bundle;
            renderBundle();
            switchTab('challenges');
            toast(data.mode === 'openai' ? 'Structured pushback generated.' : 'Fallback challenges generated. Add an OpenAI key for idea-specific criticism.');
        } finally { setLoading(false); }
    };

    const handleError = (error) => {
        console.error(error);
        setLoading(false);
        toast(error instanceof Error ? error.message : 'An unexpected error occurred.', 'error');
    };

    const bindAuthenticatedApp = () => {
        qsa('[data-action="new-idea"]').forEach((button) => button.addEventListener('click', openNewIdea));
        qs('#new-idea-button')?.addEventListener('click', openNewIdea);
        qsa('[data-action="close-dialog"]').forEach((button) => button.addEventListener('click', closeNewIdea));
        qs('#new-idea-form')?.addEventListener('submit', (event) => createIdea(event).catch(handleError));
        qs('#interpret-button')?.addEventListener('click', () => generateInterpretation().catch(handleError));
        qs('#edit-interpretation-button')?.addEventListener('click', openEditInterpretation);
        qsa('[data-action="close-edit-dialog"]').forEach((button) => button.addEventListener('click', closeEditInterpretation));
        qs('#edit-interpretation-form')?.addEventListener('submit', (event) => saveManualInterpretation(event).catch(handleError));
        qs('#research-form')?.addEventListener('submit', (event) => runResearch(event).catch(handleError));
        qs('#upload-form')?.addEventListener('submit', (event) => uploadSource(event).catch(handleError));
        qs('#challenge-button')?.addEventListener('click', () => generateChallenges().catch(handleError));
        qsa('.tab-button').forEach((button) => button.addEventListener('click', () => switchTab(button.dataset.tab)));
        qs('#logout-button')?.addEventListener('click', async () => {
            try { await api('logout', { method: 'POST', body: {} }); window.location.reload(); }
            catch (error) { handleError(error); }
        });
        loadIdeas().catch(handleError);
    };

    const bindLogin = () => {
        const form = qs('#login-form');
        form?.addEventListener('submit', async (event) => {
            event.preventDefault();
            qs('#login-error').textContent = '';
            const password = qs('#login-password').value;
            try {
                const data = await api('login', { method: 'POST', body: { password } });
                if (data.csrf) csrf = data.csrf;
                window.location.reload();
            } catch (error) {
                qs('#login-error').textContent = error instanceof Error ? error.message : 'Login failed.';
            }
        });
    };

    if (body.dataset.authenticated === '1') bindAuthenticatedApp();
    else bindLogin();
})();
