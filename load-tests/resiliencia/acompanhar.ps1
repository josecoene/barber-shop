# Acompanha um endereço da homolog e mostra cada mudança de código HTTP, com a hora e os segundos
# desde o começo. Serve para medir quanto tempo um serviço leva para cair e para voltar no roteiro
# de resiliência (load-tests/resiliencia/README.md). HTTP 000 quer dizer sem resposta nenhuma.
#
# No PowerShell, na raiz do repositório:
#   .\load-tests\resiliencia\acompanhar.ps1
#   .\load-tests\resiliencia\acompanhar.ps1 -Url http://localhost:8080/up -Segundos 120
param(
    [string] $Url = 'http://localhost:8080/api/health',
    [int] $Segundos = 300
)

$inicio = Get-Date
$anterior = $null
Write-Host "Acompanhando $Url por $Segundos s (Ctrl+C para parar)"
while (((Get-Date) - $inicio).TotalSeconds -lt $Segundos) {
    $codigo = curl.exe -s -o NUL -m 3 -w '%{http_code}' $Url
    if ($codigo -ne $anterior) {
        $decorrido = ((Get-Date) - $inicio).TotalSeconds
        '{0:HH:mm:ss}  +{1,6:N1} s  HTTP {2}' -f (Get-Date), $decorrido, $codigo
        $anterior = $codigo
    }
    Start-Sleep -Milliseconds 500
}
