import 'bootstrap/dist/css/bootstrap.min.css';
import '../css/pages.css';
import { Modal } from 'bootstrap';

const JSON_HEADERS = { Accept: 'application/json', 'Content-Type': 'application/json' };
const HTTP_NO_CONTENT = 204;
const HTTP_UNAUTHORIZED = 401;
const HTTP_FORBIDDEN = 403;
const HTTP_NOT_FOUND = 404;
/* 409: a API recusou por causa da agenda (ex.: excluir um cadastro com agendamento futuro). */
const HTTP_CONFLICT = 409;
/* 419: o token CSRF não vale mais (a sessão expirou ou foi encerrada em outra aba). */
const HTTP_SESSION_EXPIRED = 419;
const HTTP_UNPROCESSABLE = 422;
/* 429: passou do limite (ex.: "Enviar lembrete agora" de novo antes de 10 minutos). A mensagem da API diz quando tentar. */
const HTTP_TOO_MANY_REQUESTS = 429;

const FORBIDDEN_MESSAGE = 'Você não tem permissão para esta ação.';
const SESSION_PROBLEM_MESSAGE = 'Não foi possível confirmar o seu login. Recarregue a página ou entre de novo.';
const API_UNREACHABLE_MESSAGE = 'Não foi possível falar com a API. Confira se ela está rodando.';
const EXPIRED_LOGIN_URL = '/login?expirou=1';

/* Quando a tela mandou para o login pela última vez (ver goToLogin). */
const LOGIN_REDIRECT_KEY = 'barberflow:login-redirect-at';
const LOGIN_REDIRECT_INTERVAL_MS = 10000;

/* Uma consulta parada há mais tempo que isso vira erro, para a tela não ficar em "Carregando..." para sempre. */
const LOAD_TIMEOUT_MS = 15000;
const SEARCH_DEBOUNCE_MS = 300;
/* Maior página que a API aceita: as listas do Agendar carregam até esse número de itens. */
const MAX_PER_PAGE = 100;
/* A agenda do dia e Meus horários leem até esse número de páginas (de 100 itens cada). */
const MAX_PAGES = 10;
/* Itens por página nas duas listas da tela de Pagamentos. */
const PAYMENTS_PER_PAGE = 15;
const AWAITING_PER_PAGE = 10;
/* Com um período maior que isso (ex.: um ano), o Dashboard soma o faturamento por mês em vez de por dia. */
const DAY_CHART_MAX_DAYS = 62;

const SECONDS_PER_MINUTE = 60;
const MINUTES_PER_HOUR = 60;
const SECONDS_PER_HOUR = 3600;
const MS_PER_SECOND = 1000;
const DAYS_PER_WEEK = 7;

/* Fuso da barbearia (meta app-timezone do layout). "Hoje" e "agora" da agenda são sempre nele. */
const APP_TIMEZONE = document.querySelector('meta[name="app-timezone"]')?.content || undefined;

