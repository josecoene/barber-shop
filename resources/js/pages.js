import 'bootstrap/dist/css/bootstrap.min.css';
import { Modal } from 'bootstrap';

const JSON_HEADERS = { Accept: 'application/json', 'Content-Type': 'application/json' };
const HTTP_NO_CONTENT = 204;
const HTTP_UNPROCESSABLE = 422;

const OPENING_HOUR = 9;
const CLOSING_HOUR = 19;
const SLOT_MINUTES = 30;
const MINUTES_PER_HOUR = 60;

const currency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

const STATUS_LABELS = {
    active: { text: 'Ativo', badge: 'text-bg-success' },
    inactive: { text: 'Inativo', badge: 'text-bg-secondary' },
};

const showPhone = (value) => value || '—';

const COLUMNS = {
    barbers: [{ key: 'name' }, { key: 'email' }, { key: 'phone', format: showPhone }],
    clients: [{ key: 'name' }, { key: 'email' }, { key: 'phone', format: showPhone }],
    services: [
        { key: 'name' },
        { key: 'duration', format: (value) => `${value} min` },
        { key: 'price', format: (value) => currency.format(Number(value)) },
        { key: 'status', badge: true },
    ],
};

async function apiRequest(method, url, body) {
    const options = { method, headers: JSON_HEADERS };

    if (body !== undefined) {
        options.body = JSON.stringify(body);
    }

    const response = await fetch(url, options);

    if (response.status === HTTP_NO_CONTENT) {
        return { ok: true, status: response.status, data: null };
    }

    let data = null;

    try {
        data = await response.json();
    } catch {
        data = null;
    }

    return { ok: response.ok, status: response.status, data };
}

async function fetchList(url) {
    const result = await apiRequest('GET', url);

    if (!result.ok) {
        throw new Error(`Falha ao carregar ${url}`);
    }

    return result.data;
}

