#!/usr/bin/env bash
# Monta el entorno realista (enlaces permanentes + plugin temporal), ejecuta la auditoria y restaura.
SP="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
perma(){ docker exec -i nolantis-gestion-wp php /dev/stdin <<<"<?php \$_SERVER['HTTP_HOST']='localhost:8090'; \$_SERVER['SERVER_SOFTWARE']='Apache'; require '/var/www/html/wp-load.php'; require_once ABSPATH.'wp-admin/includes/misc.php'; global \$wp_rewrite; \$wp_rewrite->set_permalink_structure('$1'); flush_rewrite_rules(true); nolantis_sync_admin_access_htaccess();" >/dev/null; docker exec nolantis-gestion-wp chown www-data:www-data /var/www/html/.htaccess; }
docker exec -i nolantis-gestion-wp sh -c 'cat > /var/www/html/wp-content/mu-plugins/zz-nolantis-audit.php' < "$SP/zz-nolantis-audit.php"
perma '/%postname%/'
bash "$SP/ll-audit.sh"
perma ''
docker exec nolantis-gestion-wp rm -f /var/www/html/wp-content/mu-plugins/zz-nolantis-audit.php /tmp/nolantis-mails.log /tmp/nolantis-phpmailer.log
docker exec -i nolantis-gestion-wp php /dev/stdin <<<"<?php \$_SERVER['HTTP_HOST']='localhost:8090'; require '/var/www/html/wp-load.php'; global \$wpdb; \$wpdb->query(\"DELETE FROM {\$wpdb->options} WHERE option_name LIKE 'nolantis\\\\_test\\\\_%'\"); echo 'entorno restaurado: permalinks=[', get_option('permalink_structure'), ']';"; echo