const currency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
/* As datas da agenda chegam como "2026-11-16" e viram meia-noite em UTC (ver parseDay): o formato também usa UTC. */
const LONG_DATE = new Intl.DateTimeFormat('pt-BR', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});
const SHORT_DATE = new Intl.DateTimeFormat('pt-BR', {
    weekday: 'short',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'UTC',
});
/* Rótulos do Dashboard: "seg., 09/11", "9 de novembro de 2026", "9 de novembro" e "nov. de 2026". */
const DAY_AND_MONTH = new Intl.DateTimeFormat('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit', timeZone: 'UTC' });
const FULL_DAY = new Intl.DateTimeFormat('pt-BR', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
const DAY_OF_MONTH = new Intl.DateTimeFormat('pt-BR', { day: 'numeric', month: 'long', timeZone: 'UTC' });
const MONTH_AND_YEAR = new Intl.DateTimeFormat('pt-BR', { month: 'short', year: 'numeric', timeZone: 'UTC' });
/* Taxas com 1 casa ("22,2") e contagens com separador de milhar ("7.200"). */
const PERCENT = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const INTEGER = new Intl.NumberFormat('pt-BR');

const STATUS_LABELS = {
    active: { text: 'Ativo', badge: 'text-bg-success' },
    inactive: { text: 'Inativo', badge: 'text-bg-secondary' },
};

/*
 * Cor da etiqueta de cada status do agendamento. O nome em português e as regras de cada status
 * vêm do servidor (AppointmentStatus::catalog(), no data-statuses das telas).
 */
const APPOINTMENT_BADGES = {
    scheduled: 'text-bg-secondary',
    confirmed: 'text-bg-primary',
    in_progress: 'text-bg-warning',
    completed: 'text-bg-success',
    cancelled: 'text-bg-light border',
    no_show: 'text-bg-danger',
};

/*
 * Botões do detalhe do agendamento, na ordem em que aparecem. Cada um muda para o status da chave e só
 * aparece quando a transição vale (catalog().transitions). Os com confirm pedem confirmação antes.
 */
const STATUS_ACTIONS = {
    confirmed: { label: 'Confirmar', className: 'btn-primary' },
    in_progress: { label: 'Iniciar', className: 'btn-primary' },
    completed: { label: 'Concluir', className: 'btn-success' },
    no_show: {
        label: 'Faltou',
        className: 'btn-outline-danger',
        confirm: (who, time) => `Marcar que ${who} faltou ao horário das ${time}? Essa mudança não pode ser desfeita.`,
        confirmLabel: 'Sim, marcar a falta',
    },
    cancelled: {
        label: 'Cancelar',
        className: 'btn-outline-danger',
        confirm: (who, time) => `Cancelar o agendamento de ${who} às ${time}? Essa mudança não pode ser desfeita.`,
        confirmLabel: 'Sim, cancelar',
    },
};

/*
 * Cor da etiqueta de cada status do pagamento. O nome em português vem da API (status_label) e as
 * transições, do PaymentStatus::catalog() (data-payment-statuses da tela de Pagamentos).
 */
const PAYMENT_BADGES = {
    pending: 'text-bg-warning',
    paid: 'text-bg-success',
    refunded: 'text-bg-secondary',
};

/*
 * Mudanças de status do pagamento na tela de Pagamentos, na ordem dos botões. Cada uma muda para o
 * status da chave, só aparece quando a transição vale e pede confirmação no modal da própria tela. O
 * estorno é só do admin (adminOnly); marcar como pago vale também para o barbeiro, nos da agenda dele.
 */
const PAYMENT_ACTIONS = {
    paid: {
        label: 'Marcar como pago',
        className: 'btn-outline-success',
        title: 'Marcar como pago',
        confirm: (what) => `Marcar como pago ${what}? A data do pagamento fica sendo agora.`,
        confirmLabel: 'Sim, marcar como pago',
        confirmClass: 'btn-success',
        done: 'marcado como pago',
        adminOnly: false,
    },
    refunded: {
        label: 'Estornar',
        className: 'btn-outline-danger',
        title: 'Estornar pagamento',
        confirm: (what) => `Estornar ${what}? O valor sai do faturamento, e o atendimento não pode receber outro pagamento. Essa mudança não pode ser desfeita.`,
        confirmLabel: 'Sim, estornar',
        confirmClass: 'btn-danger',
        done: 'estornado',
        adminOnly: true,
    },
};

/*
 * Colunas de cada cadastro, na ordem do cabeçalho da tabela (partials/crud). A primeira é sempre o nome,
 * que no celular vira o título do item. Nome e e-mail quebram linha só entre palavras (o e-mail, também
 * depois do @ e antes de cada ponto: emailText); telefone, duração e preço ficam numa linha só.
 */
const COLUMNS = {
    barbers: [
        { key: 'name' },
        { key: 'email', render: emailText },
        { key: 'phone', empty: 'Não informado', className: 'text-nowrap' },
        /* Acesso: o barbeiro tem login (o admin cria ou redefine pelo campo de senha do formulário). */
        { key: 'has_login', format: (value) => (value ? 'Sim' : 'Não') },
    ],
    clients: [
        { key: 'name' },
        { key: 'email', render: emailText },
        { key: 'phone', empty: 'Não informado', className: 'text-nowrap' },
        /* Fidelidade: visitas desde o último prêmio (ex.: 7/10), vindas da própria listagem, e um selo quando há prêmio. */
        { key: 'loyalty', render: loyaltyCell },
    ],
    services: [
        { key: 'name' },
        { key: 'duration', format: (value) => `${value} min`, className: 'text-nowrap' },
        { key: 'price', format: (value) => currency.format(Number(value)), className: 'text-nowrap' },
        { key: 'status', badge: true },
    ],
};

/* Erro da API que a tela mostra com a mensagem que veio (sem permissão, login que não vale mais). */
class ApiError extends Error {}

/* Token CSRF da sessão, que o layout das telas põe na meta csrf-token. */
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/*
 * Leva ao login com o aviso de sessão expirada e devolve true. Se a tela já mandou para o login há
 * poucos segundos, não manda de novo e devolve false: quem ainda tem sessão nas telas volta do login
 * direto para cá, e se a API continuar recusando (ex.: o host não está no SANCTUM_STATEFUL_DOMAINS)
 * as duas páginas ficariam se mandando uma para a outra sem parar.
 */
function goToLogin() {
    const now = Date.now();
    let last = 0;

    try {
        last = Number(window.sessionStorage.getItem(LOGIN_REDIRECT_KEY)) || 0;
        window.sessionStorage.setItem(LOGIN_REDIRECT_KEY, String(now));
    } catch {
        /* Navegador sem sessionStorage (ex.: bloqueado): segue sem essa proteção. */
    }

    if (now - last < LOGIN_REDIRECT_INTERVAL_MS) {
        return false;
    }

    window.location.assign(EXPIRED_LOGIN_URL);

    return true;
}

/*
 * Chamada à API com o cookie da sessão das telas. O Sanctum reconhece as telas pelo Referer (por isso o
 * referrerPolicy) e confere o X-CSRF-TOKEN nos POST, PUT, PATCH e DELETE.
 * - 401 (sem login) e 419 (sessão expirada) levam ao login;
 * - 403 devolve a mensagem de permissão, para a tela mostrar.
 */
async function apiRequest(method, url, body) {
    const options = {
        method,
        headers: { ...JSON_HEADERS, 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        referrerPolicy: 'same-origin',
    };

    if (body !== undefined) {
        options.body = JSON.stringify(body);
    }

    if (method === 'GET') {
        options.signal = AbortSignal.timeout(LOAD_TIMEOUT_MS);
    }

    const response = await fetch(url, options);

    if (response.status === HTTP_UNAUTHORIZED || response.status === HTTP_SESSION_EXPIRED) {
        if (goToLogin()) {
            /* A página vai mudar: a promessa fica sem resposta, para a tela não piscar um erro antes de sair. */
            return new Promise(() => {});
        }

        return { ok: false, status: response.status, data: { message: SESSION_PROBLEM_MESSAGE } };
    }

    if (response.status === HTTP_NO_CONTENT) {
        return { ok: true, status: response.status, data: null };
    }

    let data = null;

    try {
        data = await response.json();
    } catch {
        data = null;
    }

    if (response.status === HTTP_FORBIDDEN) {
        return { ok: false, status: response.status, data: { message: data?.message ?? FORBIDDEN_MESSAGE } };
    }

    return { ok: response.ok, status: response.status, data };
}

/* As listagens da API são paginadas: devolvem { data, links, meta: { current_page, last_page, total, ... } }. */
async function fetchPage(url) {
    const result = await apiRequest('GET', url);

    if ([HTTP_UNAUTHORIZED, HTTP_FORBIDDEN, HTTP_SESSION_EXPIRED].includes(result.status)) {
        throw new ApiError(result.data.message);
    }

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

/*
 * Todos os itens de uma listagem, de 100 em 100, até MAX_PAGES páginas. Devolve { items, complete }:
 * complete fica false quando ainda sobrou página sem carregar.
 */
async function fetchAllItems(url) {
    const items = [];
    const separator = url.includes('?') ? '&' : '?';

    for (let page = 1; page <= MAX_PAGES; page += 1) {
        const result = await fetchPage(`${url}${separator}per_page=${MAX_PER_PAGE}&page=${page}`);
        items.push(...result.data);

        if (result.meta.current_page >= result.meta.last_page) {
            return { items, complete: true };
        }
    }

    return { items, complete: false };
}

/* Alerta do Bootstrap com o botão de fechar. Com append, entra depois dos alertas que já estão lá. */
function showAlert(container, type, message, { append = false } = {}) {
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

    if (append) {
        container.append(alert);
    } else {
        container.replaceChildren(alert);
    }

    return alert;
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

/* Parágrafo de aviso das listas (ex.: "Nenhum agendamento neste dia."). */
function emptyMessage(text) {
    const paragraph = document.createElement('p');
    paragraph.className = 'text-body-secondary mb-0';
    paragraph.textContent = text;

    return paragraph;
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

/*
 * Onde o erro de um campo aparece: no próprio campo ou, num grupo de opções (ex.: os horários livres,
 * que são vários botões de rádio), no elemento marcado com data-error-group. null quando o campo não
 * está na tela.
 */
function errorTarget(form, name) {
    const target = form.querySelector(`[data-error-group="${name}"]`) ?? form.elements.namedItem(name);

    return target instanceof Element ? target : null;
}

/* Tira o erro de validação de um campo. */
function clearFieldError(form, name) {
    const feedback = form.querySelector(`[data-error-for="${name}"]`);
    const field = errorTarget(form, name);

    if (feedback) {
        feedback.textContent = '';
    }

    if (field && feedback) {
        field.classList.remove('is-invalid');
        field.removeAttribute('aria-invalid');
        removeDescription(field, feedback.id);
    }
}

/* Limpa os erros de validação mostrados no formulário e o alerta dele. */
function clearFormErrors(form, formAlerts) {
    formAlerts.replaceChildren();
    form.querySelectorAll('[data-error-for]').forEach((feedback) => clearFieldError(form, feedback.dataset.errorFor));
}

/* Dá foco no primeiro campo com erro. Num grupo de opções, na opção marcada, na primeira ou no próprio grupo. */
function focusFirstInvalid(form) {
    const invalid = form.querySelector('[aria-invalid="true"]');

    if (!invalid) {
        return;
    }

    const target = invalid.matches('input, select, textarea')
        ? invalid
        : (invalid.querySelector('input:checked') ?? invalid.querySelector('input') ?? invalid);

    target.focus();
}

/* Mostra os erros do 422 embaixo de cada campo. Erro de campo que não está na tela vai para o alerta do formulário. */
function showFormErrors(form, formAlerts, errors) {
    const orphanMessages = [];

    Object.entries(errors).forEach(([name, messages]) => {
        const field = errorTarget(form, name);
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

    focusFirstInvalid(form);
}

/*
 * Valores do formulário para mandar à API: texto vazio vira null. Os textos perdem os espaços das pontas,
 * menos as senhas, que vão como foram digitadas (o Laravel também não apara senha).
 */
function formValues(form) {
    const data = {};

    new FormData(form).forEach((value, key) => {
        const isPassword = form.elements.namedItem(key)?.type === 'password';
        const text = isPassword ? String(value) : String(value).trim();
        data[key] = text === '' ? null : text;
    });

    return data;
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

/* Acrescenta a opção do item ({ id, name }) quando ela não está na lista (ex.: barbeiro excluído). */
function ensureOption(select, item) {
    if (!item || Array.from(select.options).some((option) => option.value === String(item.id))) {
        return;
    }

    const option = document.createElement('option');
    option.value = item.id;
    option.textContent = item.name;
    select.append(option);
}

function formatMinutes(totalMinutes) {
    const hours = String(Math.floor(totalMinutes / MINUTES_PER_HOUR)).padStart(2, '0');
    const minutes = String(totalMinutes % MINUTES_PER_HOUR).padStart(2, '0');

    return `${hours}:${minutes}`;
}

/* "10:00:00" ou "10:00" em minutos desde a meia-noite (600). */
function toMinutes(time) {
    const [hours, minutes] = String(time).split(':').map(Number);

    return hours * MINUTES_PER_HOUR + minutes;
}

/* "10:00:00" vira "10:00". */
function shortTime(time) {
    return String(time).slice(0, 5);
}

/* "10:00 às 10:30", do início ao fim do agendamento. */
function timeRange(appointment) {
    return `${shortTime(appointment.start_time)} às ${shortTime(appointment.end_time)}`;
}

/* "2026-11-16" vira a meia-noite desse dia em UTC (só para contar dias, sem fuso no meio), ou null se não for uma data. */
function parseDay(text) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text ?? '');

    if (!match) {
        return null;
    }

    const [year, month, day] = match.slice(1).map(Number);
    const date = new Date(Date.UTC(year, month - 1, day));

    return date.getUTCMonth() === month - 1 && date.getUTCDate() === day ? date : null;
}

function formatDay(date) {
    return date.toISOString().slice(0, 10);
}

function addDays(day, amount) {
    const date = parseDay(day);
    date.setUTCDate(date.getUTCDate() + amount);

    return formatDay(date);
}

/* Dia da semana no padrão ISO, de 1 (segunda) a 7 (domingo), como o BUSINESS_DAYS. */
function isoWeekday(day) {
    return parseDay(day).getUTCDay() || DAYS_PER_WEEK;
}

/* "Segunda-feira, 16 de novembro de 2026", para títulos. */
function longDate(day) {
    return capitalize(longDateInSentence(day));
}

/* "segunda-feira, 16 de novembro de 2026", para o meio de uma frase. */
function longDateInSentence(day) {
    return LONG_DATE.format(parseDay(day));
}

/* "seg., 16/11/2026". */
function shortDate(day) {
    return SHORT_DATE.format(parseDay(day));
}

/*
 * Agora no fuso da barbearia: { day: "2026-11-16", seconds: segundos desde a meia-noite }. O relógio é o
 * do navegador; a API confere tudo de novo com o relógio do servidor.
 */
function nowInAppTimezone() {
    const now = new Date();

    try {
        const parts = Object.fromEntries(
            new Intl.DateTimeFormat('en-CA', {
                timeZone: APP_TIMEZONE,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hourCycle: 'h23',
            })
                .formatToParts(now)
                .map((part) => [part.type, part.value]),
        );

        return {
            day: `${parts.year}-${parts.month}-${parts.day}`,
            seconds: Number(parts.hour) * SECONDS_PER_HOUR + Number(parts.minute) * SECONDS_PER_MINUTE + Number(parts.second),
        };
    } catch {
        /* Fuso que o navegador não conhece: fica o do próprio navegador. */
        const pad = (value) => String(value).padStart(2, '0');

        return {
            day: `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`,
            seconds: now.getHours() * SECONDS_PER_HOUR + now.getMinutes() * SECONDS_PER_MINUTE + now.getSeconds(),
        };
    }
}

function today() {
    return nowInAppTimezone().day;
}

/*
 * Segundos que faltam até o horário (negativo quando ele já passou). A conta é feita com dia e hora "de
 * parede" no fuso da barbearia, como o servidor faz.
 */
function secondsUntil(day, time) {
    const now = nowInAppTimezone();
    const target = parseDay(day).getTime() / MS_PER_SECOND + toMinutes(time) * SECONDS_PER_MINUTE;

    return target - (parseDay(now.day).getTime() / MS_PER_SECOND + now.seconds);
}

/* Etiqueta com o status do agendamento em português. */
function statusBadge(appointment) {
    const badge = document.createElement('span');
    badge.className = `badge ${APPOINTMENT_BADGES[appointment.status] ?? 'text-bg-light border'}`;
    badge.textContent = appointment.status_label ?? appointment.status;

    return badge;
}

/* Lista de nomes em português: "A", "A e B", "A, B e C". */
function joinNames(names) {
    return names.length <= 1 ? names.join('') : `${names.slice(0, -1).join(', ')} e ${names[names.length - 1]}`;
}

/* Linha "nome: valor" de um resumo em <dl>. */
function summaryLine(label, value) {
    const line = document.createElement('div');
    line.className = 'd-flex justify-content-between gap-3 py-1';

    const term = document.createElement('dt');
    term.className = 'fw-normal text-body-secondary';
    term.textContent = label;

    const description = document.createElement('dd');
    description.className = 'mb-0 fw-semibold text-end text-break';
    description.textContent = value;

    line.append(term, description);

    return line;
}

/* Elemento com texto (sempre por textContent: nomes e observações vêm da API). */
function textElement(tag, className, text) {
    const element = document.createElement(tag);

    if (className) {
        element.className = className;
    }

    element.textContent = text;

    return element;
}

/* "1 pagamento", "2 pagamentos" (o plural vai à parte quando não é só acrescentar o "s"). */
function countLabel(count, singular, plural = `${singular}s`) {
    return `${INTEGER.format(count)} ${count === 1 ? singular : plural}`;
}

/* Minutos em horas: "6 h 30 min", "120 h", "45 min". */
function formatDuration(totalMinutes) {
    const hours = Math.floor(totalMinutes / MINUTES_PER_HOUR);
    const minutes = totalMinutes % MINUTES_PER_HOUR;

    if (hours === 0) {
        return `${minutes} min`;
    }

    return minutes === 0 ? `${INTEGER.format(hours)} h` : `${INTEGER.format(hours)} h ${minutes} min`;
}

/* Período em português: "1 a 30 de novembro de 2026", "28 de outubro a 3 de novembro de 2026". */
function periodLabel(from, to) {
    if (from === to) {
        return FULL_DAY.format(parseDay(from));
    }

    const end = FULL_DAY.format(parseDay(to));

    if (from.slice(0, 7) === to.slice(0, 7)) {
        return `${Number(from.slice(8))} a ${end}`;
    }

    const start = from.slice(0, 4) === to.slice(0, 4) ? DAY_OF_MONTH.format(parseDay(from)) : FULL_DAY.format(parseDay(from));

    return `${start} a ${end}`;
}

/*
 * Data e hora que a API manda em UTC (ex.: paid_at) no fuso da barbearia: "12/11/2026 às 18:40". Fuso
 * que o navegador não conhece: fica o do próprio navegador.
 */
function dateTimeInAppTimezone(iso) {
    const date = new Date(iso);
    const options = { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' };
    let parts;

    try {
        parts = new Intl.DateTimeFormat('pt-BR', { ...options, timeZone: APP_TIMEZONE }).formatToParts(date);
    } catch {
        parts = new Intl.DateTimeFormat('pt-BR', options).formatToParts(date);
    }

    const part = (type) => parts.find((item) => item.type === type)?.value ?? '';

    return `${part('day')}/${part('month')}/${part('year')} às ${part('hour')}:${part('minute')}`;
}

/* Etiqueta com o status do pagamento em português (ex.: "Pago"). */
function paymentBadge(payment) {
    return textElement('span', `badge ${PAYMENT_BADGES[payment.status] ?? 'text-bg-light border'}`, payment.status_label ?? payment.status);
}

/* "Ana Pereira, Corte de cabelo, qui., 12/11/2026 às 10:00": o atendimento de um pagamento, para frases e leitores de tela. */
function describePaymentAppointment(appointment) {
    return `${appointment?.client?.name ?? 'cliente'}, ${appointment?.service?.name ?? 'serviço'}, ${shortDate(appointment?.date)} às ${shortTime(appointment?.start_time)}`;
}

/*
 * Valor de um pagamento para os avisos: "R$ 35,00 (Pix)" ou, pago com o prêmio da fidelidade,
 * "R$ 0,00 (Dinheiro), com o prêmio de fidelidade (desconto de R$ 35,00)".
 */
function describePaymentValue(payment) {
    const value = `${currency.format(Number(payment.amount))} (${payment.method_label ?? payment.method})`;

    return payment.loyalty_reward
        ? `${value}, com o prêmio de fidelidade (desconto de ${currency.format(Number(payment.discount))})`
        : value;
}

/* O que o prêmio da fidelidade vale, para o meio de uma frase: "um atendimento grátis" ou "50% de desconto num atendimento". */
function rewardBenefit(discountPercent) {
    return discountPercent >= 100 ? 'um atendimento grátis' : `${discountPercent}% de desconto num atendimento`;
}

/*
 * E-mail com pontos de quebra (<wbr>) depois do @ e antes de cada ponto: numa coluna estreita, ele quebra
 * linha nesses lugares ("henrique.barbosa@" e "demo.barberflow.test"), e não no meio de uma palavra. O
 * texto entra como texto, nunca como HTML: o e-mail vem da API.
 */
function emailText(email) {
    const wrapper = document.createElement('span');
    let part = '';

    for (const char of String(email ?? '')) {
        if (char === '.' && part) {
            wrapper.append(part, document.createElement('wbr'));
            part = '';
        }

        part += char;

        if (char === '@') {
            wrapper.append(part, document.createElement('wbr'));
            part = '';
        }
    }

    if (part) {
        wrapper.append(part);
    }

    return wrapper;
}

/*
 * Célula "Fidelidade" da lista de Clientes, com o resumo que vem em cada cliente do GET /api/clients:
 * "7/10" (visitas desde o último prêmio, por extenso para o leitor de tela) e um selo quando o cliente
 * tem prêmio para usar.
 */
function loyaltyCell(loyalty) {
    const wrapper = document.createElement('span');
    wrapper.className = 'd-inline-flex flex-wrap align-items-center gap-2';

    if (!loyalty) {
        wrapper.append(textElement('span', 'text-body-secondary', 'Sem dados'));

        return wrapper;
    }

    const progress = textElement('span', 'tabular text-nowrap', `${loyalty.progress}/${loyalty.visits_per_reward}`);
    progress.setAttribute('aria-hidden', 'true');
    wrapper.append(progress, textElement('span', 'visually-hidden', `${loyalty.progress} de ${countLabel(loyalty.visits_per_reward, 'visita')}`));

    /* text-wrap: no celular, o selo quebra em duas linhas em vez de alargar a tabela. */
    if (loyalty.available_rewards > 0) {
        const label = loyalty.available_rewards === 1 ? 'Prêmio disponível' : `${loyalty.available_rewards} prêmios disponíveis`;
        wrapper.append(textElement('span', 'badge text-bg-success text-wrap text-start', label));
    }

    return wrapper;
}

/*
 * "Página X de Y (N itens)" e os botões Anterior e Próxima de uma lista paginada. Botão desabilitado
 * perde o foco: se o foco estava num deles, passa para o outro.
 */
function updatePager({ meta, status, previous, next, singular, plural }) {
    status.textContent = `Página ${meta.current_page} de ${meta.last_page} (${countLabel(meta.total, singular, plural)})`;

    const focused = document.activeElement;
    previous.disabled = meta.current_page <= 1;
    next.disabled = meta.current_page >= meta.last_page;

    if (focused === next && next.disabled && !previous.disabled) {
        previous.focus();
    } else if (focused === previous && previous.disabled && !next.disabled) {
        next.focus();
    }
}

/*
 * Barras horizontais do Dashboard. Cada linha tem o rótulo, a barra e o valor escrito, então nenhum
 * número depende só da barra. A largura vai por el.style (a CSP barra só o style="" escrito no HTML),
 * em proporção ao maior valor da lista. items: [{ label, value, text, detail? }]. Com hideZeros, o
 * valor zero fica só para o leitor de tela: num mês, os dias sem faturamento não enchem a lista de "R$ 0,00".
 */
function renderBarList(container, items, emptyText, { hideZeros = false } = {}) {
    const max = Math.max(0, ...items.map((item) => item.value));

    if (max === 0) {
        container.replaceChildren(emptyMessage(emptyText));

        return;
    }

    const list = document.createElement('ul');
    list.className = 'bar-list';

    items.forEach((item) => {
        const row = document.createElement('li');
        row.className = 'bar-row';

        const track = document.createElement('span');
        track.className = 'bar-track';
        track.setAttribute('aria-hidden', 'true');

        const fill = document.createElement('span');
        fill.className = 'bar-fill';
        /* Um valor bem menor que o maior ainda aparece, com pelo menos 1% da largura. */
        fill.style.width = item.value > 0 ? `${Math.max(1, (item.value / max) * 100)}%` : '0';
        track.append(fill);

        let valueClass = 'bar-value';

        if (item.value === 0) {
            valueClass += hideZeros ? ' visually-hidden' : ' text-body-secondary';
        }

        const value = textElement('span', valueClass, item.text);

        if (item.detail) {
            value.append(textElement('span', 'd-block small text-body-secondary', item.detail));
        }

        row.append(textElement('span', 'bar-label', item.label), track, value);
        list.append(row);
    });

    container.replaceChildren(list);
}

/*
 * Modal de registrar e de corrigir pagamento (partials/payment-modal), da tela de Pagamentos e do
 * detalhe da Agenda:
 * - openRecord(agendamento, returnFocus): registra o pagamento de um atendimento concluído, com o valor
 *   já preenchido pelo preço copiado no agendamento. Confere a fidelidade do cliente sem contar este
 *   atendimento (a mesma conta do POST /api/payments): com prêmio disponível, mostra o aviso e a caixa
 *   "Usar o prêmio de fidelidade", que trava o valor no que sobra depois do desconto;
 * - openCorrect(pagamento, returnFocus): só o admin; corrige valor, forma e observações (o status tem
 *   ação própria).
 * Depois de salvar, chama onSaved(pagamento, 'recorded' ou 'corrected'). Quando a API diz que o
 * atendimento mudou (já tem pagamento, não está mais concluído, sumiu), chama onStale, para a tela ler
 * de novo. Ao fechar, o foco vai para o elemento que returnFocus(salvou) devolver.
 */
function createPaymentDialog(root, { onSaved, onStale }) {
    const modalElement = root.querySelector('[data-payment-modal]');
    const modal = new Modal(modalElement);
    const form = modalElement.querySelector('[data-payment-form]');
    const title = modalElement.querySelector('[data-payment-form-title]');
    const formAlerts = modalElement.querySelector('[data-payment-form-alerts]');
    const summary = modalElement.querySelector('[data-payment-summary]');
    const amountHint = modalElement.querySelector('[data-payment-amount-hint]');
    const submitButton = modalElement.querySelector('[data-payment-submit]');
    const amountInput = form.elements.namedItem('amount');
    const methodSelect = form.elements.namedItem('method');
    const notesInput = form.elements.namedItem('notes');
    const rewardBox = modalElement.querySelector('[data-loyalty-reward]');
    const rewardNotice = modalElement.querySelector('[data-loyalty-notice]');
    const rewardStatus = modalElement.querySelector('[data-loyalty-status]');
    const rewardCheckbox = form.elements.namedItem('use_loyalty_reward');

    /* O agendamento que vai receber o pagamento, ou o pagamento que está sendo corrigido. */
    let appointment = null;
    let payment = null;
    let returnFocus = null;
    let saved = false;
    let saving = false;
    /* Prêmio que este registro pode usar ({ percent, discount, amount }, valores em texto com 2 casas), ou null. */
    let reward = null;
    /* Valor que estava no campo quando a caixa do prêmio foi marcada: volta se ela for desmarcada. */
    let amountBeforeReward = '';
    /* Conta as consultas da fidelidade: a resposta que chega depois de o modal fechar ou mudar de atendimento não vale. */
    let loyaltyCheck = 0;

    function prepare(item, focusBack) {
        form.reset();
        clearFormErrors(form, formAlerts);
        returnFocus = focusBack;
        saved = false;

        reward = null;
        loyaltyCheck += 1;
        rewardBox.hidden = true;
        rewardStatus.hidden = true;
        amountInput.readOnly = false;

        summary.replaceChildren(
            summaryLine('Cliente', item.client?.name ?? ''),
            summaryLine('Barbeiro', item.barber?.name ?? ''),
            summaryLine('Serviço', item.service?.name ?? ''),
            summaryLine('Atendimento', item.date ? `${shortDate(item.date)}, ${timeRange(item)}` : ''),
        );
    }

    function recordHint(item) {
        return `Já vem com o preço do agendamento (${currency.format(Number(item.price))}). Mude se o cliente pagou outro valor.`;
    }

    function openRecord(item, focusBack) {
        appointment = item;
        payment = null;
        prepare(item, focusBack);
        title.textContent = 'Registrar pagamento';
        submitButton.textContent = 'Registrar pagamento';
        amountInput.value = Number(item.price).toFixed(2);
        amountHint.textContent = recordHint(item);
        modal.show();
        checkLoyalty(item);
    }

    function openCorrect(item, focusBack) {
        appointment = null;
        payment = item;
        prepare(item.appointment ?? {}, focusBack);
        title.textContent = 'Corrigir pagamento';
        submitButton.textContent = 'Salvar correção';
        amountInput.value = item.amount;
        methodSelect.value = item.method;
        notesInput.value = item.notes ?? '';

        const withReward = item.loyalty_reward ? `, com o prêmio de fidelidade (desconto de ${currency.format(Number(item.discount))})` : '';
        amountHint.textContent = `Valor registrado: ${currency.format(Number(item.amount))}${withReward}. A correção não muda o status nem a data do pagamento.`;
        modal.show();
    }

    /*
     * Fidelidade do cliente, sem contar o atendimento que está sendo pago. Com prêmio disponível, mostra o
     * aviso e a caixa. Cliente excluído (404) fica sem a caixa; outra falha só avisa, e o pagamento
     * continua podendo ser registrado sem o prêmio.
     */
    async function checkLoyalty(item) {
        const check = loyaltyCheck;

        if (!item.client_id) {
            return;
        }

        let result = null;

        try {
            result = await apiRequest('GET', `/api/clients/${item.client_id}/loyalty?appointment_id=${item.id}`);
        } catch {
            result = null;
        }

        if (check !== loyaltyCheck || appointment !== item) {
            return;
        }

        if (result?.ok && result.data) {
            offerReward(item, result.data);

            return;
        }

        if (result?.status === HTTP_NOT_FOUND) {
            return;
        }

        rewardStatus.textContent = 'Não foi possível conferir a fidelidade do cliente. O pagamento pode ser registrado sem o prêmio.';
        rewardStatus.hidden = false;
    }

    /*
     * Desconto e valor do prêmio, na mesma conta da API (LoyaltyService::rewardAmounts): em centavos, com o
     * desconto arredondado no centavo e o valor sendo o que sobra do preço.
     */
    function offerReward(item, loyalty) {
        if (loyalty.available_rewards < 1) {
            return;
        }

        const cents = Math.round(Number(item.price) * 100);
        const discount = Math.round((cents * loyalty.discount_percent) / 100);
        reward = {
            percent: loyalty.discount_percent,
            discount: (discount / 100).toFixed(2),
            amount: ((cents - discount) / 100).toFixed(2),
        };

        const prizes = loyalty.available_rewards === 1
            ? '1 prêmio de fidelidade disponível'
            : `${loyalty.available_rewards} prêmios de fidelidade disponíveis`;
        const benefit = reward.percent >= 100 ? 'este atendimento pode sair grátis' : `${reward.percent}% de desconto neste atendimento`;

        rewardNotice.textContent = `${item.client?.name ?? 'O cliente'} tem ${prizes}: ${benefit}.`;
        rewardCheckbox.checked = false;
        rewardBox.hidden = false;
    }

    /* Caixa do prêmio marcada: o valor fica travado no que sobra depois do desconto. Desmarcada, volta o que estava. */
    function applyReward() {
        clearFieldError(form, 'use_loyalty_reward');
        clearFieldError(form, 'amount');

        if (!appointment || !reward) {
            return;
        }

        if (rewardCheckbox.checked) {
            amountBeforeReward = amountInput.value;
            amountInput.value = reward.amount;
            amountInput.readOnly = true;
            amountHint.textContent = `Com o prêmio, o desconto é de ${currency.format(Number(reward.discount))} (${reward.percent}% do preço de ${currency.format(Number(appointment.price))}): o cliente paga ${currency.format(Number(reward.amount))}.`;

            return;
        }

        amountInput.readOnly = false;
        amountInput.value = amountBeforeReward;
        amountHint.textContent = recordHint(appointment);
    }

    async function save(event) {
        event.preventDefault();

        if (saving || (!appointment && !payment)) {
            return;
        }

        clearFormErrors(form, formAlerts);
        saving = true;
        submitButton.disabled = true;

        const values = formValues(form);
        const correcting = payment !== null;
        delete values.use_loyalty_reward;

        /* Com o prêmio, a API calcula o valor pelo desconto: o amount fica de fora (mandado junto, ela recusa). */
        if (!correcting && reward && rewardCheckbox.checked) {
            delete values.amount;
            values.use_loyalty_reward = true;
        }

        try {
            const result = correcting
                ? await apiRequest('PUT', `/api/payments/${payment.id}`, values)
                : await apiRequest('POST', '/api/payments', { appointment_id: appointment.id, ...values });

            if (result.ok) {
                saved = true;
                modal.hide();
                onSaved(result.data, correcting ? 'corrected' : 'recorded');

                return;
            }

            if (result.status === HTTP_UNPROCESSABLE && result.data?.errors) {
                /* O erro do agendamento (já tem pagamento, não está mais concluído) não tem campo: vai para o alerta do modal. */
                showFormErrors(form, formAlerts, result.data.errors);

                if (result.data.errors.appointment_id) {
                    onStale?.();
                }

                return;
            }

            showAlert(formAlerts, 'danger', result.data?.message ?? 'Não foi possível salvar o pagamento. Tente de novo.');

            /* 404 (o pagamento ou o agendamento sumiu) e 409 (o agendamento mudou no meio do caminho): a tela lê de novo. */
            if ([HTTP_NOT_FOUND, HTTP_CONFLICT].includes(result.status)) {
                onStale?.();
            }
        } catch {
            showAlert(formAlerts, 'danger', API_UNREACHABLE_MESSAGE);
        } finally {
            saving = false;
            submitButton.disabled = false;
        }
    }

    form.addEventListener('submit', save);
    ['amount', 'method', 'notes'].forEach((name) => {
        form.elements.namedItem(name).addEventListener('input', () => clearFieldError(form, name));
    });
    rewardCheckbox.addEventListener('change', applyReward);

    /* Registrar começa pela forma (o valor já vem preenchido); corrigir, pelo valor. */
    modalElement.addEventListener('shown.bs.modal', () => (payment ? amountInput : methodSelect).focus());
    modalElement.addEventListener('hidden.bs.modal', () => {
        const target = returnFocus?.(saved);
        appointment = null;
        payment = null;
        returnFocus = null;
        target?.focus();
    });

    return { openRecord, openCorrect };
}

/* Cadastros: barbeiros, clientes e serviços. */
function initCrud(root) {
    const resource = root.dataset.resource;
    const singular = root.dataset.singular;
    const plural = root.dataset.plural;
    const endpoint = `/api/${resource}`;
    const columns = COLUMNS[resource];
    /* Sem permissão para excluir (ex.: o barbeiro nos clientes), a tabela e a lista ficam sem o botão Excluir. */
    const canDelete = root.dataset.canDelete !== 'false';

    const alerts = root.querySelector('[data-alerts]');
    /* Os cadastros saem na tabela (tablet para cima) e na lista do celular; o CSS mostra uma das duas. */
    const rows = root.querySelector('[data-rows]');
    const mobileRows = root.querySelector('[data-mobile-rows]');
    const table = rows.closest('table');
    const headers = [...table.querySelectorAll('thead th')].map((header) => header.textContent.trim());
    const colspan = headers.length;

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

    /* Um dado do cadastro, igual na célula da tabela e na lista do celular. */
    function columnContent(item, column) {
        const value = item[column.key];

        /* Coluna com conteúdo próprio (ex.: o e-mail e a fidelidade dos clientes): render devolve o elemento. */
        if (column.render) {
            return column.render(value);
        }

        if (column.badge) {
            const status = STATUS_LABELS[value] ?? { text: value, badge: 'text-bg-light' };

            return textElement('span', `badge ${status.badge}`, status.text);
        }

        if (column.empty && (value === null || value === '')) {
            return textElement('span', 'text-body-secondary', column.empty);
        }

        return document.createTextNode(column.format ? column.format(value) : (value ?? ''));
    }

    function renderCell(item, column) {
        const cell = document.createElement('td');

        if (column.className) {
            cell.className = column.className;
        }

        cell.append(columnContent(item, column));

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

    /* Editar e Excluir num flex-wrap: numa coluna estreita, "Excluir" desce para baixo de "Editar". */
    function actionGroup(item) {
        const group = document.createElement('div');
        group.className = 'd-flex flex-wrap gap-2 justify-content-end';
        group.append(actionButton('Editar', 'btn-outline-primary', item, openForm));

        if (canDelete) {
            group.append(actionButton('Excluir', 'btn-outline-danger', item, openDelete));
        }

        return group;
    }

    function renderRow(item) {
        const row = document.createElement('tr');
        columns.forEach((column) => row.append(renderCell(item, column)));

        const actions = document.createElement('td');
        actions.className = 'text-end';
        actions.append(actionGroup(item));
        row.append(actions);

        return row;
    }

    /*
     * Item da lista do celular: o nome em cima; embaixo, cada um dos outros dados com o nome da coluna (o
     * texto do cabeçalho da tabela) ao lado; por último, os botões.
     */
    function renderItem(item) {
        const entry = document.createElement('li');
        entry.className = 'list-group-item';

        const details = document.createElement('dl');
        details.className = 'crud-details small mb-0';

        columns.forEach((column, index) => {
            if (column.key === 'name') {
                return;
            }

            const value = document.createElement('dd');
            value.className = 'mb-0';
            value.append(columnContent(item, column));
            details.append(textElement('dt', 'fw-normal text-body-secondary', headers[index]), value);
        });

        const actions = actionGroup(item);
        actions.classList.add('mt-2');

        entry.append(textElement('p', 'crud-name fw-semibold mb-1', item.name), details, actions);

        return entry;
    }

    /* Recado no lugar dos cadastros (carregando, vazio, erro), na tabela e na lista do celular. */
    function showMessage(text) {
        rows.replaceChildren(messageRow(colspan, text));
        mobileRows.replaceChildren(textElement('li', 'list-group-item text-center text-body-secondary py-4', text));
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
            showMessage(message);

            return;
        }

        rows.replaceChildren(...page.data.map(renderRow));
        mobileRows.replaceChildren(...page.data.map(renderItem));
    }

    async function load() {
        const loadNumber = ++loadCount;
        const params = new URLSearchParams({ page: String(currentPage) });

        if (search) {
            params.set('search', search);
        }

        showMessage('Carregando...');
        pageStatus.textContent = 'Carregando...';
        table.setAttribute('aria-busy', 'true');
        mobileRows.setAttribute('aria-busy', 'true');

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
        } catch (error) {
            if (loadNumber !== loadCount) {
                return;
            }

            showMessage('Não foi possível carregar a lista.');
            pageStatus.textContent = 'Não foi possível carregar a lista.';

            /* Sem permissão ou sem login, tentar de novo não adianta: mostra só a mensagem. */
            if (error instanceof ApiError) {
                loadErrorAlert = showAlert(alerts, 'danger', error.message);

                return;
            }

            const retry = showRetryAlert(alerts, API_UNREACHABLE_MESSAGE, () => {
                alerts.replaceChildren();
                load();
            });
            loadErrorAlert = retry.closest('.alert');
        } finally {
            if (loadNumber === loadCount) {
                table.removeAttribute('aria-busy');
                mobileRows.removeAttribute('aria-busy');
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

    function openForm(item) {
        form.reset();
        clearFormErrors(form, formAlerts);
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

    async function save(event) {
        event.preventDefault();
        clearFormErrors(form, formAlerts);
        submitButton.disabled = true;

        const method = editingId ? 'PUT' : 'POST';
        const url = editingId ? `${endpoint}/${editingId}` : endpoint;

        try {
            const result = await apiRequest(method, url, formValues(form));

            if (result.status === HTTP_UNPROCESSABLE && result.data?.errors) {
                showFormErrors(form, formAlerts, result.data.errors);

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
                /* 409: o cadastro tem agendamento futuro, e a mensagem da API diz o que fazer antes de excluir. */
                const type = result.status === HTTP_CONFLICT ? 'warning' : 'danger';
                showAlert(alerts, type, result.data?.message ?? 'Não foi possível excluir.');

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

/* Texto que o leitor de tela lê num agendamento: horário, cliente, serviço e status. */
function describeAppointment(appointment) {
    return `${timeRange(appointment)}, ${appointment.client?.name ?? 'cliente'}, ${appointment.service?.name ?? 'serviço'}, ${appointment.status_label}`;
}

/*
 * Medidas dos blocos da Agenda, em rem, as mesmas do pages.css: a altura de uma fatia
 * (--schedule-slot-height), a de uma linha do bloco (fonte de 0.75rem com line-height 1.2) e o que o
 * bloco gasta em cima e embaixo (padding de 0.125rem e borda de 1px, dos dois lados). Com elas, cada
 * bloco mostra só as linhas que cabem inteiras. Mudou uma no CSS, mude aqui também.
 */
const SCHEDULE_SLOT_REM = 4;
const SCHEDULE_LINE_REM = 0.9;
const SCHEDULE_BLOCK_EDGES_REM = 0.375;
/* Horário, cliente, serviço e status. */
const SCHEDULE_BLOCK_MAX_LINES = 4;

/* Quantas linhas inteiras cabem no bloco de um agendamento de `minutes` minutos, com fatias de `slotMinutes`. */
function scheduleBlockLines(minutes, slotMinutes) {
    const height = (minutes / slotMinutes) * SCHEDULE_SLOT_REM - SCHEDULE_BLOCK_EDGES_REM;
    /* A folga mínima cobre o arredondamento da conta, para a linha que cabe exata não ficar de fora. */
    const lines = Math.floor(height / SCHEDULE_LINE_REM + 1e-9);

    return Math.min(SCHEDULE_BLOCK_MAX_LINES, Math.max(0, lines));
}

/*
 * Linhas do bloco de um agendamento na Agenda. Com as 4 linhas: o horário ("10:00 às 10:30"), o cliente,
 * o serviço e o status. Num bloco mais curto, o início vai na linha do cliente ("10:00 · Bruno
 * Carvalho") e entram só as linhas que cabem inteiras: um bloco de 20 minutos mostra essa linha e o
 * serviço; um de 15, só ela. O detalhe completo continua no modal e no nome acessível do bloco.
 */
function scheduleBlockContent(appointment, lines) {
    const client = appointment.client?.name ?? '';
    const service = textElement('span', '', appointment.service?.name ?? '');
    const status = textElement('span', 'schedule-block-status', appointment.status_label);

    if (lines >= SCHEDULE_BLOCK_MAX_LINES) {
        return [textElement('span', 'schedule-block-time', timeRange(appointment)), textElement('span', 'fw-semibold', client), service, status];
    }

    const first = document.createElement('span');
    first.append(
        textElement('span', 'schedule-block-time', `${shortTime(appointment.start_time)} · `),
        textElement('span', 'fw-semibold', client),
    );

    return [first, service, status].slice(0, lines);
}

/*
 * Agenda do dia: uma coluna por barbeiro (o barbeiro vê só a dele), com cada agendamento num bloco da
 * altura da duração. No celular, as colunas viram uma lista por barbeiro. Clicar num agendamento abre o
 * detalhe, com as mudanças de status que valem agora, o atalho para remarcar, quando saiu o lembrete
 * por e-mail e o "Enviar lembrete agora".
 */
function initSchedule(root) {
    const opening = toMinutes(root.dataset.opening);
    const closing = toMinutes(root.dataset.closing);
    const slotMinutes = Math.max(1, Number(root.dataset.slotMinutes) || 1);
    const statuses = JSON.parse(root.dataset.statuses);
    const createUrl = root.dataset.createUrl;
    /* Quantas horas antes do horário o lembrete automático sai (REMINDER_HOURS_BEFORE), só para o texto do detalhe. */
    const reminderHours = Number(root.dataset.reminderHours) || 0;
    /* O barbeiro logado vê só a própria coluna. */
    const ownBarber = root.dataset.ownBarberId
        ? { id: Number(root.dataset.ownBarberId), name: root.dataset.ownBarberName }
        : null;

    const dateInput = root.querySelector('[data-date]');
    const dayTitle = root.querySelector('[data-day-title]');
    const newLink = root.querySelector('[data-new-appointment]');
    const alerts = root.querySelector('[data-alerts]');
    const live = root.querySelector('[data-live]');
    const board = root.querySelector('[data-board]');
    const boardMessage = root.querySelector('[data-board-message]');
    const gridCard = root.querySelector('[data-grid-card]');
    const grid = root.querySelector('[data-grid]');
    const list = root.querySelector('[data-list]');
    const inactive = root.querySelector('[data-inactive]');

    const modalElement = root.querySelector('[data-appointment-modal]');
    const modal = new Modal(modalElement);
    const modalTitle = root.querySelector('[data-modal-title]');
    const modalAlerts = root.querySelector('[data-modal-alerts]');
    const modalDetails = root.querySelector('[data-modal-details]');
    const modalHint = root.querySelector('[data-modal-hint]');
    const modalActions = root.querySelector('[data-modal-actions]');
    const confirmArea = root.querySelector('[data-modal-confirm]');
    const confirmText = root.querySelector('[data-modal-confirm-text]');
    const confirmBack = root.querySelector('[data-modal-confirm-back]');
    const confirmYes = root.querySelector('[data-modal-confirm-yes]');

    /* "Registrar pagamento" do detalhe: o mesmo modal da tela de Pagamentos (partials/payment-modal). */
    const paymentDialog = root.querySelector('[data-payment-modal]')
        ? createPaymentDialog(root, { onSaved: paymentRecorded, onStale: () => load() })
        : null;

    const initialParams = new URLSearchParams(window.location.search);
    let day = parseDay(initialParams.get('date')) ? initialParams.get('date') : today();
    let barbers = null;
    let appointments = [];
    /* Agendamento aberto no detalhe e o status que espera confirmação (cancelar ou faltou). */
    let current = null;
    let pendingStatus = null;
    /* Agendamento que recebe o pagamento: o modal do pagamento abre quando o detalhe termina de fechar. */
    let paying = null;
    /* Conta as cargas: se a pessoa troca de dia rápido, só a resposta da última é desenhada. */
    let loadCount = 0;
    let loadErrorAlert = null;

    function setDay(newDay) {
        if (!parseDay(newDay)) {
            return;
        }

        day = newDay;
        dateInput.value = day;
        dayTitle.textContent = day === today() ? `${longDate(day)} (hoje)` : longDate(day);
        newLink.href = `${createUrl}?date=${day}`;

        /* O dia fica no endereço: recarregar a página, ou voltar do Agendar, abre no mesmo dia. */
        const url = new URL(window.location.href);
        url.searchParams.set('date', day);
        url.searchParams.delete('notice');
        window.history.replaceState(null, '', url);

        load();
    }

    async function loadBarbers() {
        if (ownBarber) {
            return [ownBarber];
        }

        return fetchItems(`/api/barbers?per_page=${MAX_PER_PAGE}`);
    }

    async function load() {
        const loadNumber = ++loadCount;

        board.setAttribute('aria-busy', 'true');
        boardMessage.hidden = false;
        boardMessage.textContent = 'Carregando a agenda...';
        live.textContent = 'Carregando a agenda...';
        gridCard.hidden = true;
        [grid, list, inactive].forEach((element) => element.replaceChildren());

        try {
            const [barberList, result] = await Promise.all([
                barbers ?? loadBarbers(),
                fetchAllItems(`/api/appointments?date=${day}`),
            ]);

            if (loadNumber !== loadCount) {
                return;
            }

            barbers = barberList;
            appointments = result.items;
            render();
            loadErrorAlert?.remove();
            loadErrorAlert = null;

            const total = appointments.length;
            const count = total === 1 ? '1 agendamento' : `${total === 0 ? 'nenhum' : total} agendamento${total === 0 ? '' : 's'}`;
            live.textContent = barbers.length === 0 && total === 0 ? boardMessage.textContent : `Agenda de ${longDateInSentence(day)}: ${count}.`;

            if (!result.complete) {
                loadErrorAlert = showAlert(alerts, 'warning', 'Este dia tem agendamentos demais para a tela: só parte deles aparece.');
            }
        } catch (error) {
            if (loadNumber !== loadCount) {
                return;
            }

            boardMessage.textContent = 'Não foi possível carregar a agenda.';
            live.textContent = '';

            /* Sem permissão ou sem login, tentar de novo não adianta: mostra só a mensagem. */
            if (error instanceof ApiError) {
                loadErrorAlert = showAlert(alerts, 'danger', error.message);

                return;
            }

            const retry = showRetryAlert(alerts, API_UNREACHABLE_MESSAGE, () => {
                alerts.replaceChildren();
                load();
            });
            loadErrorAlert = retry.closest('.alert');
        } finally {
            if (loadNumber === loadCount) {
                board.removeAttribute('aria-busy');
            }
        }
    }

    /*
     * Barbeiros do dia: os da lista e, no histórico, quem foi excluído mas tem algum dos agendamentos
     * informados naquele dia.
     */
    function barbersFor(items) {
        const columns = new Map(barbers.map((barber) => [barber.id, barber.name]));

        items.forEach((appointment) => {
            if (!columns.has(appointment.barber_id)) {
                columns.set(appointment.barber_id, appointment.barber?.name ?? `Barbeiro ${appointment.barber_id}`);
            }
        });

        return [...columns].map(([id, name]) => ({ id, name }));
    }

    function render() {
        /* Na grade, só ganha coluna extra quem ocupa horário; na lista do celular entram também os cancelados. */
        const occupying = appointments.filter((appointment) => statuses[appointment.status]?.occupies_time);
        const listed = barbersFor(appointments);

        if (listed.length === 0) {
            boardMessage.textContent = 'Nenhum barbeiro cadastrado. Cadastre barbeiros para montar a agenda.';

            return;
        }

        boardMessage.hidden = true;
        renderGrid(barbersFor(occupying), occupying);
        renderList(listed);
        renderInactive();
    }

    function openButton(appointment, className) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.dataset.appointmentId = appointment.id;
        button.setAttribute('aria-label', describeAppointment(appointment));
        button.addEventListener('click', () => openDetails(appointment.id));

        return button;
    }

    function textSpan(className, text) {
        const span = document.createElement('span');
        span.className = className;
        span.textContent = text;

        return span;
    }

    /*
     * Colunas com os blocos. A grade vai da abertura ao fechamento e cresce, de fatia em fatia, se algum
     * agendamento sair do expediente (ex.: o expediente mudou depois de agendar). Altura e posição dos
     * blocos vão por el.style, que a CSP permite (ela barra só o style="" escrito no HTML).
     */
    function renderGrid(columns, occupying) {
        const first = Math.min(opening, ...occupying.map((appointment) => toMinutes(appointment.start_time)));
        const last = Math.max(closing, ...occupying.map((appointment) => toMinutes(appointment.end_time)));
        const gridStart = opening - Math.ceil((opening - first) / slotMinutes) * slotMinutes;
        const gridEnd = opening + Math.ceil((last - opening) / slotMinutes) * slotMinutes;
        const rows = (gridEnd - gridStart) / slotMinutes;
        const height = `calc(${rows} * var(--schedule-slot-height))`;

        const schedule = document.createElement('div');
        schedule.className = 'schedule';

        /* Coluna das horas: só visual, porque cada bloco já diz o horário para o leitor de tela. */
        const times = document.createElement('div');
        times.className = 'schedule-times';
        times.setAttribute('aria-hidden', 'true');

        const corner = document.createElement('div');
        corner.className = 'schedule-head';

        const labels = document.createElement('div');
        labels.className = 'schedule-labels';
        labels.style.height = height;

        for (let minutes = gridStart; minutes < gridEnd; minutes += slotMinutes) {
            labels.append(textSpan('schedule-time', formatMinutes(minutes)));
        }

        times.append(corner, labels);
        schedule.append(times);

        columns.forEach((column) => {
            const section = document.createElement('section');
            section.className = 'schedule-column';
            section.setAttribute('aria-labelledby', `schedule-column-${column.id}`);

            const head = document.createElement('h3');
            head.className = 'schedule-head';
            head.id = `schedule-column-${column.id}`;
            head.append(textSpan('text-truncate', column.name));

            const body = document.createElement('div');
            body.className = 'schedule-body';
            body.style.height = height;

            const own = occupying.filter((appointment) => appointment.barber_id === column.id);

            if (own.length === 0) {
                body.append(textSpan('visually-hidden', 'Nenhum agendamento neste dia.'));
            }

            own.forEach((appointment) => {
                const start = toMinutes(appointment.start_time);
                const end = toMinutes(appointment.end_time);
                const block = openButton(appointment, 'schedule-block');
                block.dataset.status = appointment.status;
                block.style.top = `calc(${(start - gridStart) / slotMinutes} * var(--schedule-slot-height))`;
                block.style.height = `calc(${(end - start) / slotMinutes} * var(--schedule-slot-height))`;
                block.append(...scheduleBlockContent(appointment, scheduleBlockLines(end - start, slotMinutes)));
                body.append(block);
            });

            section.append(head, body);
            schedule.append(section);
        });

        grid.replaceChildren(schedule);
        gridCard.hidden = columns.length === 0;
    }

    /* Item de lista de um agendamento (celular e "Cancelados e faltas"). */
    function listItem(appointment, withBarber = false) {
        const item = document.createElement('li');
        item.className = 'list-group-item p-0';

        const button = openButton(
            appointment,
            'list-group-item-action border-0 bg-transparent w-100 text-start d-flex flex-wrap gap-2 justify-content-between align-items-center px-3 py-2',
        );

        const text = document.createElement('span');
        text.className = 'd-flex flex-column';
        text.append(textSpan('fw-semibold', timeRange(appointment)));

        const who = withBarber
            ? `${appointment.client?.name ?? ''} com ${appointment.barber?.name ?? ''}`
            : (appointment.client?.name ?? '');
        text.append(textSpan('text-break', who), textSpan('small text-body-secondary', appointment.service?.name ?? ''));

        if (withBarber) {
            button.setAttribute('aria-label', `${describeAppointment(appointment)}, com ${appointment.barber?.name ?? 'o barbeiro'}`);
        }

        button.append(text, statusBadge(appointment));
        item.append(button);

        return item;
    }

    function renderList(columns) {
        const sections = columns.map((column) => {
            const section = document.createElement('section');
            section.className = 'mb-4';
            section.setAttribute('aria-labelledby', `schedule-list-${column.id}`);

            const title = document.createElement('h3');
            title.className = 'h6 mb-2';
            title.id = `schedule-list-${column.id}`;
            title.textContent = column.name;
            section.append(title);

            const own = appointments.filter((appointment) => appointment.barber_id === column.id);

            if (own.length === 0) {
                section.append(emptyMessage('Nenhum agendamento neste dia.'));

                return section;
            }

            const items = document.createElement('ul');
            items.className = 'list-group';
            items.append(...own.map((appointment) => listItem(appointment)));
            section.append(items);

            return section;
        });

        list.replaceChildren(...sections);
    }

    function renderInactive() {
        const others = appointments.filter((appointment) => !statuses[appointment.status]?.occupies_time);

        if (others.length === 0) {
            inactive.replaceChildren();

            return;
        }

        const title = document.createElement('h3');
        title.className = 'h6 mb-2';
        title.textContent = 'Cancelados e faltas';

        const items = document.createElement('ul');
        items.className = 'list-group';
        items.append(...others.map((appointment) => listItem(appointment, true)));

        inactive.replaceChildren(title, items);
    }

    /* Mudanças de status que valem agora: as do catálogo, e as que pedem o início só depois dele. */
    function availableActions(appointment) {
        const rules = statuses[appointment.status];
        const started = secondsUntil(appointment.date, appointment.start_time) <= 0;
        const now = [];
        const later = [];

        Object.keys(STATUS_ACTIONS).forEach((status) => {
            if (!rules?.transitions.includes(status)) {
                return;
            }

            if (statuses[status]?.requires_start && !started) {
                later.push(status);
            } else {
                now.push(status);
            }
        });

        return { now, later };
    }

    /* O agendamento ainda recebe o lembrete por e-mail: agendado ou confirmado (catalog().remindable) e antes do início. */
    function remindableNow(appointment) {
        return Boolean(statuses[appointment.status]?.remindable) && secondsUntil(appointment.date, appointment.start_time) > 0;
    }

    /*
     * "Enviar lembrete agora": o admin, em qualquer agendamento, e o barbeiro, nos da agenda dele (a mesma regra
     * da AppointmentPolicy::sendReminder). Quem decide é a API: o resto das regras volta como 422 ou 429.
     */
    function canSendReminder(appointment) {
        return remindableNow(appointment) && (!ownBarber || appointment.barber_id === ownBarber.id);
    }

    function detail(term, value) {
        const dt = document.createElement('dt');
        dt.className = 'col-sm-4 fw-normal text-body-secondary';
        dt.textContent = term;

        const dd = document.createElement('dd');
        dd.className = 'col-sm-8 text-break';

        if (value instanceof Node) {
            dd.append(value);
        } else {
            dd.textContent = value;
        }

        return [dt, dd];
    }

    function renderDetails() {
        const appointment = current;
        const { now, later } = availableActions(appointment);

        modalTitle.textContent = `${appointment.client?.name ?? 'Agendamento'} às ${shortTime(appointment.start_time)}`;

        const details = [
            ...detail('Cliente', appointment.client?.name ?? ''),
            ...detail('Barbeiro', appointment.barber?.name ?? ''),
            ...detail('Serviço', appointment.service?.name ?? ''),
            ...detail('Data', longDate(appointment.date)),
            ...detail('Horário', timeRange(appointment)),
            ...detail('Status', statusBadge(appointment)),
            ...detail('Valor', currency.format(Number(appointment.price))),
        ];

        /* Atendimento concluído: o status do pagamento, ou que ele ainda não foi registrado. */
        if (appointment.payment) {
            details.push(...detail('Pagamento', paymentBadge(appointment.payment)));
        } else if (appointment.status === 'completed') {
            details.push(...detail('Pagamento', 'Não registrado'));
        }

        /* Lembrete por e-mail: quando saiu ou, enquanto ainda pode sair, que não saiu. */
        if (appointment.reminder_sent_at) {
            details.push(...detail('Lembrete', `Enviado em ${dateTimeInAppTimezone(appointment.reminder_sent_at)}`));
        } else if (remindableNow(appointment)) {
            const automatic = reminderHours > 0
                ? ` O e-mail automático sai nas ${reminderHours} ${reminderHours === 1 ? 'hora' : 'horas'} antes do horário.`
                : '';
            details.push(...detail('Lembrete', `Ainda não enviado.${automatic}`));
        }

        if (appointment.notes) {
            details.push(...detail('Observações', appointment.notes));
        }

        modalDetails.replaceChildren(...details);

        const waiting = later.map((status) => STATUS_ACTIONS[status].label);
        modalHint.hidden = waiting.length === 0;
        modalHint.textContent = waiting.length === 0
            ? ''
            : `${joinNames(waiting)} ${waiting.length === 1 ? 'fica disponível' : 'ficam disponíveis'} a partir das ${shortTime(appointment.start_time)} de ${shortDate(appointment.date)}.`;

        const buttons = now.map((status) => {
            const action = STATUS_ACTIONS[status];
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `btn ${action.className}`;
            button.textContent = action.label;
            button.dataset.status = status;

            if (action.confirm) {
                button.dataset.confirm = 'true';
            }

            button.addEventListener('click', () => (action.confirm ? askConfirmation(status) : changeStatus(status)));

            return button;
        });

        /* Concluído sem pagamento: o detalhe fecha e abre o modal do pagamento (o Bootstrap mostra um modal por vez). */
        if (paymentDialog && appointment.status === 'completed' && !appointment.payment) {
            const record = document.createElement('button');
            record.type = 'button';
            record.className = 'btn btn-success';
            record.textContent = 'Registrar pagamento';
            record.dataset.recordPayment = 'true';
            record.addEventListener('click', () => {
                paying = appointment;
                modal.hide();
            });
            buttons.push(record);
        }

        if (canSendReminder(appointment)) {
            const remind = document.createElement('button');
            remind.type = 'button';
            remind.className = 'btn btn-outline-primary';
            remind.textContent = 'Enviar lembrete agora';
            remind.dataset.sendReminder = 'true';
            remind.addEventListener('click', sendReminder);
            buttons.push(remind);
        }

        if (statuses[appointment.status]?.reschedulable) {
            const reschedule = document.createElement('a');
            reschedule.className = 'btn btn-outline-secondary';
            reschedule.href = `${createUrl}?appointment=${appointment.id}`;
            reschedule.textContent = 'Remarcar';
            buttons.push(reschedule);
        }

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn btn-secondary';
        close.setAttribute('data-bs-dismiss', 'modal');
        close.textContent = 'Fechar';

        modalActions.replaceChildren(...buttons, close);
        confirmArea.hidden = true;
        modalActions.hidden = false;
        pendingStatus = null;
    }

    function openDetails(id) {
        current = appointments.find((appointment) => appointment.id === id) ?? null;

        if (!current) {
            return;
        }

        modalAlerts.replaceChildren();
        renderDetails();
        modal.show();
    }

    function askConfirmation(status) {
        const action = STATUS_ACTIONS[status];
        pendingStatus = status;
        confirmText.textContent = action.confirm(current.client?.name ?? 'o cliente', shortTime(current.start_time));
        confirmYes.textContent = action.confirmLabel;
        modalActions.hidden = true;
        confirmArea.hidden = false;
        confirmBack.focus();
    }

    function setActionsDisabled(disabled) {
        [...modalActions.querySelectorAll('button'), confirmBack, confirmYes].forEach((button) => {
            button.disabled = disabled;
        });
    }

    /*
     * Depois de mudar o status, o foco vai para a próxima ação que leva o atendimento adiante (ex.: Concluir
     * depois de Iniciar, Registrar pagamento depois de Concluir) ou, sem ela, para Fechar. Nunca para
     * Cancelar ou Faltou.
     */
    function focusFirstAction() {
        const forward = modalActions.querySelector('button[data-status]:not([data-confirm]), button[data-record-payment]');
        (forward ?? modalActions.querySelector('[data-bs-dismiss="modal"]'))?.focus();
    }

    async function changeStatus(status) {
        const appointment = current;
        setActionsDisabled(true);
        modalAlerts.replaceChildren();

        try {
            const result = await apiRequest('PATCH', `/api/appointments/${appointment.id}/status`, { status });

            if (result.ok) {
                appointments = appointments.map((item) => (item.id === result.data.id ? result.data : item));
                render();

                if (current?.id === result.data.id) {
                    current = result.data;
                    renderDetails();
                    focusFirstAction();
                }

                /* O alerta (role="alert") também avisa o leitor de tela. */
                showAlert(
                    modalAlerts,
                    'success',
                    `Agendamento de ${result.data.client?.name ?? 'cliente'} às ${shortTime(result.data.start_time)} mudou para ${result.data.status_label}.`,
                );

                return;
            }

            const message = result.data?.errors?.status?.[0] ?? result.data?.message ?? 'Não foi possível mudar o status. Tente de novo.';
            showAlert(modalAlerts, 'danger', message);

            /* 422: o agendamento pode ter mudado em outro lugar. A agenda é lida de novo e o detalhe acompanha. */
            if (result.status === HTTP_UNPROCESSABLE) {
                await load();
                const fresh = appointments.find((item) => item.id === appointment.id);

                if (fresh && current?.id === fresh.id) {
                    current = fresh;
                    renderDetails();
                    focusFirstAction();
                }
            }
        } catch {
            showAlert(modalAlerts, 'danger', API_UNREACHABLE_MESSAGE);
        } finally {
            setActionsDisabled(false);
        }
    }

    /* Depois do lembrete, o foco fica no próprio botão (ele continua lá) ou, se ele sumiu, na próxima ação. */
    function focusReminderButton() {
        const button = modalActions.querySelector('[data-send-reminder]');

        if (button) {
            button.focus();
        } else {
            focusFirstAction();
        }
    }

    /*
     * POST /api/appointments/{id}/reminder. Deu certo: o detalhe mostra a hora do envio e avisa. Recusas:
     * 422 (o status mudou, o horário já começou, cliente excluído ou sem e-mail que receba) e 429 (saiu um
     * lembrete há menos de 10 minutos), com a mensagem da API; 503 quando o e-mail não saiu.
     */
    async function sendReminder() {
        const appointment = current;

        if (!appointment) {
            return;
        }

        setActionsDisabled(true);
        modalAlerts.replaceChildren();

        try {
            const result = await apiRequest('POST', `/api/appointments/${appointment.id}/reminder`);

            if (result.ok && result.data?.id) {
                appointments = appointments.map((item) => (item.id === result.data.id ? result.data : item));
                render();

                if (current?.id === result.data.id) {
                    current = result.data;
                    renderDetails();
                    focusReminderButton();
                }

                showAlert(modalAlerts, 'success', `Lembrete enviado por e-mail para ${result.data.client?.name ?? 'o cliente'}.`);

                return;
            }

            const message = result.data?.errors?.reminder?.[0] ?? result.data?.message ?? 'Não foi possível enviar o lembrete. Tente de novo.';
            showAlert(modalAlerts, result.status === HTTP_TOO_MANY_REQUESTS ? 'warning' : 'danger', message);

            /* 422 e 404: o agendamento pode ter mudado em outro lugar. A agenda é lida de novo e o detalhe acompanha. */
            if ([HTTP_UNPROCESSABLE, HTTP_NOT_FOUND].includes(result.status)) {
                await load();
                const fresh = appointments.find((item) => item.id === appointment.id);

                if (fresh && current?.id === fresh.id) {
                    current = fresh;
                    renderDetails();
                    focusReminderButton();
                }
            }
        } catch {
            showAlert(modalAlerts, 'danger', API_UNREACHABLE_MESSAGE);
        } finally {
            setActionsDisabled(false);

            /* O botão desabilitado durante o pedido perde o foco: com o detalhe ainda aberto, o foco volta para ele. */
            if (current && !modalElement.contains(document.activeElement)) {
                focusReminderButton();
            }
        }
    }

    root.querySelector('[data-day-previous]').addEventListener('click', () => setDay(addDays(day, -1)));
    root.querySelector('[data-day-next]').addEventListener('click', () => setDay(addDays(day, 1)));
    root.querySelector('[data-day-today]').addEventListener('click', () => setDay(today()));
    dateInput.addEventListener('change', () => setDay(dateInput.value));

    confirmBack.addEventListener('click', () => {
        renderDetails();
        focusFirstAction();
    });
    confirmYes.addEventListener('click', () => {
        if (pendingStatus) {
            changeStatus(pendingStatus);
        }
    });

    /* O bloco ou o item da lista do agendamento que está na tela (o outro fica escondido, conforme a largura). */
    function visibleAppointment(id) {
        return [...root.querySelectorAll(`[data-appointment-id="${id}"]`)].find((element) => element.offsetParent !== null) ?? null;
    }

    /* Pagamento registrado pelo detalhe: o agendamento passa a mostrar o status dele, sem ler a agenda de novo. */
    function paymentRecorded(payment) {
        const summary = { id: payment.id, status: payment.status, status_label: payment.status_label };
        appointments = appointments.map((item) => (item.id === payment.appointment_id ? { ...item, payment: summary } : item));
        render();
        showAlert(alerts, 'success', `Pagamento de ${payment.appointment?.client?.name ?? 'cliente'} registrado: ${describePaymentValue(payment)}.`);
    }

    /*
     * Ao fechar o detalhe, o foco volta para o agendamento que estiver na tela (bloco ou item da lista). Com o
     * "Registrar pagamento", abre o modal do pagamento, e o foco volta para o agendamento quando ele fechar.
     */
    modalElement.addEventListener('hidden.bs.modal', () => {
        const id = current?.id;
        const appointment = paying;
        current = null;
        pendingStatus = null;
        paying = null;

        if (appointment) {
            paymentDialog.openRecord(appointment, () => visibleAppointment(appointment.id) ?? dateInput);

            return;
        }

        (visibleAppointment(id) ?? dateInput).focus();
    });

    /* Recado do Agendar (?notice=created ou rescheduled). O setDay tira o recado do endereço. */
    const notices = { created: 'Agendamento criado.', rescheduled: 'Agendamento remarcado.' };

    if (notices[initialParams.get('notice')]) {
        showAlert(alerts, 'success', notices[initialParams.get('notice')]);
    }

    setDay(day);
}

/* GET de um agendamento, com as recusas da API viradas em ApiError (mostradas como vieram). */
async function fetchAppointment(id) {
    if (!/^\d+$/.test(id)) {
        throw new ApiError('Não encontramos este agendamento. Confira o link.');
    }

    const result = await apiRequest('GET', `/api/appointments/${id}`);

    if (result.ok && result.data?.id) {
        return result.data;
    }

    if (result.status === HTTP_NOT_FOUND) {
        throw new ApiError('Não encontramos este agendamento. Confira o link.');
    }

    if ([HTTP_UNAUTHORIZED, HTTP_FORBIDDEN, HTTP_SESSION_EXPIRED].includes(result.status)) {
        throw new ApiError(result.data?.message ?? FORBIDDEN_MESSAGE);
    }

    throw new Error('Falha ao carregar o agendamento');
}

/*
 * Agendar: a tela da barbearia (data-mode="staff", com cliente e observações, que também remarca com
 * ?appointment={id}) e a do cliente (data-mode="client", que agenda para si). Com os campos
 * preenchidos, busca os horários livres do barbeiro e mostra cada um como um botão.
 */
function initAppointmentForm(root) {
    const isStaff = root.dataset.mode === 'staff';
    const statuses = JSON.parse(root.dataset.statuses ?? '{}');
    const businessDays = (root.dataset.businessDays ?? '').split(',').filter(Boolean).map(Number);
    /* Até quantos dias depois de hoje dá para agendar (BOOKING_DAYS_AHEAD). */
    const bookingDaysAhead = Math.max(0, Number(root.dataset.bookingDaysAhead) || 0);
    const ownBarberId = root.dataset.ownBarberId ?? '';
    const params = new URLSearchParams(window.location.search);
    const rescheduleId = isStaff ? params.get('appointment') : null;

    const form = root.querySelector('[data-form]');
    const alerts = root.querySelector('[data-alerts]');
    const formAlerts = root.querySelector('[data-form-alerts]');
    const live = root.querySelector('[data-live]');
    const heading = root.querySelector('[data-form-heading]');
    const backLink = root.querySelector('[data-back-link]');
    const submitButton = root.querySelector('[data-submit]');
    const summary = root.querySelector('[data-summary]');
    const slotGroup = root.querySelector('[data-error-group="start_time"]');
    const slotsBox = root.querySelector('[data-slots]');
    const slotsStatus = root.querySelector('[data-slots-status]');
    const clientHint = root.querySelector('[data-client-hint]');

    const clientSelect = form.elements.namedItem('client_id');
    const barberSelect = form.elements.namedItem('barber_id');
    const serviceSelect = form.elements.namedItem('service_id');
    const dateInput = form.elements.namedItem('date');
    const notesInput = form.elements.namedItem('notes');

    /* Campos que precisam estar preenchidos para buscar os horários livres. */
    const fieldsForSlots = [clientSelect, barberSelect, serviceSelect, dateInput].filter(Boolean);
    const missingFieldsMessage = isStaff
        ? 'Escolha cliente, barbeiro, serviço e data para ver os horários livres.'
        : 'Escolha barbeiro, serviço e data para ver os horários livres.';

    let services = [];
    /* O agendamento que está sendo remarcado, ou null num agendamento novo. */
    let rescheduling = null;
    /* Horário que fica marcado quando a lista chega (na remarcação, o atual). */
    let preferredTime = null;
    /* Conta as buscas de horários: só a resposta da última é desenhada. */
    let slotsLoad = 0;
    /* Remarcação de um agendamento que não pode mais mudar: o formulário fica travado. */
    let locked = false;
    let saving = false;

    function selectedTime() {
        return slotsBox.querySelector('input[name="start_time"]:checked')?.value ?? null;
    }

    /* Última data aceita para agendar: hoje mais o limite de dias. */
    function lastBookableDay() {
        return addDays(today(), bookingDaysAhead);
    }

    /*
     * Na remarcação: true quando barbeiro, serviço e data são os do agendamento e o horário é o mesmo ou
     * nenhum foi marcado (o horário atual sai da lista de livres quando já passou).
     */
    function keepsTheSchedule(schedule) {
        return schedule.barber_id === String(rescheduling.barber_id)
            && schedule.service_id === String(rescheduling.service_id)
            && schedule.date === rescheduling.date
            && (schedule.start_time === null || schedule.start_time === shortTime(rescheduling.start_time));
    }

    function selectedText(select) {
        return select.value ? (select.selectedOptions[0]?.textContent ?? '') : '';
    }

    function updateSummary() {
        const service = services.find((item) => String(item.id) === serviceSelect.value);
        const time = selectedTime();
        const lines = [];

        if (clientSelect?.value) {
            lines.push(summaryLine('Cliente', selectedText(clientSelect)));
        }

        if (barberSelect.value) {
            lines.push(summaryLine('Barbeiro', selectedText(barberSelect)));
        }

        if (service) {
            /* Na remarcação sem troca de serviço, o preço copiado no agendamento continua valendo. */
            const keepsPrice = rescheduling && rescheduling.service_id === service.id;
            lines.push(
                summaryLine('Serviço', service.name),
                summaryLine('Duração', `${service.duration} min`),
                summaryLine('Valor', currency.format(Number(keepsPrice ? rescheduling.price : service.price))),
            );
        }

        if (parseDay(dateInput.value)) {
            lines.push(summaryLine('Data', shortDate(dateInput.value)));
        }

        if (time && service) {
            lines.push(summaryLine('Horário', `${time} às ${formatMinutes(toMinutes(time) + Number(service.duration))}`));
        }

        if (lines.length === 0) {
            summary.replaceChildren(emptyMessage('Escolha o serviço, a data e o horário para ver o resumo.'));

            return;
        }

        const list = document.createElement('dl');
        list.className = 'mb-0';
        list.append(...lines);
        summary.replaceChildren(list);
    }

    function renderSlots(times, message, selected = null) {
        slotsStatus.textContent = message;

        const options = times.flatMap((time, index) => {
            const input = document.createElement('input');
            input.type = 'radio';
            input.className = 'btn-check';
            input.name = 'start_time';
            input.id = `appointment-slot-${index}`;
            input.value = time;
            input.checked = time === selected;
            input.addEventListener('change', () => {
                clearFieldError(form, 'start_time');
                updateSummary();
            });

            const label = document.createElement('label');
            label.className = 'btn btn-outline-primary slot-button';
            label.htmlFor = input.id;
            label.textContent = time;

            return [input, label];
        });

        slotsBox.replaceChildren(...options);
        updateSummary();
    }

    /* Busca os horários livres quando os campos estão preenchidos. Mantém marcado o horário escolhido, se ele continuar livre. */
    async function refreshSlots() {
        const loadNumber = ++slotsLoad;
        const selected = selectedTime() ?? preferredTime;

        if (locked) {
            renderSlots([], 'Este agendamento não pode mais ser remarcado.');

            return;
        }

        if (fieldsForSlots.some((field) => !field.value)) {
            renderSlots([], missingFieldsMessage);

            return;
        }

        const day = dateInput.value;

        if (!parseDay(day)) {
            renderSlots([], 'Escolha uma data válida.');

            return;
        }

        if (day < today()) {
            renderSlots([], 'Essa data já passou. Escolha uma data a partir de hoje.');

            return;
        }

        if (day > lastBookableDay()) {
            renderSlots([], `Só dá para agendar até ${shortDate(lastBookableDay())}. Escolha uma data mais próxima.`);

            return;
        }

        if (!businessDays.includes(isoWeekday(day))) {
            renderSlots([], 'A barbearia não abre nesse dia. Escolha outra data.');

            return;
        }

        renderSlots([], 'Buscando os horários livres...');
        slotGroup.setAttribute('aria-busy', 'true');

        const query = new URLSearchParams({ date: day, service_id: serviceSelect.value });

        if (rescheduling) {
            query.set('appointment_id', String(rescheduling.id));
        }

        try {
            const result = await apiRequest('GET', `/api/barbers/${barberSelect.value}/available-slots?${query}`);

            if (loadNumber !== slotsLoad) {
                return;
            }

            /* 422: ex.: o serviço ficou inativo depois que a tela carregou. O erro vai para o campo. */
            if (result.status === HTTP_UNPROCESSABLE && result.data?.errors) {
                showFormErrors(form, formAlerts, result.data.errors);
                renderSlots([], 'Corrija os campos acima para ver os horários livres.');

                return;
            }

            if (!result.ok || !Array.isArray(result.data?.data)) {
                throw new ApiError(result.data?.message ?? 'Não foi possível buscar os horários livres.');
            }

            const times = result.data.data;
            let message = 'Nenhum horário livre nesse dia para este serviço. Escolha outra data ou outro barbeiro.';

            if (times.length > 0) {
                message = times.length === 1 ? '1 horário livre. Escolha o horário.' : `${times.length} horários livres. Escolha um.`;
            }

            renderSlots(times, message, times.includes(selected) ? selected : null);
            live.textContent = message;
        } catch (error) {
            if (loadNumber !== slotsLoad) {
                return;
            }

            renderSlots([], error instanceof ApiError ? error.message : 'Não foi possível buscar os horários livres.');

            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'btn btn-sm btn-outline-secondary';
            retry.textContent = 'Tentar de novo';
            retry.addEventListener('click', () => refreshSlots());
            slotsBox.replaceChildren(retry);
        } finally {
            if (loadNumber === slotsLoad) {
                slotGroup.removeAttribute('aria-busy');
            }
        }
    }

    /* Remarcar: o formulário abre com os dados do agendamento, e o cliente fica fixo (a API não muda o cliente). */
    function startRescheduling(appointment) {
        rescheduling = appointment;
        preferredTime = shortTime(appointment.start_time);
        heading.textContent = 'Remarcar agendamento';
        document.title = 'Remarcar agendamento · BarberFlow';
        submitButton.textContent = 'Salvar remarcação';
        backLink.href = `${root.dataset.successUrl}?date=${appointment.date}`;

        ensureOption(clientSelect, appointment.client);
        clientSelect.value = String(appointment.client_id);
        clientSelect.disabled = true;
        clientHint.hidden = false;
        addDescription(clientSelect, clientHint.id);

        if (!ownBarberId) {
            ensureOption(barberSelect, appointment.barber);
            barberSelect.value = String(appointment.barber_id);
        }

        if (services.some((service) => service.id === appointment.service_id)) {
            serviceSelect.value = String(appointment.service_id);
        } else {
            showAlert(formAlerts, 'warning', `O serviço deste agendamento (${appointment.service?.name ?? 'excluído'}) não está mais ativo. Escolha outro.`);
        }

        dateInput.value = appointment.date;

        if (notesInput) {
            notesInput.value = appointment.notes ?? '';
        }

        if (!statuses[appointment.status]?.reschedulable) {
            const allowed = Object.values(statuses)
                .filter((status) => status.reschedulable)
                .map((status) => status.label);

            locked = true;
            Array.from(form.elements).forEach((element) => {
                element.disabled = true;
            });
            showAlert(
                alerts,
                'warning',
                `Este agendamento está com o status ${appointment.status_label} e não pode mais ser remarcado. Só dá para remarcar com o status ${allowed.join(' ou ')}.`,
            );
        }
    }

    async function loadLists({ retrying = false } = {}) {
        alerts.replaceChildren();
        const lists = [clientSelect, ownBarberId ? null : barberSelect, serviceSelect].filter(Boolean);
        lists.forEach((select) => fillSelect(select, [], 'Carregando...'));
        renderSlots([], 'Carregando...');
        live.textContent = 'Carregando os dados para agendar...';
        form.setAttribute('aria-busy', 'true');
        submitButton.disabled = true;

        try {
            const [clients, barbers, activeServices, appointment] = await Promise.all([
                clientSelect ? fetchItems(`/api/clients?per_page=${MAX_PER_PAGE}`) : null,
                ownBarberId ? null : fetchItems(`/api/barbers?per_page=${MAX_PER_PAGE}`),
                fetchItems(`/api/services?status=active&per_page=${MAX_PER_PAGE}`),
                rescheduleId ? fetchAppointment(rescheduleId) : null,
            ]);

            services = activeServices;

            if (clientSelect) {
                fillSelect(clientSelect, clients, 'Escolha o cliente');
            }

            if (!ownBarberId) {
                fillSelect(barberSelect, barbers, 'Escolha o barbeiro');
            }

            fillSelect(serviceSelect, services, 'Escolha o serviço');

            if (appointment) {
                startRescheduling(appointment);
            }

            submitButton.disabled = locked;
            live.textContent = 'Dados para agendar carregados.';

            if (retrying) {
                (clientSelect ?? barberSelect).focus();
            }

            refreshSlots();
        } catch (error) {
            services = [];
            lists.forEach((select) => fillSelect(select, [], 'Não foi possível carregar'));
            renderSlots([], 'Não foi possível carregar os dados para agendar.');
            live.textContent = '';

            /* Sem permissão, sem login ou agendamento que não existe: tentar de novo não adianta. */
            if (error instanceof ApiError) {
                showAlert(alerts, 'danger', error.message);

                return;
            }

            const retry = showRetryAlert(alerts, 'Não foi possível carregar os dados para agendar.', () => loadLists({ retrying: true }));

            if (retrying) {
                retry.focus();
            }
        } finally {
            form.removeAttribute('aria-busy');
            updateSummary();
        }
    }

    /* Endereço depois de salvar: a agenda no dia do agendamento, ou Meus horários, com o recado de sucesso. */
    function successUrl(saved) {
        const url = new URL(root.dataset.successUrl, window.location.origin);

        if (isStaff) {
            url.searchParams.set('date', saved.date);
        }

        url.searchParams.set('notice', isStaff ? (rescheduling ? 'rescheduled' : 'created') : 'booked');

        return url.toString();
    }

    async function save(event) {
        event.preventDefault();

        if (saving || locked) {
            return;
        }

        clearFormErrors(form, formAlerts);
        saving = true;
        submitButton.disabled = true;

        const schedule = {
            barber_id: barberSelect.value || null,
            service_id: serviceSelect.value || null,
            date: dateInput.value || null,
            start_time: selectedTime(),
        };

        /*
         * Remarcação que mantém barbeiro, serviço, data e horário: vai só a observação, e a API não confere o
         * horário de novo. Assim o barbeiro anota algo (ex.: "avisou que vai atrasar") mesmo depois do início.
         */
        const body = rescheduling && keepsTheSchedule(schedule) ? {} : schedule;

        if (clientSelect && !rescheduling) {
            body.client_id = clientSelect.value || null;
        }

        if (notesInput) {
            body.notes = notesInput.value.trim() || null;
        }

        let leaving = false;

        try {
            const result = rescheduling
                ? await apiRequest('PUT', `/api/appointments/${rescheduling.id}`, body)
                : await apiRequest('POST', '/api/appointments', body);

            if (result.ok) {
                leaving = true;
                window.location.assign(successUrl(result.data));

                return;
            }

            if (result.status === HTTP_UNPROCESSABLE && result.data?.errors) {
                showFormErrors(form, formAlerts, result.data.errors);

                /*
                 * O horário marcado foi ocupado (ou passou) enquanto a pessoa escolhia: avisa e busca os horários
                 * de novo. Sem horário marcado, o erro embaixo dos horários já diz o que falta.
                 */
                if (result.data.errors.start_time && body.start_time) {
                    showAlert(formAlerts, 'warning', 'O horário escolhido não está mais livre. Os horários foram atualizados: escolha outro.', { append: true });
                    preferredTime = null;
                    await refreshSlots();
                    focusFirstInvalid(form);
                }

                return;
            }

            showAlert(formAlerts, 'danger', result.data?.message ?? 'Não foi possível salvar o agendamento. Tente de novo.');
        } catch {
            showAlert(formAlerts, 'danger', API_UNREACHABLE_MESSAGE);
        } finally {
            if (!leaving) {
                saving = false;
                submitButton.disabled = locked;
            }
        }
    }

    /* Mudou um campo, a lista de horários é outra: o erro antigo do horário também sai. */
    fieldsForSlots.forEach((field) => {
        field.addEventListener('change', () => {
            clearFieldError(form, field.name);
            clearFieldError(form, 'start_time');
            refreshSlots();
        });
    });
    notesInput?.addEventListener('input', () => clearFieldError(form, 'notes'));
    form.addEventListener('submit', save);

    /*
     * A data começa em hoje, ou no dia que veio da agenda (?date=). Antes de hoje e depois do limite de dias
     * não dá para agendar.
     */
    const presetDate = params.get('date');
    dateInput.min = today();
    dateInput.max = lastBookableDay();
    dateInput.value = parseDay(presetDate) && presetDate >= today() && presetDate <= lastBookableDay() ? presetDate : today();

    loadLists();
}

/*
 * Meus horários (cliente): a fidelidade (visitas até o próximo prêmio e os prêmios para usar), os próximos
 * horários, com "Cancelar" enquanto ainda dá tempo (até data-cancel-min-hours horas antes do início), e os
 * passados ou cancelados. Quem decide a janela é a API: a tela só esconde o botão quando ela já passou.
 */
function initMyAppointments(root) {
    const cancelMinHours = Number(root.dataset.cancelMinHours) || 0;
    const statuses = JSON.parse(root.dataset.statuses);
    const bookingUrl = root.dataset.bookingUrl;

    const alerts = root.querySelector('[data-alerts]');
    const live = root.querySelector('[data-live]');
    const upcomingBox = root.querySelector('[data-upcoming]');
    const pastBox = root.querySelector('[data-past]');
    const upcomingTitle = root.querySelector('#my-upcoming-title');
    /* Sem cadastro de cliente ligado à conta, a tela fica sem a parte da fidelidade. */
    const loyaltyBox = root.querySelector('[data-loyalty]');
    const loyaltyContent = loyaltyBox?.querySelector('[data-loyalty-content]');

    const modalElement = root.querySelector('[data-cancel-modal]');
    const modal = new Modal(modalElement);
    const cancelAlerts = root.querySelector('[data-cancel-alerts]');
    const cancelText = root.querySelector('[data-cancel-text]');
    const backButton = root.querySelector('[data-cancel-back]');
    const confirmButton = root.querySelector('[data-confirm-cancel]');

    let cancelling = null;
    let cancelTrigger = null;
    let loadCount = 0;
    let loadErrorAlert = null;

    /* Próximo: ainda vai acontecer (ou está acontecendo) e o fim não passou. */
    function isUpcoming(appointment) {
        return Boolean(statuses[appointment.status]?.active) && secondsUntil(appointment.date, appointment.end_time) > 0;
    }

    function canCancel(appointment) {
        return Boolean(statuses[appointment.status]?.transitions.includes('cancelled'))
            && secondsUntil(appointment.date, appointment.start_time) >= cancelMinHours * SECONDS_PER_HOUR;
    }

    function card(appointment, upcoming) {
        const item = document.createElement('li');
        item.className = 'list-group-item d-flex flex-wrap gap-2 justify-content-between align-items-start';

        const text = document.createElement('div');
        const when = document.createElement('p');
        when.className = 'fw-semibold mb-1';
        when.textContent = `${shortDate(appointment.date)}, ${timeRange(appointment)}`;

        const what = document.createElement('p');
        what.className = 'mb-1 text-break';
        what.textContent = `${appointment.service?.name ?? 'Serviço'} com ${appointment.barber?.name ?? 'o barbeiro'}`;

        const price = document.createElement('p');
        price.className = 'small text-body-secondary mb-0';
        price.textContent = `Valor: ${currency.format(Number(appointment.price))}`;

        text.append(when, what, price);

        if (appointment.notes) {
            const notes = document.createElement('p');
            notes.className = 'small text-body-secondary mb-0 text-break';
            notes.textContent = `Observações: ${appointment.notes}`;
            text.append(notes);
        }

        /* No celular, o lado direito costuma descer para baixo do texto: lá ele fica alinhado à esquerda. */
        const side = document.createElement('div');
        side.className = 'd-flex flex-column align-items-start align-items-sm-end gap-2';
        side.append(statusBadge(appointment));

        /* Atendimento concluído: se já está pago (o status do pagamento) ou se o pagamento não foi registrado. */
        if (appointment.payment || appointment.status === 'completed') {
            const payment = document.createElement('p');
            payment.className = 'small text-sm-end mb-0';
            payment.append(
                'Pagamento: ',
                appointment.payment ? paymentBadge(appointment.payment) : textElement('span', 'text-body-secondary', 'não registrado'),
            );
            side.append(payment);
        }

        if (upcoming && canCancel(appointment)) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-outline-danger';
            button.textContent = 'Cancelar';
            button.setAttribute('aria-label', `Cancelar o horário de ${shortDate(appointment.date)} às ${shortTime(appointment.start_time)}`);
            button.addEventListener('click', () => openCancel(appointment, button));
            side.append(button);
        } else if (upcoming && statuses[appointment.status]?.transitions.includes('cancelled')) {
            const late = document.createElement('p');
            late.className = 'small text-body-secondary text-sm-end mb-0';
            late.textContent = 'Para cancelar agora, fale com a barbearia.';
            side.append(late);
        }

        item.append(text, side);

        return item;
    }

    function renderBox(box, items, upcoming, emptyText) {
        if (items.length === 0) {
            const paragraph = emptyMessage(emptyText);

            if (upcoming) {
                const link = document.createElement('a');
                link.href = bookingUrl;
                link.textContent = 'Agendar um horário';
                paragraph.append(' ', link);
            }

            box.replaceChildren(paragraph);

            return;
        }

        const list = document.createElement('ul');
        list.className = 'list-group';
        list.append(...items.map((appointment) => card(appointment, upcoming)));
        box.replaceChildren(list);
    }

    async function load() {
        const loadNumber = ++loadCount;
        upcomingBox.setAttribute('aria-busy', 'true');
        pastBox.setAttribute('aria-busy', 'true');
        live.textContent = 'Carregando seus horários...';

        try {
            const { items, complete } = await fetchAllItems('/api/appointments');

            if (loadNumber !== loadCount) {
                return;
            }

            /* A API devolve em ordem de data e horário: os próximos ficam assim, e os passados do mais recente para trás. */
            const upcoming = items.filter(isUpcoming);
            const past = items.filter((appointment) => !isUpcoming(appointment)).reverse();

            renderBox(upcomingBox, upcoming, true, 'Você não tem horários marcados.');
            renderBox(pastBox, past, false, 'Nenhum horário passado ou cancelado.');
            live.textContent = `${upcoming.length} ${upcoming.length === 1 ? 'próximo horário' : 'próximos horários'} e ${past.length} ${past.length === 1 ? 'passado ou cancelado' : 'passados ou cancelados'}.`;
            loadErrorAlert?.remove();
            loadErrorAlert = null;

            if (!complete) {
                loadErrorAlert = showAlert(alerts, 'warning', 'Você tem horários demais para a tela: só parte deles aparece.');
            }
        } catch (error) {
            if (loadNumber !== loadCount) {
                return;
            }

            [upcomingBox, pastBox].forEach((box) => box.replaceChildren(emptyMessage('Não foi possível carregar.')));
            live.textContent = '';

            if (error instanceof ApiError) {
                loadErrorAlert = showAlert(alerts, 'danger', error.message);

                return;
            }

            const retry = showRetryAlert(alerts, API_UNREACHABLE_MESSAGE, () => {
                alerts.replaceChildren();
                load();
            });
            loadErrorAlert = retry.closest('.alert');
        } finally {
            if (loadNumber === loadCount) {
                upcomingBox.removeAttribute('aria-busy');
                pastBox.removeAttribute('aria-busy');
            }
        }
    }

    /*
     * Fidelidade do cliente (GET /api/clients/{id}/loyalty): o prêmio para usar, quando há, as visitas desde
     * o último prêmio numa barra e quantas faltam para o próximo. O número de visitas e o desconto vêm da API.
     */
    function renderLoyalty(loyalty) {
        const perReward = loyalty.visits_per_reward;
        const missing = perReward - loyalty.progress;
        const benefit = rewardBenefit(loyalty.discount_percent);
        const visits = `${loyalty.progress} de ${countLabel(perReward, 'visita')}`;
        const nodes = [];

        if (loyalty.available_rewards > 0) {
            const prizes = loyalty.available_rewards === 1
                ? `Você tem 1 prêmio disponível: ${benefit}`
                : `Você tem ${loyalty.available_rewards} prêmios disponíveis: cada um vale ${benefit}`;
            nodes.push(textElement('p', 'alert alert-success py-2 mb-3', `${prizes}. A barbearia aplica o desconto na hora do pagamento.`));
        }

        /* A largura da barra vai por el.style, que a CSP permite (ela barra só o style="" escrito no HTML). */
        const bar = document.createElement('div');
        bar.className = 'progress mb-2';
        bar.setAttribute('role', 'progressbar');
        bar.setAttribute('aria-label', 'Visitas para o próximo prêmio');
        bar.setAttribute('aria-valuemin', '0');
        bar.setAttribute('aria-valuemax', String(perReward));
        bar.setAttribute('aria-valuenow', String(loyalty.progress));
        bar.setAttribute('aria-valuetext', visits);

        const fill = document.createElement('div');
        fill.className = 'progress-bar bg-success';
        fill.style.width = `${(loyalty.progress / perReward) * 100}%`;
        bar.append(fill);

        const rule = loyalty.discount_percent >= 100 ? 'grátis' : `com ${loyalty.discount_percent}% de desconto`;
        /* Com prêmio na mão, a contagem já é a do próximo. */
        const goal = loyalty.available_rewards > 0 ? 'o próximo prêmio' : `você ganhar ${benefit}`;

        nodes.push(
            textElement('p', 'fw-semibold mb-1', visits),
            bar,
            textElement('p', 'mb-1', `${missing === 1 ? 'Falta 1 visita' : `Faltam ${missing} visitas`} para ${goal}.`),
            textElement('p', 'small text-body-secondary mb-0', `A cada ${countLabel(perReward, 'atendimento concluído', 'atendimentos concluídos')}, o seguinte sai ${rule}.`),
        );

        loyaltyContent.replaceChildren(...nodes);
    }

    async function loadLoyalty() {
        if (!loyaltyBox) {
            return;
        }

        loyaltyContent.setAttribute('aria-busy', 'true');

        try {
            const result = await apiRequest('GET', `/api/clients/${loyaltyBox.dataset.clientId}/loyalty`);

            if (result.ok && result.data) {
                renderLoyalty(result.data);

                return;
            }

            /* Sem permissão ou sem login, tentar de novo não adianta: mostra só a mensagem. */
            if ([HTTP_UNAUTHORIZED, HTTP_FORBIDDEN, HTTP_SESSION_EXPIRED].includes(result.status)) {
                loyaltyContent.replaceChildren(emptyMessage(result.data?.message ?? FORBIDDEN_MESSAGE));

                return;
            }

            throw new Error('Falha ao carregar a fidelidade');
        } catch {
            const message = emptyMessage('Não foi possível carregar a sua fidelidade.');
            const retry = textElement('button', 'btn btn-sm btn-outline-secondary ms-2', 'Tentar de novo');
            retry.type = 'button';
            retry.addEventListener('click', loadLoyalty);
            message.append(retry);
            loyaltyContent.replaceChildren(message);
        } finally {
            loyaltyContent.removeAttribute('aria-busy');
        }
    }

    function openCancel(appointment, trigger) {
        cancelling = appointment;
        cancelTrigger = trigger;
        cancelAlerts.replaceChildren();
        cancelText.textContent = `Cancelar ${appointment.service?.name ?? 'o horário'} com ${appointment.barber?.name ?? 'o barbeiro'} em ${shortDate(appointment.date)} às ${shortTime(appointment.start_time)}?`;
        confirmButton.disabled = false;
        modal.show();
    }

    async function confirmCancel() {
        confirmButton.disabled = true;
        cancelAlerts.replaceChildren();
        let keepDisabled = false;

        try {
            const result = await apiRequest('PATCH', `/api/appointments/${cancelling.id}/status`, { status: 'cancelled' });

            if (result.ok) {
                /* A lista vai ser desenhada de novo: ao fechar, o foco vai para o título dos próximos. */
                cancelTrigger = null;
                modal.hide();
                showAlert(alerts, 'success', `Horário de ${shortDate(result.data.date)} às ${shortTime(result.data.start_time)} cancelado.`);
                await load();

                return;
            }

            /* 422: ex.: a janela de cancelamento acabou de fechar. Tentar de novo não adianta. */
            keepDisabled = result.status === HTTP_UNPROCESSABLE;
            showAlert(cancelAlerts, 'danger', result.data?.errors?.status?.[0] ?? result.data?.message ?? 'Não foi possível cancelar. Tente de novo.');
            load();
        } catch {
            showAlert(cancelAlerts, 'danger', API_UNREACHABLE_MESSAGE);
        } finally {
            confirmButton.disabled = keepDisabled;
        }
    }

    confirmButton.addEventListener('click', confirmCancel);
    /* O foco começa em Voltar: um Enter sem querer não cancela o horário. */
    modalElement.addEventListener('shown.bs.modal', () => backButton.focus());

    /* Ao fechar, o foco volta para o botão que abriu ou, se a lista mudou, para o título dos próximos. */
    modalElement.addEventListener('hidden.bs.modal', () => {
        (cancelTrigger?.isConnected ? cancelTrigger : upcomingTitle).focus();
        cancelling = null;
        cancelTrigger = null;
    });

    /* Recado do Agendar do cliente (?notice=booked), que sai do endereço para não voltar ao recarregar. */
    const url = new URL(window.location.href);

    if (url.searchParams.get('notice') === 'booked') {
        showAlert(alerts, 'success', 'Horário agendado. Ele está na lista de próximos.');
        url.searchParams.delete('notice');
        window.history.replaceState(null, '', url);
    }

    loadLoyalty();
    load();
}

/*
 * Pagamentos (admin e barbeiro): os atendimentos concluídos sem pagamento, cada um com "Registrar
 * pagamento", e a tabela dos pagamentos com filtros, os totais do filtro e paginação. O barbeiro vê só
 * os da agenda dele (a API filtra). Corrigir e estornar são só do admin (data-can-manage); marcar como
 * pago um pendente vale para os dois. As transições vêm do PaymentStatus::catalog().
 */
function initPayments(root) {
    const statuses = JSON.parse(root.dataset.paymentStatuses);
    const canManage = root.dataset.canManage === 'true';
    /* Para o barbeiro, o nome dele em cada linha só repetiria o óbvio. */
    const showBarber = !root.dataset.ownBarberId;

    const alerts = root.querySelector('[data-alerts]');

    const awaitingBox = root.querySelector('[data-awaiting]');
    const awaitingTitle = root.querySelector('#payments-awaiting-title');
    const awaitingPager = {
        status: root.querySelector('[data-awaiting-status]'),
        previous: root.querySelector('[data-awaiting-previous]'),
        next: root.querySelector('[data-awaiting-next]'),
    };

    const filters = root.querySelector('[data-filters]');
    const filterAlerts = root.querySelector('[data-filter-alerts]');
    const barberSelect = filters.elements.namedItem('barber_id');

    const listTitle = root.querySelector('#payments-list-title');
    const totalsBox = root.querySelector('[data-totals]');
    /* Os pagamentos saem na tabela (tablet para cima) e na lista do celular; o CSS mostra uma das duas. */
    const rows = root.querySelector('[data-rows]');
    const mobileRows = root.querySelector('[data-mobile-rows]');
    const table = rows.closest('table');
    const colspan = table.querySelectorAll('thead th').length;
    const pager = {
        status: root.querySelector('[data-page-status]'),
        previous: root.querySelector('[data-page-previous]'),
        next: root.querySelector('[data-page-next]'),
    };

    const confirmElement = root.querySelector('[data-payment-confirm-modal]');
    const confirmModal = new Modal(confirmElement);
    const confirmTitle = root.querySelector('[data-payment-confirm-title]');
    const confirmAlerts = root.querySelector('[data-payment-confirm-alerts]');
    const confirmText = root.querySelector('[data-payment-confirm-text]');
    const confirmBack = root.querySelector('[data-payment-confirm-back]');
    const confirmYes = root.querySelector('[data-payment-confirm-yes]');

    const dialog = createPaymentDialog(root, { onSaved: paymentSaved, onStale: reloadLists });

    let awaitingPage = 1;
    let awaitingLastPage = 1;
    let paymentsPage = 1;
    let paymentsLastPage = 1;
    /* Contam as cargas de cada lista: se a pessoa troca de filtro ou de página rápido, só a última é desenhada. */
    let awaitingLoad = 0;
    let paymentsLoad = 0;
    /* Alerta de "não foi possível carregar" dos pagamentos: sai assim que uma carga seguinte der certo. */
    let paymentsError = null;
    /* Mudança de status esperando a confirmação: { payment, status, trigger, done }. */
    let changing = null;

    /* Botão "Tentar de novo" dentro da caixa de uma lista. */
    function retryButton(onRetry) {
        const button = textElement('button', 'btn btn-sm btn-outline-secondary ms-2', 'Tentar de novo');
        button.type = 'button';
        button.addEventListener('click', onRetry);

        return button;
    }

    /* Item da lista de atendimentos sem pagamento, com o botão que abre o modal. */
    function awaitingItem(appointment) {
        const item = document.createElement('li');
        item.className = 'list-group-item d-flex flex-wrap gap-2 justify-content-between align-items-center';

        const details = [`${shortDate(appointment.date)}, ${timeRange(appointment)}`, appointment.service?.name ?? 'Serviço'];

        if (showBarber) {
            details.push(`com ${appointment.barber?.name ?? 'o barbeiro'}`);
        }

        const text = document.createElement('div');
        text.className = 'text-break';
        text.append(
            textElement('p', 'fw-semibold mb-1', appointment.client?.name ?? 'Cliente'),
            textElement('p', 'small text-body-secondary mb-0', details.join(' · ')),
        );

        const side = document.createElement('div');
        side.className = 'd-flex flex-wrap align-items-center gap-3 ms-auto';

        const button = textElement('button', 'btn btn-sm btn-success', 'Registrar pagamento');
        button.type = 'button';
        button.setAttribute(
            'aria-label',
            `Registrar pagamento de ${appointment.client?.name ?? 'cliente'}, ${shortDate(appointment.date)} às ${shortTime(appointment.start_time)}`,
        );
        /* Depois de registrar, a lista é lida de novo e o botão some: o foco vai para o título da lista. */
        button.addEventListener('click', () => dialog.openRecord(appointment, (saved) => (saved || !button.isConnected ? awaitingTitle : button)));

        side.append(textElement('span', 'fw-semibold text-nowrap', currency.format(Number(appointment.price))), button);
        item.append(text, side);

        return item;
    }

    async function loadAwaiting() {
        const loadNumber = ++awaitingLoad;
        const params = new URLSearchParams({ without_payment: '1', page: String(awaitingPage), per_page: String(AWAITING_PER_PAGE) });

        awaitingBox.setAttribute('aria-busy', 'true');
        awaitingPager.status.textContent = 'Carregando...';

        try {
            const page = await fetchPage(`/api/appointments?${params}`);

            if (loadNumber !== awaitingLoad) {
                return;
            }

            /* A página pedida não existe mais (ex.: registrou o último item dela): volta para a última que existe. */
            if (page.data.length === 0 && page.meta.current_page > page.meta.last_page) {
                awaitingPage = page.meta.last_page;
                await loadAwaiting();

                return;
            }

            awaitingPage = page.meta.current_page;
            awaitingLastPage = page.meta.last_page;
            updatePager({ meta: page.meta, ...awaitingPager, singular: 'atendimento', plural: 'atendimentos' });

            if (page.data.length === 0) {
                awaitingBox.replaceChildren(textElement('p', 'text-body-secondary px-3 py-4 mb-0', 'Nenhum atendimento concluído sem pagamento.'));

                return;
            }

            const list = document.createElement('ul');
            list.className = 'list-group list-group-flush';
            list.append(...page.data.map(awaitingItem));
            awaitingBox.replaceChildren(list);
        } catch (error) {
            if (loadNumber !== awaitingLoad) {
                return;
            }

            /* Sem permissão ou sem login, tentar de novo não adianta: mostra só a mensagem. */
            const message = textElement(
                'p',
                'text-body-secondary px-3 py-4 mb-0',
                error instanceof ApiError ? error.message : 'Não foi possível carregar os atendimentos sem pagamento.',
            );

            if (!(error instanceof ApiError)) {
                message.append(retryButton(() => loadAwaiting()));
            }

            awaitingBox.replaceChildren(message);
            awaitingPager.status.textContent = 'Não foi possível carregar a lista.';
            awaitingPager.previous.disabled = true;
            awaitingPager.next.disabled = true;
        } finally {
            if (loadNumber === awaitingLoad) {
                awaitingBox.removeAttribute('aria-busy');
            }
        }
    }

    /* Filtros preenchidos, prontos para a query (os vazios ficam de fora). */
    function filterParams() {
        const params = new URLSearchParams();

        ['from', 'to', 'method', 'status', 'barber_id'].forEach((name) => {
            const value = filters.elements.namedItem(name)?.value;

            if (value) {
                params.set(name, value);
            }
        });

        return params;
    }

    function renderTotals(totals) {
        const items = Object.entries(statuses).map(([status, rules]) => {
            const total = totals?.[status] ?? { payments: 0, total: '0.00' };
            const box = document.createElement('div');
            box.className = 'col';
            box.append(
                textElement('dt', 'small fw-normal text-body-secondary', rules.label),
                textElement('dd', 'fs-5 fw-semibold mb-0', currency.format(Number(total.total))),
                textElement('dd', 'small text-body-secondary mb-0', countLabel(total.payments, 'pagamento')),
            );

            return box;
        });

        totalsBox.replaceChildren(...items);
    }

    function actionButton(label, className, payment, onClick) {
        const button = textElement('button', `btn btn-sm ${className}`, label);
        button.type = 'button';
        button.setAttribute('aria-label', `${label}: pagamento de ${describePaymentAppointment(payment.appointment)}`);
        button.addEventListener('click', () => onClick(button));

        return button;
    }

    /*
     * Botões do pagamento: Corrigir (só o admin) e as mudanças de status que valem. Ficam num flex-wrap,
     * então um desce para baixo do outro em vez de alargar a linha. null quando não há nenhum.
     */
    function paymentActions(payment) {
        const group = document.createElement('div');
        group.className = 'd-flex flex-wrap gap-2 justify-content-end';
        const transitions = statuses[payment.status]?.transitions ?? [];

        if (canManage) {
            group.append(actionButton('Corrigir', 'btn-outline-primary', payment, (button) => {
                dialog.openCorrect(payment, (saved) => (saved || !button.isConnected ? listTitle : button));
            }));
        }

        Object.entries(PAYMENT_ACTIONS).forEach(([status, action]) => {
            if (transitions.includes(status) && (canManage || !action.adminOnly)) {
                group.append(actionButton(action.label, action.className, payment, (button) => askStatusChange(payment, status, button)));
            }
        });

        return group.children.length > 0 ? group : null;
    }

    /* Linha da tabela: atendimento, cliente (com o barbeiro, para o admin), valor e forma, status e ações. */
    function paymentRow(payment) {
        const appointment = payment.appointment ?? {};
        const row = document.createElement('tr');
        const cell = (children, className = '') => {
            const td = document.createElement('td');
            td.className = className;
            td.append(...children);

            return td;
        };

        const who = [textElement('span', 'd-block', appointment.client?.name ?? '')];

        if (showBarber) {
            who.push(textElement('span', 'd-block small text-body-secondary', `com ${appointment.barber?.name ?? 'o barbeiro'}`));
        }

        if (payment.notes) {
            who.push(textElement('span', 'd-block small text-body-secondary', `Observações: ${payment.notes}`));
        }

        const status = [paymentBadge(payment)];

        /* O estorno mantém a data em que foi pago. */
        if (payment.paid_at) {
            status.push(textElement('span', 'd-block small text-body-secondary mt-1', `Pago em ${dateTimeInAppTimezone(payment.paid_at)}`));
        }

        const actions = paymentActions(payment);

        const value = [
            textElement('span', 'd-block fw-semibold text-nowrap', currency.format(Number(payment.amount))),
            textElement('span', 'd-block small text-body-secondary', payment.method_label ?? payment.method),
        ];

        /* Pago com o prêmio da fidelidade: o desconto explica o valor menor que o preço. */
        if (payment.loyalty_reward) {
            value.push(textElement('span', 'd-block small text-body-secondary', `Prêmio de fidelidade: desconto de ${currency.format(Number(payment.discount))}`));
        }

        row.append(
            cell([
                textElement('span', 'd-block fw-semibold text-nowrap', shortDate(appointment.date)),
                textElement('span', 'd-block small text-body-secondary', `${timeRange(appointment)} · ${appointment.service?.name ?? ''}`),
            ]),
            cell(who, 'text-break'),
            cell(value),
            cell(status),
            cell([actions ?? textElement('span', 'visually-hidden', 'Nenhuma ação')], 'text-end'),
        );

        return row;
    }

    /* No celular: cliente e valor na primeira linha, o atendimento e o status embaixo, e por último os botões. */
    function paymentItem(payment) {
        const appointment = payment.appointment ?? {};
        const item = document.createElement('li');
        item.className = 'list-group-item';

        const top = document.createElement('div');
        top.className = 'd-flex justify-content-between gap-2';
        top.append(
            textElement('span', 'fw-semibold text-break', appointment.client?.name ?? ''),
            textElement('span', 'fw-semibold text-nowrap', currency.format(Number(payment.amount))),
        );
        item.append(top, textElement('p', 'small text-body-secondary mb-1', `${shortDate(appointment.date)}, ${timeRange(appointment)} · ${appointment.service?.name ?? ''}`));

        if (showBarber) {
            item.append(textElement('p', 'small text-body-secondary mb-1', `com ${appointment.barber?.name ?? 'o barbeiro'}`));
        }

        if (payment.notes) {
            item.append(textElement('p', 'small text-body-secondary text-break mb-1', `Observações: ${payment.notes}`));
        }

        const details = [payment.method_label ?? payment.method];

        if (payment.loyalty_reward) {
            details.push(`prêmio de fidelidade (desconto de ${currency.format(Number(payment.discount))})`);
        }

        if (payment.paid_at) {
            details.push(`pago em ${dateTimeInAppTimezone(payment.paid_at)}`);
        }

        const status = document.createElement('p');
        status.className = 'd-flex flex-wrap align-items-center gap-2 small mb-0';
        status.append(paymentBadge(payment), textElement('span', 'text-body-secondary', details.join(' · ')));
        item.append(status);

        const actions = paymentActions(payment);

        if (actions) {
            actions.classList.add('mt-2');
            item.append(actions);
        }

        return item;
    }

    /* Recado no lugar da lista (carregando, vazio, erro), na tabela e na lista do celular. */
    function showPaymentsMessage(text) {
        rows.replaceChildren(messageRow(colspan, text));
        mobileRows.replaceChildren(textElement('li', 'list-group-item text-center text-body-secondary py-4', text));
    }

    function renderPayments(page) {
        paymentsPage = page.meta.current_page;
        paymentsLastPage = page.meta.last_page;
        updatePager({ meta: page.meta, ...pager, singular: 'pagamento', plural: 'pagamentos' });
        renderTotals(page.totals);

        if (page.data.length === 0) {
            showPaymentsMessage([...filterParams().keys()].length > 0 ? 'Nenhum pagamento com esses filtros.' : 'Nenhum pagamento registrado ainda.');

            return;
        }

        rows.replaceChildren(...page.data.map(paymentRow));
        mobileRows.replaceChildren(...page.data.map(paymentItem));
    }

    async function loadPayments() {
        const loadNumber = ++paymentsLoad;
        const params = filterParams();
        params.set('page', String(paymentsPage));
        params.set('per_page', String(PAYMENTS_PER_PAGE));

        showPaymentsMessage('Carregando...');
        pager.status.textContent = 'Carregando...';
        table.setAttribute('aria-busy', 'true');
        mobileRows.setAttribute('aria-busy', 'true');

        try {
            const result = await apiRequest('GET', `/api/payments?${params}`);

            if (loadNumber !== paymentsLoad) {
                return;
            }

            /* 422: filtro inválido (ex.: "Até" antes de "De"). O erro aparece embaixo do campo. */
            if (result.status === HTTP_UNPROCESSABLE && result.data?.errors) {
                showFormErrors(filters, filterAlerts, result.data.errors);
                showPaymentsMessage('Corrija os filtros para ver os pagamentos.');
                pager.status.textContent = 'Corrija os filtros para ver os pagamentos.';
                pager.previous.disabled = true;
                pager.next.disabled = true;
                totalsBox.replaceChildren();

                return;
            }

            if ([HTTP_UNAUTHORIZED, HTTP_FORBIDDEN, HTTP_SESSION_EXPIRED].includes(result.status)) {
                throw new ApiError(result.data?.message ?? FORBIDDEN_MESSAGE);
            }

            if (!result.ok || !Array.isArray(result.data?.data) || !result.data.meta) {
                throw new Error('Falha ao carregar os pagamentos');
            }

            /* A página pedida não existe mais (ex.: mudou o filtro em outra aba): volta para a última que existe. */
            if (result.data.data.length === 0 && result.data.meta.current_page > result.data.meta.last_page) {
                paymentsPage = result.data.meta.last_page;
                await loadPayments();

                return;
            }

            renderPayments(result.data);
            paymentsError?.remove();
            paymentsError = null;
        } catch (error) {
            if (loadNumber !== paymentsLoad) {
                return;
            }

            showPaymentsMessage('Não foi possível carregar os pagamentos.');
            pager.status.textContent = 'Não foi possível carregar os pagamentos.';
            pager.previous.disabled = true;
            pager.next.disabled = true;
            totalsBox.replaceChildren();

            /* Sem permissão ou sem login, tentar de novo não adianta: mostra só a mensagem. */
            if (error instanceof ApiError) {
                paymentsError = showAlert(alerts, 'danger', error.message);

                return;
            }

            const retry = showRetryAlert(alerts, API_UNREACHABLE_MESSAGE, () => {
                alerts.replaceChildren();
                loadPayments();
            });
            paymentsError = retry.closest('.alert');
        } finally {
            if (loadNumber === paymentsLoad) {
                table.removeAttribute('aria-busy');
                mobileRows.removeAttribute('aria-busy');
            }
        }
    }

    function reloadLists() {
        loadAwaiting();
        loadPayments();
    }

    /* Depois de registrar, o atendimento sai da lista de sem pagamento e o pagamento entra na tabela. */
    function paymentSaved(payment, mode) {
        const who = payment.appointment?.client?.name ?? 'cliente';
        const what = describePaymentValue(payment);
        showAlert(alerts, 'success', mode === 'recorded' ? `Pagamento de ${who} registrado: ${what}.` : `Pagamento de ${who} corrigido: ${what}.`);
        paymentsError = null;

        if (mode === 'recorded') {
            loadAwaiting();
        }

        loadPayments();
    }

    function askStatusChange(payment, status, trigger) {
        const action = PAYMENT_ACTIONS[status];
        changing = { payment, status, trigger, done: false };
        confirmAlerts.replaceChildren();
        confirmTitle.textContent = action.title;
        confirmText.textContent = action.confirm(
            `o pagamento de ${currency.format(Number(payment.amount))} de ${describePaymentAppointment(payment.appointment)}`,
        );
        confirmYes.textContent = action.confirmLabel;
        confirmYes.className = `btn ${action.confirmClass}`;
        confirmYes.disabled = false;
        confirmModal.show();
    }

    async function confirmStatusChange() {
        if (!changing || changing.done) {
            return;
        }

        const { payment, status } = changing;
        confirmYes.disabled = true;
        confirmAlerts.replaceChildren();
        let keepDisabled = false;

        try {
            const result = await apiRequest('PATCH', `/api/payments/${payment.id}/status`, { status });

            if (result.ok) {
                changing.done = true;
                confirmModal.hide();
                showAlert(
                    alerts,
                    'success',
                    `Pagamento de ${result.data.appointment?.client?.name ?? 'cliente'} (${currency.format(Number(result.data.amount))}) ${PAYMENT_ACTIONS[status].done}.`,
                );
                paymentsError = null;
                await loadPayments();

                return;
            }

            /* 422: o pagamento mudou em outro lugar (ex.: já foi estornado). Tentar de novo não adianta: a tabela é lida de novo. */
            keepDisabled = result.status === HTTP_UNPROCESSABLE;
            showAlert(confirmAlerts, 'danger', result.data?.errors?.status?.[0] ?? result.data?.message ?? 'Não foi possível mudar o status. Tente de novo.');

            if (keepDisabled || result.status === HTTP_NOT_FOUND) {
                loadPayments();
            }
        } catch {
            showAlert(confirmAlerts, 'danger', API_UNREACHABLE_MESSAGE);
        } finally {
            confirmYes.disabled = keepDisabled;
        }
    }

    /* O filtro de barbeiro (só o admin) lista os barbeiros cadastrados. */
    async function loadBarbers() {
        if (!barberSelect) {
            return;
        }

        try {
            fillSelect(barberSelect, await fetchItems(`/api/barbers?per_page=${MAX_PER_PAGE}`), 'Todos');
        } catch {
            showAlert(filterAlerts, 'warning', 'Não foi possível carregar os barbeiros do filtro. As listas continuam funcionando.');
        }
    }

    /* Mudou um filtro: a tabela volta para a página 1. Os dois campos do período perdem o erro juntos. */
    filters.addEventListener('change', (event) => {
        const names = ['from', 'to'].includes(event.target.name) ? ['from', 'to'] : [event.target.name];
        names.forEach((name) => clearFieldError(filters, name));
        filterAlerts.replaceChildren();
        paymentsPage = 1;
        loadPayments();
    });
    filters.addEventListener('submit', (event) => event.preventDefault());
    root.querySelector('[data-clear-filters]').addEventListener('click', () => {
        filters.reset();
        clearFormErrors(filters, filterAlerts);
        paymentsPage = 1;
        loadPayments();
    });

    awaitingPager.previous.addEventListener('click', () => {
        if (awaitingPage > 1) {
            awaitingPage -= 1;
            loadAwaiting();
        }
    });
    awaitingPager.next.addEventListener('click', () => {
        if (awaitingPage < awaitingLastPage) {
            awaitingPage += 1;
            loadAwaiting();
        }
    });
    pager.previous.addEventListener('click', () => {
        if (paymentsPage > 1) {
            paymentsPage -= 1;
            loadPayments();
        }
    });
    pager.next.addEventListener('click', () => {
        if (paymentsPage < paymentsLastPage) {
            paymentsPage += 1;
            loadPayments();
        }
    });

    confirmYes.addEventListener('click', confirmStatusChange);
    /* O foco começa em Voltar: um Enter sem querer não estorna nada. */
    confirmElement.addEventListener('shown.bs.modal', () => confirmBack.focus());
    /* Ao fechar, o foco volta para o botão que abriu ou, se a tabela mudou, para o título dela. */
    confirmElement.addEventListener('hidden.bs.modal', () => {
        const target = changing && !changing.done && changing.trigger.isConnected ? changing.trigger : listTitle;
        changing = null;
        target.focus();
    });

    loadBarbers();
    loadAwaiting();
    loadPayments();
}

/*
 * Dashboard (só o admin): os números de GET /api/dashboard num período (mês atual, mês passado ou
 * personalizado, até 366 dias). O período fica no endereço (?from=&to=), menos o mês atual, que é o
 * padrão. Enquanto um período novo carrega, os números do anterior continuam na tela, mais claros.
 */
function initDashboard(root) {
    const options = [...root.querySelectorAll('[data-period-option]')];
    const customForm = root.querySelector('[data-custom-period]');
    const fromInput = customForm.elements.namedItem('from');
    const toInput = customForm.elements.namedItem('to');
    const periodTitle = root.querySelector('[data-period-title]');
    const alerts = root.querySelector('[data-alerts]');
    const live = root.querySelector('[data-live]');
    const content = root.querySelector('[data-dashboard-content]');
    const daysTitle = root.querySelector('[data-days-title]');
    const daysBox = root.querySelector('[data-days]');
    const methodsBox = root.querySelector('[data-methods]');
    const statusesBox = root.querySelector('[data-statuses]');
    const servicesRows = root.querySelector('[data-services]');
    const barbersRows = root.querySelector('[data-barbers]');

    let loadCount = 0;
    let loadErrorAlert = null;
    /* Último período que carregou: o "Personalizado" abre com ele preenchido. */
    let shown = null;

    /* Primeiro e último dia do mês de hoje (offset 0) ou do mês passado (-1), no fuso da barbearia. */
    function monthPeriod(offset) {
        const [year, month] = today().split('-').map(Number);

        return {
            from: formatDay(new Date(Date.UTC(year, month - 1 + offset, 1))),
            to: formatDay(new Date(Date.UTC(year, month + offset, 0))),
        };
    }

    function presetOf(period) {
        const presets = { current: monthPeriod(0), previous: monthPeriod(-1) };
        const match = Object.entries(presets).find(([, range]) => range.from === period.from && range.to === period.to);

        return match ? match[0] : 'custom';
    }

    function selectOption(value) {
        options.forEach((option) => {
            option.checked = option.value === value;
        });
        customForm.hidden = value !== 'custom';
    }

    /*
     * Depois que o período carrega, ele vai para o endereço (recarregar a página mantém); o mês atual, que é
     * o padrão, sai dele. Um período que a API recusou não entra.
     */
    function rememberPeriod(period, keepInAddress) {
        const url = new URL(window.location.href);
        url.searchParams.delete('from');
        url.searchParams.delete('to');

        if (keepInAddress) {
            url.searchParams.set('from', period.from);
            url.searchParams.set('to', period.to);
        }

        window.history.replaceState(null, '', url);
    }

    function setTile(key, value, detailText) {
        root.querySelector(`[data-tile-value="${key}"]`).textContent = value;
        root.querySelector(`[data-tile-detail="${key}"]`).textContent = detailText;
    }

    /* Primeira coluna como cabeçalho da linha (o nome), e os números alinhados à direita. */
    function tableRow(name, values) {
        const row = document.createElement('tr');
        const header = textElement('th', 'fw-normal', name);
        header.scope = 'row';
        row.append(header, ...values.map((text) => textElement('td', 'text-end text-nowrap tabular', text)));

        return row;
    }

    /* Com mais de DAY_CHART_MAX_DAYS dias, as barras somam o faturamento de cada mês. */
    function dayItems(byDay, byMonth) {
        if (!byMonth) {
            return byDay.map((day) => ({
                label: DAY_AND_MONTH.format(parseDay(day.date)),
                value: Number(day.total),
                text: currency.format(Number(day.total)),
            }));
        }

        const months = new Map();

        byDay.forEach((day) => {
            const key = day.date.slice(0, 7);
            months.set(key, (months.get(key) ?? 0) + Number(day.total));
        });

        return [...months].map(([key, total]) => ({
            label: MONTH_AND_YEAR.format(parseDay(`${key}-01`)),
            value: total,
            text: currency.format(total),
        }));
    }

    function render(data) {
        const { period, revenue, appointments, occupancy } = data;
        const counts = Object.fromEntries(appointments.by_status.map((item) => [item.status, item.count]));
        const completed = counts.completed ?? 0;
        const noShow = counts.no_show ?? 0;

        shown = { from: period.from, to: period.to };
        periodTitle.textContent = `${capitalize(periodLabel(period.from, period.to))} (${countLabel(period.days, 'dia')})`;

        setTile(
            'revenue',
            currency.format(Number(revenue.total)),
            revenue.payments === 0 ? 'Nenhum pagamento pago no período.' : `${countLabel(revenue.payments, 'pagamento pago', 'pagamentos pagos')}.`,
        );
        setTile('appointments', INTEGER.format(appointments.total), `Em todos os status; ${countLabel(completed, 'concluído', 'concluídos')}.`);
        setTile('ticket', currency.format(Number(revenue.average_ticket)), 'Faturamento dividido pelos pagamentos pagos (o corte grátis da fidelidade não conta).');
        setTile(
            'no-show',
            appointments.no_show_rate === null ? 'Sem dados' : `${PERCENT.format(appointments.no_show_rate)}%`,
            appointments.no_show_rate === null
                ? 'Nenhum atendimento concluído nem falta no período.'
                : `${countLabel(noShow, 'falta', 'faltas')} em ${countLabel(completed + noShow, 'atendimento concluído ou com falta', 'atendimentos concluídos ou com falta')}.`,
        );
        setTile(
            'occupancy',
            occupancy.rate === null ? 'Sem dados' : `${PERCENT.format(occupancy.rate)}%`,
            occupancy.rate === null
                ? 'Nenhum barbeiro ou dia de expediente no período.'
                : `${formatDuration(occupancy.occupied_minutes)} agendados de ${formatDuration(occupancy.available_minutes)} de expediente (${countLabel(occupancy.barbers, 'barbeiro')}).`,
        );

        const byMonth = period.days > DAY_CHART_MAX_DAYS;
        daysTitle.textContent = byMonth ? 'Faturamento por mês' : 'Faturamento por dia';
        renderBarList(daysBox, dayItems(revenue.by_day, byMonth), 'Nenhum pagamento pago no período.', { hideZeros: true });

        renderBarList(
            methodsBox,
            revenue.by_method.map((method) => ({
                label: method.label,
                value: Number(method.total),
                text: currency.format(Number(method.total)),
                detail: countLabel(method.payments, 'pagamento'),
            })),
            'Nenhum pagamento pago no período.',
        );

        const statusList = document.createElement('ul');
        statusList.className = 'list-unstyled mb-0';
        statusList.append(...appointments.by_status.map((item) => {
            const line = document.createElement('li');
            line.className = 'd-flex justify-content-between align-items-center gap-3 py-1';
            line.append(
                textElement('span', `badge ${APPOINTMENT_BADGES[item.status] ?? 'text-bg-light border'}`, item.label),
                textElement('span', 'fw-semibold tabular', INTEGER.format(item.count)),
            );

            return line;
        }));
        statusesBox.replaceChildren(statusList);

        servicesRows.replaceChildren(...(data.top_services.length === 0
            ? [messageRow(3, 'Nenhum atendimento concluído no período.')]
            : data.top_services.map((service) => tableRow(service.name, [INTEGER.format(service.completed), currency.format(Number(service.revenue))]))));

        barbersRows.replaceChildren(...(data.barbers.length === 0
            ? [messageRow(4, 'Nenhum barbeiro no período.')]
            : data.barbers.map((barber) => tableRow(barber.name, [
                INTEGER.format(barber.completed),
                INTEGER.format(barber.no_show),
                currency.format(Number(barber.revenue)),
            ]))));
    }

    async function load(period, { keepInAddress = false } = {}) {
        const loadNumber = ++loadCount;
        content.setAttribute('aria-busy', 'true');
        live.textContent = 'Carregando os números do período...';

        try {
            const result = await apiRequest('GET', `/api/dashboard?${new URLSearchParams(period)}`);

            if (loadNumber !== loadCount) {
                return;
            }

            /* 422: período inválido (ex.: mais de 366 dias). O erro aparece embaixo do campo do período personalizado. */
            if (result.status === HTTP_UNPROCESSABLE && result.data?.errors) {
                selectOption('custom');
                fromInput.value = period.from;
                toInput.value = period.to;
                showFormErrors(customForm, alerts, result.data.errors);
                live.textContent = '';

                return;
            }

            if ([HTTP_UNAUTHORIZED, HTTP_FORBIDDEN, HTTP_SESSION_EXPIRED].includes(result.status)) {
                throw new ApiError(result.data?.message ?? FORBIDDEN_MESSAGE);
            }

            if (!result.ok || !result.data?.period) {
                throw new Error('Falha ao carregar o dashboard');
            }

            render(result.data);
            rememberPeriod(period, keepInAddress);
            loadErrorAlert?.remove();
            loadErrorAlert = null;
            live.textContent = `Números de ${periodLabel(result.data.period.from, result.data.period.to)} carregados.`;
        } catch (error) {
            if (loadNumber !== loadCount) {
                return;
            }

            live.textContent = '';

            /* Sem permissão ou sem login, tentar de novo não adianta: mostra só a mensagem. */
            if (error instanceof ApiError) {
                loadErrorAlert = showAlert(alerts, 'danger', error.message);

                return;
            }

            const retry = showRetryAlert(alerts, API_UNREACHABLE_MESSAGE, () => {
                alerts.replaceChildren();
                load(period, { keepInAddress });
            });
            loadErrorAlert = retry.closest('.alert');
        } finally {
            if (loadNumber === loadCount) {
                content.removeAttribute('aria-busy');
            }
        }
    }

    options.forEach((option) => {
        option.addEventListener('change', () => {
            if (!option.checked) {
                return;
            }

            clearFormErrors(customForm, alerts);

            /* Personalizado: abre os campos com o período da tela e espera o "Ver o período". */
            if (option.value === 'custom') {
                customForm.hidden = false;
                fromInput.value = shown?.from ?? '';
                toInput.value = shown?.to ?? '';
                fromInput.focus();

                return;
            }

            customForm.hidden = true;
            load(monthPeriod(option.value === 'current' ? 0 : -1), { keepInAddress: option.value !== 'current' });
        });
    });

    customForm.addEventListener('submit', (event) => {
        event.preventDefault();
        clearFormErrors(customForm, alerts);

        /* Sem as duas datas, a API devolveria o mês atual: aqui as duas são obrigatórias. */
        const missing = {};

        if (!parseDay(fromInput.value)) {
            missing.from = ['Escolha a data inicial.'];
        }

        if (!parseDay(toInput.value)) {
            missing.to = ['Escolha a data final.'];
        }

        if (Object.keys(missing).length > 0) {
            showFormErrors(customForm, alerts, missing);

            return;
        }

        load({ from: fromInput.value, to: toInput.value }, { keepInAddress: true });
    });

    [fromInput, toInput].forEach((input) => input.addEventListener('input', () => clearFieldError(customForm, input.name)));

    /* Começa no período do endereço, se ele for válido, ou no mês atual. */
    const params = new URLSearchParams(window.location.search);
    const fromAddress = parseDay(params.get('from')) && parseDay(params.get('to')) ? { from: params.get('from'), to: params.get('to') } : null;
    const initial = fromAddress ?? monthPeriod(0);
    const preset = fromAddress ? presetOf(fromAddress) : 'current';

    selectOption(preset);

    if (preset === 'custom') {
        fromInput.value = initial.from;
        toInput.value = initial.to;
    }

    load(initial, { keepInAddress: preset !== 'current' });
}

/*
 * Minha conta: troca da senha pela API (PUT /api/user/password, que confere a senha atual). Os outros
 * aparelhos conectados à conta saem; esta sessão continua.
 */
function initPasswordChange(root) {
    const form = root.querySelector('[data-password-form]');
    const alerts = root.querySelector('[data-alerts]');
    const submitButton = root.querySelector('[data-submit]');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFormErrors(form, alerts);
        submitButton.disabled = true;

        try {
            const result = await apiRequest('PUT', '/api/user/password', formValues(form));

            if (result.status === HTTP_UNPROCESSABLE && result.data?.errors) {
                showFormErrors(form, alerts, result.data.errors);

                return;
            }

            if (!result.ok) {
                showAlert(alerts, 'danger', result.data?.message ?? 'Não foi possível trocar a senha. Tente de novo.');

                return;
            }

            form.reset();
            showAlert(alerts, 'success', 'Senha trocada. Quem estava conectado à sua conta em outro aparelho vai precisar entrar de novo.');
        } catch {
            showAlert(alerts, 'danger', API_UNREACHABLE_MESSAGE);
        } finally {
            submitButton.disabled = false;
        }
    });
}

document.querySelectorAll('[data-crud]').forEach(initCrud);
document.querySelectorAll('[data-schedule]').forEach(initSchedule);
document.querySelectorAll('[data-appointment-form]').forEach(initAppointmentForm);
document.querySelectorAll('[data-my-appointments]').forEach(initMyAppointments);
document.querySelectorAll('[data-payments]').forEach(initPayments);
document.querySelectorAll('[data-dashboard]').forEach(initDashboard);
document.querySelectorAll('[data-password-change]').forEach(initPasswordChange);
