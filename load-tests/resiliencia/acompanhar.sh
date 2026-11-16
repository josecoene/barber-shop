#!/usr/bin/env bash
# Acompanha um endereço da homolog e mostra cada mudança de código HTTP, com a hora e os segundos
# desde o começo. Serve para medir quanto tempo um serviço leva para cair e para voltar no roteiro
# de resiliência (load-tests/resiliencia/README.md). HTTP 000 quer dizer sem resposta nenhuma.
#
# No Git Bash (ou no Linux e no Mac), na raiz do repositório:
#   bash load-tests/resiliencia/acompanhar.sh
#   bash load-tests/resiliencia/acompanhar.sh http://localhost:8080/up 120
set -u

url="${1:-http://localhost:8080/api/health}"
segundos="${2:-300}"

inicio="$(date +%s%N)"
anterior=""
echo "Acompanhando $url por $segundos s (Ctrl+C para parar)"
while :; do
    agora="$(date +%s%N)"
    decorrido_ms=$(((agora - inicio) / 1000000))
    if [ "$decorrido_ms" -ge $((segundos * 1000)) ]; then
        break
    fi
    codigo="$(curl -s -o /dev/null -m 3 -w '%{http_code}' "$url")"
    if [ "$codigo" != "$anterior" ]; then
        printf '%s  +%5d.%01d s  HTTP %s\n' "$(date +%H:%M:%S)" $((decorrido_ms / 1000)) $(((decorrido_ms % 1000) / 100)) "$codigo"
        anterior="$codigo"
    fi
    sleep 0.5
done
