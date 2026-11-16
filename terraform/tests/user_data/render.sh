#!/bin/bash
# Renderiza o terraform/templates/user_data.sh.tftpl como o templatefile do compute.tf faria,
# com valores de exemplo da homolog, e escreve o script pronto na saída padrão. Serve ao teste
# da montagem do .env (env_test.sh) e ao ShellCheck (docs/nuvem/TERRAFORM.md, "Como o user_data
# foi testado").
#
# O template usa só marcadores ${nome} e o escape $${ (que vira ${). Este script troca os dois
# e para se sobrar marcador ou expressão sem valor, ou se o template ganhar uma diretiva %{ },
# que ele não sabe renderizar: o certo então é atualizar este script junto. A versão e o
# SHA-256 do compose vêm do compute.tf e a image_tag provisória vem do ssm.tf, para o teste
# usar os mesmos valores que vão para a AWS.
#
# Uso, na raiz do repositório (Git Bash ou Linux):
#   bash terraform/tests/user_data/render.sh > user_data.sh
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)
template="$root/terraform/templates/user_data.sh.tftpl"
compute="$root/terraform/compute.tf"
ssm="$root/terraform/ssm.tf"
compose="$root/docker-compose.prod.yml"

die() {
  printf 'render.sh: %s\n' "$*" >&2
  exit 1
}

for f in "$template" "$compute" "$ssm" "$compose"; do
  [[ -f "$f" ]] || die "arquivo não encontrado: $f"
done

compute_text=$(<"$compute")
ssm_text=$(<"$ssm")
[[ $compute_text =~ compose_version[[:space:]]*=[[:space:]]*\"(v[0-9]+\.[0-9]+\.[0-9]+)\" ]] ||
  die "compose_version não encontrado no compute.tf"
compose_version=${BASH_REMATCH[1]}
[[ $compute_text =~ compose_sha256[[:space:]]*=[[:space:]]*\"([0-9a-f]{64})\" ]] ||
  die "compose_sha256 não encontrado no compute.tf"
compose_sha256=${BASH_REMATCH[1]}
[[ $ssm_text =~ image_tag[[:space:]]*=[[:space:]]*\{[^}]*initial_value[[:space:]]*=[[:space:]]*\"([^\"]+)\" ]] ||
  die "valor provisório da image_tag não encontrado no ssm.tf"
image_tag_placeholder=${BASH_REMATCH[1]}

# Os mesmos nomes do templatefile do compute.tf, com o que o Terraform daria na homolog.
registry='123456789012.dkr.ecr.us-east-1.amazonaws.com'
declare -A values=(
  [region]='us-east-1'
  [ssm_path]='/barberflow/homolog'
  [registry]="$registry"
  [app_repository_url]="$registry/barberflow-homolog-app"
  [web_repository_url]="$registry/barberflow-homolog-web"
  [log_group_app]='/barberflow/homolog/app'
  [log_group_web]='/barberflow/homolog/web'
  [image_tag_placeholder]="$image_tag_placeholder"
  [compose_version]="$compose_version"
  [compose_sha256]="$compose_sha256"
  [compose_file_gzip_base64]="$(gzip -9 -n -c "$compose" | base64 -w0)"
)

# Os escapes ($${ e %%{) ficam guardados enquanto os marcadores são trocados.
dollar=$'\x01'
percent=$'\x02'
text=$(<"$template")
text=${text//"\$\${"/"$dollar"}
text=${text//"%%{"/"$percent"}
for name in "${!values[@]}"; do
  text=${text//"\${$name}"/"${values[$name]}"}
done
[[ $text != *"\${"* ]] || die "sobrou marcador ou expressão sem valor no template; atualize este script junto"
[[ $text != *"%{"* ]] || die "o template tem diretiva %{ }, que este script não renderiza; atualize este script junto"
text=${text//"$dollar"/"\${"}
text=${text//"$percent"/"%{"}

printf '%s\n' "$text"
