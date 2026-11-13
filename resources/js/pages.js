import 'bootstrap/dist/css/bootstrap.min.css';
import { Modal } from 'bootstrap';

const JSON_HEADERS = { Accept: 'application/json', 'Content-Type': 'application/json' };
const HTTP_NO_CONTENT = 204;
const HTTP_UNPROCESSABLE = 422;

/* Uma consulta parada há mais tempo que isso vira erro, para a tela não ficar em "Carregando..." para sempre. */
const LOAD_TIMEOUT_MS = 15000;
const SEARCH_DEBOUNCE_MS = 300;
/* Maior página que a API aceita: as listas do Agendar e da Agenda carregam até esse número de itens. */
const MAX_PER_PAGE = 100;

const OPENING_HOUR = 9;
const CLOSING_HOUR = 19;
const SLOT_MINUTES = 30;
const MINUTES_PER_HOUR = 60;

const currency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

const STATUS_LABELS = {
    active: { text: 'Ativo', badge: 'text-bg-success' },
    inactive: { text: 'Inativo', badge: 'text-bg-secondary' },
};

/* text-break deixa nome e e-mail longos quebrarem linha, para a tabela caber no celular. */
const COLUMNS = {
    barbers: [
        { key: 'name', className: 'text-break' },
        { key: 'email', className: 'text-break' },
        { key: 'phone', empty: 'Não informado' },
    ],
    clients: [
        { key: 'name', className: 'text-break' },
        { key: 'email', className: 'text-break' },
        { key: 'phone', empty: 'Não informado' },
    ],
    services: [
        { key: 'name', className: 'text-break' },
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

    if (method === 'GET') {
        options.signal = AbortSignal.timeout(LOAD_TIMEOUT_MS);
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

/* As listagens da API são paginadas: devolvem { data, links, meta: { current_page, last_page, total, ... } }. */
async function fetchPage(url) {
    const result = await apiRequest('GET', url);

    if (!result.ok || !Array.isArray(result.data?.data) || !result.data.meta) {
        throw new Error(`Falha ao carregar ${url}`);
    }

    return result.data;
}

/* Só os itens da primeira página. Quem chama pede per_page=100 para trazer o máximo que a API deixa. */
async function fetchItems(url) {
    const page = await fetchPage(url);

    return page.data;
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

/* Alerta de erro com o botão "Tentar de novo". Devolve o botão, para quem chama poder dar foco nele. */
function showRetryAlert(container, message, onRetry) {
    const alert = document.createElement('div');
    alert.className = 'alert alert-danger d-flex flex-wrap gap-2 justify-content-between align-items-center';
    alert.setAttribute('role', 'alert');

    const text = document.createElement('span');
    text.textContent = message;

    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'btn btn-sm btn-danger';
    retry.textContent = 'Tentar de novo';
    retry.addEventListener('click', onRetry);

    alert.append(text, retry);
    container.replaceChildren(alert);

    return retry;
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

/* aria-describedby guarda vários ids separados por espaço: o do erro entra na frente da dica do campo. */
function addDescription(field, id) {
    const ids = (field.getAttribute('aria-describedby') ?? '').split(' ').filter((token) => token && token !== id);
    field.setAttribute('aria-describedby', [id, ...ids].join(' '));
}

function removeDescription(field, id) {
    const ids = (field.getAttribute('aria-describedby') ?? '').split(' ').filter((token) => token && token !== id);

    if (ids.length > 0) {
        field.setAttribute('aria-describedby', ids.join(' '));
    } else {
        field.removeAttribute('aria-describedby');
    }
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
    const plural = root.dataset.plural;
    const endpoint = `/api/${resource}`;
    const columns = COLUMNS[resource];

    const alerts = root.querySelector('[data-alerts]');
    const rows = root.querySelector('[data-rows]');
    const table = rows.closest('table');
    const colspan = root.querySelectorAll('thead th').length;

    const searchInput = root.querySelector('[data-search]');
    const pageStatus = root.querySelector('[data-page-status]');
    const previousButton = root.querySelector('[data-page-previous]');
    const nextButton = root.querySelector('[data-page-next]');

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
    let currentPage = 1;
    let lastPage = 1;
    let search = '';
    let searchTimer = null;
    /* Conta as cargas: se o usuário digita ou troca de página rápido, só a resposta da última é desenhada. */
    let loadCount = 0;
    /* Alerta de "não foi possível carregar": sai assim que uma carga seguinte der certo. */
    let loadErrorAlert = null;

    function renderCell(item, column) {
        const cell = document.createElement('td');
        const value = item[column.key];

        if (column.className) {
            cell.className = column.className;
        }

        if (column.badge) {
            const status = STATUS_LABELS[value] ?? { text: value, badge: 'text-bg-light' };
            const badge = document.createElement('span');
            badge.className = `badge ${status.badge}`;
            badge.textContent = status.text;
            cell.append(badge);

            return cell;
        }

        if (column.empty && (value === null || value === '')) {
            const empty = document.createElement('span');
            empty.className = 'text-body-secondary';
            empty.textContent = column.empty;
            cell.append(empty);

            return cell;
        }

        cell.textContent = column.format ? column.format(value) : value;

        return cell;
    }

    function actionButton(label, className, item, onClick) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `btn btn-sm ${className}`;
        button.textContent = label;
        button.setAttribute('aria-label', `${label} ${item.name}`);
        button.addEventListener('click', () => onClick(item));

        return button;
    }

    /* Os botões ficam num flex-wrap: no celular, "Excluir" desce para baixo de "Editar" em vez de alargar a tabela. */
    function renderActions(item) {
        const cell = document.createElement('td');
        cell.className = 'text-end';

        const group = document.createElement('div');
        group.className = 'd-flex flex-wrap gap-2 justify-content-end';
        group.append(
            actionButton('Editar', 'btn-outline-primary', item, openForm),
            actionButton('Excluir', 'btn-outline-danger', item, openDelete),
        );
        cell.append(group);

        return cell;
    }

    function updatePager(meta) {
        const total = `${meta.total} ${meta.total === 1 ? singular : plural}`;
        pageStatus.textContent = `Página ${meta.current_page} de ${meta.last_page} (${total})`;

        /* Botão desabilitado perde o foco: se o foco estava num deles, passa para o outro. */
        const focused = document.activeElement;
        previousButton.disabled = meta.current_page <= 1;
        nextButton.disabled = meta.current_page >= meta.last_page;

        if (focused === nextButton && nextButton.disabled && !previousButton.disabled) {
            previousButton.focus();
        } else if (focused === previousButton && previousButton.disabled && !nextButton.disabled) {
            nextButton.focus();
        }
    }

    function render(page) {
        currentPage = page.meta.current_page;
        lastPage = page.meta.last_page;
        updatePager(page.meta);

        if (page.data.length === 0) {
            const message = search
                ? `Nenhum ${singular} encontrado para "${search}".`
                : `Nenhum ${singular} cadastrado ainda.`;
            rows.replaceChildren(messageRow(colspan, message));

            return;
        }

        const lines = page.data.map((item) => {
            const row = document.createElement('tr');
            columns.forEach((column) => row.append(renderCell(item, column)));
            row.append(renderActions(item));

            return row;
        });

        rows.replaceChildren(...lines);
    }

    async function load() {
        const loadNumber = ++loadCount;
        const params = new URLSearchParams({ page: String(currentPage) });

        if (search) {
            params.set('search', search);
        }

        rows.replaceChildren(messageRow(colspan, 'Carregando...'));
        pageStatus.textContent = 'Carregando...';
        table.setAttribute('aria-busy', 'true');

        try {
            const page = await fetchPage(`${endpoint}?${params}`);

            if (loadNumber !== loadCount) {
                return;
            }

            /* A página pedida não existe mais (ex.: excluiu o último item dela): volta para a última que existe. */
            if (page.data.length === 0 && page.meta.current_page > page.meta.last_page) {
                currentPage = page.meta.last_page;
                await load();

                return;
            }

            render(page);
            loadErrorAlert?.remove();
            loadErrorAlert = null;
        } catch {
            if (loadNumber !== loadCount) {
                return;
            }

            rows.replaceChildren(messageRow(colspan, 'Não foi possível carregar a lista.'));
            pageStatus.textContent = 'Não foi possível carregar a lista.';
            const retry = showRetryAlert(alerts, 'Não foi possível falar com a API. Confira se ela está rodando.', () => {
                alerts.replaceChildren();
                load();
            });
            loadErrorAlert = retry.closest('.alert');
        } finally {
            if (loadNumber === loadCount) {
                table.removeAttribute('aria-busy');
            }
        }
    }

    function goToPage(page) {
        if (page < 1 || page > lastPage || page === currentPage) {
            return;
        }

        currentPage = page;
        load();
    }

    function onSearch() {
        clearTimeout(searchTimer);

        searchTimer = setTimeout(() => {
            const term = searchInput.value.trim();

            if (term === search) {
                return;
            }

            search = term;
            currentPage = 1;
            load();
        }, SEARCH_DEBOUNCE_MS);
    }

    function clearErrors() {
        formAlerts.replaceChildren();
        form.querySelectorAll('[data-error-for]').forEach((feedback) => {
            const field = form.elements.namedItem(feedback.dataset.errorFor);
            feedback.textContent = '';

            if (field) {
                field.classList.remove('is-invalid');
                field.removeAttribute('aria-invalid');
                removeDescription(field, feedback.id);
            }
        });
    }

    function showErrors(errors) {
        const orphanMessages = [];

        Object.entries(errors).forEach(([name, messages]) => {
            const field = form.elements.namedItem(name);
            const feedback = form.querySelector(`[data-error-for="${name}"]`);

            if (!field || !feedback) {
                orphanMessages.push(...messages);

                return;
            }

            feedback.textContent = messages.join(' ');
            field.classList.add('is-invalid');
            field.setAttribute('aria-invalid', 'true');
            addDescription(field, feedback.id);
        });

        if (orphanMessages.length > 0) {
            showAlert(formAlerts, 'danger', orphanMessages.join(' '));
        }

        form.querySelector('[aria-invalid="true"]')?.focus();
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
                showAlert(formAlerts, 'danger', result.data?.message ?? 'Não foi possível salvar. Tente de novo.');

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
                showAlert(alerts, 'danger', result.data?.message ?? 'Não foi possível excluir.');

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
    searchInput.addEventListener('input', onSearch);
    previousButton.addEventListener('click', () => goToPage(currentPage - 1));
    nextButton.addEventListener('click', () => goToPage(currentPage + 1));
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
    const table = body.closest('table');
    const alerts = root.querySelector('[data-alerts]');
    const loadingStatus = root.querySelector('[data-loading-status]');

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

    /* O status escondido (aria-live) avisa o leitor de tela que a agenda está carregando e quando terminou. */
    loadingStatus.textContent = 'Carregando a agenda...';
    table.setAttribute('aria-busy', 'true');

    fetchItems(`/api/barbers?per_page=${MAX_PER_PAGE}`)
        .then((barbers) => {
            render(barbers);
            loadingStatus.textContent = 'Agenda carregada.';
        })
        .catch(() => {
            loadingStatus.textContent = '';
            body.replaceChildren(messageRow(1, 'Não foi possível carregar os barbeiros.'));
            showAlert(alerts, 'danger', 'Não foi possível falar com a API. Confira se ela está rodando.');
        })
        .finally(() => table.removeAttribute('aria-busy'));
}

/* Agendamento online: só visual. Os selects usam dados reais, mas nada é salvo. */
function initBooking(root) {
    const form = root.querySelector('[data-booking-form]');
    const alerts = root.querySelector('[data-alerts]');
    const loadingStatus = root.querySelector('[data-loading-status]');
    const clientSelect = form.elements.namedItem('client_id');
    const barberSelect = form.elements.namedItem('barber_id');
    const serviceSelect = form.elements.namedItem('service_id');
    const dateInput = form.elements.namedItem('date');
    const timeSelect = form.elements.namedItem('start_time');
    const summary = root.querySelector('[data-summary]');
    const lists = [clientSelect, barberSelect, serviceSelect];

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

    /* Carrega até 100 clientes, barbeiros e serviços ativos. Se falhar, mostra o erro com "Tentar de novo". */
    async function loadLists({ retrying = false } = {}) {
        alerts.replaceChildren();
        lists.forEach((select) => fillSelect(select, [], 'Carregando...'));
        loadingStatus.textContent = 'Carregando clientes, barbeiros e serviços...';
        form.setAttribute('aria-busy', 'true');

        try {
            const [clients, barbers, activeServices] = await Promise.all([
                fetchItems(`/api/clients?per_page=${MAX_PER_PAGE}`),
                fetchItems(`/api/barbers?per_page=${MAX_PER_PAGE}`),
                fetchItems(`/api/services?status=active&per_page=${MAX_PER_PAGE}`),
            ]);

            services = activeServices;
            fillSelect(clientSelect, clients, 'Escolha o cliente');
            fillSelect(barberSelect, barbers, 'Escolha o barbeiro');
            fillSelect(serviceSelect, services, 'Escolha o serviço');
            loadingStatus.textContent = 'Clientes, barbeiros e serviços carregados.';

            if (retrying) {
                clientSelect.focus();
            }
        } catch {
            services = [];
            lists.forEach((select) => fillSelect(select, [], 'Não foi possível carregar'));
            loadingStatus.textContent = '';

            const retry = showRetryAlert(alerts, 'Não foi possível carregar clientes, barbeiros e serviços.', () =>
                loadLists({ retrying: true }),
            );

            if (retrying) {
                retry.focus();
            }
        } finally {
            form.removeAttribute('aria-busy');
            updateSummary();
        }
    }

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

    loadLists();
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
