#!/usr/bin/env bash
# =============================================================================
#  build-patch.sh — сборка и деплой патча для byB-workspace
#  Использование:
#    ./build-patch.sh                     # изменения с последнего коммита (git diff)
#    ./build-patch.sh --staged            # только staged файлы (git diff --cached)
#    ./build-patch.sh --all               # все неотслеживаемые + изменённые файлы
#    ./build-patch.sh file1 file2 ...     # конкретные файлы
#    ./build-patch.sh --dry               # показать файлы без отправки
#    ./build-patch.sh --sql file.sql      # добавить SQL-миграцию
# =============================================================================

set -euo pipefail

# ─── Конфиг ──────────────────────────────────────────────────────────────────
DEPLOY_URL="${DEPLOY_URL:-https://YOUR_DOMAIN/deploy/deploy.php}"
DEPLOY_KEY="${DEPLOY_KEY:-1234}"
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TMP_DIR="$(mktemp -d)"
ZIP_NAME="patch-$(date +%Y%m%d-%H%M%S).zip"
ZIP_PATH="$TMP_DIR/$ZIP_NAME"
SQL_FILE=""
DRY_RUN=false
VERBOSE=false

# ─── Цвета ───────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

log()    { echo -e "${CYAN}[build-patch]${RESET} $*"; }
ok()     { echo -e "${GREEN}[✓]${RESET} $*"; }
warn()   { echo -e "${YELLOW}[!]${RESET} $*"; }
err()    { echo -e "${RED}[✗]${RESET} $*" >&2; }
header() { echo -e "\n${BOLD}$*${RESET}"; }
cleanup(){ rm -rf "$TMP_DIR"; }
trap cleanup EXIT

# ─── Зависимости ─────────────────────────────────────────────────────────────
for cmd in zip curl git; do
    command -v "$cmd" &>/dev/null || { err "Не найдена утилита: $cmd"; exit 1; }
done

# ─── Парсинг аргументов ──────────────────────────────────────────────────────
MODE="git-diff"
MANUAL_FILES=()

while [[ $# -gt 0 ]]; do
    case "$1" in
        --staged)    MODE="staged"; shift ;;
        --all)       MODE="all"; shift ;;
        --dry|-n)    DRY_RUN=true; shift ;;
        --verbose|-v) VERBOSE=true; shift ;;
        --sql)       SQL_FILE="$2"; shift 2 ;;
        --url)       DEPLOY_URL="$2"; shift 2 ;;
        --key)       DEPLOY_KEY="$2"; shift 2 ;;
        --*)         err "Неизвестный флаг: $1"; exit 1 ;;
        *)           MANUAL_FILES+=("$1"); MODE="manual"; shift ;;
    esac
done

# ─── Сбор файлов ─────────────────────────────────────────────────────────────
header "📦  Сбор файлов..."

cd "$PROJECT_DIR"

FILES=()

case "$MODE" in
    manual)
        for f in "${MANUAL_FILES[@]}"; do
            [[ -f "$f" ]] && FILES+=("$f") || warn "Файл не найден: $f"
        done
        ;;
    staged)
        while IFS= read -r line; do
            [[ -f "$line" ]] && FILES+=("$line")
        done < <(git diff --cached --name-only --diff-filter=ACM)
        ;;
    all)
        while IFS= read -r line; do
            [[ -f "$line" ]] && FILES+=("$line")
        done < <(git status --short | awk '{print $2}')
        ;;
    git-diff)
        # Все изменения: staged + unstaged + untracked
        while IFS= read -r line; do
            [[ -f "$line" ]] && FILES+=("$line")
        done < <({
            git diff --name-only --diff-filter=ACM
            git diff --cached --name-only --diff-filter=ACM
            git ls-files --others --exclude-standard
        } | sort -u)
        ;;
esac

# Исключаем служебные файлы
EXCLUDE_PATTERNS=(
    "build-patch.sh"
    "deploy/"
    ".git/"
    ".env"
    "*.log"
    "node_modules/"
    "vendor/"
    ".DS_Store"
    "Thumbs.db"
)

FILTERED=()
for f in "${FILES[@]}"; do
    skip=false
    for pat in "${EXCLUDE_PATTERNS[@]}"; do
        if [[ "$f" == $pat* ]] || [[ "$f" == *"$pat"* ]]; then
            skip=true; break
        fi
    done
    $skip || FILTERED+=("$f")
done
FILES=("${FILTERED[@]}")

