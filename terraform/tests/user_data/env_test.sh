#!/bin/bash
# Teste da montagem do .env do user_data (terraform/templates/user_data.sh.tftpl), sem conta da
# AWS, num container descartável do Amazon Linux 2023, o sistema das instâncias:
#   1. instala o jq e o gzip pelo dnf, como a instância;
#   2. renderiza o template (render.sh) e carrega o script com source, que só define as
#      constantes e as funções (a subida inteira só roda quando o arquivo é executado);
#   3. instala o Docker Compose pela função do próprio user_data: versão do compute.tf,
#      conferida pelo SHA-256;
#   4. troca o aws por um falso, que devolve o ssm_parameters.json, monta o .env e grava o compose;
#   5. confere no docker compose config da produção que cada parâmetro chega igual ao app e ao
#      scheduler;
#   6. confere que a subida para, sem mexer no .env anterior e sem mostrar o valor, nos casos
#      que o formato não aceita.
#
# Uso, no PowerShell, na raiz do repositório (Git Bash e detalhes no docs/nuvem/TERRAFORM.md,
# seção "Como o user_data foi testado"):
#   docker run --rm -v "${PWD}\terraform:/src/terraform:ro" -v "${PWD}\docker-compose.prod.yml:/src/docker-compose.prod.yml:ro" <imagem do Amazon Linux 2023 do guia> bash /src/terraform/tests/user_data/env_test.sh
# Precisa de internet: repositórios do dnf e a release do compose no GitHub.
set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
root=$(cd "$here/../../.." && pwd)
work=$(mktemp -d)
readonly INSTANCE='i-0123456789abcdef0'
readonly TAG='3f2a9c1b7d4e'

checks=0
ok() {
  checks=$((checks + 1))
  printf 'ok %2d  %s\n' "$checks" "$*"
}

die() {
  printf '\nFALHOU: %s\n' "$*" >&2
  exit 1
}

# O teste instala pacotes e grava em /opt e em /usr/local: só num container descartável.
if [[ ! -f /.dockerenv ]] || ! grep -qx 'ID="amzn"' /etc/os-release || ! grep -qx 'VERSION_ID="2023"' /etc/os-release; then
  die "rode num container descartável do Amazon Linux 2023 (comando no começo deste arquivo)"
fi

printf '== jq e gzip pelo dnf\n'
if ! dnf install -y jq gzip > "$work/dnf.log" 2>&1; then
  cat "$work/dnf.log" >&2
  die "dnf install jq gzip"
fi

printf '== user_data renderizado e carregado com source\n'
bash "$here/render.sh" > "$work/user_data.sh"
# shellcheck source=/dev/null
source "$work/user_data.sh"
[[ $SSM_PATH == /barberflow/homolog && $APP_DIR == /opt/barberflow && $AWS_REGION_NAME == us-east-1 ]] ||
  die "as constantes do user_data não são as do render.sh"
ok "user_data renderizado ($(wc -c < "$work/user_data.sh") bytes) e carregado sem rodar a subida"

printf '== Docker Compose pela função do user_data\n'
# O docker de verdade não existe aqui: só o "docker compose" é atendido, direto pelo plugin.
docker() {
  if [[ ${1:-} == compose ]]; then
    shift
    /usr/local/lib/docker/cli-plugins/docker-compose "$@"
  else
    printf 'teste: docker %s não existe neste container\n' "$*" >&2
    return 1
  fi
}
install_compose_plugin
ok "Docker Compose $COMPOSE_VERSION instalado, com o SHA-256 do compute.tf conferido"

# aws falso: só aceita a chamada exata do user_data (caminho do ambiente, recursivo e com
# --with-decryption) e devolve o JSON de SSM_JSON_FILE.
SSM_JSON_FILE="$here/ssm_parameters.json"
aws() {
  if [[ "$*" == "ssm get-parameters-by-path --region $AWS_REGION_NAME --path $SSM_PATH --recursive --with-decryption --output json" ]]; then
    cat "$SSM_JSON_FILE"
  else
    printf 'teste: chamada inesperada: aws %s\n' "$*" >&2
    return 1
  fi
}

printf '== .env e compose da produção\n'
install -d -m 0700 "$APP_DIR"
write_env_file "$INSTANCE"
write_compose_file
env_file="$APP_DIR/.env"

[[ $(stat -c '%a %U:%G' "$env_file") == '600 root:root' ]] || die "o .env não tem modo 600 e dono root"
! compgen -G "$APP_DIR/.env.*" > /dev/null || die "sobrou arquivo temporário do .env"
ok ".env com modo 600, dono root e sem arquivo temporário"

! grep -q '^image_tag=' "$env_file" || die "a image_tag virou linha do .env"
for line in "APP_IMAGE='$APP_REPOSITORY:$TAG'" "WEB_IMAGE='$WEB_REPOSITORY:$TAG'" \
  "AWSLOGS_REGION='$AWS_REGION_NAME'" "AWSLOGS_WEB_GROUP='$LOG_GROUP_WEB'" "INSTANCE_ID='$INSTANCE'"; do
  grep -qxF "$line" "$env_file" || die "falta no .env a linha $line"
done
ok "a image_tag vira APP_IMAGE e WEB_IMAGE, e o .env traz região, grupo de log do web e ID da instância"

