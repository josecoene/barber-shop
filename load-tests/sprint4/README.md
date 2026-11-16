# Resultados da Sprint 4 (29/10/2026)

Cópia, sem mudança, dos resultados do teste de carga da Sprint 4, que viviam no repositório local da Sprint 4 (`barber-shop-cloud`, fora do repositório do grupo). Ficam aqui como referência do que foi entregue naquela sprint.

| Arquivo | O que é |
|---|---|
| `RESULTADOS.md` | As cinco rodadas (baseline e otimizada), as janelas de 30 s, os achados e os limites do teste |
| `AMBIENTE.md` | A máquina e o Docker usados |
| `results/*.json` e `results/*.txt` | O resumo do k6 de cada rodada (os logs brutos, de perto de 200 MB, ficaram de fora) |

**Não compare estes números com os de agora.** A API, a stack e o script eram outros (`load-tests/README.md`, "Resultados da Sprint 4"). O `RESULTADOS.md` cita o script daquela época como `barberflow.js`: ele ficou no repositório da Sprint 4, porque baixava uma biblioteca da internet a cada execução, e o script de agora (`load-tests/barberflow.js`) é outro, com login e sem essa biblioteca. As imagens do painel daquela rodada estão em `docs/nuvem/prints/`, e o relatório da sprint, em `docs/nuvem/sprint4/`.