if [[ ${#FILES[@]} -eq 0 ]]; then
    warn "Нет изменённых файлов для деплоя."
    exit 0
fi

# ─── Вывод списка файлов ─────────────────────────────────────────────────────
echo ""
log "Файлы для деплоя (${#FILES[@]}):"
for f in "${FILES[@]}"; do
    echo "   ${GREEN}+${RESET} $f"
done

if [[ -n "$SQL_FILE" ]]; then
    [[ -f "$SQL_FILE" ]] || { err "SQL файл не найден: $SQL_FILE"; exit 1; }
    echo "   ${CYAN}SQL${RESET} $SQL_FILE"
fi

# ─── Dry run ─────────────────────────────────────────────────────────────────
if $DRY_RUN; then
    echo ""
    warn "Dry-run режим: zip не создаётся, на сервер не отправляется."
    exit 0
fi

# ─── Создание ZIP ─────────────────────────────────────────────────────────────
header "🗜   Создание архива..."

# Структура внутри zip:
#   files/      — файлы проекта (с их относительными путями)
#   sql/        — SQL миграции
#   manifest.json

MANIFEST_FILE="$TMP_DIR/manifest.json"
FILES_DIR="$TMP_DIR/files"
SQL_DIR="$TMP_DIR/sql"
mkdir -p "$FILES_DIR" "$SQL_DIR"

# Копируем файлы сохраняя структуру директорий
for f in "${FILES[@]}"; do
    dest="$FILES_DIR/$f"
    mkdir -p "$(dirname "$dest")"
    cp "$f" "$dest"
done

# Копируем SQL
if [[ -n "$SQL_FILE" ]]; then
    cp "$SQL_FILE" "$SQL_DIR/$(basename "$SQL_FILE")"
fi

# Записываем manifest
cat > "$MANIFEST_FILE" <<EOF
{
  "created_at": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")",
  "builder": "$(whoami)@$(hostname)",
  "project": "byB-workspace",
  "mode": "$MODE",
  "files_count": ${#FILES[@]},
  "files": $(printf '%s\n' "${FILES[@]}" | jq -R . | jq -s .),
  "has_sql": $([ -n "$SQL_FILE" ] && echo "true" || echo "false"),
  "git_branch": "$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'unknown')",
  "git_commit": "$(git rev-parse --short HEAD 2>/dev/null || echo 'unknown')"
}
EOF

# Собираем zip (sql/ только если есть файлы)
cd "$TMP_DIR"
if [ -n "$(ls -A "$SQL_DIR" 2>/dev/null)" ]; then
    zip -r "$ZIP_PATH" files/ sql/ manifest.json -x "*.DS_Store" "*.Thumbs.db" &>/dev/null
else
    zip -r "$ZIP_PATH" files/ manifest.json -x "*.DS_Store" "*.Thumbs.db" &>/dev/null
fi
ok "Архив создан: $ZIP_NAME ($(du -h "$ZIP_PATH" | cut -f1))"

# ─── Отправка на сервер ───────────────────────────────────────────────────────
header "🚀  Отправка на сервер..."
echo "   URL: $DEPLOY_URL"
echo ""

RESPONSE=$(curl -s -w "\n%{http_code}" \
    -X POST \
    -H "X-Deploy-Key: $DEPLOY_KEY" \
    -F "zip=@${ZIP_PATH};type=application/zip" \
    "$DEPLOY_URL" 2>&1)

HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY=$(echo "$RESPONSE" | head -n -1)

# ─── Результат ───────────────────────────────────────────────────────────────
echo ""
if [[ "$HTTP_CODE" == "200" ]]; then
    ok "Сервер ответил 200 OK"
    echo ""

    # Парсим JSON-ответ через python если есть
    if command -v python3 &>/dev/null; then
        echo "$BODY" | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    status = d.get('status','?')
    msg    = d.get('message','')
    files  = d.get('deployed_files', [])
    sql    = d.get('sql_executed', [])
    errors = d.get('errors', [])

    color = '\033[0;32m' if status == 'ok' else '\033[0;31m'
    reset = '\033[0m'
    print(f'{color}  Статус: {status}{reset}')
    if msg:  print(f'  {msg}')
    if files:
        print(f'\n  Задеплоено файлов: {len(files)}')
        for f in files: print(f'    ✓ {f}')
    if sql:
        print(f'\n  SQL-миграций выполнено: {len(sql)}')
        for s in sql: print(f'    ✓ {s}')
    if errors:
        print(f'\n\033[0;31m  Ошибки ({len(errors)}):\033[0m')
        for e in errors: print(f'    ✗ {e}')
except Exception as ex:
    print(sys.stdin.read() if False else '')
    print('Raw response:')
    print(sys.argv)
" 2>/dev/null || echo "$BODY"
    else
        echo "$BODY"
    fi
else
    err "Сервер ответил: HTTP $HTTP_CODE"
    echo ""
    echo "$BODY"
    exit 1
fi

echo ""
ok "Готово!"
