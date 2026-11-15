// Teste de concorrência da agenda (k6): várias VUs pedem, no mesmo instante, o mesmo horário com
// o mesmo barbeiro para o mesmo cliente (POST /api/appointments). Passa só se exatamente um
// pedido receber 201 e todos os outros receberem 422 pelo horário ocupado (start_time ou
// client_id), sem nenhum 409, 500 ou erro de rede, e se a agenda terminar com um agendamento só
// naquele horário. É a prova, com pedidos simultâneos de verdade, das travas do
// App\Services\AppointmentScheduler (o AppointmentConcurrencyTest prova com duas conexões).
// Passo a passo em load-tests/README.md.
//
// Variáveis (-e no docker compose run):
//   VUS                 quantos pedidos ao mesmo tempo (padrão 10, de 2 a 50)
//   RODADA              nome livre da rodada, vai no nome do arquivo de resultado (padrão: teste)
//   BASE_URL            endereço da aplicação (padrão: http://web)
//   LOAD_USER_EMAIL     e-mail do usuário de carga, um admin (nunca escrito em arquivo)
//   LOAD_USER_PASSWORD  senha do usuário de carga (nunca escrita em arquivo)
//
// O setup() entra uma vez para cada VU (um token por VU, como no barberflow.js) e cria um
// cliente, um barbeiro e um serviço só deste teste, com nomes únicos. O teardown() cancela o
// agendamento que passou, exclui os três cadastros e faz o logout dos tokens.

import http from 'k6/http';
import exec from 'k6/execution';
import { check, fail, sleep } from 'k6';
import { Counter } from 'k6/metrics';

const BASE_URL = (__ENV.BASE_URL || 'http://web').replace(/\/+$/, '');
const RODADA = (__ENV.RODADA || 'teste').replace(/[^A-Za-z0-9._-]/g, '-');
const EMAIL = __ENV.LOAD_USER_EMAIL || '';
const SENHA = __ENV.LOAD_USER_PASSWORD || '';
const VUS = parseInt(__ENV.VUS || '10', 10);

if (!Number.isInteger(VUS) || VUS < 2 || VUS > 50) {
  throw new Error(`VUS inválido: ${__ENV.VUS}. Use de 2 a 50.`);
}

const LOTE = 10;

const criados = new Counter('agendamentos_criados');
const recusados = new Counter('agendamentos_recusados');
const inesperados = new Counter('respostas_inesperadas');

export const options = {
  scenarios: {
    mesmo_horario: {
      executor: 'per-vu-iterations',
      vus: VUS,
      iterations: 1,
      maxDuration: '1m',
    },
  },
  thresholds: {
    agendamentos_criados: ['count==1'],
    agendamentos_recusados: [`count==${VUS - 1}`],
    respostas_inesperadas: ['count==0'],
    checks: ['rate==1'],
  },
  setupTimeout: '2m',
  teardownTimeout: '2m',
  batchPerHost: LOTE,
};

const JSON_HEADERS = { Accept: 'application/json', 'Content-Type': 'application/json' };

function parametros(nome, token) {
  const headers = token ? { ...JSON_HEADERS, Authorization: `Bearer ${token}` } : JSON_HEADERS;
  return { headers, tags: { name: nome } };
}

function explicar(etapa, resposta) {
  const motivos = {
    0: `a aplicação não respondeu em ${BASE_URL}. A homolog de carga está no ar?`,
    400: 'a aplicação recusou o host. Suba a homolog com o docker-compose.carga.yml (TRUSTED_HOSTS=web).',
    401: 'o token não vale. O usuário de carga tem acesso?',
    403: 'o usuário de carga não tem permissão. Ele precisa ser admin.',
    404: 'a rota não existe nesta versão da aplicação (a agenda chega com a branch feat/agendamentos).',
    422: 'a aplicação recusou os dados. No login, confira LOAD_USER_EMAIL e LOAD_USER_PASSWORD.',
    429: 'limite de requisições. Suba a homolog com o docker-compose.carga.yml, que levanta os limites.',
  };
  return `${etapa}: HTTP ${resposta.status}. ${motivos[resposta.status] || 'veja o log do app.'}`;
}

