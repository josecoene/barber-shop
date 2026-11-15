// Teste de carga da API do BarberFlow (k6), com login por token do Sanctum.
// Passo a passo em load-tests/README.md. Na homolog de carga (docker-compose.carga.yml), o
// serviço k6 chama http://web pela rede do Docker.
//
// Variáveis (-e no docker compose run):
//   SCENARIO            smoke (padrão), carga ou estresse
//   RODADA              nome livre da rodada, vai no nome do arquivo de resultado (padrão: teste)
//   BASE_URL            endereço da aplicação (padrão: http://web)
//   LOAD_USER_EMAIL     e-mail do usuário de carga, um admin (nunca escrito em arquivo)
//   LOAD_USER_PASSWORD  senha do usuário de carga (nunca escrita em arquivo)
//
// Metas: p95 abaixo de 500 ms e menos de 1% de erro nas requisições do cenário. O login e a
// preparação do setup() e a limpeza do teardown() ficam fora das metas.
//
// Login: o setup() entra uma vez para cada VU que o cenário chega a ter, e cada VU usa o próprio
// token (exec.vu.idInTest). Assim o Sanctum atualiza o last_used_at de um token diferente em cada
// requisição, e o teste não mede uma fila de trava numa linha só da personal_access_tokens. O
// teardown() faz o logout de todos os tokens.

import http from 'k6/http';
import exec from 'k6/execution';
import { check, fail, sleep } from 'k6';

const BASE_URL = (__ENV.BASE_URL || 'http://web').replace(/\/+$/, '');
const SCENARIO = __ENV.SCENARIO || 'smoke';
const RODADA = (__ENV.RODADA || 'teste').replace(/[^A-Za-z0-9._-]/g, '-');
const EMAIL = __ENV.LOAD_USER_EMAIL || '';
const SENHA = __ENV.LOAD_USER_PASSWORD || '';

// Logins e logouts do setup() e do teardown() vão em lotes, em paralelo.
const LOTE = 10;

const CENARIOS = {
  // 1 usuário por 30 s: confere que cada rota responde antes dos testes maiores
  smoke: {
    executor: 'constant-vus',
    vus: 1,
    duration: '30s',
  },
  // Carga esperada: sobe até 50 usuários, mantém 3 minutos e desce
  carga: {
    executor: 'ramping-vus',
    startVUs: 0,
    stages: [
      { duration: '1m', target: 50 },
      { duration: '3m', target: 50 },
      { duration: '30s', target: 0 },
    ],
    gracefulRampDown: '30s',
  },
  // Estresse: degraus de 50 usuários por minuto até 200, só leitura
  estresse: {
    executor: 'ramping-vus',
    startVUs: 0,
    stages: [
      { duration: '1m', target: 50 },
      { duration: '1m', target: 100 },
      { duration: '1m', target: 150 },
      { duration: '1m', target: 200 },
      { duration: '30s', target: 0 },
    ],
    gracefulRampDown: '30s',
  },
};

if (!CENARIOS[SCENARIO]) {
  throw new Error(`SCENARIO inválido: ${SCENARIO}. Use smoke, carga ou estresse.`);
}

// Quantas VUs o cenário chega a ter ao mesmo tempo: é quantos tokens o setup() pede.
function maximoDeVus(cenario) {
  if (cenario.vus) {
    return cenario.vus;
  }
  return Math.max(cenario.startVUs || 0, ...cenario.stages.map((estagio) => estagio.target));
}

const VUS = maximoDeVus(CENARIOS[SCENARIO]);

export const options = {
  scenarios: { [SCENARIO]: CENARIOS[SCENARIO] },
  // As metas olham só as requisições do cenário (tag scenario), sem o setup() e o teardown().
  thresholds: {
    [`http_req_duration{scenario:${SCENARIO}}`]: ['p(95)<500'],
    [`http_req_failed{scenario:${SCENARIO}}`]: ['rate<0.01'],
    [`checks{scenario:${SCENARIO}}`]: ['rate>0.99'],
  },
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  setupTimeout: '5m',
  teardownTimeout: '5m',
  batchPerHost: LOTE,
};

const JSON_HEADERS = { Accept: 'application/json', 'Content-Type': 'application/json' };

function parametros(nome, token) {
  const headers = token ? { ...JSON_HEADERS, Authorization: `Bearer ${token}` } : JSON_HEADERS;
  // A tag name agrupa as métricas pela rota, e não por URL com id e data.
  return { headers, tags: { name: nome } };
}