function showAlert(container, type, message) {
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show`;
    alert.setAttribute('role', 'alert');
    alert.textContent = message;

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close';
    close.setAttribute('data-bs-dismiss', 'alert');
    close.setAttribute('aria-label', 'Fechar');
    alert.append(close);

    container.replaceChildren(alert);
}

function messageRow(colspan, text) {
    const row = document.createElement('tr');
    const cell = document.createElement('td');
    cell.colSpan = colspan;
    cell.className = 'text-center text-body-secondary py-4';
    cell.textContent = text;
    row.append(cell);

    return row;
}

function capitalize(text) {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function fillSelect(select, items, placeholder) {
    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = placeholder;

    const options = items.map((item) => {
        const option = document.createElement('option');
        option.value = item.id;
        option.textContent = item.name;

        return option;
    });

    select.replaceChildren(empty, ...options);
}

function timeSlots() {
    const slots = [];

    for (let minutes = OPENING_HOUR * MINUTES_PER_HOUR; minutes < CLOSING_HOUR * MINUTES_PER_HOUR; minutes += SLOT_MINUTES) {
        slots.push(formatMinutes(minutes));
    }

    return slots;
}

function formatMinutes(totalMinutes) {
    const hours = String(Math.floor(totalMinutes / MINUTES_PER_HOUR)).padStart(2, '0');
    const minutes = String(totalMinutes % MINUTES_PER_HOUR).padStart(2, '0');

    return `${hours}:${minutes}`;
}

function today() {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');

    return `${now.getFullYear()}-${month}-${day}`;
}

/* Cadastros: barbeiros, clientes e serviços (funcionam de verdade com a API). */
function initCrud(root) {
    const resource = root.dataset.resource;
    const singular = root.dataset.singular;
    const endpoint = `/api/${resource}`;
    const columns = COLUMNS[resource];

    const alerts = root.querySelector('[data-alerts]');
    const rows = root.querySelector('[data-rows]');
    const colspan = root.querySelectorAll('thead th').length;

    const formModalElement = root.querySelector('[data-form-modal]');
    const formModal = new Modal(formModalElement);
    const form = root.querySelector('[data-form]');
    const formTitle = root.querySelector('[data-form-title]');
    const formAlerts = root.querySelector('[data-form-alerts]');
    const submitButton = root.querySelector('[data-submit]');

    const deleteModal = new Modal(root.querySelector('[data-delete-modal]'));
    const deleteName = root.querySelector('[data-delete-name]');
    const confirmDeleteButton = root.querySelector('[data-confirm-delete]');

    let editingId = null;
    let deletingItem = null;

    function renderCell(item, column) {
        const cell = document.createElement('td');
        const value = item[column.key];

        if (column.badge) {
            const status = STATUS_LABELS[value] ?? { text: value, badge: 'text-bg-light' };
            const badge = document.createElement('span');
            badge.className = `badge ${status.badge}`;
            badge.textContent = status.text;
            cell.append(badge);

            return cell;
        }

        cell.textContent = column.format ? column.format(value) : value;

        return cell;
    }

    function renderActions(item) {
        const cell = document.createElement('td');
        cell.className = 'text-end text-nowrap';

        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'btn btn-sm btn-outline-primary me-2';
        edit.textContent = 'Editar';
        edit.addEventListener('click', () => openForm(item));

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-sm btn-outline-danger';
        remove.textContent = 'Excluir';
        remove.addEventListener('click', () => openDelete(item));

        cell.append(edit, remove);

        return cell;
    }

    function render(items) {
        if (items.length === 0) {
            rows.replaceChildren(messageRow(colspan, `Nenhum ${singular} cadastrado ainda.`));

            return;
        }

        const lines = items.map((item) => {
            const row = document.createElement('tr');
            columns.forEach((column) => row.append(renderCell(item, column)));
            row.append(renderActions(item));

            return row;
        });

        rows.replaceChildren(...lines);
    }

    async function load() {
        rows.replaceChildren(messageRow(colspan, 'Carregando...'));

        try {
            render(await fetchList(endpoint));
        } catch {
            rows.replaceChildren(messageRow(colspan, 'Não foi possível carregar a lista.'));
            showAlert(alerts, 'danger', 'Não foi possível falar com a API. Confira se ela está rodando.');
        }
    }

    function clearErrors() {
        formAlerts.replaceChildren();
        form.querySelectorAll('.is-invalid').forEach((field) => field.classList.remove('is-invalid'));
        form.querySelectorAll('[data-error-for]').forEach((feedback) => {
            feedback.textContent = '';
        });
    }

    function showErrors(errors) {
        Object.entries(errors).forEach(([field, messages]) => {
            const input = form.elements.namedItem(field);
            const feedback = form.querySelector(`[data-error-for="${field}"]`);

            if (input) {
                input.classList.add('is-invalid');
            }

            if (feedback) {
                feedback.textContent = messages.join(' ');
            }
        });
    }

    function openForm(item) {
        form.reset();
        clearErrors();
        editingId = item ? item.id : null;
        formTitle.textContent = item ? `Editar ${singular}` : `Novo ${singular}`;

        if (item) {
            Array.from(form.elements)
                .filter((field) => field.name)
                .forEach((field) => {
                    field.value = item[field.name] ?? '';
                });
        }

        formModal.show();
    }

    function formData() {
        const data = {};

        new FormData(form).forEach((value, key) => {
            const text = String(value).trim();
            data[key] = text === '' ? null : text;
        });

        return data;
    }

    async function save(event) {
        event.preventDefault();
        clearErrors();
        submitButton.disabled = true;

        const method = editingId ? 'PUT' : 'POST';
        const url = editingId ? `${endpoint}/${editingId}` : endpoint;

        try {
            const result = await apiRequest(method, url, formData());

            if (result.status === HTTP_UNPROCESSABLE && result.data?.errors) {
                showErrors(result.data.errors);

                return;
            }

            if (!result.ok) {
                showAlert(formAlerts, 'danger', 'Não foi possível salvar. Tente de novo.');

                return;
            }

            formModal.hide();
            showAlert(alerts, 'success', `${capitalize(singular)} salvo com sucesso.`);
            await load();
        } catch {
            showAlert(formAlerts, 'danger', 'Não foi possível falar com a API.');
        } finally {
            submitButton.disabled = false;
        }
    }

    function openDelete(item) {
        deletingItem = item;
        deleteName.textContent = item.name;
        deleteModal.show();
    }

    async function confirmDelete() {
        confirmDeleteButton.disabled = true;

        try {
            const result = await apiRequest('DELETE', `${endpoint}/${deletingItem.id}`);

            deleteModal.hide();

            if (!result.ok) {
                showAlert(alerts, 'danger', 'Não foi possível excluir.');

                return;
            }

            showAlert(alerts, 'success', `${capitalize(singular)} excluído.`);
            await load();
        } catch {
            deleteModal.hide();
            showAlert(alerts, 'danger', 'Não foi possível falar com a API.');
        } finally {
            confirmDeleteButton.disabled = false;
        }
    }

    root.querySelector('[data-action="create"]').addEventListener('click', () => openForm(null));
    form.addEventListener('submit', save);
    confirmDeleteButton.addEventListener('click', confirmDelete);
    formModalElement.addEventListener('shown.bs.modal', () => form.querySelector('input')?.focus());

    load();
}

/* Agenda: só visual. A API ainda não tem agendamentos, então a grade fica sem horários marcados. */
function initSchedule(root) {
    const dateInput = root.querySelector('[data-date]');
    const head = root.querySelector('[data-schedule-head]');
    const body = root.querySelector('[data-schedule-body]');
    const alerts = root.querySelector('[data-alerts]');

    dateInput.value = today();

    function render(barbers) {
        const timeHeader = document.createElement('th');
        timeHeader.scope = 'col';
        timeHeader.className = 'text-nowrap';
        timeHeader.textContent = 'Horário';

        const barberHeaders = barbers.map((barber) => {
            const header = document.createElement('th');
            header.scope = 'col';
            header.textContent = barber.name;

            return header;
        });

        const headerRow = document.createElement('tr');
        headerRow.append(timeHeader, ...barberHeaders);
        head.replaceChildren(headerRow);

        if (barbers.length === 0) {
            body.replaceChildren(messageRow(1, 'Nenhum barbeiro cadastrado. Cadastre barbeiros para montar a agenda.'));

            return;
        }

        const lines = timeSlots().map((slot) => {
            const row = document.createElement('tr');
            const time = document.createElement('th');
            time.scope = 'row';
            time.className = 'text-body-secondary fw-normal';
            time.textContent = slot;
            row.append(time);

            barbers.forEach(() => {
                const cell = document.createElement('td');
                cell.className = 'text-body-tertiary';
                cell.textContent = '—';
                row.append(cell);
            });

            return row;
        });

        body.replaceChildren(...lines);
    }

    fetchList('/api/barbers')
        .then(render)
        .catch(() => {
            body.replaceChildren(messageRow(1, 'Não foi possível carregar os barbeiros.'));
            showAlert(alerts, 'danger', 'Não foi possível falar com a API. Confira se ela está rodando.');
        });
}

/* Agendamento online: só visual. Os selects usam dados reais, mas nada é salvo. */
function initBooking(root) {
    const form = root.querySelector('[data-booking-form]');
    const alerts = root.querySelector('[data-alerts]');
    const clientSelect = form.elements.namedItem('client_id');
    const barberSelect = form.elements.namedItem('barber_id');
    const serviceSelect = form.elements.namedItem('service_id');
    const dateInput = form.elements.namedItem('date');
    const timeSelect = form.elements.namedItem('start_time');
    const summary = root.querySelector('[data-summary]');

    let services = [];

    dateInput.min = today();
    dateInput.value = today();
    fillSelect(timeSelect, timeSlots().map((slot) => ({ id: slot, name: slot })), 'Escolha o horário');

    function summaryLine(label, value) {
        const line = document.createElement('div');
        line.className = 'd-flex justify-content-between py-1';

        const name = document.createElement('span');
        name.className = 'text-body-secondary';
        name.textContent = label;

        const content = document.createElement('span');
        content.className = 'fw-semibold';
        content.textContent = value;

        line.append(name, content);

        return line;
    }

    function updateSummary() {
        const service = services.find((item) => String(item.id) === serviceSelect.value);

        if (!service) {
            const hint = document.createElement('p');
            hint.className = 'text-body-secondary mb-0';
            hint.textContent = 'Escolha um serviço para ver duração e valor.';
            summary.replaceChildren(hint);

            return;
        }

        const lines = [
            summaryLine('Serviço', service.name),
            summaryLine('Duração', `${service.duration} min`),
            summaryLine('Valor', currency.format(Number(service.price))),
        ];

        if (timeSelect.value) {
            const [hours, minutes] = timeSelect.value.split(':').map(Number);
            const end = hours * MINUTES_PER_HOUR + minutes + Number(service.duration);
            lines.push(summaryLine('Horário', `${timeSelect.value} às ${formatMinutes(end)}`));
        }

        summary.replaceChildren(...lines);
    }

    Promise.all([fetchList('/api/clients'), fetchList('/api/barbers'), fetchList('/api/services')])
        .then(([clients, barbers, allServices]) => {
            services = allServices.filter((service) => service.status === 'active');
            fillSelect(clientSelect, clients, 'Escolha o cliente');
            fillSelect(barberSelect, barbers, 'Escolha o barbeiro');
            fillSelect(serviceSelect, services, 'Escolha o serviço');
        })
        .catch(() => {
            showAlert(alerts, 'danger', 'Não foi possível carregar clientes, barbeiros e serviços.');
        });

    serviceSelect.addEventListener('change', updateSummary);
    timeSelect.addEventListener('change', updateSummary);

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        form.classList.add('was-validated');

        if (!form.checkValidity()) {
            return;
        }

        showAlert(
            alerts,
            'warning',
            'O agendamento online ainda não está disponível: a API não tem a rota de agendamentos. Nada foi salvo.',
        );
    });

    updateSummary();
}

/* Login: só visual. O Sanctum ainda não está ativado na API. */
function initLogin(root) {
    const form = root.querySelector('[data-login-form]');
    const alerts = root.querySelector('[data-alerts]');

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        form.classList.add('was-validated');

        if (!form.checkValidity()) {
            return;
        }

        showAlert(alerts, 'warning', 'O login ainda não está disponível: a API não tem autenticação ativada.');
    });
}

document.querySelectorAll('[data-crud]').forEach(initCrud);
document.querySelectorAll('[data-schedule]').forEach(initSchedule);
document.querySelectorAll('[data-booking]').forEach(initBooking);
document.querySelectorAll('[data-login]').forEach(initLogin);