function entrar(quantidade) {
  const tokens = [];
  for (let inicio = 0; inicio < quantidade; inicio += LOTE) {
    const pedidos = [];
    for (let i = inicio; i < Math.min(quantidade, inicio + LOTE); i++) {
      const corpo = JSON.stringify({ email: EMAIL, password: SENHA, device_name: `k6-concorrencia-${RODADA}-vu${i + 1}` });
      pedidos.push(['POST', `${BASE_URL}/api/login`, corpo, parametros('POST /api/login')]);
    }
    for (const resposta of http.batch(pedidos)) {
      if (resposta.status !== 200 || !resposta.json('token')) {
        fail(explicar('login', resposta));
      }
      tokens.push(resposta.json('token'));
    }
  }
  return tokens;
}

function criar(rota, corpo, token) {
  const resposta = http.post(`${BASE_URL}/api/${rota}`, JSON.stringify(corpo), parametros(`POST /api/${rota}`, token));
  if (resposta.status !== 201) {
    fail(explicar(`criar ${rota}`, resposta));
  }
  return resposta.json('id');
}

function dia(deslocamento) {
  return new Date(Date.now() + deslocamento * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
}

export function setup() {
  if (!EMAIL || !SENHA) {
    fail('Defina LOAD_USER_EMAIL e LOAD_USER_PASSWORD no terminal e passe com -e (load-tests/README.md).');
  }

  const tokens = entrar(VUS);
  const admin = tokens[0];
  const marca = `${RODADA}-${Date.now()}`;

  const serviceId = criar('services', { name: `Corte concorrência ${marca}`, duration: 30, price: 35 }, admin);
  const barberId = criar('barbers', { name: `Barbeiro concorrência ${marca}`, email: `k6-barbeiro-${marca}@example.com`, phone: '67999990000' }, admin);
  const clientId = criar('clients', { name: `Cliente concorrência ${marca}`, email: `k6-cliente-${marca}@example.com`, phone: '67999990000' }, admin);

  // O primeiro horário livre do barbeiro novo, a partir de amanhã (o domingo e os dias sem
  // expediente voltam sem horário). A aplicação calcula no fuso dela; amanhã em UTC nunca é hoje
  // em Brasília.
  let data = null;
  let horario = null;
  for (let deslocamento = 1; deslocamento <= 14 && !horario; deslocamento++) {
    const candidato = dia(deslocamento);
    const url = `${BASE_URL}/api/barbers/${barberId}/available-slots?date=${candidato}&service_id=${serviceId}`;
    const resposta = http.get(url, parametros('GET /api/barbers/{id}/available-slots', admin));
    if (resposta.status !== 200) {
      fail(explicar('horários livres', resposta));
    }
    const livres = resposta.json('data') || [];
    if (livres.length > 0) {
      data = candidato;
      horario = livres[0];
    }
  }
  if (!horario) {
    fail('nenhum horário livre nos próximos 14 dias. Confira o expediente (BUSINESS_*) da homolog.');
  }

  // Todas as VUs esperam até este instante e mandam o pedido juntas.
  const largada = Date.now() + 3000;

  return { tokens, serviceId, barberId, clientId, data, horario, largada };
}

export default function (dados) {
  // Os contadores começam em 0, para as metas valerem mesmo quando nenhum pedido cai neles.
  criados.add(0);
  recusados.add(0);
  inesperados.add(0);

  const token = dados.tokens[(exec.vu.idInTest - 1) % dados.tokens.length];
  const espera = dados.largada - Date.now();
  if (espera > 0) {
    sleep(espera / 1000);
  }

  const corpo = JSON.stringify({
    client_id: dados.clientId,
    barber_id: dados.barberId,
    service_id: dados.serviceId,
    date: dados.data,
    start_time: dados.horario,
  });
  const resposta = http.post(`${BASE_URL}/api/appointments`, corpo, parametros('POST /api/appointments', token));

  // 422 só conta como recusa certa quando o motivo é o horário ocupado (do barbeiro ou do cliente).
  const erros = resposta.status === 422 ? resposta.json('errors') || {} : {};
  if (resposta.status === 201) {
    criados.add(1);
  } else if (resposta.status === 422 && (erros.start_time || erros.client_id)) {
    recusados.add(1);
  } else {
    inesperados.add(1);
    console.error(`VU ${exec.vu.idInTest}: resposta inesperada ${resposta.status} (${Object.keys(erros).join(', ') || 'sem campos de erro'})`);
  }
}

export function teardown(dados) {
  const admin = dados.tokens[0];
  const url = `${BASE_URL}/api/appointments?date=${dados.data}&barber_id=${dados.barberId}&per_page=100`;
  const lista = http.get(url, parametros('GET /api/appointments', admin));
  const agendamentos = lista.status === 200 ? lista.json('data') || [] : [];
  const noHorario = agendamentos.filter((a) => String(a.start_time).startsWith(dados.horario) && a.status !== 'cancelled' && a.status !== 'no_show');

  check(noHorario, { 'um agendamento só no horário disputado': (l) => l.length === 1 });

  // Limpeza: cancela o agendamento e exclui os cadastros do teste (o cliente é anonimizado).
  for (const agendamento of agendamentos) {
    http.patch(`${BASE_URL}/api/appointments/${agendamento.id}/status`, JSON.stringify({ status: 'cancelled' }), parametros('PATCH /api/appointments/{id}/status', admin));
  }
  http.del(`${BASE_URL}/api/clients/${dados.clientId}`, null, parametros('DELETE /api/clients/{id}', admin));
  http.del(`${BASE_URL}/api/barbers/${dados.barberId}`, null, parametros('DELETE /api/barbers/{id}', admin));
  http.del(`${BASE_URL}/api/services/${dados.serviceId}`, null, parametros('DELETE /api/services/{id}', admin));

  for (let inicio = 0; inicio < dados.tokens.length; inicio += LOTE) {
    http.batch(dados.tokens.slice(inicio, inicio + LOTE).map((token) => ['POST', `${BASE_URL}/api/logout`, null, parametros('POST /api/logout', token)]));
  }
}

function contagem(dados, nome) {
  const metrica = dados.metrics[nome];
  return metrica ? metrica.values.count : 0;
}

export function handleSummary(dados) {
  const linhas = [
    `BarberFlow, concorrência da agenda, rodada ${RODADA}, ${VUS} pedidos ao mesmo tempo`,
    ...(dados.metrics.iterations ? [] : ['NENHUM pedido saiu: o setup() parou antes (o motivo está no erro logo abaixo).']),
    `201 (criado): ${contagem(dados, 'agendamentos_criados')}, esperado 1`,
    `422 pelo horário ocupado: ${contagem(dados, 'agendamentos_recusados')}, esperado ${VUS - 1}`,
    `outras respostas: ${contagem(dados, 'respostas_inesperadas')}, esperado 0`,
    'metas:',
  ];
  for (const [nome, metrica] of Object.entries(dados.metrics)) {
    for (const [regra, resultado] of Object.entries(metrica.thresholds || {})) {
      linhas.push(`  ${resultado.ok ? 'passou' : 'FALHOU'}  ${nome} ${regra}`);
    }
  }
  // O setup_data sai do arquivo: ele tem os tokens.
  const semTokens = { ...dados };
  delete semTokens.setup_data;
  return {
    stdout: `${linhas.join('\n')}\n`,
    [`/results/${RODADA}-concorrencia.json`]: JSON.stringify(semTokens, null, 2),
  };
}
