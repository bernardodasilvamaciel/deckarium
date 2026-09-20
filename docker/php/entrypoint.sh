#!/bin/sh
# Sobe o cron da rotina automática antes do Apache.
#
# O cron não herda o ambiente do container: as variáveis usadas pelos scripts são
# gravadas em /etc/deckarium/cron.env e carregadas na própria linha do agendamento.
set -e

TZ="${TZ:-America/Sao_Paulo}"
export TZ
if [ -f "/usr/share/zoneinfo/${TZ}" ]; then
    ln -snf "/usr/share/zoneinfo/${TZ}" /etc/localtime
    printf '%s\n' "$TZ" > /etc/timezone
fi
# PHP e cron precisam concordar no fuso, senão o horário exibido em Status mente.
printf 'date.timezone=%s\n' "$TZ" > /usr/local/etc/php/conf.d/zzz-timezone.ini

AUTO_UPDATE_ENABLED="${AUTO_UPDATE_ENABLED:-1}"
AUTO_UPDATE_TIMES="${AUTO_UPDATE_TIMES:-06:10,18:10}"

if [ "$AUTO_UPDATE_ENABLED" != "0" ] && command -v cron >/dev/null 2>&1; then
    mkdir -p /etc/deckarium
    : > /etc/deckarium/cron.env
    chown root:www-data /etc/deckarium/cron.env
    chmod 640 /etc/deckarium/cron.env
    for name in $(env | sed -n 's/^\(DB_[A-Z_0-9]*\|POSTGRES_[A-Z_0-9]*\|SCRYFALL_[A-Z_0-9]*\|STORAGE_DIR\|AUTO_UPDATE_[A-Z_0-9]*\|TZ\)=.*/\1/p'); do
        eval "value=\${$name}"
        escaped=$(printf '%s' "$value" | sed "s/'/'\\\\''/g")
        printf "export %s='%s'\n" "$name" "$escaped" >> /etc/deckarium/cron.env
    done

    {
        printf 'SHELL=/bin/sh\n'
        printf 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n'
        printf 'CRON_TZ=%s\n' "$TZ"
        printf '%s\n' "$AUTO_UPDATE_TIMES" | tr ',;' '\n\n' | while read -r entry; do
            entry=$(printf '%s' "$entry" | tr -d ' \t')
            [ -n "$entry" ] || continue
            # Horários fora de HH:MM são ignorados para não gravar um crontab inválido.
            echo "$entry" | grep -Eq '^([01]?[0-9]|2[0-3]):[0-5][0-9]$' || { echo "Horário inválido em AUTO_UPDATE_TIMES: $entry" >&2; continue; }
            hour=${entry%%:*}
            minute=${entry##*:}
            printf '%s %s * * * www-data . /etc/deckarium/cron.env; php /var/www/html/bin/auto_update.php >> ${STORAGE_DIR:-/var/www/storage}/auto-update.log 2>&1\n' "$minute" "$hour"
        done
    } > /etc/cron.d/deckarium
    chmod 0644 /etc/cron.d/deckarium

    mkdir -p "${STORAGE_DIR:-/var/www/storage}"
    touch "${STORAGE_DIR:-/var/www/storage}/auto-update.log" 2>/dev/null || true
    chown www-data:www-data "${STORAGE_DIR:-/var/www/storage}/auto-update.log" 2>/dev/null || true

    cron
    echo "Rotina automática agendada para ${AUTO_UPDATE_TIMES} (${TZ})."
else
    echo "Rotina automática desligada (AUTO_UPDATE_ENABLED=${AUTO_UPDATE_ENABLED})."
fi

exec docker-php-entrypoint "$@"
