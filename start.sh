#!/usr/bin/env bash
set -e
cd "$(dirname "$0")"

if ! command -v php >/dev/null 2>&1; then
    echo "PHP não encontrado. Instale com 'brew install php' (macOS) ou o"
    echo "gerenciador de pacotes da sua distro (ex.: 'sudo apt install php-cli' no Ubuntu/Debian)."
    exit 1
fi

if ! command -v nmap >/dev/null 2>&1; then
    echo "[Aviso] nmap não encontrado — o monitor funciona, mas fica mais"
    echo "lento e menos completo sem ele."
fi

PORT=8080
URL="http://127.0.0.1:${PORT}/"

echo "============================================"
echo "  Monitor de Rede em Tempo Real"
echo "============================================"
echo "Iniciando servidor em ${URL}"
echo "Pressione Ctrl+C para parar o monitor."
echo "============================================"
echo

php -S 127.0.0.1:"$PORT" -t public &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null' EXIT

sleep 1

if command -v open >/dev/null 2>&1; then
    open "$URL"
elif command -v xdg-open >/dev/null 2>&1; then
    xdg-open "$URL" >/dev/null 2>&1 || true
else
    echo "Abra manualmente no navegador: $URL"
fi

wait "$SERVER_PID"
