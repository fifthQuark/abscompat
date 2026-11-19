#!/usr/bin/env bash
# The e2e suite tests failed logins on purpose; repeated runs trip Nextcloud's brute-force
# protection (HTTP 429). Clear it on the local test container.
set -euo pipefail
for ip in $(podman exec -u www-data abs-nc34 php -r '$db=new PDO("sqlite:/var/www/html/data/nextcloud.db"); foreach($db->query("select distinct ip from oc_bruteforce_attempts") as $r) echo $r[0],"\n";'); do
  podman exec -u www-data abs-nc34 php occ security:bruteforce:reset "$ip"
done