// Explica uma resposta inesperada sem mostrar o corpo (que pode ter dados) nem o token.
function explicar(etapa, resposta) {
  const motivos = {
    0: `a aplicação não respondeu em ${BASE_URL}. A homolog de carga está no ar?`,
    400: 'a aplicação recusou o host. Suba a homolog com o docker-compose.carga.yml (TRUSTED_HOSTS=web).',
    401: 'o token não vale. O usuário de carga tem acesso?',
    403: 'o usuário de carga não tem permissão. Ele precisa ser admin.',
    404: 'a rota não existe nesta versão da aplicação (o login e o /api/health chegam com as branches feat/auth e feat/api-qualidade).',
    422: 'a aplicação recusou os dados. No login, confira LOAD_USER_EMAIL e LOAD_USER_PASSWORD.',
    429: 'limite de requisições. Suba a homolog com o docker-compose.carga.yml, que levanta os limites.',
    503: 'o /api/health diz que o banco ou o cache estão fora.',
  };
  return `${etapa}: HTTP ${resposta.status}. ${motivos[resposta.status] || 'veja o log do app.'}`;
}

function entrar(quantidade) {
  const tokens = [];
  for (let inicio = 0; inicio < quantidade; inicio += LOTE) {
    const pedidos = [];
    for (let i = inicio; i < Math.min(quantidade, inicio + LOTE); i++) {
      const corpo = JSON.stringify({ email: EMAIL, password: SENHA, device_name: `k6-${RODADA}-vu${i + 1}` });
      pedidos.push(['POST', `${BASE_URL}/api/login`, corpo, parametros('POST /api/login')]);
    }
    for (const resposta of http.batch(pedidos)) {
      if (resposta.status !== 200) {
        fail(explicar('login', resposta));
      }
      const token = resposta.json('token');
      if (!token) {
        fail('login: a resposta 200 veio sem o campo token.');
      }
      tokens.push(token);
    }
  }
  return tokens;
}

function sair(tokens) {
  for (let inicio = 0; inicio < tokens.length; inicio += LOTE) {
    const pedidos = tokens.slice(inicio, inicio + LOTE).map((token) => ['POST', `${BASE_URL}/api/logout`, null, parametros('POST /api/logout', token)]);
    http.batch(pedidos);
  }
}

// Primeiro item ativo de uma listagem paginada (data), ou null.
function primeiroAtivo(resposta) {
  const itens = resposta.status === 200 ? resposta.json('data') || [] : [];
  const item = itens.find((i) => !i.status || i.status === 'active');
  return item ? item.id : null;
}

function amanha() {
  const dia = new Date(Date.now() + 24 * 60 * 60 * 1000);
  return dia.toISOString().slice(0, 10);
}

export function setup() {
  if (!EMAIL || !SENHA) {
    fail('Defina LOAD_USER_EMAIL e LOAD_USER_PASSWORD no terminal e passe com -e (load-tests/README.md).');
  }

  const saude = http.get(`${BASE_URL}/api/health`, parametros('GET /api/health'));
  if (saude.status !== 200) {
    fail(explicar('/api/health', saude));
  }

  const tokens = entrar(VUS);
  const admin = tokens[0];

  // Um serviço e um barbeiro para os horários livres. Sem nenhum cadastrado (homolog vazia),
  // o setup() cria os dois, e o teardown() exclui.
  const criados = { serviceId: null, barberId: null };
  let serviceId = primeiroAtivo(http.get(`${BASE_URL}/api/services?per_page=100`, parametros('GET /api/services', admin)));
  if (!serviceId) {
    const novo = http.post(`${BASE_URL}/api/services`, JSON.stringify({ name: `Corte k6 ${RODADA}`, duration: 30, price: 35 }), parametros('POST /api/services', admin));
    if (novo.status !== 201) {
      fail(explicar('criar serviço', novo));
    }
    serviceId = novo.json('id');
    criados.serviceId = serviceId;
  }
  let barberId = primeiroAtivo(http.get(`${BASE_URL}/api/barbers?per_page=100`, parametros('GET /api/barbers', admin)));
  if (!barberId) {
    const corpo = JSON.stringify({ name: `Barbeiro k6 ${RODADA}`, email: `k6-${RODADA}-${Date.now()}@example.com`, phone: '67999990000' });
    const novo = http.post(`${BASE_URL}/api/barbers`, corpo, parametros('POST /api/barbers', admin));
    if (novo.status !== 201) {
      fail(explicar('criar barbeiro', novo));
    }
    barberId = novo.json('id');
    criados.barberId = barberId;
  }

  return { tokens, serviceId, barberId, data: amanha(), criados };
}

function listarServicos(token) {
  const r = http.get(`${BASE_URL}/api/services?per_page=15`, parametros('GET /api/services', token));
  check(r, { 'serviços 200': (x) => x.status === 200 });
}

function listarBarbeiros(token) {
  const r = http.get(`${BASE_URL}/api/barbers?per_page=15`, parametros('GET /api/barbers', token));
  check(r, { 'barbeiros 200': (x) => x.status === 200 });
}

