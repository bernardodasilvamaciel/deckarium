#!/bin/sh
# One resumable job; retry missing images in up to three passes.
cd /var/www/html || exit 1
for pass in 1 2 3; do
  echo "Passagem $pass: todas as impressoes em normal"
  php bin/download_images.php all normal 4
  result=$?
  if [ "$result" -eq 0 ]; then exit 0; fi
  if [ "$result" -ne 2 ]; then exit "$result"; fi
  sleep 10
done
exit 2
