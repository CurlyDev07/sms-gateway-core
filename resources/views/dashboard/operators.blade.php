<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Operator Management</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 24px;
            color: #1f2937;
        }

        h1 {
            margin: 0 0 8px 0;
            font-size: 24px;
        }

        .links {
            margin-bottom: 12px;
            font-size: 14px;
        }

        .links a {
            color: #1d4ed8;
            text-decoration: none;
            margin-right: 12px;
        }

        .links a:hover {
            text-decoration: underline;
        }

        .links .logout-button {
            border: none;
            background: none;
            color: #1d4ed8;
            padding: 0;
            cursor: pointer;
            font-size: 14px;
        }

        .links .logout-button:hover {
            text-decoration: underline;
        }

        .muted {
            color: #6b7280;
            margin-bottom: 16px;
        }

        .controls {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
            align-items: end;
        }

        label {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 14px;
        }

        input, select {
            min-width: 220px;
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
        }

        button {
            padding: 9px 14px;
            border: 1px solid #111827;
            background: #111827;
            color: #ffffff;
            border-radius: 4px;
            cursor: pointer;
        }

        .button-secondary {
            background: #ffffff;
            color: #111827;
        }

        .status {
            margin: 8px 0 14px 0;
            min-height: 20px;
            font-size: 14px;
        }

        .error {
            color: #b91c1c;
        }

        .ok {
            color: #065f46;
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid #e5e7eb;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            min-width: 980px;
            font-size: 13px;
        }

        th, td {
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            padding: 8px 10px;
            vertical-align: middle;
        }

        thead th {
            background: #f9fafb;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .role-select {
            min-width: 130px;
            padding: 6px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 13px;
        }

        .action-note {
            font-size: 12px;
            color: #6b7280;
        }

        .create-result {
            margin: 0 0 14px 0;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            background: #f9fafb;
            font-size: 14px;
            display: none;
        }
    </style>
</head>
<body>
<h1>Operator Management</h1>
<div class="links">
    <a href="/dashboard">Dashboard Home</a>
    <a href="/dashboard/sims">SIM Fleet</a>
    <a href="/dashboard/assignments">Assignments</a>
    <a href="/dashboard/migration">Migration</a>
    <a href="/dashboard/messages/status">Message Status</a>
    <a href="/dashboard/operators">Operators</a>
    <a href="/dashboard/audit">Audit Log</a>
    <form method="POST" action="{{ route('logout') }}" style="display:inline;">
        @csrf
        <button type="submit" class="logout-button">Logout</button>
    </form>
</div>
@include('dashboard.partials.operator-context')
<p class="muted">
    Tenant-local operator list powered by <code>GET /dashboard/api/operators</code>.
    Operator creation, role updates, activation toggles, and temporary-password resets are owner-only in this first RBAC management slice.
</p>

<div class="controls">
    <button id="loadButton" type="button">Load Operators</button>
</div>

<div id="createPanel" class="controls" style="display:none;">
    <label>
        name
        <input id="createName" type="text" placeholder="e.g. Jane Operator">
    </label>
    <label>
        email
        <input id="createEmail" type="email" placeholder="e.g. jane@example.com">
    </label>
    <label>
        operator_role
        <select id="createRole">
            <option value="admin" selected>admin</option>
            <option value="support">support</option>
            <option value="owner">owner</option>
        </select>
    </label>
    <button id="createOperatorButton" type="button" class="button-secondary">Create Operator</button>
</div>

<div id="createResult" class="create-result"></div>

<div id="status" class="status muted">No data loaded yet.</div>

<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Email</th>
            <th>Company ID</th>
            <th>Operator Role</th>
            <th>Active</th>
            <th>Action</th>
        </tr>
        </thead>
        <tbody id="operatorRows">
        <tr>
            <td colspan="7" class="muted">No operator rows loaded.</td>
        </tr>
        </tbody>
    </table>
</div>