function agendaDoDia(token, dados) {
  const r = http.get(`${BASE_URL}/api/appointments?date=${dados.data}&per_page=100`, parametros('GET /api/appointments', token));
  check(r, { 'agenda 200': (x) => x.status === 200 });
}

function horariosLivres(token, dados) {
  const url = `${BASE_URL}/api/barbers/${dados.barberId}/available-slots?date=${dados.data}&service_id=${dados.serviceId}`;
  const r = http.get(url, parametros('GET /api/barbers/{id}/available-slots', token));
  check(r, { 'horários livres 200': (x) => x.status === 200 });
}

function cadastrarCliente(token) {
  const sufixo = `${exec.vu.idInTest}-${exec.vu.iterationInScenario}-${Date.now()}`;
  const corpo = JSON.stringify({ name: `Cliente k6 ${sufixo}`, email: `k6-${RODADA}-${sufixo}@example.com`, phone: '67999990000' });
  const r = http.post(`${BASE_URL}/api/clients`, corpo, parametros('POST /api/clients', token));
  check(r, { 'cliente 201': (x) => x.status === 201 });
}

export default function (dados) {
  // Cada VU com o próprio token: a VU 1 usa o primeiro, a VU 2 o segundo, e assim por diante.
  const token = dados.tokens[(exec.vu.idInTest - 1) % dados.tokens.length];
  const sorteio = Math.random();

  if (SCENARIO === 'smoke') {
    // Smoke: todas as rotas em toda iteração
    listarServicos(token);
    listarBarbeiros(token);
    agendaDoDia(token, dados);
    horariosLivres(token, dados);
    cadastrarCliente(token);
  } else if (SCENARIO === 'estresse') {
    // Estresse só com leitura, para o banco não crescer durante o teste
    if (sorteio < 0.3) listarServicos(token);
    else if (sorteio < 0.55) listarBarbeiros(token);
    else if (sorteio < 0.8) agendaDoDia(token, dados);
    else horariosLivres(token, dados);
  } else if (sorteio < 0.3) {
    listarServicos(token);
  } else if (sorteio < 0.55) {
    listarBarbeiros(token);
  } else if (sorteio < 0.75) {
    agendaDoDia(token, dados);
  } else if (sorteio < 0.9) {
    horariosLivres(token, dados);
  } else {
    cadastrarCliente(token);
  }

  sleep(1);
}

export function teardown(dados) {
  const admin = dados.tokens[0];
  if (dados.criados.serviceId) {
    http.del(`${BASE_URL}/api/services/${dados.criados.serviceId}`, null, parametros('DELETE /api/services/{id}', admin));
  }
  if (dados.criados.barberId) {
    http.del(`${BASE_URL}/api/barbers/${dados.criados.barberId}`, null, parametros('DELETE /api/barbers/{id}', admin));
  }
  sair(dados.tokens);
}

function ms(valor) {
  return valor === undefined ? '-' : `${Math.round(valor)} ms`;
}

// Resumo curto no terminal, sem biblioteca baixada da internet.
function resumo(dados) {
  const m = dados.metrics;
  const duracao = m[`http_req_duration{scenario:${SCENARIO}}`];
  const falhas = m[`http_req_failed{scenario:${SCENARIO}}`];
  const linhas = [`BarberFlow, cenário ${SCENARIO}, rodada ${RODADA}`];
  if (!m.iterations) {
    linhas.push('NENHUMA iteração do cenário rodou: o setup() parou antes, e as metas abaixo não valem (o motivo está no erro logo abaixo).');
  }
  if (m.http_reqs) {
    linhas.push(`requisições: ${m.http_reqs.values.count} no total (${m.http_reqs.values.rate.toFixed(1)} por segundo), com o setup() e o teardown()`);
  }
  if (duracao) {
    const v = duracao.values;
    linhas.push(`tempo de resposta no cenário: mediana ${ms(v.med)}, p95 ${ms(v['p(95)'])}, p99 ${ms(v['p(99)'])}, máximo ${ms(v.max)}`);
  }
  if (falhas) {
    linhas.push(`erros no cenário: ${(falhas.values.rate * 100).toFixed(2)}%`);
  }
  linhas.push('metas:');
  for (const [nome, metrica] of Object.entries(m)) {
    for (const [regra, resultado] of Object.entries(metrica.thresholds || {})) {
      linhas.push(`  ${resultado.ok ? 'passou' : 'FALHOU'}  ${nome} ${regra}`);
    }
  }
  return `${linhas.join('\n')}\n`;
}

// Grava só o JSON, em load-tests/results/<RODADA>-<SCENARIO>.json. O setup_data sai do arquivo:
// ele tem os tokens.
export function handleSummary(dados) {
  const semTokens = { ...dados };
  delete semTokens.setup_data;
  return {
    stdout: resumo(dados),
    [`/results/${RODADA}-${SCENARIO}.json`]: JSON.stringify(semTokens, null, 2),
  };
}