[[ $(sha256sum < "$APP_DIR/docker-compose.prod.yml") == "$(sha256sum < "$root/docker-compose.prod.yml")" ]] ||
  die "o compose gravado não é o docker-compose.prod.yml do repositório"
ok "compose gravado igual ao docker-compose.prod.yml do repositório"

# O config escreve cada $ do valor como $$, para a saída continuar sendo um compose válido; o
# container recebe o $ simples. Se o compose interpretasse o $HOME do .env, aqui sairia o valor
# trocado, e não $$HOME.
compose_prod config --format json > "$work/config.json" || die "o docker compose não conseguiu ler o .env montado"
total=$(jq --arg name "$SSM_PATH/image_tag" '[.Parameters[] | select(.Name != $name)] | length' "$SSM_JSON_FILE")
(( total >= 20 )) || die "o ssm_parameters.json tem só $total parâmetros"
mismatches=$(jq -r -n --slurpfile ssm "$SSM_JSON_FILE" --slurpfile cfg "$work/config.json" --arg prefix "$SSM_PATH/" '
  $ssm[0].Parameters[]
  | (.Name | ltrimstr($prefix)) as $key
  | select($key != "image_tag")
  | (.Value | gsub("\\$"; "$$")) as $expected
  | ("app", "scheduler")
  | select($cfg[0].services[.].environment[$key] != $expected)
  | "\(.): \($key)"')
[[ -z $mismatches ]] || die "parâmetros que não chegaram iguais (serviço: nome):"$'\n'"$mismatches"
ok "os $total parâmetros chegam iguais ao app e ao scheduler (aspas, \$, \${...}, #, crase, barra invertida, quebra de linha, tab e espaços)"

jq -e --arg app "$APP_REPOSITORY:$TAG" --arg web "$WEB_REPOSITORY:$TAG" --arg group "$LOG_GROUP_WEB" --arg tag "$INSTANCE/{{.Name}}" '
  .services.app.image == $app and .services.scheduler.image == $app and .services.web.image == $web
  and .services.web.logging.driver == "awslogs"
  and .services.web.logging.options["awslogs-group"] == $group
  and .services.web.logging.options["awslogs-region"] == "us-east-1"
  and .services.web.logging.options.tag == $tag' "$work/config.json" > /dev/null ||
  die "imagens ou logging do web diferentes do esperado no docker compose config"
ok "imagens do ECR na tag certa e logs do web no grupo web, com o ID da instância no stream"

printf '== casos em que a subida tem de parar\n'
base=$(<"$SSM_JSON_FILE")
with_tag() {
  jq -c --arg name "$SSM_PATH/image_tag" --arg value "$1" '.Parameters |= map(if .Name == $name then .Value = $value else . end)' <<< "$base"
}
with_param() {
  jq -c --arg name "$1" --arg value "$2" '.Parameters += [{Name: $name, Type: "String", Value: $value}]' <<< "$base"
}

# Cada caso roda num subshell com o próprio set -e, como a subida de verdade. O .env montado
# acima tem de ficar igual, e o log não pode mostrar o valor do parâmetro (o terceiro argumento).
expect_stop() {
  local name=$1 json=$2 secret=${3:-} before status
  printf '%s\n' "$json" > "$work/case.json"
  before=$(sha256sum < "$env_file")
  set +e
  (
    set -e
    SSM_JSON_FILE="$work/case.json"
    write_env_file "$INSTANCE"
  ) > "$work/case.log" 2>&1
  status=$?
  set -e
  (( status != 0 )) || die "$name: a subida devia parar"
  [[ $(sha256sum < "$env_file") == "$before" ]] || die "$name: o .env anterior mudou"
  ! compgen -G "$APP_DIR/.env.*" > /dev/null || die "$name: sobrou arquivo temporário"
  if [[ -n $secret ]] && grep -qF "$secret" "$work/case.log"; then
    die "$name: o log mostrou o valor do parâmetro"
  fi
  ok "para sem mexer no .env: $name"
}

expect_stop "image_tag com o valor provisório" "$(with_tag "$IMAGE_TAG_PLACEHOLDER")"
expect_stop "sem image_tag" "$(jq -c --arg name "$SSM_PATH/image_tag" '.Parameters |= map(select(.Name != $name))' <<< "$base")"
expect_stop "image_tag que não é tag de imagem" "$(with_tag '../tag com espaço')"
expect_stop "parâmetro com nome reservado (APP_IMAGE)" "$(with_param "$SSM_PATH/APP_IMAGE" 'outra-imagem')"
expect_stop "nome com ponto (MAIL.HOST)" "$(with_param "$SSM_PATH/MAIL.HOST" 'smtp.example.com')"
expect_stop "parâmetro numa pasta (mail/USERNAME)" "$(with_param "$SSM_PATH/mail/USERNAME" 'fulano')"
expect_stop "barra invertida antes de aspa simples" "$(with_param "$SSM_PATH/TESTE_SEGREDO" "valor-secreto-1\\'x")" 'valor-secreto-1'
expect_stop "barra invertida no fim do valor" "$(with_param "$SSM_PATH/TESTE_SEGREDO" "valor-secreto-2\\")" 'valor-secreto-2'

printf '\nSucesso: %d conferências passaram.\n' "$checks"
