#!/usr/bin/env bash
set -euo pipefail

SSH_HOST="${SSH_HOST:-server211.hosting.reg.ru}"
SSH_USER="${SSH_USER:-u2270813}"
REMOTE_ENV="${REMOTE_ENV:-~/www/new.devtakt.ru/.env}"
WEBHOOK_URL="${WEBHOOK_URL:-https://new.devtakt.ru/webhook.php}"
WEBHOOK_IP="${WEBHOOK_IP:-31.31.196.25}"

command -v curl >/dev/null 2>&1 || { echo "Ошибка: curl не найден." >&2; exit 1; }
command -v ssh >/dev/null 2>&1 || { echo "Ошибка: ssh не найден." >&2; exit 1; }

printf '\n1/4 Получаю текущий TELEGRAM_WEBHOOK_SECRET с сервера...\n'
WEBHOOK_SECRET="$(
  ssh -o ConnectTimeout=15 -o StrictHostKeyChecking=accept-new "${SSH_USER}@${SSH_HOST}" \
    "awk -F= '/^TELEGRAM_WEBHOOK_SECRET=/{sub(/\\r$/,\"\",\$2); print \$2; exit}' ${REMOTE_ENV}"
)"

if [[ -z "${WEBHOOK_SECRET}" ]]; then
  echo "Ошибка: TELEGRAM_WEBHOOK_SECRET не найден в ${REMOTE_ENV}." >&2
  exit 1
fi

if [[ ! "${WEBHOOK_SECRET}" =~ ^[A-Za-z0-9_-]{1,256}$ ]]; then
  echo "Ошибка: секрет на сервере имеет недопустимый формат." >&2
  exit 1
fi

echo "Секрет получен. Длина: ${#WEBHOOK_SECRET}"

printf '\n2/4 Вставьте текущий токен бота из BotFather и нажмите Enter:\n'
IFS= read -r -s BOT_TOKEN </dev/tty
printf '\n'

BOT_TOKEN="${BOT_TOKEN//$'\r'/}"
BOT_TOKEN="${BOT_TOKEN//$'\n'/}"

if [[ ! "${BOT_TOKEN}" =~ ^[0-9]+:[A-Za-z0-9_-]+$ ]]; then
  echo "Ошибка: токен имеет неверный формат. Нужен только токен вида 123456:ABC... без кавычек и пробелов." >&2
  exit 1
fi

printf '\n3/4 Проверяю токен через getMe...\n'
GET_ME="$(curl --fail-with-body -sS --connect-timeout 15 --max-time 30 \
  "https://api.telegram.org/bot${BOT_TOKEN}/getMe")"

if [[ "${GET_ME}" != *'"ok":true'* ]]; then
  echo "Telegram не принял токен:" >&2
  echo "${GET_ME}" >&2
  exit 1
fi

echo "Токен действителен."

printf '\n4/4 Устанавливаю webhook через интернет MacBook...\n'
SET_RESPONSE="$(curl --fail-with-body -sS --connect-timeout 15 --max-time 60 \
  -X POST "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook" \
  --data-urlencode "url=${WEBHOOK_URL}" \
  --data-urlencode "secret_token=${WEBHOOK_SECRET}" \
  --data-urlencode 'allowed_updates=["channel_post","edited_channel_post"]' \
  --data-urlencode 'drop_pending_updates=false' \
  --data-urlencode 'max_connections=1' \
  --data-urlencode "ip_address=${WEBHOOK_IP}")"

if [[ "${SET_RESPONSE}" != *'"ok":true'* ]]; then
  echo "Telegram не установил webhook:" >&2
  echo "${SET_RESPONSE}" >&2
  exit 1
fi

echo "${SET_RESPONSE}"

INFO_RESPONSE="$(curl --fail-with-body -sS --connect-timeout 15 --max-time 30 \
  "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo")"

echo
if [[ "${INFO_RESPONSE}" == *"\"url\":\"${WEBHOOK_URL}\""* && "${INFO_RESPONSE}" == *"\"ip_address\":\"${WEBHOOK_IP}\""* ]]; then
  echo "Готово: webhook подтверждён."
  echo "URL: ${WEBHOOK_URL}"
  echo "IP:  ${WEBHOOK_IP}"
else
  echo "Webhook установлен, но проверочный ответ требует просмотра:"
  echo "${INFO_RESPONSE}"
fi

unset BOT_TOKEN WEBHOOK_SECRET GET_ME SET_RESPONSE INFO_RESPONSE
