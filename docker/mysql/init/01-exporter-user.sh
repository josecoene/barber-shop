#!/bin/bash
# Cria o usuário exporter, só de leitura, que o mysqld-exporter do monitoramento usa
# (docker-compose.monitoring.yml). A senha vem de MYSQL_EXPORTER_PASSWORD, que o
# docker-compose.homolog.yml passa ao banco a partir do docker/homolog/homolog.env.
#
# Permissões mínimas: PROCESS e REPLICATION CLIENT para o status do servidor e SELECT só no
# performance_schema. Nada de SELECT nas tabelas da aplicação, que têm dados pessoais.
# No máximo 3 conexões, para o exporter nunca disputar o banco com o app.
#
# Dois jeitos de rodar:
#   1. Banco novo: o entrypoint do MySQL roda os scripts de /docker-entrypoint-initdb.d uma vez
#      só, quando cria o banco, com um servidor temporário no ar. Se o arquivo não tem permissão
#      de execução, o entrypoint carrega o script com "." e ele usa o docker_process_sql; se tem,
#      roda o script à parte, e ele entra pelo socket com a senha do root que o entrypoint acabou
#      de criar (MYSQL_ROOT_PASSWORD, exportada por ele).
#   2. Banco que já existe (criado antes deste script): a senha do root da homolog é aleatória e
#      não fica guardada. Com o serviço db parado, um container avulso do mesmo serviço roda este
#      script, que sobe o mysqld por alguns segundos com --init-file (o jeito que a documentação do
#      MySQL dá para mexer em usuários sem a senha do root), cria o usuário e desliga. Passo a
#      passo no docs/nuvem/MONITORAMENTO.md, "Banco que já existe".
#
# Tudo fica em funções e nada chama exit: quando o entrypoint carrega o arquivo com ".", um exit
# derrubaria o entrypoint.

barberflow_exporter_sql() {
    # Dentro do texto entre aspas simples do SQL, a barra invertida e a aspa simples viram \\ e ''.
    local password
    password="$(printf '%s' "$MYSQL_EXPORTER_PASSWORD" | sed -e 's/\\/\\\\/g' -e "s/'/''/g")"

    # O ALTER USER acerta a senha de um exporter que já existia (o script rodado de novo).
    printf '%s\n' \
        "CREATE USER IF NOT EXISTS 'exporter'@'%' IDENTIFIED BY '${password}' WITH MAX_USER_CONNECTIONS 3;" \
        "ALTER USER 'exporter'@'%' IDENTIFIED BY '${password}' WITH MAX_USER_CONNECTIONS 3;" \
        "GRANT PROCESS, REPLICATION CLIENT ON *.* TO 'exporter'@'%';" \
        "GRANT SELECT ON performance_schema.* TO 'exporter'@'%';"
}

barberflow_exporter_existing_database() {
    local socket=/tmp/barberflow-exporter.sock
    local sql_file
    sql_file="$(mktemp /tmp/barberflow-exporter.XXXXXX.sql)"

    # O arquivo com a senha só existe dentro deste container avulso (o run --rm apaga tudo no fim)
    # e só o usuário mysql lê.
    barberflow_exporter_sql > "$sql_file"
    chown mysql:mysql "$sql_file"
    chmod 600 "$sql_file"

    # Sem rede e com um socket próprio: nada de fora alcança este mysqld temporário.
    mysqld --user=mysql --skip-networking --socket="$socket" --init-file="$sql_file" &
    local pid=$!

    # O --init-file roda antes de o servidor aceitar conexões: quando o ping responde, o usuário
    # já foi criado. O mysqladmin ping devolve sucesso com o servidor no ar, mesmo sem senha.
    local ready=false
    for _ in $(seq 1 90); do
        if ! kill -0 "$pid" 2> /dev/null; then
            break
        fi
        if mysqladmin --socket="$socket" ping > /dev/null 2>&1; then
            ready=true
            break
        fi
        sleep 1
    done

    if kill -0 "$pid" 2> /dev/null; then
        kill -TERM "$pid"
    fi
    wait "$pid" 2> /dev/null
    rm -f "$sql_file"

    if [ "$ready" != true ]; then
        echo "[01-exporter-user] o mysqld temporário não subiu. Pare o serviço db antes (docs/nuvem/MONITORAMENTO.md, \"Banco que já existe\") e veja a mensagem do MySQL acima." >&2
        return 1
    fi
}

barberflow_create_exporter_user() {
    # Sem senha, só avisa: a criação do banco segue, sem o usuário do monitoramento. O
    # docker-compose.homolog.yml não sobe sem a HOMOLOG_DB_EXPORTER_PASSWORD, então isso só
    # acontece com o script usado fora dele.
    if [ -z "${MYSQL_EXPORTER_PASSWORD:-}" ]; then
        echo "[01-exporter-user] MYSQL_EXPORTER_PASSWORD vazio: o usuário exporter não foi criado." >&2
        return 0
    fi

    if declare -F docker_process_sql > /dev/null; then
        # Banco novo, script carregado pelo entrypoint.
        barberflow_exporter_sql | docker_process_sql || return 1
    elif mysqladmin --protocol=socket ping > /dev/null 2>&1; then
        # Banco novo, script rodado à parte pelo entrypoint: o servidor temporário está no ar.
        if [ -z "${MYSQL_ROOT_PASSWORD:-}" ]; then
            echo "[01-exporter-user] há um MySQL no ar, mas sem a senha do root. Num banco que já existe, pare o serviço db antes (docs/nuvem/MONITORAMENTO.md, \"Banco que já existe\")." >&2
            return 1
        fi
        barberflow_exporter_sql | MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket --user=root || return 1
    else
        # Banco que já existe, com o serviço db parado.
        barberflow_exporter_existing_database || return 1
    fi

    echo "[01-exporter-user] usuário exporter pronto (só leitura, até 3 conexões)."
}

barberflow_create_exporter_user
