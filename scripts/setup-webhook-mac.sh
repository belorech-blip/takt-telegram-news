#!/usr/bin/env bash
set -euo pipefail

WEBHOOK_URL="${WEBHOOK_URL:-https://new.devtakt.ru/webhook-open.php}"
WEBHOOK_IP="${WEBHOOK_IP:-31.31.196.25}"

command -v curl >/dev/null 2>&1 || { echo "Ошибка: curl не найден." >&2; exit 1; }

printf '\nВставьте токен BotFather и нажмите Enter:\n'
IFS= read -r -s BOT_TOKEN </dev/tty
printf '\n'

BOT_TOKEN="${BOT_TOKEN//$'\r'/}"
BOT_TOKEN="${BOT_TOKEN//$'\n'/}"
BOT_TOKEN="${BOT_TOKEN// /}"

if [[ ! "${BOT_TOKEN}" =~ ^[0-9]+:[A-Za-z0-9_-]+$ ]]; then
  echo "Ошибка: нужен только токен BotFather вида 123456789:ABC... без кавычек и пробелов." >&2
  exit 1
fi

printf '\nПроверяю токен BotFather...\n'
GET_ME="$(curl -sS --connect-timeout 15 --max-time 30 \
  "https://api.telegram.org/bot${BOT_TOKEN}/getMe")"

if [[ "${GET_ME}" != *'"ok":true'* ]]; then
  echo "Telegram не принял токен BotFather:" >&2
  echo "${GET_ME}" >&2
  exit 1
fi

echo "Токен BotFather действителен."

printf '\nУстанавливаю webhook...\n'
SET_RESPONSE="$(curl -sS --connect-timeout 15 --max-time 60 \
  -X POST "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook" \
  --data-urlencode "url=${WEBHOOK_URL}" \
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

sleep 3
INFO_RESPONSE="$(curl -sS --connect-timeout 15 --max-time 30 \
  "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo")"

echo
if [[ "${INFO_RESPONSE}" == *"\"url\":\"${WEBHOOK_URL}\""* ]]; then
  echo "Готово: webhook подтверждён."
  echo "URL: ${WEBHOOK_URL}"
  echo "IP:  ${WEBHOOK_IP}"
else
  echo "Webhook установлен, но проверочный ответ требует просмотра:"
  echo "${INFO_RESPONSE}"
fi

unset BOT_TOKEN GET_ME SET_RESPONSE INFO_RESPONSE