<script>
    (() => {
        const listPath = '/dashboard/api/operators';
        const rolePathBase = '/dashboard/api/operators';
        const createPath = '/dashboard/api/operators';
        const resetPathBase = '/dashboard/api/operators';
        const activationPathBase = '/dashboard/api/operators';
        const csrfToken = @json(csrf_token());

        const loadButton = document.getElementById('loadButton');
        const createPanel = document.getElementById('createPanel');
        const createNameInput = document.getElementById('createName');
        const createEmailInput = document.getElementById('createEmail');
        const createRoleInput = document.getElementById('createRole');
        const createOperatorButton = document.getElementById('createOperatorButton');
        const createResultEl = document.getElementById('createResult');
        const statusEl = document.getElementById('status');
        const rowsEl = document.getElementById('operatorRows');

        const state = {
            operators: [],
            currentUserId: null,
            currentUserRole: null,
            canManageRoles: false,
        };

        const roleOptions = ['owner', 'admin', 'support'];

        const escapeHtml = (value) => {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        };

        const setStatus = (text, type = 'muted') => {
            statusEl.className = `status ${type}`;
            statusEl.textContent = text;
        };

        const hideCreateResult = () => {
            createResultEl.style.display = 'none';
            createResultEl.textContent = '';
        };

        const showCreateResult = (text) => {
            createResultEl.style.display = 'block';
            createResultEl.textContent = text;
        };

        const renderRows = () => {
            const operators = state.operators;

            if (!Array.isArray(operators) || operators.length === 0) {
                rowsEl.innerHTML = '<tr><td colspan="7" class="muted">No operators found for this tenant.</td></tr>';
                return;
            }

            rowsEl.innerHTML = operators.map((operator) => {
                const operatorId = Number(operator.id);
                const isSelf = Number.isFinite(operatorId) && Number(state.currentUserId) === operatorId;
                const isActive = Boolean(operator.is_active);

                if (!state.canManageRoles) {
                    return `
                        <tr>
                            <td>${escapeHtml(operator.id ?? '')}</td>
                            <td>${escapeHtml(operator.name ?? '')}</td>
                            <td>${escapeHtml(operator.email ?? '')}</td>
                            <td>${escapeHtml(operator.company_id ?? '')}</td>
                            <td>${escapeHtml(operator.operator_role ?? '')}</td>
                            <td>${isActive ? 'yes' : 'no'}</td>
                            <td><span class="action-note">Read-only</span></td>
                        </tr>
                    `;
                }

                const options = roleOptions.map((role) => {
                    const selected = role === operator.operator_role ? 'selected' : '';
                    return `<option value="${role}" ${selected}>${role}</option>`;
                }).join('');

                const disabled = isSelf ? 'disabled' : '';
                const actionContent = isSelf
                    ? '<span class="action-note">You cannot change or reset your own account here.</span>'
                    : `
                        <button type="button" class="button-secondary save-role" data-operator-id="${operatorId}">Save Role</button>
                        <button type="button" class="button-secondary toggle-activation" data-operator-id="${operatorId}" data-operator-email="${escapeHtml(operator.email ?? '')}" data-operator-active="${isActive ? '1' : '0'}">${isActive ? 'Deactivate' : 'Activate'}</button>
                        <button type="button" class="button-secondary reset-password" data-operator-id="${operatorId}" data-operator-email="${escapeHtml(operator.email ?? '')}">Reset Password</button>
                    `;

                return `
                    <tr>
                        <td>${escapeHtml(operator.id ?? '')}</td>
                        <td>${escapeHtml(operator.name ?? '')}</td>
                        <td>${escapeHtml(operator.email ?? '')}</td>
                        <td>${escapeHtml(operator.company_id ?? '')}</td>
                        <td>
                            <select class="role-select" data-role-input-id="${operatorId}" ${disabled}>
                                ${options}
                            </select>
                        </td>
                        <td>${isActive ? 'yes' : 'no'}</td>
                        <td>${actionContent}</td>
                    </tr>
                `;
            }).join('');

            rowsEl.querySelectorAll('.save-role').forEach((button) => {
                button.addEventListener('click', async () => {
                    const operatorId = Number(button.getAttribute('data-operator-id'));
                    const selectEl = rowsEl.querySelector(`select[data-role-input-id="${operatorId}"]`);
                    if (!selectEl) {
                        setStatus('Role input not found.', 'error');
                        return;
                    }

                    const operatorRole = String(selectEl.value || '').trim();
                    await updateRole(operatorId, operatorRole);
                });
            });

            rowsEl.querySelectorAll('.reset-password').forEach((button) => {
                button.addEventListener('click', async () => {
                    const operatorId = Number(button.getAttribute('data-operator-id'));
                    const operatorEmail = String(button.getAttribute('data-operator-email') || '').trim();
                    await resetPassword(operatorId, operatorEmail);
                });
            });

            rowsEl.querySelectorAll('.toggle-activation').forEach((button) => {
                button.addEventListener('click', async () => {
                    const operatorId = Number(button.getAttribute('data-operator-id'));
                    const operatorEmail = String(button.getAttribute('data-operator-email') || '').trim();
                    const isActive = String(button.getAttribute('data-operator-active') || '0') === '1';
                    await updateActivation(operatorId, !isActive, operatorEmail);
                });
            });
        };

        const loadOperators = async ({silent = false} = {}) => {
            if (!silent) {
                setStatus('Loading operators...', 'muted');
            }

            try {
                const response = await fetch(listPath, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                    },
                });

                let payload = null;
                try {
                    payload = await response.json();
                } catch (_) {
                    payload = null;
                }

                if (!response.ok || !payload || payload.ok !== true) {
                    const error = payload && payload.error ? payload.error : `HTTP ${response.status}`;
                    if (!silent) {
                        setStatus(`Load failed: ${error}`, 'error');
                    }
                    state.operators = [];
                    renderRows();
                    return false;
                }

                const operators = Array.isArray(payload.operators) ? payload.operators : [];
                const meta = payload.meta || {};

                state.operators = operators;
                state.currentUserId = meta.current_user_id ?? null;
                state.currentUserRole = meta.current_user_role ?? null;
                state.canManageRoles = Boolean(meta.can_manage_roles);
                createPanel.style.display = state.canManageRoles ? 'flex' : 'none';
                if (!state.canManageRoles) {
                    hideCreateResult();
                }

                renderRows();

                if (!silent) {
                    const suffix = state.canManageRoles
                        ? ' Owner role detected: role updates enabled.'
                        : ' Read-only mode: owner role required for updates.';
                    setStatus(`Loaded ${operators.length} operator(s).${suffix}`, 'ok');
                }

                return true;
            } catch (error) {
                if (!silent) {
                    setStatus(`Load failed: ${error.message}`, 'error');
                }
                state.operators = [];
                renderRows();
                return false;
            }
        };

        const updateRole = async (operatorId, operatorRole) => {
            if (!state.canManageRoles) {
                setStatus('Role update requires owner role.', 'error');
                return;
            }

            setStatus(`Updating role for operator ${operatorId}...`, 'muted');

            try {
                const response = await fetch(`${rolePathBase}/${operatorId}/role`, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        operator_role: operatorRole,
                    }),
                });

                let payload = null;
                try {
                    payload = await response.json();
                } catch (_) {
                    payload = null;
                }

                if (!response.ok || !payload || payload.ok !== true) {
                    const error = payload && payload.error ? payload.error : `HTTP ${response.status}`;
                    setStatus(`Role update failed: ${error}`, 'error');
                    return;
                }

                const noChange = Boolean(payload.no_change);
                const refreshed = await loadOperators({silent: true});
                const refreshSuffix = refreshed ? ' Operator list refreshed.' : ' Update applied but list refresh failed.';
                const successText = noChange ? 'No role change needed.' : 'Role updated successfully.';

                setStatus(`${successText}${refreshSuffix}`, 'ok');
            } catch (error) {
                setStatus(`Role update failed: ${error.message}`, 'error');
            }
        };

        const createOperator = async () => {
            if (!state.canManageRoles) {
                setStatus('Operator creation requires owner role.', 'error');
                return;
            }

            const name = String(createNameInput.value || '').trim();
            const email = String(createEmailInput.value || '').trim();
            const operatorRole = String(createRoleInput.value || '').trim();

            if (!name || !email || !operatorRole) {
                setStatus('name, email, and operator_role are required.', 'error');
                return;
            }

            hideCreateResult();
            setStatus('Creating operator...', 'muted');

            try {
                const response = await fetch(createPath, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        name,
                        email,
                        operator_role: operatorRole,
                    }),
                });

                let payload = null;
                try {
                    payload = await response.json();
                } catch (_) {
                    payload = null;
                }

                if (!response.ok || !payload || payload.ok !== true) {
                    const error = payload && payload.error ? payload.error : `HTTP ${response.status}`;
                    setStatus(`Create failed: ${error}`, 'error');
                    return;
                }

                const temporaryPassword = payload.temporary_password || '';
                const operatorEmail = payload.operator && payload.operator.email ? payload.operator.email : email;
                showCreateResult(`Temporary password for ${operatorEmail}: ${temporaryPassword} (shown once, copy now).`);

                createNameInput.value = '';
                createEmailInput.value = '';
                createRoleInput.value = 'admin';

                const refreshed = await loadOperators({silent: true});
                const refreshSuffix = refreshed ? ' Operator list refreshed.' : ' Operator created but list refresh failed.';
                setStatus(`Operator created successfully.${refreshSuffix}`, 'ok');
            } catch (error) {
                setStatus(`Create failed: ${error.message}`, 'error');
            }
        };

        const resetPassword = async (operatorId, operatorEmail) => {
            if (!state.canManageRoles) {
                setStatus('Password reset requires owner role.', 'error');
                return;
            }

            const confirmMessage = `Generate a new temporary password for ${operatorEmail || `operator ${operatorId}`}?`;
            if (!window.confirm(confirmMessage)) {
                return;
            }

            hideCreateResult();
            setStatus(`Resetting password for operator ${operatorId}...`, 'muted');

            try {
                const response = await fetch(`${resetPathBase}/${operatorId}/reset-password`, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({}),
                });

                let payload = null;
                try {
                    payload = await response.json();
                } catch (_) {
                    payload = null;
                }

                if (!response.ok || !payload || payload.ok !== true) {
                    const error = payload && payload.error ? payload.error : `HTTP ${response.status}`;
                    setStatus(`Reset failed: ${error}`, 'error');
                    return;
                }

                const temporaryPassword = payload.temporary_password || '';
                const targetEmail = payload.operator && payload.operator.email ? payload.operator.email : operatorEmail;
                showCreateResult(`Temporary password for ${targetEmail}: ${temporaryPassword} (shown once, copy now).`);

                const refreshed = await loadOperators({silent: true});
                const refreshSuffix = refreshed ? ' Operator list refreshed.' : ' Reset applied but list refresh failed.';
                setStatus(`Password reset completed.${refreshSuffix}`, 'ok');
            } catch (error) {
                setStatus(`Reset failed: ${error.message}`, 'error');
            }
        };

        const updateActivation = async (operatorId, isActive, operatorEmail) => {
            if (!state.canManageRoles) {
                setStatus('Activation update requires owner role.', 'error');
                return;
            }

            const actionWord = isActive ? 'activate' : 'deactivate';
            const confirmMessage = `Do you want to ${actionWord} ${operatorEmail || `operator ${operatorId}`}?`;
            if (!window.confirm(confirmMessage)) {
                return;
            }

            hideCreateResult();
            setStatus(`Applying activation change for operator ${operatorId}...`, 'muted');

            try {
                const response = await fetch(`${activationPathBase}/${operatorId}/activation`, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        is_active: isActive,
                    }),
                });

                let payload = null;
                try {
                    payload = await response.json();
                } catch (_) {
                    payload = null;
                }

                if (!response.ok || !payload || payload.ok !== true) {
                    const error = payload && payload.error ? payload.error : `HTTP ${response.status}`;
                    setStatus(`Activation update failed: ${error}`, 'error');
                    return;
                }

                const noChange = Boolean(payload.no_change);
                const refreshed = await loadOperators({silent: true});
                const refreshSuffix = refreshed ? ' Operator list refreshed.' : ' Update applied but list refresh failed.';
                const successText = noChange ? 'No activation change needed.' : `Operator ${actionWord}d successfully.`;

                setStatus(`${successText}${refreshSuffix}`, 'ok');
            } catch (error) {
                setStatus(`Activation update failed: ${error.message}`, 'error');
            }
        };

        loadButton.addEventListener('click', () => {
            loadOperators();
        });

        createOperatorButton.addEventListener('click', () => {
            createOperator();
        });
    })();
</script>
</body>
</html>
